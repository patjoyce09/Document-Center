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

$queue_payload = dcb_chart_routing_queue_payload_shape(array(
    'source_artifact_id' => 77,
    'trace_id' => 'dcb-intake-trace-xyz',
    'extracted_identifiers' => array('patient_name' => 'J Doe'),
    'candidate_count' => 2,
    'confidence_tier' => 'medium_confidence',
    'confidence_score' => 0.72,
    'document_type' => 'visit_note',
    'document_type_confidence' => 0.61,
    'route_status' => 'needs_review',
    'connector_mode' => 'report_import',
));

assert_true((int) ($queue_payload['source_artifact_id'] ?? 0) === 77, 'queue payload should keep source artifact id');
assert_true((string) ($queue_payload['confidence_tier'] ?? '') === 'medium_confidence', 'queue payload should keep confidence tier');
assert_true((string) ($queue_payload['document_type'] ?? '') === 'visit_note', 'queue payload should keep document type');

$audit_payload = dcb_chart_routing_audit_payload_shape(array(
    'source_artifact_id' => 77,
    'trace_id' => 'dcb-intake-trace-xyz',
    'extracted_identifiers_snapshot' => array('mrn' => '1234'),
    'candidate_list_summary' => array(array('candidate_key' => 'abc', 'score' => 0.72)),
    'chosen_patient_target' => array('patient_id' => '1234'),
    'chosen_document_type' => 'visit_note',
    'route_method' => 'manual',
    'confirmed_by_user_id' => 11,
    'confirmed_by_name' => 'Queue User',
    'confirmed_at' => '2026-04-17 12:00:00',
    'result' => 'success',
    'result_message' => 'Routed manually.',
));

assert_true((int) ($audit_payload['confirmed_by_user_id'] ?? 0) === 11, 'audit payload should preserve confirming user id');
assert_true((string) ($audit_payload['route_method'] ?? '') === 'manual', 'audit payload should preserve route method');
assert_true((string) ($audit_payload['chosen_document_type'] ?? '') === 'visit_note', 'audit payload should preserve chosen document type');

$invalid_doc = dcb_chart_routing_normalize_document_type('something_else');
assert_true($invalid_doc === 'miscellaneous', 'unknown document type must normalize to miscellaneous');


echo "chart_routing_payloads_smoke:ok\n";
