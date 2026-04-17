<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['dcb_test_options'] = array();
$GLOBALS['dcb_test_post_meta'] = array();
$GLOBALS['dcb_test_user_meta'] = array();
$GLOBALS['dcb_test_filters'] = array();
$GLOBALS['dcb_test_actions'] = array();

if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback) {
        if (!isset($GLOBALS['dcb_test_filters'][(string) $hook])) {
            $GLOBALS['dcb_test_filters'][(string) $hook] = array();
        }
        $GLOBALS['dcb_test_filters'][(string) $hook][] = $callback;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        $args = func_get_args();
        array_shift($args);
        $current = array_shift($args);
        $callbacks = $GLOBALS['dcb_test_filters'][(string) $hook] ?? array();
        foreach ($callbacks as $cb) {
            $current = call_user_func_array($cb, array_merge(array($current), $args));
        }
        return $current;
    }
}
if (!function_exists('do_action')) {
    function do_action($hook) {
        $args = func_get_args();
        $GLOBALS['dcb_test_actions'][] = $args;
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-16 00:00:00'; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) {
        return array_key_exists((string) $key, $GLOBALS['dcb_test_options']) ? $GLOBALS['dcb_test_options'][(string) $key] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) {
        $GLOBALS['dcb_test_options'][(string) $key] = $value;
        return true;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        $value = $GLOBALS['dcb_test_post_meta'][(int) $post_id][(string) $key] ?? null;
        if ($single) {
            return $value;
        }
        return $value === null ? array() : array($value);
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        $GLOBALS['dcb_test_post_meta'][(int) $post_id][(string) $key] = $value;
        return true;
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = false) {
        $value = $GLOBALS['dcb_test_user_meta'][(int) $user_id][(string) $key] ?? null;
        if ($single) {
            return $value;
        }
        return $value === null ? array() : array($value);
    }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $key, $value) {
        $GLOBALS['dcb_test_user_meta'][(int) $user_id][(string) $key] = $value;
        return true;
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return (string) $text; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return (string) $text; }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($text) { return (string) $text; }
}
if (!function_exists('checked')) {
    function checked($checked, $current = true, $echo = true) {
        $result = ((bool) $checked === (bool) $current) ? 'checked="checked"' : '';
        if ($echo) {
            echo $result;
        }
        return $result;
    }
}
if (!function_exists('dcb_form_definitions')) {
    function dcb_form_definitions($for_js = false) {
        return array(
            'intake_form' => array('label' => 'Intake'),
            'followup_form' => array('label' => 'Follow Up'),
        );
    }
}

require_once dirname(__DIR__) . '/includes/class-integration-tutor.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$GLOBALS['dcb_test_options']['dcb_tutor_integration_enabled'] = '0';
$allowed_when_disabled = DCB_Integration_Tutor::gate_access(true, 'intake_form', 10, array('source' => 'test'));
assert_true($allowed_when_disabled === true, 'integration disabled should not alter access');

$GLOBALS['dcb_test_options']['dcb_tutor_integration_enabled'] = '1';
$GLOBALS['dcb_test_options']['dcb_tutor_mapping'] = array(
    'intake_form' => array(
        'course_id' => 123,
        'lesson_id' => 44,
        'quiz_id' => 9,
        'require_course_completion' => true,
        'assign_training_after_completion' => true,
        'record_relationship_metadata' => true,
    ),
);

$normalized = DCB_Integration_Tutor::get_mapping_for_form('intake_form');
assert_true((int) ($normalized['course_id'] ?? 0) === 123, 'mapping normalization should keep course id');
assert_true(!empty($normalized['assign_training_after_completion']), 'mapping normalization should keep assignment toggle');

$blocked = DCB_Integration_Tutor::gate_access(true, 'intake_form', 20, array('source' => 'submit_ajax'));
assert_true($blocked === false, 'without Tutor installed, required completion should block');
$gate = DCB_Integration_Tutor::get_last_access_gate_result(20);
assert_true((string) ($gate['blocked_requirement'] ?? '') === 'course_completion', 'gate diagnostics should record blocked requirement');

add_filter('dcb_tutor_training_assignment_adapter', static function ($result, $payload, $mapping) {
    return array(
        'attempted' => true,
        'success' => true,
        'message' => 'adapter enrolled user',
        'method' => 'test_adapter',
    );
});

$submission_id = 7001;
DCB_Integration_Tutor::record_completion_relation($submission_id, 'intake_form', 20);
$relation = get_post_meta($submission_id, '_dcb_tutor_relation', true);
assert_true(is_array($relation) && (int) ($relation['course_id'] ?? 0) === 123, 'relation recording should persist mapped ids');
assert_true((string) ($relation['mapping_key'] ?? '') === 'intake_form', 'relation recording should include mapping key');

$assignment = get_post_meta($submission_id, '_dcb_tutor_training_assignment', true);
assert_true(is_array($assignment) && !empty($assignment['success']), 'assignment adapter path should be invoked and recorded');

$html = DCB_Integration_Tutor::render_submission_integration_meta($submission_id);
assert_true(strpos($html, 'Tutor Integration') !== false, 'admin visibility html should render tutor section');
assert_true(strpos($html, 'intake_form') !== false, 'admin visibility html should include relation data');

DCB_Integration_Tutor::save_settings_from_post(array(
    'dcb_tutor_rows' => array(
        array(
            'form_key' => 'followup_form',
            'course_id' => '55',
            'lesson_id' => '0',
            'quiz_id' => '0',
            'require_course_completion' => '1',
            'assign_training_after_completion' => '0',
            'record_relationship_metadata' => '1',
        ),
    ),
    'dcb_tutor_mapping_json' => '',
));

$saved_mapping = get_option('dcb_tutor_mapping', array());
assert_true(isset($saved_mapping['followup_form']), 'structured settings save should persist mapping rows');

$action_names = array();
foreach ($GLOBALS['dcb_test_actions'] as $entry) {
    $action_names[] = (string) ($entry[0] ?? '');
}
assert_true(in_array('dcb_tutor_access_gated', $action_names, true), 'access gated action should fire');
assert_true(in_array('dcb_tutor_completion_relation_recorded', $action_names, true), 'completion relation action should fire');
assert_true(in_array('dcb_tutor_training_assignment_completed', $action_names, true), 'assignment completed action should fire');

echo "tutor_integration_smoke:ok\n";
