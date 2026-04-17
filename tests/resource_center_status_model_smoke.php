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

$model = dcb_resource_center_status_model(
    array(
        'consent_form' => array('label' => 'Consent Form'),
        'id_form' => array('label' => 'ID Form'),
    ),
    array('consent_form'),
    array(
        'consent_form' => array('workflow_status' => 'submitted', 'review_status' => ''),
    ),
    array(
        'id_form' => array('count' => 1, 'latest_state' => 'returned_for_correction'),
    )
);

$summary = $model['summary'] ?? array();
if ((int) ($summary['required_count'] ?? 0) !== 1) {
    fwrite(STDERR, "required count mismatch\n");
    exit(1);
}
if ((int) ($summary['correction_count'] ?? 0) < 1) {
    fwrite(STDERR, "correction count mismatch\n");
    exit(1);
}

echo "resource_center_status_model_smoke:ok\n";
