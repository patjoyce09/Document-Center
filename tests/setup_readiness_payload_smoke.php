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

require_once dirname(__DIR__) . '/includes/helpers-ops.php';

$payload = dcb_ops_setup_readiness_payload(array(
    'ocr_mode' => 'remote',
    'ocr_api_base_url' => '',
    'ocr_api_key' => '',
    'upload_writable' => false,
    'permalink_structure' => '',
    'capabilities_ok' => true,
));

$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : array();
$summary = isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : array();

if (count($items) < 4) {
    fwrite(STDERR, "readiness items missing\n");
    exit(1);
}
if ((int) ($summary['fail'] ?? 0) < 2) {
    fwrite(STDERR, "expected fail statuses\n");
    exit(1);
}

echo "setup_readiness_payload_smoke:ok\n";
