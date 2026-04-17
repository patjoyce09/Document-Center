<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['meta_store'] = array();
$GLOBALS['options_store'] = array();
$GLOBALS['mock_current_user_caps'] = array();
$GLOBALS['mock_redirect_url'] = '';

class DCB_Test_Stop extends Exception {}

if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID = 11;
        public string $display_name = 'Reviewer User';
        public string $user_email = 'reviewer@example.com';
        public array $roles = array('editor');
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_modified = '2026-04-16 00:00:00';
        public string $post_type = 'dcb_form_submission';
    }
}

if (!function_exists('__')) {
    function __($text) { return $text; }
}
if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('add_submenu_page')) {
    function add_submenu_page() {}
}
if (!function_exists('add_meta_box')) {
    function add_meta_box() {}
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce() { return true; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($text) { return trim((string) $text); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-16 00:00:00'; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new WP_User(); }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return !empty($GLOBALS['mock_current_user_caps'][(string) $cap]); }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return 11; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        $value = $GLOBALS['meta_store'][(int) $post_id][(string) $key] ?? null;
        if ($single) {
            return $value;
        }
        return $value === null ? array() : array($value);
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        $GLOBALS['meta_store'][(int) $post_id][(string) $key] = $value;
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) {
        return array_key_exists((string) $key, $GLOBALS['options_store']) ? $GLOBALS['options_store'][(string) $key] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) {
        $GLOBALS['options_store'][(string) $key] = $value;
        return true;
    }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        if ($field === 'id' && (int) $value === 11) {
            return new WP_User();
        }
        return null;
    }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer() { return true; }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($url) { $GLOBALS['mock_redirect_url'] = (string) $url; return true; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://example.local/wp-admin/' . ltrim((string) $path, '/'); }
}
if (!function_exists('wp_die')) {
    function wp_die($message = 'wp_die') { throw new DCB_Test_Stop((string) $message); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('do_action')) {
    function do_action() {}
}

require_once dirname(__DIR__) . '/includes/class-permissions.php';
require_once dirname(__DIR__) . '/includes/class-workflow.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assert_equals($expected, $actual, $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "Assertion failed: {$message}. Expected " . var_export($expected, true) . " got " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$submission_id = 4101;
update_post_meta($submission_id, '_dcb_workflow_status', 'submitted');
update_post_meta($submission_id, '_dcb_form_key', 'intake_form');
update_post_meta($submission_id, '_dcb_document_type', 'id_proof');
update_post_meta($submission_id, '_dcb_form_data', json_encode(array('priority' => 'high', 'region' => 'east')));

$GLOBALS['options_store']['dcb_workflow_routing_rules'] = array(
    array(
        'id' => 'priority-review',
        'name' => 'Priority Intake Review',
        'priority' => 10,
        'match' => array(
            'form_keys' => array('intake_form'),
            'from_statuses' => array('submitted'),
            'conditions' => array(
                array('field' => 'priority', 'operator' => 'eq', 'value' => 'high'),
            ),
        ),
        'assignment' => array('type' => 'role', 'role' => 'editor'),
        'reviewer_pool' => array('roles' => array('editor')),
        'approver_pool' => array('roles' => array('administrator')),
        'packet_key' => 'onboarding',
        'set_status' => 'in_review',
    ),
);

$GLOBALS['options_store']['dcb_workflow_packet_definitions'] = array(
    'onboarding' => array(
        'label' => 'Onboarding Packet',
        'required_document_types' => array('id_proof', 'consent_form'),
    ),
);

assert_true(DCB_Workflow::can_transition('submitted', 'in_review'), 'submitted -> in_review should be valid');
assert_true(!DCB_Workflow::can_transition('submitted', 'finalized'), 'submitted -> finalized should be invalid');

$route = DCB_Workflow::route_submission($submission_id);
assert_true(!empty($route['matched']), 'routing rule should match');
assert_equals('role', (string) ($route['assignment']['type'] ?? ''), 'assignment type should resolve to role');
assert_equals('editor', (string) ($route['assignment']['role'] ?? ''), 'assignment role should resolve');
assert_equals('in_review', DCB_Workflow::get_status($submission_id), 'rule should transition to in_review');

$packet_state = DCB_Workflow::get_packet_state($submission_id);
assert_equals('onboarding', (string) ($packet_state['packet_key'] ?? ''), 'packet key should initialize');
assert_true(in_array('consent_form', (array) ($packet_state['missing_items'] ?? array()), true), 'consent_form should be missing initially');

$packet_state = DCB_Workflow::mark_packet_item($submission_id, 'consent_form', true);
assert_true(empty($packet_state['missing_items']), 'packet should become complete once missing item is received');
assert_true(in_array('consent_form', (array) ($packet_state['approved_items'] ?? array()), true), 'approved packet item should be tracked');

$correction_ok = DCB_Workflow::request_correction($submission_id, 'Please upload clearer photo ID.');
assert_true($correction_ok, 'correction request should be accepted from in_review');
assert_equals('needs_correction', DCB_Workflow::get_status($submission_id), 'status should move to needs_correction');

$resubmitted_ok = DCB_Workflow::set_status($submission_id, 'submitted', 'Applicant resubmitted required file.');
assert_true($resubmitted_ok, 'needs_correction -> submitted should be valid');

$events = DCB_Workflow::get_timeline($submission_id);
$event_names = array_values(array_filter(array_map(static function ($row) {
    return is_array($row) ? (string) ($row['event'] ?? '') : '';
}, $events)));
assert_true(in_array('submitted', $event_names, true) || in_array('reviewed', $event_names, true), 'timeline should include submission/review event');
assert_true(in_array('correction_requested', $event_names, true), 'timeline should include correction_requested');
assert_true(in_array('corrected_resubmitted', $event_names, true), 'timeline should include corrected_resubmitted');
assert_true(in_array('assigned', $event_names, true) || in_array('re_assigned', $event_names, true), 'timeline should include assignment event');

$GLOBALS['mock_current_user_caps'] = array();
$_POST = array(
    'submission_id' => $submission_id,
    'to_status' => 'in_review',
    'dcb_workflow_nonce' => 'ok',
);
$threw = false;
try {
    DCB_Workflow::handle_transition();
} catch (DCB_Test_Stop $e) {
    $threw = true;
}
assert_true($threw, 'workflow transition must enforce capability checks');

echo "workflow_routing_smoke:ok\n";
