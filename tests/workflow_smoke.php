<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['meta_store'] = array();

if (!function_exists('__')) {
    function __($text) { return $text; }
}
if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID = 1;
        public string $display_name = 'Tester';
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($text) { return trim((string) $text); }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = '') { return $default; }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-15 00:00:00'; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new WP_User(); }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        return $GLOBALS['meta_store'][$post_id][$key] ?? ($single ? '' : array());
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        if (!isset($GLOBALS['meta_store'][$post_id])) {
            $GLOBALS['meta_store'][$post_id] = array();
        }
        $GLOBALS['meta_store'][$post_id][$key] = $value;
    }
}

require_once dirname(__DIR__) . '/includes/class-workflow.php';

$post_id = 123;
update_post_meta($post_id, '_dcb_workflow_status', 'submitted');
$ok = DCB_Workflow::set_status($post_id, 'in_review', 'Initial routing');
if (!$ok) {
    fwrite(STDERR, "workflow transition failed\n");
    exit(1);
}
if (DCB_Workflow::get_status($post_id) !== 'in_review') {
    fwrite(STDERR, "workflow status mismatch\n");
    exit(1);
}

echo "workflow_smoke:ok\n";
