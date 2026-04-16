<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Workflow {
    public static function init(): void {
        add_action('add_meta_boxes', array(__CLASS__, 'register_meta_box'));
        add_action('admin_post_dcb_workflow_transition', array(__CLASS__, 'handle_transition'));
        add_action('admin_post_dcb_workflow_note', array(__CLASS__, 'handle_note'));
        add_action('admin_post_dcb_workflow_queue_action', array(__CLASS__, 'handle_queue_action'));
        add_filter('bulk_actions-edit-dcb_form_submission', array(__CLASS__, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-edit-dcb_form_submission', array(__CLASS__, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'bulk_action_notice'));
    }

    public static function statuses(): array {
        return array(
            'draft' => __('Draft', 'document-center-builder'),
            'submitted' => __('Submitted', 'document-center-builder'),
            'in_review' => __('In Review', 'document-center-builder'),
            'needs_correction' => __('Needs Correction', 'document-center-builder'),
            'approved' => __('Approved', 'document-center-builder'),
            'rejected' => __('Rejected', 'document-center-builder'),
            'finalized' => __('Finalized', 'document-center-builder'),
        );
    }

    public static function transitions(): array {
        return array(
            'draft' => array('submitted'),
            'submitted' => array('in_review', 'rejected'),
            'in_review' => array('needs_correction', 'approved', 'rejected'),
            'needs_correction' => array('submitted', 'rejected'),
            'approved' => array('finalized'),
            'rejected' => array('in_review'),
            'finalized' => array(),
        );
    }

    public static function get_status(int $submission_id): string {
        $status = sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_status', true));
        if (!isset(self::statuses()[$status])) {
            $status = sanitize_key((string) get_option('dcb_workflow_default_status', 'submitted'));
        }
        if (!isset(self::statuses()[$status])) {
            $status = 'submitted';
        }
        return $status;
    }

    public static function set_status(int $submission_id, string $to_status, string $note = ''): bool {
        $to_status = sanitize_key($to_status);
        $statuses = self::statuses();
        if (!isset($statuses[$to_status])) {
            return false;
        }

        $from_status = self::get_status($submission_id);
        if ($from_status !== $to_status) {
            $allowed = self::transitions()[$from_status] ?? array();
            if (!in_array($to_status, $allowed, true)) {
                return false;
            }
        }

        update_post_meta($submission_id, '_dcb_workflow_status', $to_status);
        self::add_timeline($submission_id, 'status_changed', array(
            'from' => $from_status,
            'to' => $to_status,
            'note' => $note,
        ));

        return true;
    }

    public static function assign_reviewer(int $submission_id, int $user_id): void {
        update_post_meta($submission_id, '_dcb_workflow_assignee_user_id', max(0, $user_id));
        self::add_timeline($submission_id, 'assignee_changed', array('assignee_user_id' => max(0, $user_id)));
    }

    public static function assign_role_queue(int $submission_id, string $role): void {
        $role = sanitize_key($role);
        update_post_meta($submission_id, '_dcb_workflow_assignee_role', $role);
        self::add_timeline($submission_id, 'assignee_role_changed', array('assignee_role' => $role));
    }

    public static function add_note(int $submission_id, string $type, string $message): void {
        self::add_timeline($submission_id, $type, array('message' => sanitize_textarea_field($message)));
    }

    public static function add_timeline(int $submission_id, string $event, array $payload = array()): void {
        if (get_option('dcb_workflow_enable_activity_timeline', '1') !== '1') {
            return;
        }

        $rows = get_post_meta($submission_id, '_dcb_workflow_timeline', true);
        if (!is_array($rows)) {
            $rows = array();
        }

        $user = wp_get_current_user();
        $rows[] = array(
            'time' => current_time('mysql'),
            'event' => sanitize_key($event),
            'actor_user_id' => $user instanceof WP_User ? (int) $user->ID : 0,
            'actor_name' => $user instanceof WP_User ? (string) $user->display_name : '',
            'payload' => $payload,
        );

        if (count($rows) > 200) {
            $rows = array_slice($rows, -200);
        }

        update_post_meta($submission_id, '_dcb_workflow_timeline', $rows);
    }

    public static function get_timeline(int $submission_id): array {
        $timeline = get_post_meta($submission_id, '_dcb_workflow_timeline', true);
        return is_array($timeline) ? $timeline : array();
    }

    private static function timeline_entries_by_event(array $timeline, array $events, int $limit = 6): array {
        $out = array();
        foreach ($timeline as $row) {
            if (!is_array($row)) {
                continue;
            }
            $event = sanitize_key((string) ($row['event'] ?? ''));
            if (!in_array($event, $events, true)) {
                continue;
            }
            $out[] = $row;
        }

        if ($limit > 0 && count($out) > $limit) {
            $out = array_slice($out, -$limit);
        }

        return $out;
    }

    private static function timeline_row_message(array $row): string {
        $payload = isset($row['payload']) && is_array($row['payload']) ? $row['payload'] : array();
        if (!empty($payload['message'])) {
            return sanitize_textarea_field((string) $payload['message']);
        }
        if (!empty($payload['note'])) {
            return sanitize_textarea_field((string) $payload['note']);
        }
        if (!empty($payload['to'])) {
            return sprintf(__('Status changed to %s', 'document-center-builder'), sanitize_text_field((string) $payload['to']));
        }

        return '';
    }

    private static function reviewer_display_label(?WP_User $user, int $id): string {
        if ($user instanceof WP_User) {
            $name = trim((string) $user->display_name);
            if ($name !== '') {
                return $name;
            }
            $email = trim((string) $user->user_email);
            if ($email !== '') {
                return $email;
            }
        }

        return $id > 0 ? sprintf(__('User #%d', 'document-center-builder'), $id) : __('Unassigned', 'document-center-builder');
    }

    public static function register_meta_box(): void {
        add_meta_box(
            'dcb-workflow-meta',
            __('Workflow Routing', 'document-center-builder'),
            array(__CLASS__, 'render_meta_box'),
            'dcb_form_submission',
            'side',
            'high'
        );
    }

    public static function render_meta_box(WP_Post $post): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_REVIEW_SUBMISSIONS)) {
            echo '<p>Unauthorized.</p>';
            return;
        }

        $submission_id = (int) $post->ID;
        $status = self::get_status($submission_id);
        $statuses = self::statuses();
        $allowed = self::transitions()[$status] ?? array();
        $assignee = (int) get_post_meta($submission_id, '_dcb_workflow_assignee_user_id', true);
        $assignee_role = sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_assignee_role', true));
        $timeline = get_post_meta($submission_id, '_dcb_workflow_timeline', true);
        if (!is_array($timeline)) {
            $timeline = array();
        }

        $reviewers = get_users(array(
            'fields' => array('ID', 'display_name', 'user_email'),
            'orderby' => 'display_name',
            'order' => 'ASC',
            'capability' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
        ));
        if (!is_array($reviewers)) {
            $reviewers = array();
        }

        global $wp_roles;
        $editable_roles = is_object($wp_roles) ? (array) $wp_roles->roles : array();

        echo '<p><strong>Status:</strong> ' . esc_html((string) ($statuses[$status] ?? $status)) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dcb_workflow_transition_' . $submission_id, 'dcb_workflow_nonce');
        echo '<input type="hidden" name="action" value="dcb_workflow_transition" />';
        echo '<input type="hidden" name="submission_id" value="' . esc_attr((string) $submission_id) . '" />';

        echo '<p><label for="dcb-workflow-next"><strong>Transition To</strong></label><br/>';
        echo '<select id="dcb-workflow-next" name="to_status" style="width:100%;">';
        foreach ($allowed as $next) {
            echo '<option value="' . esc_attr($next) . '">' . esc_html((string) ($statuses[$next] ?? $next)) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label for="dcb-workflow-assignee"><strong>Assignee User</strong></label><br/>';
        echo '<select id="dcb-workflow-assignee" name="assignee_user_id" style="width:100%;">';
        echo '<option value="0">Unassigned</option>';
        foreach ($reviewers as $reviewer) {
            if (!$reviewer instanceof WP_User) {
                continue;
            }
            $label = trim((string) $reviewer->display_name) !== '' ? (string) $reviewer->display_name : (string) $reviewer->user_email;
            echo '<option value="' . esc_attr((string) $reviewer->ID) . '" ' . selected($assignee, (int) $reviewer->ID, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label for="dcb-workflow-assignee-role"><strong>Assignee Role Queue</strong></label><br/>';
        echo '<select id="dcb-workflow-assignee-role" name="assignee_role" style="width:100%;">';
        echo '<option value="">No role queue</option>';
        foreach ($editable_roles as $role_key => $role_data) {
            $role_key = sanitize_key((string) $role_key);
            $name = is_array($role_data) ? (string) ($role_data['name'] ?? $role_key) : $role_key;
            echo '<option value="' . esc_attr($role_key) . '" ' . selected($assignee_role, $role_key, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label for="dcb-workflow-note"><strong>Note</strong></label><br/>';
        echo '<textarea id="dcb-workflow-note" name="note" rows="3" style="width:100%;"></textarea></p>';

        echo '<p><label for="dcb-workflow-correction"><strong>Correction Request</strong></label><br/>';
        echo '<textarea id="dcb-workflow-correction" name="correction_request" rows="3" style="width:100%;" placeholder="Describe required corrections..."></textarea></p>';

        submit_button(__('Apply Workflow Update', 'document-center-builder'), 'secondary', 'submit', false);
        echo '</form>';

        if ($status === 'finalized') {
            echo '<p><em>' . esc_html__('This submission is finalized. Changes should be limited to audit-safe actions.', 'document-center-builder') . '</em></p>';
        }

        echo '<hr/><p><strong>' . esc_html__('Correction Requests', 'document-center-builder') . '</strong></p>';
        $corrections = self::timeline_entries_by_event($timeline, array('correction_request', 'status_changed'), 6);
        $correction_rows = array();
        foreach ($corrections as $row) {
            $event = sanitize_key((string) ($row['event'] ?? ''));
            $message = self::timeline_row_message($row);
            if ($event === 'status_changed' && strpos(strtolower($message), 'needs') === false && strpos(strtolower($message), 'correction') === false) {
                continue;
            }
            if ($message === '') {
                continue;
            }
            $correction_rows[] = array(
                'time' => sanitize_text_field((string) ($row['time'] ?? '')),
                'actor' => sanitize_text_field((string) ($row['actor_name'] ?? 'system')),
                'message' => $message,
            );
        }

        if (empty($correction_rows)) {
            echo '<p>' . esc_html__('No correction thread entries yet.', 'document-center-builder') . '</p>';
        } else {
            echo '<ul style="margin:0; padding-left:18px; max-height:160px; overflow:auto;">';
            foreach ($correction_rows as $entry) {
                echo '<li><strong>' . esc_html($entry['time']) . '</strong> — ' . esc_html($entry['actor'] !== '' ? $entry['actor'] : 'system') . ': ' . esc_html($entry['message']) . '</li>';
            }
            echo '</ul>';
        }

        echo '<hr/><p><strong>' . esc_html__('Review Notes', 'document-center-builder') . '</strong></p>';
        $notes = self::timeline_entries_by_event($timeline, array('review_note'), 6);
        if (empty($notes)) {
            echo '<p>' . esc_html__('No review notes yet.', 'document-center-builder') . '</p>';
        } else {
            echo '<ul style="margin:0; padding-left:18px; max-height:160px; overflow:auto;">';
            foreach ($notes as $row) {
                $time = sanitize_text_field((string) ($row['time'] ?? ''));
                $actor = sanitize_text_field((string) ($row['actor_name'] ?? 'system'));
                $message = self::timeline_row_message($row);
                echo '<li><strong>' . esc_html($time) . '</strong> — ' . esc_html($actor !== '' ? $actor : 'system') . ': ' . esc_html($message) . '</li>';
            }
            echo '</ul>';
        }

        echo '<hr/><p><strong>' . esc_html__('Recent Activity', 'document-center-builder') . '</strong></p>';
        if (empty($timeline)) {
            echo '<p>' . esc_html__('No activity yet.', 'document-center-builder') . '</p>';
            return;
        }

        $timeline = array_slice($timeline, -8);
        echo '<ul style="margin:0; padding-left:18px; max-height:210px; overflow:auto;">';
        foreach ($timeline as $row) {
            if (!is_array($row)) {
                continue;
            }
            $time = sanitize_text_field((string) ($row['time'] ?? ''));
            $event = sanitize_key((string) ($row['event'] ?? 'event'));
            $actor = sanitize_text_field((string) ($row['actor_name'] ?? 'system'));
            $detail = self::timeline_row_message($row);
            echo '<li><strong>' . esc_html($time) . '</strong> — ' . esc_html(str_replace('_', ' ', $event)) . ' (' . esc_html($actor !== '' ? $actor : 'system') . ')';
            if ($detail !== '') {
                echo ': ' . esc_html($detail);
            }
            echo '</li>';
        }
        echo '</ul>';
    }

    public static function handle_transition(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_WORKFLOWS)) {
            wp_die('Unauthorized');
        }

        $submission_id = isset($_POST['submission_id']) ? (int) $_POST['submission_id'] : 0;
        check_admin_referer('dcb_workflow_transition_' . $submission_id, 'dcb_workflow_nonce');

        $to_status = sanitize_key((string) ($_POST['to_status'] ?? ''));
        $assignee_user_id = isset($_POST['assignee_user_id']) ? (int) $_POST['assignee_user_id'] : 0;
        $assignee_role = sanitize_key((string) ($_POST['assignee_role'] ?? ''));
        $note = sanitize_textarea_field((string) ($_POST['note'] ?? ''));
        $correction_request = sanitize_textarea_field((string) ($_POST['correction_request'] ?? ''));

        if ($assignee_user_id >= 0) {
            self::assign_reviewer($submission_id, $assignee_user_id);
        }
        self::assign_role_queue($submission_id, $assignee_role);

        if ($correction_request !== '') {
            self::add_note($submission_id, 'correction_request', $correction_request);
            self::set_status($submission_id, 'needs_correction', $correction_request);
        } elseif ($to_status !== '') {
            self::set_status($submission_id, $to_status, $note);
            if ($to_status === 'finalized') {
                dcb_finalize_submission_output($submission_id, get_current_user_id());
            }
        } elseif ($note !== '') {
            self::add_note($submission_id, 'review_note', $note);
        }

        wp_safe_redirect(admin_url('post.php?post=' . $submission_id . '&action=edit'));
        exit;
    }

    public static function handle_note(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_WORKFLOWS)) {
            wp_die('Unauthorized');
        }
        $submission_id = isset($_POST['submission_id']) ? (int) $_POST['submission_id'] : 0;
        $note = sanitize_textarea_field((string) ($_POST['note'] ?? ''));
        if ($submission_id > 0 && $note !== '') {
            self::add_note($submission_id, 'review_note', $note);
        }
        wp_safe_redirect(admin_url('post.php?post=' . $submission_id . '&action=edit'));
        exit;
    }

    public static function handle_queue_action(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_WORKFLOWS)) {
            wp_die('Unauthorized');
        }

        check_admin_referer('dcb_workflow_queue_action', 'dcb_workflow_queue_nonce');

        $queue_action = sanitize_key((string) ($_POST['queue_action'] ?? ''));
        $submission_ids = array();

        if (isset($_POST['submission_id'])) {
            $single_id = (int) $_POST['submission_id'];
            if ($single_id > 0) {
                $submission_ids[] = $single_id;
            }
        }

        if (isset($_POST['submission_ids']) && is_array($_POST['submission_ids'])) {
            foreach ((array) $_POST['submission_ids'] as $maybe_id) {
                $id = (int) $maybe_id;
                if ($id > 0) {
                    $submission_ids[] = $id;
                }
            }
        }
        $submission_ids = array_values(array_unique($submission_ids));

        $changed = 0;
        if (!empty($submission_ids)) {
            if ($queue_action === 'quick_assign') {
                $assignee_user_id = isset($_POST['assignee_user_id']) ? (int) $_POST['assignee_user_id'] : 0;
                foreach ($submission_ids as $submission_id) {
                    self::assign_reviewer((int) $submission_id, max(0, $assignee_user_id));
                    $changed++;
                }
            } elseif ($queue_action === 'quick_transition') {
                $to_status = sanitize_key((string) ($_POST['to_status'] ?? ''));
                foreach ($submission_ids as $submission_id) {
                    if (self::set_status((int) $submission_id, $to_status, 'Queue quick transition')) {
                        if ($to_status === 'finalized') {
                            dcb_finalize_submission_output((int) $submission_id, get_current_user_id());
                        }
                        $changed++;
                    }
                }
            } elseif ($queue_action === 'bulk_transition') {
                $to_status = sanitize_key((string) ($_POST['bulk_to_status'] ?? ''));
                foreach ($submission_ids as $submission_id) {
                    if (self::set_status((int) $submission_id, $to_status, 'Queue bulk transition')) {
                        if ($to_status === 'finalized') {
                            dcb_finalize_submission_output((int) $submission_id, get_current_user_id());
                        }
                        $changed++;
                    }
                }
            } elseif ($queue_action === 'bulk_assign') {
                $assignee_user_id = isset($_POST['bulk_assignee_user_id']) ? (int) $_POST['bulk_assignee_user_id'] : 0;
                foreach ($submission_ids as $submission_id) {
                    self::assign_reviewer((int) $submission_id, max(0, $assignee_user_id));
                    $changed++;
                }
            }
        }

        $redirect = add_query_arg(array(
            'page' => 'dcb-review-queue',
            'queue_updated' => $changed,
        ), admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public static function register_bulk_actions(array $actions): array {
        $actions['dcb_mark_in_review'] = __('DCB: Mark In Review', 'document-center-builder');
        $actions['dcb_mark_needs_correction'] = __('DCB: Mark Needs Correction', 'document-center-builder');
        $actions['dcb_mark_approved'] = __('DCB: Mark Approved', 'document-center-builder');
        $actions['dcb_mark_rejected'] = __('DCB: Mark Rejected', 'document-center-builder');
        $actions['dcb_mark_finalized'] = __('DCB: Mark Finalized', 'document-center-builder');
        return $actions;
    }

    public static function handle_bulk_actions(string $redirect_to, string $doaction, array $post_ids): string {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_WORKFLOWS)) {
            return $redirect_to;
        }

        $action_map = array(
            'dcb_mark_in_review' => 'in_review',
            'dcb_mark_needs_correction' => 'needs_correction',
            'dcb_mark_approved' => 'approved',
            'dcb_mark_rejected' => 'rejected',
            'dcb_mark_finalized' => 'finalized',
        );

        if (!isset($action_map[$doaction])) {
            return $redirect_to;
        }

        $target = $action_map[$doaction];
        $changed = 0;
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id < 1) {
                continue;
            }
            if (self::set_status($post_id, $target, 'Bulk workflow update')) {
                if ($target === 'finalized') {
                    dcb_finalize_submission_output($post_id, get_current_user_id());
                }
                $changed++;
            }
        }

        return add_query_arg(array('dcb_bulk_workflow_updated' => $changed), $redirect_to);
    }

    public static function bulk_action_notice(): void {
        if (!isset($_GET['dcb_bulk_workflow_updated'])) {
            return;
        }
        $updated = (int) $_GET['dcb_bulk_workflow_updated'];
        if ($updated < 1) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('%d submissions updated.', 'document-center-builder'), $updated)) . '</p></div>';
    }

    public static function render_queue_page(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_REVIEW_SUBMISSIONS)) {
            wp_die('Unauthorized');
        }

        $status_filter = isset($_GET['workflow_status']) ? sanitize_key((string) $_GET['workflow_status']) : '';
        $assignee_filter = isset($_GET['workflow_assignee']) ? (int) $_GET['workflow_assignee'] : -1;
        $role_filter = isset($_GET['workflow_role']) ? sanitize_key((string) $_GET['workflow_role']) : '';
        $form_filter = isset($_GET['workflow_form']) ? sanitize_key((string) $_GET['workflow_form']) : '';
        $date_from = isset($_GET['workflow_date_from']) ? sanitize_text_field((string) $_GET['workflow_date_from']) : '';
        $date_to = isset($_GET['workflow_date_to']) ? sanitize_text_field((string) $_GET['workflow_date_to']) : '';
        $paged = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $statuses = self::statuses();

        $reviewers = get_users(array(
            'fields' => array('ID', 'display_name', 'user_email'),
            'orderby' => 'display_name',
            'order' => 'ASC',
            'capability' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
        ));
        if (!is_array($reviewers)) {
            $reviewers = array();
        }

        global $wp_roles;
        $editable_roles = is_object($wp_roles) ? (array) $wp_roles->roles : array();

        $meta_query = array('relation' => 'AND');
        if ($status_filter !== '' && isset($statuses[$status_filter])) {
            $meta_query[] = array('key' => '_dcb_workflow_status', 'value' => $status_filter);
        }
        if ($assignee_filter >= 0) {
            $meta_query[] = array('key' => '_dcb_workflow_assignee_user_id', 'value' => $assignee_filter);
        }
        if ($role_filter !== '') {
            $meta_query[] = array('key' => '_dcb_workflow_assignee_role', 'value' => $role_filter);
        }
        if ($form_filter !== '') {
            $meta_query[] = array('key' => '_dcb_form_key', 'value' => $form_filter);
        }

        $date_query = array();
        if ($date_from !== '' || $date_to !== '') {
            $range = array('inclusive' => true);
            if ($date_from !== '') {
                $range['after'] = $date_from;
            }
            if ($date_to !== '') {
                $range['before'] = $date_to;
            }
            $date_query[] = $range;
        }

        $query = new WP_Query(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'posts_per_page' => 40,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => count($meta_query) > 1 ? $meta_query : array(),
            'date_query' => $date_query,
        ));

        echo '<div class="wrap"><h1>' . esc_html__('Reviewer Queue', 'document-center-builder') . '</h1>';
        echo '<p>' . esc_html__('Review, route, request corrections, and finalize submissions.', 'document-center-builder') . '</p>';

        if (isset($_GET['queue_updated'])) {
            $updated = (int) $_GET['queue_updated'];
            if ($updated > 0) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('%d queue item(s) updated.', 'document-center-builder'), $updated)) . '</p></div>';
            }
        }

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end;">';
        echo '<input type="hidden" name="page" value="dcb-review-queue" />';
        echo '<span><label for="dcb-workflow-status-filter"><strong>' . esc_html__('Status', 'document-center-builder') . '</strong></label><br/>';
        echo '<select id="dcb-workflow-status-filter" name="workflow_status">';
        echo '<option value="">' . esc_html__('All', 'document-center-builder') . '</option>';
        foreach ($statuses as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($status_filter, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></span>';

        echo '<span><label for="dcb-workflow-assignee-filter"><strong>' . esc_html__('Assignee', 'document-center-builder') . '</strong></label><br/>';
        echo '<select id="dcb-workflow-assignee-filter" name="workflow_assignee">';
        echo '<option value="-1">' . esc_html__('All', 'document-center-builder') . '</option>';
        echo '<option value="0" ' . selected($assignee_filter, 0, false) . '>' . esc_html__('Unassigned', 'document-center-builder') . '</option>';
        foreach ($reviewers as $reviewer) {
            if (!$reviewer instanceof WP_User) {
                continue;
            }
            echo '<option value="' . esc_attr((string) $reviewer->ID) . '" ' . selected($assignee_filter, (int) $reviewer->ID, false) . '>' . esc_html(self::reviewer_display_label($reviewer, (int) $reviewer->ID)) . '</option>';
        }
        echo '</select></span>';

        echo '<span><label for="dcb-workflow-role-filter"><strong>' . esc_html__('Role Queue', 'document-center-builder') . '</strong></label><br/>';
        echo '<select id="dcb-workflow-role-filter" name="workflow_role">';
        echo '<option value="">' . esc_html__('All', 'document-center-builder') . '</option>';
        foreach ($editable_roles as $role_key => $role_data) {
            $role_key = sanitize_key((string) $role_key);
            $name = is_array($role_data) ? (string) ($role_data['name'] ?? $role_key) : $role_key;
            echo '<option value="' . esc_attr($role_key) . '" ' . selected($role_filter, $role_key, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select></span>';

        echo '<span><label for="dcb-workflow-form-filter"><strong>' . esc_html__('Form Key', 'document-center-builder') . '</strong></label><br/>';
        echo '<input type="text" id="dcb-workflow-form-filter" name="workflow_form" value="' . esc_attr($form_filter) . '" class="regular-text" /></span>';

        echo '<span><label for="dcb-workflow-date-from"><strong>' . esc_html__('From', 'document-center-builder') . '</strong></label><br/>';
        echo '<input type="date" id="dcb-workflow-date-from" name="workflow_date_from" value="' . esc_attr($date_from) . '" /></span>';

        echo '<span><label for="dcb-workflow-date-to"><strong>' . esc_html__('To', 'document-center-builder') . '</strong></label><br/>';
        echo '<input type="date" id="dcb-workflow-date-to" name="workflow_date_to" value="' . esc_attr($date_to) . '" /></span>';

        echo '<span>';
        submit_button(__('Filter Queue', 'document-center-builder'), 'secondary', '', false);
        echo '</span>';
        echo '</form>';

        if (!$query->have_posts()) {
            echo '<p>' . esc_html__('No submissions in queue.', 'document-center-builder') . '</p></div>';
            return;
        }

        echo '<form id="dcb-queue-bulk" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dcb_workflow_queue_action', 'dcb_workflow_queue_nonce');
        echo '<input type="hidden" name="action" value="dcb_workflow_queue_action" />';

        echo '<div style="margin:0 0 10px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
        echo '<select name="bulk_to_status">';
        echo '<option value="">' . esc_html__('Bulk transition…', 'document-center-builder') . '</option>';
        foreach ($statuses as $status_key => $status_label) {
            echo '<option value="' . esc_attr($status_key) . '">' . esc_html($status_label) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" name="queue_action" value="bulk_transition" class="button">' . esc_html__('Apply Status', 'document-center-builder') . '</button>';

        echo '<select name="bulk_assignee_user_id">';
        echo '<option value="">' . esc_html__('Bulk assign reviewer…', 'document-center-builder') . '</option>';
        echo '<option value="0">' . esc_html__('Unassigned', 'document-center-builder') . '</option>';
        foreach ($reviewers as $reviewer) {
            if (!$reviewer instanceof WP_User) {
                continue;
            }
            echo '<option value="' . esc_attr((string) $reviewer->ID) . '">' . esc_html(self::reviewer_display_label($reviewer, (int) $reviewer->ID)) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" name="queue_action" value="bulk_assign" class="button">' . esc_html__('Apply Assignee', 'document-center-builder') . '</button>';
        echo '</div>';
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th class="check-column"><input type="checkbox" onclick="jQuery(\'.dcb-queue-select\').prop(\'checked\', this.checked);" /></th>';
        echo '<th>ID</th><th>Record</th><th>Status</th><th>Assignee</th><th>Role Queue</th><th>Review Thread</th><th>Submitted</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        while ($query->have_posts()) {
            $query->the_post();
            $submission_id = (int) get_the_ID();
            $status = self::get_status($submission_id);
            $assignee_user_id = (int) get_post_meta($submission_id, '_dcb_workflow_assignee_user_id', true);
            $assignee_user = $assignee_user_id > 0 ? get_user_by('id', $assignee_user_id) : null;
            $assignee_role = sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_assignee_role', true));
            $submitted = (string) get_post_meta($submission_id, '_dcb_form_submitted_at', true);
            $timeline = self::get_timeline($submission_id);
            $review_notes = self::timeline_entries_by_event($timeline, array('review_note'), 1);
            $correction_notes = self::timeline_entries_by_event($timeline, array('correction_request'), 1);
            $allowed = self::transitions()[$status] ?? array();
            $is_finalized = $status === 'finalized';

            echo '<tr>';
            echo '<th class="check-column"><input class="dcb-queue-select" type="checkbox" name="submission_ids[]" value="' . esc_attr((string) $submission_id) . '" form="dcb-queue-bulk" /></th>';
            echo '<td>' . esc_html((string) $submission_id) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('post.php?post=' . $submission_id . '&action=edit')) . '">' . esc_html(get_the_title()) . '</a></td>';
            echo '<td><strong>' . esc_html((string) ($statuses[$status] ?? $status)) . '</strong>';
            if ($is_finalized) {
                echo '<div><span class="description">' . esc_html__('Finalized', 'document-center-builder') . '</span></div>';
            }
            echo '</td>';
            echo '<td>' . esc_html(self::reviewer_display_label($assignee_user instanceof WP_User ? $assignee_user : null, $assignee_user_id)) . '</td>';
            echo '<td>' . esc_html($assignee_role !== '' ? $assignee_role : '—') . '</td>';
            echo '<td>';
            if (!empty($correction_notes)) {
                $latest = $correction_notes[count($correction_notes) - 1];
                echo '<div><strong>' . esc_html__('Correction:', 'document-center-builder') . '</strong> ' . esc_html(self::timeline_row_message($latest)) . '</div>';
            }
            if (!empty($review_notes)) {
                $latest_note = $review_notes[count($review_notes) - 1];
                echo '<div><strong>' . esc_html__('Review Note:', 'document-center-builder') . '</strong> ' . esc_html(self::timeline_row_message($latest_note)) . '</div>';
            }
            if (empty($review_notes) && empty($correction_notes)) {
                echo '—';
            }
            echo '</td>';
            echo '<td>' . esc_html($submitted) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . esc_url(admin_url('post.php?post=' . $submission_id . '&action=edit')) . '">' . esc_html__('Open', 'document-center-builder') . '</a> ';
            echo '<a class="button button-small" href="' . esc_url(DCB_Renderer::submission_print_url($submission_id)) . '">' . esc_html__('Print', 'document-center-builder') . '</a> ';
            echo '<a class="button button-small" href="' . esc_url(DCB_Renderer::submission_export_url($submission_id)) . '">' . esc_html__('JSON', 'document-center-builder') . '</a> ';

            if (!$is_finalized && !empty($allowed)) {
                echo '<div style="margin-top:6px;">';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
                wp_nonce_field('dcb_workflow_queue_action', 'dcb_workflow_queue_nonce');
                echo '<input type="hidden" name="action" value="dcb_workflow_queue_action" />';
                echo '<input type="hidden" name="submission_id" value="' . esc_attr((string) $submission_id) . '" />';
                echo '<select name="to_status">';
                foreach ($allowed as $next_status) {
                    echo '<option value="' . esc_attr($next_status) . '">' . esc_html((string) ($statuses[$next_status] ?? $next_status)) . '</option>';
                }
                echo '</select> ';
                echo '<button type="submit" class="button button-small" name="queue_action" value="quick_transition">' . esc_html__('Set', 'document-center-builder') . '</button>';
                echo '</form>';
                echo '</div>';
            } else {
                echo '<div style="margin-top:6px;"><span class="description">' . esc_html__('No further transitions.', 'document-center-builder') . '</span></div>';
            }

            echo '<div style="margin-top:6px;">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
            wp_nonce_field('dcb_workflow_queue_action', 'dcb_workflow_queue_nonce');
            echo '<input type="hidden" name="action" value="dcb_workflow_queue_action" />';
            echo '<input type="hidden" name="submission_id" value="' . esc_attr((string) $submission_id) . '" />';
            echo '<select name="assignee_user_id">';
            echo '<option value="0">' . esc_html__('Unassigned', 'document-center-builder') . '</option>';
            foreach ($reviewers as $reviewer) {
                if (!$reviewer instanceof WP_User) {
                    continue;
                }
                echo '<option value="' . esc_attr((string) $reviewer->ID) . '" ' . selected($assignee_user_id, (int) $reviewer->ID, false) . '>' . esc_html(self::reviewer_display_label($reviewer, (int) $reviewer->ID)) . '</option>';
            }
            echo '</select> ';
            echo '<button type="submit" class="button button-small" name="queue_action" value="quick_assign">' . esc_html__('Assign', 'document-center-builder') . '</button>';
            echo '</form>';
            echo '</div>';
            echo '</td>';
            echo '</tr>';
        }
        wp_reset_postdata();

        echo '</tbody></table>';

        if ($query->max_num_pages > 1) {
            $base = add_query_arg(array('paged' => '%#%'));
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post((string) paginate_links(array(
                'base' => $base,
                'format' => '',
                'current' => $paged,
                'total' => (int) $query->max_num_pages,
                'prev_text' => __('&laquo;', 'document-center-builder'),
                'next_text' => __('&raquo;', 'document-center-builder'),
            )));
            echo '</div></div>';
        }

        echo '</div>';
    }
}
