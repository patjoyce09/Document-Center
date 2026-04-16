<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($text) { return trim((string) $text); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return trim((string) $url); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12) { return substr(str_repeat('a', $len), 0, $len); }
}
if (!function_exists('is_email')) {
    function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
}

require_once dirname(__DIR__) . '/includes/helpers-schema.php';

$cond = dcb_normalize_condition(array('field' => 'age', 'operator' => 'gte', 'value' => '18'));
if (!is_array($cond) || $cond['field'] !== 'age' || $cond['operator'] !== 'gte') {
    fwrite(STDERR, "Condition normalization failed\n");
    exit(1);
}

$form = dcb_normalize_single_form(array(
    'label' => 'Intake Form',
    'fields' => array(
        array('key' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true),
    ),
    'sections' => array(array('key' => 'general', 'label' => 'General', 'field_keys' => array('first_name'))),
    'steps' => array(array('key' => 'step_1', 'label' => 'Step 1', 'section_keys' => array('general'))),
));

if (!is_array($form) || empty($form['fields']) || empty($form['sections']) || empty($form['steps'])) {
    fwrite(STDERR, "Form schema normalization failed\n");
    exit(1);
}

echo "schema_smoke:ok\n";
