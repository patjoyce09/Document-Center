<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Workflow {
    public static function init(): void {
        add_action('add_meta_boxes', array(__CLASS__, 'register_meta_box'));
        add_action('admin_post_dcb_workflow_transition', array(__CLASS__, 'handle_transition'));
        add_action('admin_post_dcb_workflow_note', array(__CLASS__, 'handle_note'));
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
        if (!current_user_can('manage_options')) {
            echo '<p>Unauthorized.</p>';
            return;
        }

        $submission_id = (int) $post->ID;
        $status = self::get_status($submission_id);
        $statuses = self::statuses();
        $allowed = self::transitions()[$status] ?? array();
        $assignee = (int) get_post_meta($submission_id, '_dcb_workflow_assignee_user_id', true);
        $timeline = get_post_meta($submission_id, '_dcb_workflow_timeline', true);
        if (!is_array($timeline)) {
            $timeline = array();
        }

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

        echo '<p><label for="dcb-workflow-assignee"><strong>Assignee User ID</strong></label><br/>';
        echo '<input id="dcb-workflow-assignee" type="number" min="0" name="assignee_user_id" value="' . esc_attr((string) $assignee) . '" style="width:100%;" /></p>';

        echo '<p><label for="dcb-workflow-note"><strong>Note</strong></label><br/>';
        echo '<textarea id="dcb-workflow-note" name="note" rows="3" style="width:100%;"></textarea></p>';

        submit_button(__('Apply Workflow Update', 'document-center-builder'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<hr/><p><strong>Activity Timeline</strong></p>';
        if (empty($timeline)) {
            echo '<p>No activity yet.</p>';
            return;
        }

        $timeline = array_slice($timeline, -8);
        echo '<ul style="margin:0; padding-left:18px;">';
        foreach ($timeline as $row) {
            if (!is_array($row)) {
                continue;
            }
            $time = sanitize_text_field((string) ($row['time'] ?? ''));
            $event = sanitize_key((string) ($row['event'] ?? 'event'));
            $actor = sanitize_text_field((string) ($row['actor_name'] ?? 'system'));
            echo '<li><strong>' . esc_html($time) . '</strong> — ' . esc_html($event) . ' (' . esc_html($actor !== '' ? $actor : 'system') . ')</li>';
        }
        echo '</ul>';
    }

    public static function handle_transition(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $submission_id = isset($_POST['submission_id']) ? (int) $_POST['submission_id'] : 0;
        check_admin_referer('dcb_workflow_transition_' . $submission_id, 'dcb_workflow_nonce');

        $to_status = sanitize_key((string) ($_POST['to_status'] ?? ''));
        $assignee_user_id = isset($_POST['assignee_user_id']) ? (int) $_POST['assignee_user_id'] : 0;
        $note = sanitize_textarea_field((string) ($_POST['note'] ?? ''));

        if ($assignee_user_id >= 0) {
            self::assign_reviewer($submission_id, $assignee_user_id);
        }

        if ($to_status !== '') {
            self::set_status($submission_id, $to_status, $note);
        } elseif ($note !== '') {
            self::add_note($submission_id, 'review_note', $note);
        }

        wp_safe_redirect(admin_url('post.php?post=' . $submission_id . '&action=edit'));
        exit;
    }

    public static function handle_note(): void {
        if (!current_user_can('manage_options')) {
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
}
