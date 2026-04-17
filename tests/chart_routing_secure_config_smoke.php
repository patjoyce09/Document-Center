<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['options_store'] = array();

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-17 00:00:00'; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
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

require_once dirname(__DIR__) . '/includes/helpers-chart-routing.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$secret = 'token-ABCD-123456';
$sealed = dcb_chart_routing_seal_secret($secret);
$unsealed = dcb_chart_routing_unseal_secret($sealed);
assert_true($unsealed === $secret, 'sealed secret should round-trip');

$masked = dcb_chart_routing_mask_secret($secret);
assert_true($masked !== $secret, 'masked secret should not equal raw secret');
assert_true(substr($masked, -4) === '3456', 'masked secret should preserve last 4 chars');

$raw_config = array(
    'provider_key' => 'real_connector_skeleton',
    'api_base_url' => 'https://example.test/api',
    'api_token' => 'should_not_persist',
    'client_secret' => 'should_not_persist',
    'integration_key' => 'clinic-1',
);

$public = dcb_chart_routing_sanitize_public_connector_config($raw_config);
assert_true(!isset($public['api_token']), 'public config should strip api_token');
assert_true(!isset($public['client_secret']), 'public config should strip client_secret');
assert_true((string) ($public['provider_key'] ?? '') === 'real_connector_skeleton', 'public config should keep provider key');

update_option('dcb_chart_routing_connector_config', $public);
update_option('dcb_chart_routing_connector_secret', $sealed);

$resolved = dcb_chart_routing_resolved_connector_config();
assert_true((string) ($resolved['api_token'] ?? '') === $secret, 'resolved config should expose runtime token only');

echo "chart_routing_secure_config_smoke:ok\n";
