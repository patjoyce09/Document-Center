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

$retry_state = dcb_chart_routing_resolve_result_state(false, 'failed', 1, 3);
assert_true($retry_state === 'retry_pending', 'failed attempt under max retries should become retry_pending');

$failed_state = dcb_chart_routing_resolve_result_state(false, 'failed', 3, 3);
assert_true($failed_state === 'failed', 'failed attempt at max retries should remain failed');

$attached_state = dcb_chart_routing_resolve_result_state(true, 'failed', 1, 3);
assert_true($attached_state === 'attached', 'successful attempt should resolve to attached');

$payload = dcb_chart_routing_route_result_payload_shape(array(
    'state' => 'retry_pending',
    'attempted' => true,
    'confirmed' => true,
    'attached' => false,
    'retry_count' => 2,
    'retryable' => true,
    'failure_reason' => 'network_timeout',
    'message' => 'Temporary connector timeout.',
    'attempted_at' => '2026-04-17 10:00:00',
    'retry_pending_at' => '2026-04-17 10:00:00',
));

assert_true((string) ($payload['state'] ?? '') === 'retry_pending', 'payload should preserve retry_pending state');
assert_true((int) ($payload['retry_count'] ?? 0) === 2, 'payload should preserve retry count');
assert_true((string) ($payload['failure_reason'] ?? '') === 'network_timeout', 'payload should preserve failure reason');


echo "chart_routing_retry_state_smoke:ok\n";
