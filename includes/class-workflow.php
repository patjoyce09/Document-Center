<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Workflow {
    public static function init(): void {
        add_action('add_meta_boxes', array(__CLASS__, 'register_meta_box'));
        add_action('admin_post_dcb_workflow_transition', array(__CLASS__, 'handle_transition'));
        add_action('admin_post_dcb_workflow_note', array(__CLASS__, 'handle_note'));
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

        echo '<hr/><p><strong>Activity Timeline</strong></p>';
        if (empty($timeline)) {
            echo '<p>No activity yet.</p>';
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
            $payload = isset($row['payload']) && is_array($row['payload']) ? $row['payload'] : array();
            $detail = '';
            if (isset($payload['note']) && $payload['note'] !== '') {
                $detail = ' — ' . sanitize_text_field((string) $payload['note']);
            } elseif (isset($payload['message']) && $payload['message'] !== '') {
                $detail = ' — ' . sanitize_text_field((string) $payload['message']);
            } elseif (isset($payload['to']) && $payload['to'] !== '') {
                $detail = ' — ' . sanitize_text_field((string) $payload['to']);
            }
            echo '<li><strong>' . esc_html($time) . '</strong> — ' . esc_html(str_replace('_', ' ', $event)) . ' (' . esc_html($actor !== '' ? $actor : 'system') . ')' . esc_html($detail) . '</li>';
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
        $statuses = self::statuses();

        $query = new WP_Query(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'posts_per_page' => 80,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => $status_filter !== '' ? array(
                array('key' => '_dcb_workflow_status', 'value' => $status_filter),
            ) : array(),
        ));

        echo '<div class="wrap"><h1>Reviewer Queue</h1>';
        echo '<p>Review, route, request corrections, and finalize submissions.</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin-bottom:12px;">';
        echo '<input type="hidden" name="page" value="dcb-review-queue" />';
        echo '<label for="dcb-workflow-status-filter"><strong>Status</strong></label> ';
        echo '<select id="dcb-workflow-status-filter" name="workflow_status">';
        echo '<option value="">All</option>';
        foreach ($statuses as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($status_filter, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';
        submit_button(__('Filter', 'document-center-builder'), 'secondary', '', false);
        echo '</form>';

        if (!$query->have_posts()) {
            echo '<p>No submissions in queue.</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>ID</th><th>Record</th><th>Status</th><th>Assignee</th><th>Role Queue</th><th>Submitted</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        while ($query->have_posts()) {
            $query->the_post();
            $submission_id = (int) get_the_ID();
            $status = self::get_status($submission_id);
            $assignee_user_id = (int) get_post_meta($submission_id, '_dcb_workflow_assignee_user_id', true);
            $assignee_user = $assignee_user_id > 0 ? get_user_by('id', $assignee_user_id) : null;
            $assignee_role = sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_assignee_role', true));
            $submitted = (string) get_post_meta($submission_id, '_dcb_form_submitted_at', true);

            echo '<tr>';
            echo '<td>' . esc_html((string) $submission_id) . '</td>';
            echo '<td><a href="' . esc_url(admin_url('post.php?post=' . $submission_id . '&action=edit')) . '">' . esc_html(get_the_title()) . '</a></td>';
            echo '<td>' . esc_html((string) ($statuses[$status] ?? $status)) . '</td>';
            echo '<td>' . esc_html($assignee_user instanceof WP_User ? (string) $assignee_user->display_name : '—') . '</td>';
            echo '<td>' . esc_html($assignee_role !== '' ? $assignee_role : '—') . '</td>';
            echo '<td>' . esc_html($submitted) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url(admin_url('post.php?post=' . $submission_id . '&action=edit')) . '">Open</a></td>';
            echo '</tr>';
        }
        wp_reset_postdata();

        echo '</tbody></table></div>';
    }
}
