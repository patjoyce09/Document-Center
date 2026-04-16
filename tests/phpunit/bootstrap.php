<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['dcb_options'] = array();
$GLOBALS['dcb_post_meta'] = array();

if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID;
        public string $display_name;
        public string $user_email;

        public function __construct(int $id = 1, string $display_name = 'Tester', string $user_email = 'tester@example.com') {
            $this->ID = $id;
            $this->display_name = $display_name;
            $this->user_email = $user_email;
        }
    }
}

if (!function_exists('__')) {
    function __($text) { return $text; }
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
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return trim((string) $url); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12) { return substr(str_repeat('a', max(1, $len)), 0, max(1, $len)); }
}
if (!function_exists('is_email')) {
    function is_email($email) { return filter_var((string) $email, FILTER_VALIDATE_EMAIL) !== false; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['dcb_options']) ? $GLOBALS['dcb_options'][$key] : $default; }
}
if (!function_exists('add_option')) {
    function add_option($key, $value) { if (!array_key_exists($key, $GLOBALS['dcb_options'])) { $GLOBALS['dcb_options'][$key] = $value; } }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { $GLOBALS['dcb_options'][$key] = $value; }
}
if (!function_exists('current_time')) {
    function current_time($type = 'mysql') { return '2026-04-15 00:00:00'; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new WP_User(); }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = true) {
        $post_id = (int) $post_id;
        if (!isset($GLOBALS['dcb_post_meta'][$post_id]) || !array_key_exists($key, $GLOBALS['dcb_post_meta'][$post_id])) {
            return $single ? '' : array();
        }
        return $GLOBALS['dcb_post_meta'][$post_id][$key];
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        $post_id = (int) $post_id;
        if (!isset($GLOBALS['dcb_post_meta'][$post_id])) {
            $GLOBALS['dcb_post_meta'][$post_id] = array();
        }
        $GLOBALS['dcb_post_meta'][$post_id][$key] = $value;
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function get_error_message(): string { return 'request failed'; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($v) { return $v instanceof WP_Error; }
}
if (!function_exists('dcb_upload_extract_text_from_file_local')) {
    function dcb_upload_extract_text_from_file_local($path, $mime) { return array('text' => 'local text', 'pages' => array()); }
}
if (!function_exists('dcb_ocr_collect_environment_diagnostics')) {
    function dcb_ocr_collect_environment_diagnostics() { return array('status' => 'ready', 'warnings' => array()); }
}
if (!function_exists('dcb_upload_normalize_text')) {
    function dcb_upload_normalize_text($text) { return trim(strtolower((string) $text)); }
}
if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array()) {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'contract_version' => 'dcb-ocr-v1',
                'request_id' => 'test-request',
                'provider' => array('name' => 'test-remote', 'version' => '1.0'),
                'result' => array('engine' => 'remote-api', 'text' => 'remote text', 'pages' => array(), 'warnings' => array(), 'failure_reason' => ''),
            )),
        );
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
}
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
}

require_once dirname(__DIR__, 2) . '/includes/helpers-schema.php';
require_once dirname(__DIR__, 2) . '/includes/class-workflow.php';
require_once dirname(__DIR__, 2) . '/includes/class-migrations.php';
