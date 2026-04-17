<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['mock_current_user_caps'] = array();
$GLOBALS['mock_died'] = false;

class DCB_Test_Stop extends Exception {}

if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID = 11;
        public string $display_name = 'Routing User';
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
if (!function_exists('register_post_type')) {
    function register_post_type() {}
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
if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return !empty($GLOBALS['mock_current_user_caps'][(string) $cap]); }
}
if (!function_exists('wp_die')) {
    function wp_die($message = 'wp_die') { $GLOBALS['mock_died'] = true; throw new DCB_Test_Stop((string) $message); }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce() { return true; }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect() { return true; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://example.local/wp-admin/' . ltrim((string) $path, '/'); }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) {
        return $url . '?' . http_build_query(is_array($args) ? $args : array());
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta() { return ''; }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta() { return true; }
}
if (!function_exists('get_posts')) {
    function get_posts() { return array(); }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new WP_User(); }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-17 00:00:00'; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('do_action')) {
    function do_action() {}
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}

require_once dirname(__DIR__) . '/includes/class-permissions.php';
require_once dirname(__DIR__) . '/includes/helpers-chart-routing.php';
require_once dirname(__DIR__) . '/includes/class-chart-routing-connectors.php';
require_once dirname(__DIR__) . '/includes/class-chart-routing.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$manual = new DCB_Chart_Routing_Manual_Connector();
$manual_attach = $manual->attach_document_to_chart(array('chart_target_id' => 'x'), array('upload_log_id' => 10));
assert_true(empty($manual_attach['ok']), 'manual connector must be no-op attach');

$_POST = array(
    'queue_id' => 123,
    'task' => 'confirm_match',
    'dcb_chart_routing_nonce' => 'ok',
);

$threw = false;
try {
    DCB_Chart_Routing::handle_queue_action();
} catch (DCB_Test_Stop $e) {
    $threw = true;
}
assert_true($threw, 'queue action should enforce capability checks');
assert_true(!empty($GLOBALS['mock_died']), 'queue action should call wp_die when unauthorized');


echo "chart_routing_actions_smoke:ok\n";
