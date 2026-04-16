<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-15 00:00:00'; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($v) { return rtrim((string) $v, '/') . '/'; }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return (string) $url; }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post() { return new WP_Error(); }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function get_error_message() { return 'request failed'; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($v) { return $v instanceof WP_Error; }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code() { return 500; }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body() { return '{}'; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('dcb_upload_normalize_text')) {
    function dcb_upload_normalize_text($text) { return trim(strtolower((string) $text)); }
}
if (!function_exists('dcb_upload_extract_text_from_file_local')) {
    function dcb_upload_extract_text_from_file_local($path, $mime) { return array('text' => '', 'pages' => array(), 'warnings' => array()); }
}
if (!function_exists('dcb_ocr_collect_environment_diagnostics')) {
    function dcb_ocr_collect_environment_diagnostics($include_provider_diagnostics = true) { return array('status' => 'missing', 'warnings' => array('missing')); }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = '') {
        $map = array(
            'dcb_ocr_mode' => 'remote',
            'dcb_ocr_api_base_url' => 'https://ocr.example.com',
            'dcb_ocr_api_key' => 'abc123',
            'dcb_ocr_timeout_seconds' => 10,
            'dcb_ocr_max_file_size_mb' => 15,
        );
        return $map[$key] ?? $default;
    }
}

require_once dirname(__DIR__) . '/includes/class-ocr-engine.php';

$manager = DCB_OCR_Engine_Manager::active_engine();
if (!($manager instanceof DCB_OCR_Engine)) {
    fwrite(STDERR, "ocr manager failed\n");
    exit(1);
}

echo "ocr_engine_smoke:ok\n";
