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

require_once dirname(__DIR__) . '/includes/helpers-health.php';

$snapshot = array(
    'readiness_summary' => array('ok' => 3, 'warn' => 1, 'fail' => 0),
    'unresolved_ocr_risk_count' => 2,
    'ops_last_action' => array('action' => 'forms_export', 'status' => 'ok'),
);

$digest = dcb_health_weekly_digest_payload($snapshot, array('enabled' => true));
if (empty($digest['enabled'])) {
    fwrite(STDERR, "digest should be enabled by context\n");
    exit(1);
}
if ((int) (($digest['summary']['unresolved_ocr_risk_count'] ?? 0)) !== 2) {
    fwrite(STDERR, "digest unresolved count mismatch\n");
    exit(1);
}

echo "weekly_digest_payload_smoke:ok\n";
