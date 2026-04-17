<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-17 00:00:00'; }
}

require_once dirname(__DIR__) . '/includes/helpers-chart-routing.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$payload = dcb_chart_routing_event_log_payload_shape(array(
    'event' => 'retry_queued',
    'queue_id' => 12,
    'trace_id' => 'trace-12',
    'connector_mode' => 'api',
    'provider_key' => 'real_connector_skeleton',
    'state' => 'retry_pending',
    'failure_reason' => 'network_timeout',
    'retry_count' => 2,
    'message' => 'patient John Doe with MRN 123456 failed at api_token abc123',
    'context' => array(
        'next_retry_at' => '2026-04-17 10:03:00',
        'scheduled' => '1',
    ),
));

assert_true((string) ($payload['event'] ?? '') === 'retry_queued', 'event should be preserved');
assert_true((int) ($payload['queue_id'] ?? 0) === 12, 'queue id should be preserved');
assert_true((string) ($payload['state'] ?? '') === 'retry_pending', 'state should be preserved');
assert_true(strpos((string) ($payload['message'] ?? ''), 'John Doe') === false, 'message should redact person-like names');
assert_true(strpos((string) ($payload['message'] ?? ''), '123456') === false, 'message should redact numeric identifiers');
assert_true(strpos((string) ($payload['message'] ?? ''), 'api_token') === false, 'message should redact secret keys');


echo "chart_routing_event_log_payload_smoke:ok\n";
