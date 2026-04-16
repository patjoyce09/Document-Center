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
        add_filter('set-screen-option', array(__CLASS__, 'set_screen_option'), 10, 3);
        add_filter('bulk_actions-edit-dcb_form_submission', array(__CLASS__, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-edit-dcb_form_submission', array(__CLASS__, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array(__CLASS__, 'bulk_action_notice'));
    }

    public static function on_queue_page_load(): void {
        add_screen_option('per_page', array(
            'label' => __('Reviewer Queue Items', 'document-center-builder'),
            'default' => 20,
            'option' => 'dcb_review_queue_per_page',
        ));
    }

    public static function set_screen_option($status, string $option, $value) {
        if ($option !== 'dcb_review_queue_per_page') {
            return $status;
        }

        $value = (int) $value;
        if ($value < 1) {
            $value = 20;
        }

        return max(5, min(200, $value));
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

    public static function timeline_entries_by_event(array $timeline, array $events, int $limit = 6): array {
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

    public static function timeline_row_message(array $row): string {
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

    public static function reviewer_display_label(?WP_User $user, int $id): string {
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

    public static function get_queue_reviewers(): array {
        $reviewers = get_users(array(
            'fields' => array('ID', 'display_name', 'user_email'),
            'orderby' => 'display_name',
            'order' => 'ASC',
            'capability' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
        ));

        return is_array($reviewers) ? $reviewers : array();
    }

    public static function get_queue_roles(): array {
        global $wp_roles;
        return is_object($wp_roles) ? (array) $wp_roles->roles : array();
    }

    public static function get_queue_filters_from_request(?array $request = null): array {
        $request = is_array($request) ? $request : $_REQUEST;

        return array(
            'status' => isset($request['workflow_status']) ? sanitize_key((string) $request['workflow_status']) : '',
            'assignee' => isset($request['workflow_assignee']) ? (int) $request['workflow_assignee'] : -1,
            'role' => isset($request['workflow_role']) ? sanitize_key((string) $request['workflow_role']) : '',
            'form' => isset($request['workflow_form']) ? sanitize_key((string) $request['workflow_form']) : '',
            'date_from' => isset($request['workflow_date_from']) ? sanitize_text_field((string) $request['workflow_date_from']) : '',
            'date_to' => isset($request['workflow_date_to']) ? sanitize_text_field((string) $request['workflow_date_to']) : '',
        );
    }

    public static function queue_meta_query(array $filters): array {
        $statuses = self::statuses();
        $meta_query = array('relation' => 'AND');

        $status = sanitize_key((string) ($filters['status'] ?? ''));
        if ($status !== '' && isset($statuses[$status])) {
            $meta_query[] = array('key' => '_dcb_workflow_status', 'value' => $status);
        }

        $assignee = isset($filters['assignee']) ? (int) $filters['assignee'] : -1;
        if ($assignee >= 0) {
            $meta_query[] = array('key' => '_dcb_workflow_assignee_user_id', 'value' => $assignee);
        }

        $role = sanitize_key((string) ($filters['role'] ?? ''));
        if ($role !== '') {
            $meta_query[] = array('key' => '_dcb_workflow_assignee_role', 'value' => $role);
        }

        $form = sanitize_key((string) ($filters['form'] ?? ''));
        if ($form !== '') {
            $meta_query[] = array('key' => '_dcb_form_key', 'value' => $form);
        }

        return count($meta_query) > 1 ? $meta_query : array();
    }

    public static function queue_date_query(array $filters): array {
        $date_from = sanitize_text_field((string) ($filters['date_from'] ?? ''));
        $date_to = sanitize_text_field((string) ($filters['date_to'] ?? ''));
        if ($date_from === '' && $date_to === '') {
            return array();
        }

        $range = array('inclusive' => true);
        if ($date_from !== '') {
            $range['after'] = $date_from;
        }
        if ($date_to !== '') {
            $range['before'] = $date_to;
        }

        return array($range);
    }

    public static function queue_latest_thread_snippets(int $submission_id): array {
        $timeline = self::get_timeline($submission_id);
        $review_notes = self::timeline_entries_by_event($timeline, array('review_note'), 1);
        $correction_notes = self::timeline_entries_by_event($timeline, array('correction_request'), 1);

        $latest_review = !empty($review_notes) ? self::timeline_row_message($review_notes[count($review_notes) - 1]) : '';
        $latest_correction = !empty($correction_notes) ? self::timeline_row_message($correction_notes[count($correction_notes) - 1]) : '';

        return array(
            'review' => $latest_review,
            'correction' => $latest_correction,
        );
    }

    public static function queue_state_snapshot(int $submission_id): array {
        return array(
            'status' => self::get_status($submission_id),
            'assignee_user_id' => (int) get_post_meta($submission_id, '_dcb_workflow_assignee_user_id', true),
            'assignee_role' => sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_assignee_role', true)),
            'finalized' => self::get_status($submission_id) === 'finalized' ? 1 : 0,
        );
    }

    public static function queue_state_token(int $submission_id): string {
        $snapshot = self::queue_state_snapshot($submission_id);
        return sha1((string) wp_json_encode(array($submission_id, $snapshot), JSON_UNESCAPED_SLASHES));
    }

    public static function queue_age_days(int $submission_id): int {
        $submitted_at = sanitize_text_field((string) get_post_meta($submission_id, '_dcb_form_submitted_at', true));
        $ts = $submitted_at !== '' ? strtotime($submitted_at) : false;
        if ($ts === false || $ts <= 0) {
            return 0;
        }

        $age_seconds = max(0, time() - $ts);
        $day_seconds = defined('DAY_IN_SECONDS') ? (int) DAY_IN_SECONDS : 86400;
        return (int) floor($age_seconds / max(1, $day_seconds));
    }

    public static function queue_signals_for_submission(int $submission_id): array {
        $status = self::get_status($submission_id);
        $age_days = self::queue_age_days($submission_id);
        $stale = in_array($status, array('submitted', 'in_review', 'needs_correction'), true) && $age_days >= 7;
        $overdue = in_array($status, array('submitted', 'in_review'), true) && $age_days >= 14;

        return array(
            'age_days' => $age_days,
            'stale' => $stale,
            'overdue' => $overdue,
            'status' => $status,
        );
    }

    public static function queue_analytics_summary(): array {
        $summary = array(
            'aging_buckets' => array('0_2' => 0, '3_7' => 0, '8_14' => 0, '15_plus' => 0),
            'stale_reviews' => 0,
            'overdue_reviews' => 0,
            'assignee_workload' => array(),
        );

        $query = new WP_Query(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'posts_per_page' => 500,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        foreach ((array) $query->posts as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id < 1) {
                continue;
            }

            $signal = self::queue_signals_for_submission($post_id);
            $age_days = (int) ($signal['age_days'] ?? 0);
            if ($age_days <= 2) {
                $summary['aging_buckets']['0_2']++;
            } elseif ($age_days <= 7) {
                $summary['aging_buckets']['3_7']++;
            } elseif ($age_days <= 14) {
                $summary['aging_buckets']['8_14']++;
            } else {
                $summary['aging_buckets']['15_plus']++;
            }

            if (!empty($signal['stale'])) {
                $summary['stale_reviews']++;
            }
            if (!empty($signal['overdue'])) {
                $summary['overdue_reviews']++;
            }

            $assignee = (int) get_post_meta($post_id, '_dcb_workflow_assignee_user_id', true);
            $workload_key = $assignee > 0 ? (string) $assignee : 'unassigned';
            if (!isset($summary['assignee_workload'][$workload_key])) {
                $summary['assignee_workload'][$workload_key] = 0;
            }
            $summary['assignee_workload'][$workload_key]++;
        }

        arsort($summary['assignee_workload']);
        $summary['assignee_workload'] = array_slice($summary['assignee_workload'], 0, 8, true);

        return $summary;
    }

    public static function queue_health_summary(): array {
        $summary = array(
            'submitted' => 0,
            'in_review' => 0,
            'needs_correction' => 0,
            'approved' => 0,
            'finalized' => 0,
            'stale_reviews' => 0,
            'overdue_reviews' => 0,
        );

        $query = new WP_Query(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        foreach ((array) $query->posts as $post_id) {
            $status = self::get_status((int) $post_id);
            if (!isset($summary[$status])) {
                continue;
            }
            $summary[$status]++;

            $signals = self::queue_signals_for_submission((int) $post_id);
            if (!empty($signals['stale'])) {
                $summary['stale_reviews']++;
            }
            if (!empty($signals['overdue'])) {
                $summary['overdue_reviews']++;
            }
        }

        return $summary;
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

        $result = self::execute_queue_action($queue_action, $submission_ids, $_POST);

        $redirect = add_query_arg(array(
            'page' => 'dcb-review-queue',
            'queue_notice' => rawurlencode((string) ($result['message'] ?? __('No queue changes were applied.', 'document-center-builder'))),
            'queue_notice_type' => sanitize_key((string) ($result['type'] ?? 'warning')),
        ), admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    private static function execute_queue_action(string $queue_action, array $submission_ids, array $payload): array {
        $queue_action = sanitize_key($queue_action);

        $changed = 0;
        $invalid_assignee = 0;
        $invalid_transition = 0;
        $conflicts = 0;
        $not_found = 0;
        $finalized_protected = 0;
        $stale_state = 0;
        $state_tokens = isset($payload['state_tokens']) && is_array($payload['state_tokens']) ? $payload['state_tokens'] : array();

        if (!empty($submission_ids)) {
            if ($queue_action === 'quick_assign') {
                $assignee_user_id = isset($payload['assignee_user_id']) ? (int) $payload['assignee_user_id'] : 0;
                $expected_assignee = isset($payload['expected_assignee_user_id']) ? (int) $payload['expected_assignee_user_id'] : null;
                $state_token = sanitize_text_field((string) ($payload['state_token'] ?? ''));
                foreach ($submission_ids as $submission_id) {
                    if (!self::is_valid_assignee($assignee_user_id)) {
                        $invalid_assignee++;
                        continue;
                    }
                    if (!self::is_valid_submission($submission_id)) {
                        $not_found++;
                        continue;
                    }

                    $submission_id = (int) $submission_id;
                    $current_status = self::get_status($submission_id);
                    if ($current_status === 'finalized') {
                        $finalized_protected++;
                        continue;
                    }

                    if ($expected_assignee !== null && (int) get_post_meta($submission_id, '_dcb_workflow_assignee_user_id', true) !== (int) $expected_assignee) {
                        $stale_state++;
                        continue;
                    }
                    if ($state_token !== '' && !self::queue_state_token_matches($submission_id, $state_token)) {
                        $stale_state++;
                        continue;
                    }

                    self::assign_reviewer((int) $submission_id, max(0, $assignee_user_id));
                    $changed++;
                }
            } elseif ($queue_action === 'quick_transition') {
                $to_status = sanitize_key((string) ($payload['to_status'] ?? ''));
                $from_status = sanitize_key((string) ($payload['from_status'] ?? ''));
                $state_token = sanitize_text_field((string) ($payload['state_token'] ?? ''));
                foreach ($submission_ids as $submission_id) {
                    if (!self::is_valid_submission($submission_id)) {
                        $not_found++;
                        continue;
                    }

                    $submission_id = (int) $submission_id;
                    $current_status = self::get_status($submission_id);
                    if ($current_status === 'finalized') {
                        $finalized_protected++;
                        continue;
                    }
                    if ($from_status !== '' && $current_status !== $from_status) {
                        $stale_state++;
                        continue;
                    }
                    if ($state_token !== '' && !self::queue_state_token_matches($submission_id, $state_token)) {
                        $stale_state++;
                        continue;
                    }

                    if (self::set_status($submission_id, $to_status, 'Queue quick transition')) {
                        if ($to_status === 'finalized') {
                            dcb_finalize_submission_output($submission_id, get_current_user_id());
                        }
                        $changed++;
                    } else {
                        $invalid_transition++;
                    }
                }
            } elseif ($queue_action === 'bulk_transition') {
                $to_status = sanitize_key((string) ($payload['bulk_to_status'] ?? ''));
                foreach ($submission_ids as $submission_id) {
                    if (!self::is_valid_submission($submission_id)) {
                        $not_found++;
                        continue;
                    }

                    $submission_id = (int) $submission_id;
                    if (self::get_status($submission_id) === 'finalized') {
                        $finalized_protected++;
                        continue;
                    }

                    if (isset($state_tokens[$submission_id])) {
                        $token = sanitize_text_field((string) $state_tokens[$submission_id]);
                        if ($token !== '' && !self::queue_state_token_matches($submission_id, $token)) {
                            $stale_state++;
                            continue;
                        }
                    }

                    if (self::set_status($submission_id, $to_status, 'Queue bulk transition')) {
                        if ($to_status === 'finalized') {
                            dcb_finalize_submission_output($submission_id, get_current_user_id());
                        }
                        $changed++;
                    } else {
                        $invalid_transition++;
                    }
                }
            } elseif ($queue_action === 'bulk_assign') {
                $assignee_user_id = isset($payload['bulk_assignee_user_id']) ? (int) $payload['bulk_assignee_user_id'] : 0;
                foreach ($submission_ids as $submission_id) {
                    if (!self::is_valid_assignee($assignee_user_id)) {
                        $invalid_assignee++;
                        continue;
                    }
                    if (!self::is_valid_submission($submission_id)) {
                        $not_found++;
                        continue;
                    }

                    $submission_id = (int) $submission_id;
                    if (self::get_status($submission_id) === 'finalized') {
                        $finalized_protected++;
                        continue;
                    }

                    if (isset($state_tokens[$submission_id])) {
                        $token = sanitize_text_field((string) $state_tokens[$submission_id]);
                        if ($token !== '' && !self::queue_state_token_matches($submission_id, $token)) {
                            $stale_state++;
                            continue;
                        }
                    }

                    self::assign_reviewer($submission_id, max(0, $assignee_user_id));
                    $changed++;
                }
            }
        }

        $notice_parts = array();
        $type = 'success';
        if ($changed > 0) {
            $notice_parts[] = sprintf(__('%d queue item(s) updated.', 'document-center-builder'), $changed);
        }
        if ($invalid_transition > 0) {
            $notice_parts[] = sprintf(__('%d item(s) skipped due to invalid status transition for current state.', 'document-center-builder'), $invalid_transition);
            $type = 'warning';
        }
        if ($invalid_assignee > 0) {
            $notice_parts[] = sprintf(__('%d item(s) skipped due to invalid assignee.', 'document-center-builder'), $invalid_assignee);
            $type = 'warning';
        }
        if ($finalized_protected > 0) {
            $notice_parts[] = sprintf(__('%d item(s) skipped because finalized submissions are protected.', 'document-center-builder'), $finalized_protected);
            $type = 'warning';
        }
        if ($stale_state > 0) {
            $notice_parts[] = sprintf(__('%d item(s) skipped because queue state changed before apply. Refresh queue and retry.', 'document-center-builder'), $stale_state);
            $type = 'warning';
        }
        if ($conflicts > 0) {
            $notice_parts[] = sprintf(__('%d item(s) skipped due to status conflict.', 'document-center-builder'), $conflicts);
            $type = 'warning';
        }
        if ($not_found > 0) {
            $notice_parts[] = sprintf(__('%d item(s) were missing or no longer available.', 'document-center-builder'), $not_found);
            $type = 'warning';
        }
        if (empty($notice_parts)) {
            $notice_parts[] = __('No queue changes were applied.', 'document-center-builder');
            $type = 'warning';
        }

        self::record_queue_failure_summary($queue_action, array(
            'changed' => $changed,
            'invalid_transition' => $invalid_transition,
            'invalid_assignee' => $invalid_assignee,
            'conflicts' => $conflicts,
            'stale_state' => $stale_state,
            'finalized_protected' => $finalized_protected,
            'not_found' => $not_found,
            'notice' => implode(' ', $notice_parts),
        ));

        return array(
            'message' => implode(' ', $notice_parts),
            'type' => $type,
            'changed' => $changed,
            'invalid_transition' => $invalid_transition,
            'invalid_assignee' => $invalid_assignee,
            'conflicts' => $conflicts,
            'stale_state' => $stale_state,
            'finalized_protected' => $finalized_protected,
            'not_found' => $not_found,
        );
    }

    private static function queue_state_token_matches(int $submission_id, string $token): bool {
        $token = sanitize_text_field($token);
        if ($token === '') {
            return true;
        }

        return hash_equals(self::queue_state_token($submission_id), $token);
    }

    private static function record_queue_failure_summary(string $queue_action, array $result): void {
        $failures = (int) ($result['invalid_transition'] ?? 0)
            + (int) ($result['invalid_assignee'] ?? 0)
            + (int) ($result['conflicts'] ?? 0)
            + (int) ($result['stale_state'] ?? 0)
            + (int) ($result['finalized_protected'] ?? 0)
            + (int) ($result['not_found'] ?? 0);

        if ($failures < 1) {
            return;
        }

        $rows = get_option('dcb_workflow_recent_queue_failures', array());
        if (!is_array($rows)) {
            $rows = array();
        }

        $rows[] = array(
            'time' => current_time('mysql'),
            'action' => sanitize_key($queue_action),
            'actor_user_id' => (int) get_current_user_id(),
            'summary' => sanitize_text_field((string) ($result['notice'] ?? 'Queue action reported skipped items.')),
            'counts' => array(
                'invalid_transition' => (int) ($result['invalid_transition'] ?? 0),
                'invalid_assignee' => (int) ($result['invalid_assignee'] ?? 0),
                'conflicts' => (int) ($result['conflicts'] ?? 0),
                'stale_state' => (int) ($result['stale_state'] ?? 0),
                'finalized_protected' => (int) ($result['finalized_protected'] ?? 0),
                'not_found' => (int) ($result['not_found'] ?? 0),
            ),
        );

        if (count($rows) > 25) {
            $rows = array_slice($rows, -25);
        }

        update_option('dcb_workflow_recent_queue_failures', $rows, false);
    }

    private static function is_valid_submission(int $submission_id): bool {
        if ($submission_id < 1) {
            return false;
        }

        $post = get_post($submission_id);
        return $post instanceof WP_Post && $post->post_type === 'dcb_form_submission';
    }

    private static function is_valid_assignee(int $assignee_user_id): bool {
        if ($assignee_user_id <= 0) {
            return true;
        }

        $user = get_user_by('id', $assignee_user_id);
        if (!$user instanceof WP_User) {
            return false;
        }

        return user_can($user, DCB_Permissions::CAP_REVIEW_SUBMISSIONS);
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

        if (!class_exists('DCB_Workflow_Queue_Table')) {
            require_once DCB_PLUGIN_DIR . 'includes/class-workflow-queue-table.php';
        }

        $table = new DCB_Workflow_Queue_Table();
        self::handle_queue_table_bulk_action($table);
        $table->prepare_items();

        $health = self::queue_health_summary();
        $analytics = self::queue_analytics_summary();

        echo '<div class="wrap"><h1>' . esc_html__('Reviewer Queue', 'document-center-builder') . '</h1>';
        echo '<p>' . esc_html__('Review, route, request corrections, and finalize submissions.', 'document-center-builder') . '</p>';
        echo '<p class="description">' . esc_html__('Use filters and bulk actions below. Quick row actions are available in the Actions column.', 'document-center-builder') . '</p>';

        if (isset($_GET['queue_notice'])) {
            $type = sanitize_key((string) ($_GET['queue_notice_type'] ?? 'success'));
            if (!in_array($type, array('success', 'warning', 'error', 'info'), true)) {
                $type = 'info';
            }
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html(sanitize_text_field((string) $_GET['queue_notice'])) . '</p></div>';
        }

        echo '<div class="notice notice-info inline"><p>';
        echo esc_html(sprintf(
            __('Queue health — Submitted: %1$d | In Review: %2$d | Needs Correction: %3$d | Approved: %4$d | Finalized: %5$d | Stale: %6$d | Overdue: %7$d', 'document-center-builder'),
            (int) ($health['submitted'] ?? 0),
            (int) ($health['in_review'] ?? 0),
            (int) ($health['needs_correction'] ?? 0),
            (int) ($health['approved'] ?? 0),
            (int) ($health['finalized'] ?? 0),
            (int) ($health['stale_reviews'] ?? 0),
            (int) ($health['overdue_reviews'] ?? 0)
        ));
        echo '</p></div>';

        $workload_bits = array();
        foreach ((array) ($analytics['assignee_workload'] ?? array()) as $assignee_key => $count) {
            $label = (string) $assignee_key;
            if ($assignee_key === 'unassigned') {
                $label = __('Unassigned', 'document-center-builder');
            } else {
                $user = get_user_by('id', (int) $assignee_key);
                $label = self::reviewer_display_label($user instanceof WP_User ? $user : null, (int) $assignee_key);
            }
            $workload_bits[] = $label . ': ' . (int) $count;
        }
        if (!empty($workload_bits)) {
            echo '<p class="description">' . esc_html__('Assignee workload:', 'document-center-builder') . ' ' . esc_html(implode(' | ', $workload_bits)) . '</p>';
        }

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="dcb-review-queue" />';
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    private static function handle_queue_table_bulk_action($table): void {
        if (!($table instanceof DCB_Workflow_Queue_Table)) {
            return;
        }

        $bulk_action = $table->current_action();
        $bulk_assign_triggered = !empty($_REQUEST['dcb_bulk_assign_submit']);
        if ($bulk_action === false && !$bulk_assign_triggered) {
            return;
        }

        check_admin_referer('bulk-dcb-review-queue');

        $submission_ids = isset($_REQUEST['submission_ids']) && is_array($_REQUEST['submission_ids'])
            ? array_values(array_filter(array_map('intval', (array) $_REQUEST['submission_ids']), static function ($id) { return $id > 0; }))
            : array();

        if (empty($submission_ids)) {
            $redirect = add_query_arg(array(
                'page' => 'dcb-review-queue',
                'queue_notice' => rawurlencode(__('No submissions were selected for bulk action.', 'document-center-builder')),
                'queue_notice_type' => 'warning',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        if ($bulk_assign_triggered) {
            $result = self::execute_queue_action('bulk_assign', $submission_ids, array(
                'bulk_assignee_user_id' => isset($_REQUEST['bulk_assignee_user_id']) ? (int) $_REQUEST['bulk_assignee_user_id'] : 0,
                'state_tokens' => isset($_REQUEST['state_tokens']) && is_array($_REQUEST['state_tokens']) ? (array) $_REQUEST['state_tokens'] : array(),
            ));
            $redirect = add_query_arg(array(
                'page' => 'dcb-review-queue',
                'queue_notice' => rawurlencode((string) ($result['message'] ?? '')),
                'queue_notice_type' => sanitize_key((string) ($result['type'] ?? 'warning')),
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        $map = array(
            'bulk_status_in_review' => 'in_review',
            'bulk_status_needs_correction' => 'needs_correction',
            'bulk_status_approved' => 'approved',
            'bulk_status_rejected' => 'rejected',
            'bulk_status_finalized' => 'finalized',
        );

        if (!isset($map[$bulk_action])) {
            return;
        }

        $result = self::execute_queue_action('bulk_transition', $submission_ids, array(
            'bulk_to_status' => $map[$bulk_action],
            'state_tokens' => isset($_REQUEST['state_tokens']) && is_array($_REQUEST['state_tokens']) ? (array) $_REQUEST['state_tokens'] : array(),
        ));
        $redirect = add_query_arg(array(
            'page' => 'dcb-review-queue',
            'queue_notice' => rawurlencode((string) ($result['message'] ?? '')),
            'queue_notice_type' => sanitize_key((string) ($result['type'] ?? 'warning')),
        ), admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }
}
