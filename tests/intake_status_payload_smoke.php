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

$state1 = dcb_intake_state_from_statuses('needs_correction', 'corrected');
$state2 = dcb_intake_state_from_statuses('finalized', 'approved');
$state3 = dcb_intake_state_from_statuses('', 'pending_review');

if ($state1 !== 'returned_for_correction' || $state2 !== 'finalized' || $state3 !== 'ocr_review_pending') {
    fwrite(STDERR, "intake state mapping mismatch\n");
    exit(1);
}

echo "intake_status_payload_smoke:ok\n";
