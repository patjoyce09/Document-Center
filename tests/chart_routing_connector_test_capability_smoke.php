<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['mock_current_user_caps'] = array();
$GLOBALS['mock_died'] = false;

class DCB_Test_Stop extends Exception {}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
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

require_once dirname(__DIR__) . '/includes/class-permissions.php';
require_once dirname(__DIR__) . '/includes/class-chart-routing.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$_POST = array('dcb_chart_routing_test_nonce' => 'ok');
$threw = false;
try {
    DCB_Chart_Routing::handle_test_connector();
} catch (DCB_Test_Stop $e) {
    $threw = true;
}

assert_true($threw, 'connector test action should enforce capability checks');
assert_true(!empty($GLOBALS['mock_died']), 'connector test action should call wp_die when unauthorized');

echo "chart_routing_connector_test_capability_smoke:ok\n";
