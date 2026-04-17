<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Workflow {
    private const META_STATUS = '_dcb_workflow_status';
    private const META_TIMELINE = '_dcb_workflow_timeline';
    private const META_ASSIGNEE_USER_ID = '_dcb_workflow_assignee_user_id';
    private const META_ASSIGNMENT = '_dcb_workflow_assignment';
    private const META_REVIEWER_POOL = '_dcb_workflow_reviewer_pool';
    private const META_APPROVER_POOL = '_dcb_workflow_approver_pool';
    private const META_NOTES = '_dcb_workflow_notes';
    private const META_PACKET_STATE = '_dcb_workflow_packet_state';
    private const META_QUEUE_KEY = '_dcb_workflow_queue_key';

    public static function init(): void {
        add_action('add_meta_boxes', array(__CLASS__, 'register_meta_box'));
        add_action('admin_post_dcb_workflow_transition', array(__CLASS__, 'handle_transition'));
        add_action('admin_post_dcb_workflow_note', array(__CLASS__, 'handle_note'));
        add_action('admin_menu', array(__CLASS__, 'register_admin_pages'));
        add_action('admin_post_dcb_workflow_config_save', array(__CLASS__, 'handle_config_save'));
    }

    public static function statuses(): array {
        $statuses = array(
            'draft' => __('Draft', 'document-center-builder'),
            'submitted' => __('Submitted', 'document-center-builder'),
            'in_review' => __('In Review', 'document-center-builder'),
            'needs_correction' => __('Needs Correction', 'document-center-builder'),
            'approved' => __('Approved', 'document-center-builder'),
            'rejected' => __('Rejected', 'document-center-builder'),
            'finalized' => __('Finalized', 'document-center-builder'),
        );

        return function_exists('apply_filters') ? (array) apply_filters('dcb_workflow_statuses', $statuses) : $statuses;
    }

    public static function transitions(): array {
        $transitions = array(
            'draft' => array('submitted'),
            'submitted' => array('in_review', 'rejected'),
            'in_review' => array('needs_correction', 'approved', 'rejected'),
            'needs_correction' => array('submitted', 'rejected'),
            'approved' => array('finalized'),
            'rejected' => array('in_review'),
            'finalized' => array(),
        );

        return function_exists('apply_filters') ? (array) apply_filters('dcb_workflow_status_transitions', $transitions) : $transitions;
    }

    public static function can_transition(string $from_status, string $to_status): bool {
        $from_status = sanitize_key($from_status);
        $to_status = sanitize_key($to_status);
        $statuses = self::statuses();
        if (!isset($statuses[$from_status]) || !isset($statuses[$to_status])) {
            return false;
        }

        if ($from_status === $to_status) {
            return true;
        }

        $allowed = self::transitions()[$from_status] ?? array();
        return in_array($to_status, $allowed, true);
    }

    public static function get_status(int $submission_id): string {
        $status = sanitize_key((string) get_post_meta($submission_id, self::META_STATUS, true));
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
        if (!self::can_transition($from_status, $to_status)) {
            return false;
        }

        update_post_meta($submission_id, self::META_STATUS, $to_status);

        $event = self::transition_event($from_status, $to_status);
        self::add_timeline($submission_id, $event, array(
            'from' => $from_status,
            'to' => $to_status,
            'note' => sanitize_textarea_field($note),
        ));

        if ($note !== '') {
            self::append_note($submission_id, 'review_note', sanitize_textarea_field($note));
        }

        self::trigger_notification('status_changed', $submission_id, array(
            'from' => $from_status,
            'to' => $to_status,
            'note' => sanitize_textarea_field($note),
        ));

        if (function_exists('do_action')) {
            do_action('dcb_workflow_status_transition', $submission_id, $from_status, $to_status, $note);
        }

        return true;
    }

    public static function assign_reviewer(int $submission_id, int $user_id): void {
        self::assign_target($submission_id, array(
            'type' => 'user',
            'user_id' => max(0, $user_id),
        ), 'assignee_changed');
    }

    public static function add_note(int $submission_id, string $type, string $message): void {
        $type = sanitize_key($type);
        if ($type === '') {
            $type = 'review_note';
        }

        $message = sanitize_textarea_field($message);
        if ($message === '') {
            return;
        }

        self::append_note($submission_id, $type, $message);
        self::add_timeline($submission_id, $type, array('message' => $message));
        self::trigger_notification('note_added', $submission_id, array(
            'type' => $type,
            'message' => $message,
        ));
    }

    public static function request_correction(int $submission_id, string $message): bool {
        $message = sanitize_textarea_field($message);
        if ($message === '') {
            return false;
        }

        self::append_note($submission_id, 'correction_request', $message);
        $ok = self::set_status($submission_id, 'needs_correction', $message);
        if ($ok) {
            self::add_timeline($submission_id, 'correction_requested', array('message' => $message));
            self::trigger_notification('correction_requested', $submission_id, array('message' => $message));
        }

        return $ok;
    }

    public static function add_timeline(int $submission_id, string $event, array $payload = array()): void {
        if (get_option('dcb_workflow_enable_activity_timeline', '1') !== '1') {
            return;
        }

        $rows = get_post_meta($submission_id, self::META_TIMELINE, true);
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

        update_post_meta($submission_id, self::META_TIMELINE, $rows);

        if (function_exists('do_action')) {
            $latest = end($rows);
            do_action('dcb_workflow_event', $submission_id, $latest);
            do_action('dcb_workflow_event_' . sanitize_key($event), $submission_id, $latest);
        }
    }

    public static function get_timeline(int $submission_id, string $event = ''): array {
        $rows = get_post_meta($submission_id, self::META_TIMELINE, true);
        if (!is_array($rows)) {
            return array();
        }

        $event = sanitize_key($event);
        if ($event === '') {
            return array_values($rows);
        }

        return array_values(array_filter($rows, static function ($row) use ($event) {
            return is_array($row) && sanitize_key((string) ($row['event'] ?? '')) === $event;
        }));
    }

    public static function get_assignment(int $submission_id): array {
        $assignment = get_post_meta($submission_id, self::META_ASSIGNMENT, true);
        if (is_array($assignment) && !empty($assignment['type'])) {
            return self::normalize_assignment_target($assignment);
        }

        $legacy_user_id = (int) get_post_meta($submission_id, self::META_ASSIGNEE_USER_ID, true);
        if ($legacy_user_id > 0) {
            return array('type' => 'user', 'user_id' => $legacy_user_id);
        }

        return array('type' => 'unassigned');
    }

    public static function assign_target(int $submission_id, array $target, string $reason = 'assigned'): array {
        $clean = self::normalize_assignment_target($target);
        $previous = self::get_assignment($submission_id);

        $user_id = (int) ($clean['user_id'] ?? 0);
        update_post_meta($submission_id, self::META_ASSIGNEE_USER_ID, $user_id > 0 ? $user_id : 0);
        update_post_meta($submission_id, self::META_ASSIGNMENT, $clean);

        $queue_key = sanitize_key((string) ($clean['queue'] ?? ''));
        if ($queue_key !== '') {
            update_post_meta($submission_id, self::META_QUEUE_KEY, $queue_key);
        }

        $event = self::assignments_equal($previous, $clean) ? 'assignment_confirmed' : (!empty($previous['type']) && $previous['type'] !== 'unassigned' ? 're_assigned' : 'assigned');
        self::add_timeline($submission_id, $event, array(
            'reason' => sanitize_key($reason),
            'assignment' => $clean,
        ));

        self::trigger_notification('assigned', $submission_id, array(
            'reason' => sanitize_key($reason),
            'assignment' => $clean,
            'previous_assignment' => $previous,
        ));

        return $clean;
    }

    public static function route_submission(int $submission_id, array $context = array()): array {
        $ctx = array_merge(self::build_submission_context($submission_id), is_array($context) ? $context : array());
        $rules = self::get_routing_rules();
        $matched = null;

        foreach ($rules as $rule) {
            if (self::rule_matches($rule, $ctx)) {
                $matched = $rule;
                break;
            }
        }

        if ($matched === null) {
            self::add_timeline($submission_id, 'routing_unmatched', array(
                'status' => (string) ($ctx['status'] ?? ''),
                'form_key' => (string) ($ctx['form_key'] ?? ''),
            ));
            return array('matched' => false);
        }

        update_post_meta($submission_id, '_dcb_workflow_route_rule_id', (string) ($matched['id'] ?? ''));
        update_post_meta($submission_id, '_dcb_workflow_route_rule_name', (string) ($matched['name'] ?? ''));

        $assignment = self::assign_target($submission_id, (array) ($matched['assignment'] ?? array()), 'rule_match');

        $reviewer_pool = self::normalize_pool((array) ($matched['reviewer_pool'] ?? array()));
        $approver_pool = self::normalize_pool((array) ($matched['approver_pool'] ?? array()));
        update_post_meta($submission_id, self::META_REVIEWER_POOL, $reviewer_pool);
        update_post_meta($submission_id, self::META_APPROVER_POOL, $approver_pool);

        $queue_key = sanitize_key((string) ($assignment['queue'] ?? ''));
        if ($queue_key !== '') {
            update_post_meta($submission_id, self::META_QUEUE_KEY, $queue_key);
        }

        $packet_key = sanitize_key((string) ($matched['packet_key'] ?? ''));
        if ($packet_key !== '') {
            self::initialize_packet_state($submission_id, $packet_key, (string) ($ctx['document_type'] ?? ''));
        }

        $set_status = sanitize_key((string) ($matched['set_status'] ?? ''));
        if ($set_status !== '' && self::can_transition(self::get_status($submission_id), $set_status)) {
            self::set_status($submission_id, $set_status, 'Routed by workflow rule.');
        }

        self::add_timeline($submission_id, 'routed', array(
            'rule_id' => (string) ($matched['id'] ?? ''),
            'rule_name' => (string) ($matched['name'] ?? ''),
            'queue_key' => $queue_key,
        ));

        self::trigger_notification('routed', $submission_id, array(
            'rule' => $matched,
            'context' => $ctx,
            'assignment' => $assignment,
            'reviewer_pool' => $reviewer_pool,
            'approver_pool' => $approver_pool,
        ));

        return array(
            'matched' => true,
            'rule' => $matched,
            'assignment' => $assignment,
            'reviewer_pool' => $reviewer_pool,
            'approver_pool' => $approver_pool,
        );
    }

    public static function initialize_packet_state(int $submission_id, string $packet_key, string $document_type = ''): array {
        $packet_key = sanitize_key($packet_key);
        if ($packet_key === '') {
            return array();
        }

        $definitions = self::get_packet_definitions();
        $definition = $definitions[$packet_key] ?? array();
        $required = array_values(array_filter(array_map('sanitize_key', (array) ($definition['required_document_types'] ?? array()))));

        $state = get_post_meta($submission_id, self::META_PACKET_STATE, true);
        if (!is_array($state)) {
            $state = array();
        }

        $received = array_values(array_filter(array_map('sanitize_key', (array) ($state['received_items'] ?? array()))));
        $approved = array_values(array_filter(array_map('sanitize_key', (array) ($state['approved_items'] ?? array()))));
        $doc_key = sanitize_key($document_type);
        if ($doc_key !== '' && !in_array($doc_key, $received, true)) {
            $received[] = $doc_key;
        }

        $missing = array_values(array_diff($required, $received));
        $new_state = array(
            'packet_key' => $packet_key,
            'required_items' => $required,
            'received_items' => $received,
            'approved_items' => $approved,
            'missing_items' => $missing,
            'is_complete' => empty($missing),
        );

        update_post_meta($submission_id, self::META_PACKET_STATE, $new_state);
        self::add_timeline($submission_id, 'packet_initialized', $new_state);
        return $new_state;
    }

    public static function mark_packet_item(int $submission_id, string $item_key, bool $approved = false): array {
        $item_key = sanitize_key($item_key);
        if ($item_key === '') {
            return self::get_packet_state($submission_id);
        }

        $state = self::get_packet_state($submission_id);
        if (empty($state)) {
            return array();
        }

        $received = array_values(array_filter(array_map('sanitize_key', (array) ($state['received_items'] ?? array()))));
        $approved_items = array_values(array_filter(array_map('sanitize_key', (array) ($state['approved_items'] ?? array()))));
        if (!in_array($item_key, $received, true)) {
            $received[] = $item_key;
        }
        if ($approved && !in_array($item_key, $approved_items, true)) {
            $approved_items[] = $item_key;
        }

        $required = array_values(array_filter(array_map('sanitize_key', (array) ($state['required_items'] ?? array()))));
        $missing = array_values(array_diff($required, $received));

        $state['received_items'] = $received;
        $state['approved_items'] = $approved_items;
        $state['missing_items'] = $missing;
        $state['is_complete'] = empty($missing);

        update_post_meta($submission_id, self::META_PACKET_STATE, $state);
        self::add_timeline($submission_id, $approved ? 'packet_item_approved' : 'packet_item_received', array(
            'item_key' => $item_key,
            'packet_key' => (string) ($state['packet_key'] ?? ''),
            'is_complete' => !empty($state['is_complete']),
        ));

        return $state;
    }

    public static function get_packet_state(int $submission_id): array {
        $state = get_post_meta($submission_id, self::META_PACKET_STATE, true);
        return is_array($state) ? $state : array();
    }

    public static function register_admin_pages(): void {
        add_submenu_page(
            'dcb-dashboard',
            __('Workflow Queues', 'document-center-builder'),
            __('Workflow Queues', 'document-center-builder'),
            DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'dcb-workflow-queues',
            array(__CLASS__, 'render_queue_page')
        );

        add_submenu_page(
            'dcb-dashboard',
            __('Workflow Routing', 'document-center-builder'),
            __('Workflow Routing', 'document-center-builder'),
            DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'dcb-workflow-config',
            array(__CLASS__, 'render_config_page')
        );
    }

    public static function render_queue_page(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_REVIEW_SUBMISSIONS)) {
            wp_die('Unauthorized');
        }

        $views = self::queue_views();
        $current_view = sanitize_key((string) ($_GET['view'] ?? 'my_assigned'));
        if (!isset($views[$current_view])) {
            $current_view = 'my_assigned';
        }

        $items = self::query_queue_items($current_view);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Workflow Queues', 'document-center-builder') . '</h1>';
        echo '<p>' . esc_html__('Generic queue views for assignments, review, corrections, and finalized records.', 'document-center-builder') . '</p>';

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($views as $key => $label) {
            $url = add_query_arg(array('page' => 'dcb-workflow-queues', 'view' => $key), admin_url('admin.php'));
            $class = $key === $current_view ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        if (empty($items)) {
            echo '<p>' . esc_html__('No items found for this queue.', 'document-center-builder') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Submission', 'document-center-builder') . '</th>';
        echo '<th>' . esc_html__('Form', 'document-center-builder') . '</th>';
        echo '<th>' . esc_html__('Status', 'document-center-builder') . '</th>';
        echo '<th>' . esc_html__('Assignment', 'document-center-builder') . '</th>';
        echo '<th>' . esc_html__('Queue', 'document-center-builder') . '</th>';
        echo '<th>' . esc_html__('Updated', 'document-center-builder') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            if (!$item instanceof WP_Post) {
                continue;
            }

            $submission_id = (int) $item->ID;
            $status = self::get_status($submission_id);
            $status_label = (string) (self::statuses()[$status] ?? $status);
            $assignment_label = self::assignment_label(self::get_assignment($submission_id));
            $queue_key = (string) get_post_meta($submission_id, self::META_QUEUE_KEY, true);
            $edit_url = admin_url('post.php?post=' . $submission_id . '&action=edit');

            echo '<tr>';
            echo '<td><a href="' . esc_url($edit_url) . '">#' . esc_html((string) $submission_id) . ' — ' . esc_html((string) $item->post_title) . '</a></td>';
            echo '<td>' . esc_html((string) get_post_meta($submission_id, '_dcb_form_label', true)) . '</td>';
            echo '<td>' . esc_html($status_label) . '</td>';
            echo '<td>' . esc_html($assignment_label) . '</td>';
            echo '<td>' . esc_html($queue_key !== '' ? $queue_key : '—') . '</td>';
            echo '<td>' . esc_html((string) $item->post_modified) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public static function render_config_page(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_WORKFLOWS)) {
            wp_die('Unauthorized');
        }

        $rules = wp_json_encode(self::get_routing_rules(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $queues = wp_json_encode(self::get_queue_groups(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $packets = wp_json_encode(self::get_packet_definitions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($rules)) {
            $rules = '[]';
        }
        if (!is_string($queues)) {
            $queues = '[]';
        }
        if (!is_string($packets)) {
            $packets = '[]';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Workflow Routing Configuration', 'document-center-builder') . '</h1>';
        echo '<p>' . esc_html__('Data-driven routing rules and packet/queue mapping. Keep JSON structured and generic.', 'document-center-builder') . '</p>';

        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Workflow configuration saved.', 'document-center-builder') . '</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Invalid JSON payload. Configuration was not saved.', 'document-center-builder') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dcb_workflow_config_save', 'dcb_workflow_config_nonce');
        echo '<input type="hidden" name="action" value="dcb_workflow_config_save" />';

        echo '<h2>' . esc_html__('Routing Rules', 'document-center-builder') . '</h2>';
        echo '<textarea name="dcb_workflow_routing_rules_json" rows="12" class="large-text code">' . esc_textarea($rules) . '</textarea>';

        echo '<h2>' . esc_html__('Queue Groups', 'document-center-builder') . '</h2>';
        echo '<textarea name="dcb_workflow_queue_groups_json" rows="8" class="large-text code">' . esc_textarea($queues) . '</textarea>';

        echo '<h2>' . esc_html__('Packet Definitions', 'document-center-builder') . '</h2>';
        echo '<textarea name="dcb_workflow_packet_definitions_json" rows="8" class="large-text code">' . esc_textarea($packets) . '</textarea>';

        submit_button(__('Save Workflow Configuration', 'document-center-builder'));
        echo '</form>';
        echo '</div>';
    }

    public static function handle_config_save(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_WORKFLOWS)) {
            wp_die('Unauthorized');
        }

        $nonce = isset($_POST['dcb_workflow_config_nonce']) ? (string) $_POST['dcb_workflow_config_nonce'] : '';
        if (!wp_verify_nonce($nonce, 'dcb_workflow_config_save')) {
            wp_die('Security check failed');
        }

        $rules_raw = isset($_POST['dcb_workflow_routing_rules_json']) ? wp_unslash((string) $_POST['dcb_workflow_routing_rules_json']) : '[]';
        $queues_raw = isset($_POST['dcb_workflow_queue_groups_json']) ? wp_unslash((string) $_POST['dcb_workflow_queue_groups_json']) : '[]';
        $packets_raw = isset($_POST['dcb_workflow_packet_definitions_json']) ? wp_unslash((string) $_POST['dcb_workflow_packet_definitions_json']) : '[]';

        $rules = json_decode($rules_raw, true);
        $queues = json_decode($queues_raw, true);
        $packets = json_decode($packets_raw, true);

        if (!is_array($rules) || !is_array($queues) || !is_array($packets)) {
            wp_safe_redirect(add_query_arg(array('page' => 'dcb-workflow-config', 'error' => '1'), admin_url('admin.php')));
            exit;
        }

        update_option('dcb_workflow_routing_rules', self::normalize_routing_rules($rules), false);
        update_option('dcb_workflow_queue_groups', self::normalize_queue_groups($queues), false);
        update_option('dcb_workflow_packet_definitions', self::normalize_packet_definitions($packets), false);

        wp_safe_redirect(add_query_arg(array('page' => 'dcb-workflow-config', 'updated' => '1'), admin_url('admin.php')));
        exit;
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
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_WORKFLOWS)) {
            echo '<p>Unauthorized.</p>';
            return;
        }

        $submission_id = (int) $post->ID;
        $status = self::get_status($submission_id);
        $statuses = self::statuses();
        $allowed = self::transitions()[$status] ?? array();
        $assignment = self::get_assignment($submission_id);
        $assignee = (int) ($assignment['user_id'] ?? 0);
        $timeline = self::get_timeline($submission_id);
        $packet = self::get_packet_state($submission_id);
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

        echo '<p><label for="dcb-workflow-assignee-role"><strong>Assignee Role</strong></label><br/>';
        echo '<input id="dcb-workflow-assignee-role" type="text" name="assignee_role" value="' . esc_attr((string) ($assignment['role'] ?? '')) . '" style="width:100%;" placeholder="editor" /></p>';

        echo '<p><label for="dcb-workflow-assignee-queue"><strong>Assignee Queue</strong></label><br/>';
        echo '<input id="dcb-workflow-assignee-queue" type="text" name="assignee_queue" value="' . esc_attr((string) ($assignment['queue'] ?? '')) . '" style="width:100%;" placeholder="intake_review" /></p>';

        echo '<p><label for="dcb-workflow-note"><strong>Note</strong></label><br/>';
        echo '<textarea id="dcb-workflow-note" name="note" rows="3" style="width:100%;"></textarea></p>';

        submit_button(__('Apply Workflow Update', 'document-center-builder'), 'secondary', 'submit', false);
        echo '</form>';

        if (!empty($packet)) {
            $missing = isset($packet['missing_items']) && is_array($packet['missing_items']) ? $packet['missing_items'] : array();
            echo '<hr/><p><strong>Packet State</strong></p>';
            echo '<p><strong>Packet:</strong> ' . esc_html((string) ($packet['packet_key'] ?? '')) . '</p>';
            echo '<p><strong>Missing:</strong> ' . esc_html(!empty($missing) ? implode(', ', array_map('strval', $missing)) : 'None') . '</p>';
        }

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
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_WORKFLOWS)) {
            wp_die('Unauthorized');
        }

        $submission_id = isset($_POST['submission_id']) ? (int) $_POST['submission_id'] : 0;
        check_admin_referer('dcb_workflow_transition_' . $submission_id, 'dcb_workflow_nonce');

        $to_status = sanitize_key((string) ($_POST['to_status'] ?? ''));
        $assignee_user_id = isset($_POST['assignee_user_id']) ? (int) $_POST['assignee_user_id'] : 0;
        $assignee_role = sanitize_key((string) ($_POST['assignee_role'] ?? ''));
        $assignee_queue = sanitize_key((string) ($_POST['assignee_queue'] ?? ''));
        $note = sanitize_textarea_field((string) ($_POST['note'] ?? ''));

        if ($assignee_user_id > 0) {
            self::assign_target($submission_id, array('type' => 'user', 'user_id' => $assignee_user_id), 'manual_transition');
        } elseif ($assignee_role !== '') {
            self::assign_target($submission_id, array('type' => 'role', 'role' => $assignee_role), 'manual_transition');
        } elseif ($assignee_queue !== '') {
            self::assign_target($submission_id, array('type' => 'queue', 'queue' => $assignee_queue), 'manual_transition');
        }

        if ($to_status !== '') {
            if ($to_status === 'needs_correction') {
                self::request_correction($submission_id, $note !== '' ? $note : 'Correction requested.');
            } else {
                self::set_status($submission_id, $to_status, $note);
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

    private static function append_note(int $submission_id, string $type, string $message): void {
        $rows = get_post_meta($submission_id, self::META_NOTES, true);
        if (!is_array($rows)) {
            $rows = array();
        }

        $user = wp_get_current_user();
        $rows[] = array(
            'time' => current_time('mysql'),
            'type' => sanitize_key($type),
            'message' => sanitize_textarea_field($message),
            'actor_user_id' => $user instanceof WP_User ? (int) $user->ID : 0,
            'actor_name' => $user instanceof WP_User ? (string) $user->display_name : '',
        );

        if (count($rows) > 200) {
            $rows = array_slice($rows, -200);
        }

        update_post_meta($submission_id, self::META_NOTES, $rows);
    }

    private static function transition_event(string $from, string $to): string {
        $map = array(
            'draft:submitted' => 'submitted',
            'submitted:in_review' => 'reviewed',
            'in_review:needs_correction' => 'correction_requested',
            'needs_correction:submitted' => 'corrected_resubmitted',
            'in_review:approved' => 'approved',
            'in_review:rejected' => 'rejected',
            'approved:finalized' => 'finalized',
        );

        $key = sanitize_key($from) . ':' . sanitize_key($to);
        return $map[$key] ?? 'status_changed';
    }

    private static function normalize_assignment_target(array $target): array {
        $type = sanitize_key((string) ($target['type'] ?? 'unassigned'));
        if (!in_array($type, array('user', 'role', 'queue', 'unassigned'), true)) {
            $type = 'unassigned';
        }

        if ($type === 'user') {
            return array('type' => 'user', 'user_id' => max(0, (int) ($target['user_id'] ?? 0)));
        }
        if ($type === 'role') {
            return array('type' => 'role', 'role' => sanitize_key((string) ($target['role'] ?? '')));
        }
        if ($type === 'queue') {
            return array('type' => 'queue', 'queue' => sanitize_key((string) ($target['queue'] ?? '')));
        }

        return array('type' => 'unassigned');
    }

    private static function assignments_equal(array $a, array $b): bool {
        return wp_json_encode(self::normalize_assignment_target($a)) === wp_json_encode(self::normalize_assignment_target($b));
    }

    private static function build_submission_context(int $submission_id): array {
        $form_key = sanitize_key((string) get_post_meta($submission_id, '_dcb_form_key', true));
        $status = self::get_status($submission_id);
        $document_type = sanitize_key((string) get_post_meta($submission_id, '_dcb_document_type', true));
        $template_id = sanitize_key((string) get_post_meta($submission_id, '_dcb_template_id', true));

        $raw = (string) get_post_meta($submission_id, '_dcb_form_data', true);
        $fields = json_decode($raw, true);
        if (!is_array($fields)) {
            $fields = array();
        }

        return array(
            'submission_id' => $submission_id,
            'form_key' => $form_key,
            'status' => $status,
            'document_type' => $document_type,
            'template_id' => $template_id,
            'packet_key' => sanitize_key((string) get_post_meta($submission_id, self::META_QUEUE_KEY, true)),
            'fields' => $fields,
        );
    }

    private static function get_routing_rules(): array {
        return self::normalize_routing_rules(get_option('dcb_workflow_routing_rules', array()));
    }

    private static function normalize_routing_rules($rules): array {
        if (!is_array($rules)) {
            return array();
        }

        $out = array();
        foreach ($rules as $idx => $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $id = sanitize_key((string) ($rule['id'] ?? ('rule_' . ($idx + 1))));
            $name = sanitize_text_field((string) ($rule['name'] ?? ('Rule ' . ($idx + 1))));
            $enabled = !array_key_exists('enabled', $rule) || !empty($rule['enabled']);
            if (!$enabled) {
                continue;
            }

            $legacy_when = isset($rule['when']) && is_array($rule['when']) ? $rule['when'] : array();
            $match = isset($rule['match']) && is_array($rule['match']) ? $rule['match'] : array();
            $conditions = isset($match['conditions']) && is_array($match['conditions']) ? $match['conditions'] : $legacy_when;

            $clean_conditions = array();
            foreach ($conditions as $condition) {
                if (!is_array($condition)) {
                    continue;
                }
                if (function_exists('dcb_normalize_condition')) {
                    $normalized = dcb_normalize_condition($condition);
                    if ($normalized !== null) {
                        $clean_conditions[] = $normalized;
                    }
                }
            }

            $legacy_role = sanitize_key((string) ($rule['assignee_role'] ?? ''));
            $legacy_queue = sanitize_key((string) ($rule['queue'] ?? ''));
            $assignment = isset($rule['assignment']) && is_array($rule['assignment']) ? $rule['assignment'] : array();
            if (empty($assignment) && $legacy_role !== '') {
                $assignment = array('type' => 'role', 'role' => $legacy_role);
            }
            if (empty($assignment) && $legacy_queue !== '') {
                $assignment = array('type' => 'queue', 'queue' => $legacy_queue);
            }

            $priority = isset($rule['priority']) && is_numeric($rule['priority']) ? (int) $rule['priority'] : 100;
            $clean_rule = array(
                'id' => $id,
                'name' => $name,
                'priority' => $priority,
                'match' => array(
                    'form_keys' => self::clean_key_list($match['form_keys'] ?? $rule['form_keys'] ?? array()),
                    'template_ids' => self::clean_key_list($match['template_ids'] ?? array()),
                    'from_statuses' => self::clean_key_list($match['from_statuses'] ?? $rule['from_statuses'] ?? array()),
                    'document_types' => self::clean_key_list($match['document_types'] ?? array()),
                    'packet_keys' => self::clean_key_list($match['packet_keys'] ?? array()),
                    'conditions' => $clean_conditions,
                ),
                'assignment' => self::normalize_assignment_target($assignment),
                'reviewer_pool' => self::normalize_pool((array) ($rule['reviewer_pool'] ?? array())),
                'approver_pool' => self::normalize_pool((array) ($rule['approver_pool'] ?? array())),
                'packet_key' => sanitize_key((string) ($rule['packet_key'] ?? '')),
                'set_status' => sanitize_key((string) ($rule['set_status'] ?? 'in_review')),
            );

            $out[] = $clean_rule;
        }

        usort($out, static function ($a, $b) {
            return ((int) ($a['priority'] ?? 100)) <=> ((int) ($b['priority'] ?? 100));
        });

        return array_values($out);
    }

    private static function rule_matches(array $rule, array $context): bool {
        $match = isset($rule['match']) && is_array($rule['match']) ? $rule['match'] : array();

        $form_keys = self::clean_key_list($match['form_keys'] ?? array());
        if (!empty($form_keys) && !in_array(sanitize_key((string) ($context['form_key'] ?? '')), $form_keys, true)) {
            return false;
        }

        $template_ids = self::clean_key_list($match['template_ids'] ?? array());
        if (!empty($template_ids) && !in_array(sanitize_key((string) ($context['template_id'] ?? '')), $template_ids, true)) {
            return false;
        }

        $statuses = self::clean_key_list($match['from_statuses'] ?? array());
        if (!empty($statuses) && !in_array(sanitize_key((string) ($context['status'] ?? '')), $statuses, true)) {
            return false;
        }

        $document_types = self::clean_key_list($match['document_types'] ?? array());
        if (!empty($document_types) && !in_array(sanitize_key((string) ($context['document_type'] ?? '')), $document_types, true)) {
            return false;
        }

        $packet_keys = self::clean_key_list($match['packet_keys'] ?? array());
        if (!empty($packet_keys) && !in_array(sanitize_key((string) ($context['packet_key'] ?? '')), $packet_keys, true)) {
            return false;
        }

        $conditions = isset($match['conditions']) && is_array($match['conditions']) ? $match['conditions'] : array();
        $values = isset($context['fields']) && is_array($context['fields']) ? $context['fields'] : array();
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            if (!self::condition_matches($condition, $values)) {
                return false;
            }
        }

        return true;
    }

    private static function condition_matches(array $condition, array $values): bool {
        if (function_exists('dcb_condition_matches')) {
            return (bool) dcb_condition_matches($condition, $values);
        }

        $field = sanitize_key((string) ($condition['field'] ?? ''));
        $operator = sanitize_key((string) ($condition['operator'] ?? 'eq'));
        $left = isset($values[$field]) ? (string) $values[$field] : '';
        $value = isset($condition['value']) ? (string) $condition['value'] : '';

        if ($operator === 'filled') {
            return trim($left) !== '';
        }
        if ($operator === 'not_filled') {
            return trim($left) === '';
        }

        return $left === $value;
    }

    private static function normalize_pool(array $pool): array {
        $users = array_values(array_unique(array_filter(array_map('intval', (array) ($pool['users'] ?? array())), static function ($id) {
            return $id > 0;
        })));
        $roles = self::clean_key_list($pool['roles'] ?? array());
        $queues = self::clean_key_list($pool['queues'] ?? array());
        return array('users' => $users, 'roles' => $roles, 'queues' => $queues);
    }

    private static function clean_key_list($values): array {
        if (!is_array($values)) {
            return array();
        }
        return array_values(array_unique(array_filter(array_map('sanitize_key', $values))));
    }

    private static function get_queue_groups(): array {
        return self::normalize_queue_groups(get_option('dcb_workflow_queue_groups', array()));
    }

    private static function normalize_queue_groups($groups): array {
        if (!is_array($groups)) {
            return array();
        }

        $out = array();
        foreach ($groups as $idx => $group) {
            if (!is_array($group)) {
                continue;
            }
            $key = sanitize_key((string) ($group['key'] ?? ('queue_' . ($idx + 1))));
            $label = sanitize_text_field((string) ($group['label'] ?? $key));
            $roles = self::clean_key_list($group['roles'] ?? array());
            $statuses = self::clean_key_list($group['statuses'] ?? array());
            if ($key === '') {
                continue;
            }
            $out[] = array(
                'key' => $key,
                'label' => $label,
                'roles' => $roles,
                'statuses' => $statuses,
            );
        }

        return $out;
    }

    private static function get_packet_definitions(): array {
        return self::normalize_packet_definitions(get_option('dcb_workflow_packet_definitions', array()));
    }

    private static function normalize_packet_definitions($definitions): array {
        if (!is_array($definitions)) {
            return array();
        }

        $out = array();
        foreach ($definitions as $key => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $packet_key = sanitize_key((string) (is_string($key) ? $key : ($definition['key'] ?? '')));
            if ($packet_key === '') {
                continue;
            }

            $out[$packet_key] = array(
                'key' => $packet_key,
                'label' => sanitize_text_field((string) ($definition['label'] ?? $packet_key)),
                'required_document_types' => self::clean_key_list($definition['required_document_types'] ?? array()),
            );
        }

        return $out;
    }

    private static function queue_views(): array {
        $base = array(
            'my_assigned' => __('My Assigned Items', 'document-center-builder'),
            'awaiting_review' => __('Awaiting Review', 'document-center-builder'),
            'needs_correction' => __('Needs Correction', 'document-center-builder'),
            'finalized' => __('Finalized / Completed', 'document-center-builder'),
        );

        $groups = self::get_queue_groups();
        foreach ($groups as $group) {
            $key = sanitize_key((string) ($group['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $base['group_' . $key] = sanitize_text_field((string) ($group['label'] ?? $key));
        }

        return $base;
    }

    private static function query_queue_items(string $view): array {
        if (!class_exists('WP_Query')) {
            return array();
        }

        $status_filter = array();
        $require_assignment_to_current = false;
        $meta_query = array();

        if ($view === 'my_assigned') {
            $status_filter = array('submitted', 'in_review', 'needs_correction');
            $require_assignment_to_current = true;
            $meta_query[] = array(
                'key' => self::META_ASSIGNEE_USER_ID,
                'value' => (string) get_current_user_id(),
                'compare' => '=',
            );
        } elseif ($view === 'awaiting_review') {
            $status_filter = array('submitted', 'in_review');
        } elseif ($view === 'needs_correction') {
            $status_filter = array('needs_correction');
        } elseif ($view === 'finalized') {
            $status_filter = array('finalized');
        } elseif (strpos($view, 'group_') === 0) {
            $group_key = sanitize_key(substr($view, 6));
            $group = self::find_queue_group($group_key);
            if (!empty($group)) {
                $status_filter = self::clean_key_list($group['statuses'] ?? array());
                if (!empty($group_key)) {
                    $meta_query[] = array(
                        'key' => self::META_QUEUE_KEY,
                        'value' => $group_key,
                        'compare' => '=',
                    );
                }
            }
        }

        $query_args = array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'orderby' => 'modified',
            'order' => 'DESC',
        );

        if (!empty($meta_query)) {
            $query_args['meta_query'] = $meta_query;
        }

        $query = new WP_Query($query_args);
        $posts = is_array($query->posts ?? null) ? $query->posts : array();

        $posts = array_values(array_filter($posts, static function ($post) use ($status_filter) {
            if (!$post instanceof WP_Post) {
                return false;
            }
            if (empty($status_filter)) {
                return true;
            }
            $status = self::get_status((int) $post->ID);
            return in_array($status, $status_filter, true);
        }));

        if ($require_assignment_to_current) {
            $current_user_id = (int) get_current_user_id();
            $posts = array_values(array_filter($posts, static function ($post) use ($current_user_id) {
                $assignment = self::get_assignment((int) $post->ID);
                return (int) ($assignment['user_id'] ?? 0) === $current_user_id;
            }));
        }

        return $posts;
    }

    private static function find_queue_group(string $key): array {
        $groups = self::get_queue_groups();
        foreach ($groups as $group) {
            if (sanitize_key((string) ($group['key'] ?? '')) === $key) {
                return $group;
            }
        }
        return array();
    }

    private static function assignment_label(array $assignment): string {
        $type = sanitize_key((string) ($assignment['type'] ?? 'unassigned'));
        if ($type === 'user') {
            $user_id = (int) ($assignment['user_id'] ?? 0);
            if ($user_id < 1) {
                return 'Unassigned';
            }
            $user = get_user_by('id', $user_id);
            return $user instanceof WP_User ? (string) $user->display_name : ('User #' . $user_id);
        }
        if ($type === 'role') {
            return 'Role: ' . (string) ($assignment['role'] ?? '');
        }
        if ($type === 'queue') {
            return 'Queue: ' . (string) ($assignment['queue'] ?? '');
        }
        return 'Unassigned';
    }

    private static function trigger_notification(string $event, int $submission_id, array $context = array()): void {
        $event = sanitize_key($event);
        $payload = array(
            'event' => $event,
            'submission_id' => $submission_id,
            'context' => $context,
            'status' => self::get_status($submission_id),
        );

        if (function_exists('apply_filters')) {
            $payload = (array) apply_filters('dcb_workflow_notification_payload', $payload, $submission_id, $event, $context);
        }

        if (function_exists('do_action')) {
            do_action('dcb_workflow_trigger_notification', $submission_id, $event, $payload);
            do_action('dcb_workflow_trigger_notification_' . $event, $submission_id, $payload);
        }
    }
}
