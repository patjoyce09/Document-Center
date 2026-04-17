<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['mock_caps'] = array();
$GLOBALS['mock_last_json'] = array();

class DCB_Test_Stop extends Exception {
    public array $payload;
    public int $statusCode;

    public function __construct(string $message, array $payload = array(), int $statusCode = 200) {
        parent::__construct($message);
        $this->payload = $payload;
        $this->statusCode = $statusCode;
    }
}

if (!class_exists('WP_Role')) {
    class WP_Role {
        public array $caps = array();
        public function add_cap(string $cap): void {
            $this->caps[$cap] = true;
        }
    }
}

if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($text) { return trim((string) $text); }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) { return filter_var((string) $email, FILTER_SANITIZE_EMAIL); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return trim((string) $url); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12) { return substr(str_repeat('a', $len), 0, $len); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return !empty($GLOBALS['mock_caps'][(string) $cap]); }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer() { return true; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = array(), $status = 200) {
        $GLOBALS['mock_last_json'] = array('success' => true, 'data' => $data, 'status' => $status);
        throw new DCB_Test_Stop('json_success', is_array($data) ? $data : array(), (int) $status);
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = array(), $status = 400) {
        $GLOBALS['mock_last_json'] = array('success' => false, 'data' => $data, 'status' => $status);
        throw new DCB_Test_Stop('json_error', is_array($data) ? $data : array(), (int) $status);
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-16 00:00:00'; }
}

require_once dirname(__DIR__) . '/includes/helpers-schema.php';
require_once dirname(__DIR__) . '/includes/class-permissions.php';
require_once dirname(__DIR__) . '/includes/class-builder.php';

function dcb_test_assert(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$GLOBALS['mock_caps'] = array(
    DCB_Permissions::CAP_MANAGE_FORMS => true,
);

$_POST = array(
    'nonce' => 'ok',
    'form' => wp_json_encode(array(
        'fields' => array(
            array('key' => 'dup_key', 'label' => 'One', 'type' => 'text'),
            array('key' => 'dup_key', 'label' => '', 'type' => 'text'),
        ),
        'sections' => array(array('key' => 'general', 'label' => 'General', 'field_keys' => array('missing_field'))),
        'steps' => array(array('key' => 'step_1', 'label' => 'Step 1', 'section_keys' => array('missing_section'))),
        'template_blocks' => array(array('block_id' => 'blk_intro', 'type' => 'heading', 'text' => 'Intro')),
        'document_nodes' => array(array('type' => 'field', 'field_key' => 'missing_field')),
    )),
);

try {
    DCB_Builder::validate_schema_ajax();
    fwrite(STDERR, "Expected JSON response not emitted\n");
    exit(1);
} catch (DCB_Test_Stop $e) {
    dcb_test_assert($e->getMessage() === 'json_success', 'validation endpoint should return success payload');
    $data = $e->payload;
    dcb_test_assert(!empty($data['errors']) && is_array($data['errors']), 'validation endpoint should return errors array');
    dcb_test_assert(isset($data['warnings']) && is_array($data['warnings']), 'validation endpoint should return warnings array');
    dcb_test_assert(isset($data['preview']) && is_array($data['preview']), 'validation endpoint should return preview payload');
}

echo "builder_validation_endpoint_smoke:ok\n";
