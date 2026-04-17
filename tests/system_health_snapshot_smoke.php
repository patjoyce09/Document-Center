<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['mock_caps'] = array('dcb_review_submissions' => true, 'dcb_run_ocr_tools' => true);

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://example.local/wp-admin/' . ltrim((string) $path, '/'); }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return !empty($GLOBALS['mock_caps'][(string) $cap]); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('dcb_ocr_review_queue_summary')) {
    function dcb_ocr_review_queue_summary() {
        return array('status_counts' => array('pending_review' => 3, 'approved' => 7), 'failure_counts' => array());
    }
}

require_once dirname(__DIR__) . '/includes/helpers-ops.php';
require_once dirname(__DIR__) . '/includes/helpers-health.php';

$payload = dcb_health_snapshot_payload(array(
    'readiness_context' => array(
        'ocr_mode' => 'auto',
        'ocr_api_base_url' => '',
        'ocr_api_key' => '',
        'upload_writable' => true,
        'permalink_structure' => '/%postname%/',
        'capabilities_ok' => true,
    ),
    'ocr_health' => array('status' => 'ready'),
    'workflow_counts' => array('submitted' => 5, 'in_review' => 2),
    'unresolved_risk_count' => 4,
    'ops_last_action' => array('action' => 'forms_import', 'status' => 'ok', 'message' => 'Imported', 'time' => '2026-04-16T00:00:00Z'),
));

if ((int) (($payload['unresolved_ocr_risk_count'] ?? 0)) !== 4) {
    fwrite(STDERR, "unresolved risk mismatch\n");
    exit(1);
}
if ((string) ($payload['ocr']['mode'] ?? '') !== 'auto') {
    fwrite(STDERR, "ocr mode mismatch\n");
    exit(1);
}
if (empty($payload['links']['setup_ops']) || strpos((string) $payload['links']['setup_ops'], 'dcb-ops') === false) {
    fwrite(STDERR, "health links missing\n");
    exit(1);
}

echo "system_health_snapshot_smoke:ok\n";
