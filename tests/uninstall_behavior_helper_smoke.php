<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['mock_options'] = array('dcb_uninstall_remove_data' => '0');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) {
        return $GLOBALS['mock_options'][(string) $key] ?? $default;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}

require_once dirname(__DIR__) . '/includes/helpers-ops.php';

if (dcb_ops_uninstall_should_purge() !== false) {
    fwrite(STDERR, "conservative default failed\n");
    exit(1);
}

$GLOBALS['mock_options']['dcb_uninstall_remove_data'] = '1';
if (dcb_ops_uninstall_should_purge() !== true) {
    fwrite(STDERR, "purge opt-in failed\n");
    exit(1);
}

echo "uninstall_behavior_helper_smoke:ok\n";
