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

$cases = array(
    'direct' => 'direct_upload',
    'photo' => 'phone_photo',
    'pdf_scan' => 'scanned_pdf',
    'email' => 'email_import',
    'manual_digital' => 'digital_only',
);

foreach ($cases as $raw => $expected) {
    $actual = dcb_intake_normalize_source_channel($raw);
    if ($actual !== $expected) {
        fwrite(STDERR, "channel normalization mismatch for {$raw}: {$actual}\n");
        exit(1);
    }
}

echo "intake_source_normalization_smoke:ok\n";
