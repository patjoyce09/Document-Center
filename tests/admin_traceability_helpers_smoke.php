<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-16 00:00:00'; }
}

require_once dirname(__DIR__) . '/includes/helpers-intake.php';

$summary = dcb_intake_build_traceability_summary(array(
    'trace_id' => 'dcb-intake-demo',
    'source_channel' => 'email_import',
    'capture_type' => 'email_attachment',
    'upload_log_id' => 12,
    'review_item_id' => 15,
    'submission_id' => 20,
    'workflow_status' => 'approved',
    'review_status' => 'approved',
    'current_state' => 'approved',
    'routing_status' => 'queued',
    'final_output_status' => 'generated',
));

if ((string) ($summary['source_channel'] ?? '') !== 'email_import') {
    fwrite(STDERR, "source channel mismatch\n");
    exit(1);
}
if ((string) ($summary['current_state_label'] ?? '') !== 'Approved') {
    fwrite(STDERR, "state label mismatch\n");
    exit(1);
}

echo "admin_traceability_helpers_smoke:ok\n";
