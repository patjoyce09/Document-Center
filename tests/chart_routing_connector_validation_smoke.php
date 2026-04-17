<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-17 00:00:00'; }
}

require_once dirname(__DIR__) . '/includes/class-chart-routing-connectors.php';
require_once dirname(__DIR__) . '/providers/real-connector-skeleton/class-real-connector-skeleton.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$connector = new DCB_Real_Connector_Skeleton(array());

$invalid = $connector->validate_connector_config(array(
    'provider_key' => 'real_connector_skeleton',
    'api_base_url' => 'http://insecure.local',
    'integration_key' => '',
    'api_token' => '',
));
assert_true(empty($invalid['ok']), 'invalid config should fail validation');
assert_true(!empty($invalid['errors']), 'invalid config should include validation errors');

$valid = $connector->validate_connector_config(array(
    'provider_key' => 'real_connector_skeleton',
    'api_base_url' => 'https://api.example.test',
    'integration_key' => 'site-a',
    'api_token' => 'secure-token-123',
));
assert_true(!empty($valid['ok']), 'valid config should pass validation');

$attach = $connector->attach_document_to_chart(
    array('chart_target_id' => 'p-1'),
    array('upload_log_id' => 10),
    array('queue_id' => 44)
);
assert_true(empty($attach['ok']), 'skeleton attach should default to non-success until external handler exists');
assert_true((string) ($attach['state'] ?? '') === 'retry_pending', 'skeleton attach should default to retry_pending');


echo "chart_routing_connector_validation_smoke:ok\n";
