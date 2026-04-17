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
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}

require_once dirname(__DIR__) . '/includes/helpers-chart-routing.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$identifiers = array(
    'patient_name' => 'Alex Carter',
    'dob' => '01/19/1988',
    'mrn' => '',
    'visit_date' => '04/11/2026',
    'clinician_name' => 'Dr. Lee',
);

$candidates = array(
    array(
        'full_name' => 'Alex Carter',
        'dob' => '01/19/1988',
        'mrn' => 'A123456',
        'visit_date' => '04/11/2026',
        'clinician_name' => 'Dr. Lee',
        'chart_target_id' => 'chart-100',
    ),
    array(
        'full_name' => 'Alex Carter',
        'dob' => '',
        'mrn' => '',
        'visit_date' => '',
        'clinician_name' => '',
        'chart_target_id' => 'chart-200',
    ),
);

$match = dcb_chart_routing_build_match_result($identifiers, $candidates);
assert_true((string) ($match['confidence_tier'] ?? '') === 'high_confidence', 'strong multi-signal match should be high confidence');
assert_true(empty($match['name_only_guardrail_triggered']), 'strong multi-signal match should not trigger name-only guardrail');

$name_only = dcb_chart_routing_build_match_result(
    array('patient_name' => 'Alex Carter', 'dob' => '', 'mrn' => '', 'visit_date' => '', 'clinician_name' => ''),
    array(array('full_name' => 'Alex Carter', 'chart_target_id' => 'chart-300'))
);
assert_true((string) ($name_only['confidence_tier'] ?? '') !== 'high_confidence', 'name-only match must not be high confidence');
assert_true(!empty($name_only['name_only_guardrail_triggered']), 'name-only guardrail must be triggered');
assert_true(empty($name_only['auto_route_allowed']), 'name-only match must not be auto-route eligible');

$doc_type = dcb_chart_routing_classify_document_type(array(
    'ocr_text' => 'Patient Consent and HIPAA authorization form signed by patient.',
));
assert_true((string) ($doc_type['document_type'] ?? '') === 'consent', 'consent clues should classify as consent');

$fallback_type = dcb_chart_routing_classify_document_type(array('ocr_text' => '')); 
assert_true((string) ($fallback_type['document_type'] ?? '') === 'miscellaneous', 'unknown content should fallback to miscellaneous');

$extracted = dcb_chart_routing_extract_identifiers(array(
    'ocr_text' => "Patient Name: Robin Vale\nDOB: 02/03/1987\nMRN: ZX-99112\nVisit Date: 04/12/2026\nClinician: Dr. K",
));
assert_true((string) ($extracted['mrn'] ?? '') === 'ZX-99112', 'MRN should be parsed from OCR text');
assert_true((string) ($extracted['patient_name'] ?? '') !== '', 'patient name should be parsed from OCR text');


echo "chart_routing_matching_smoke:ok\n";
