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

$trace = dcb_intake_build_traceability_summary(array(
    'trace_id' => dcb_intake_generate_trace_id(42, 84),
    'source_channel' => 'phone_photo',
    'capture_type' => 'photo_image',
    'upload_log_id' => 42,
    'review_item_id' => 84,
    'submission_id' => 108,
    'workflow_status' => 'in_review',
    'review_status' => 'pending_review',
    'current_state' => 'ocr_review_pending',
));

if ((int) ($trace['upload_log_id'] ?? 0) !== 42 || (int) ($trace['review_item_id'] ?? 0) !== 84 || (int) ($trace['submission_id'] ?? 0) !== 108) {
    fwrite(STDERR, "linkage ids mismatch\n");
    exit(1);
}
if ((string) ($trace['current_state'] ?? '') !== 'ocr_review_pending') {
    fwrite(STDERR, "state mismatch\n");
    exit(1);
}

echo "intake_linkage_traceability_smoke:ok\n";
