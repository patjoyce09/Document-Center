<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['dcb_test_options'] = array();

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($text) { return trim((string) $text); }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) { return filter_var((string) $email, FILTER_SANITIZE_EMAIL); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return trim((string) $url); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12) { return substr(str_repeat('a', $len), 0, $len); }
}
if (!function_exists('is_email')) {
    function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) {
        if ($key === 'dcb_forms_custom') {
            return $GLOBALS['dcb_test_options']['dcb_forms_custom'] ?? $default;
        }
        return $default;
    }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-16 00:00:00'; }
}

require_once dirname(__DIR__) . '/includes/helpers-schema.php';

function dcb_test_assert(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$cond = dcb_normalize_condition(array('field' => 'age', 'operator' => 'in', 'values' => array('18', '21')));
dcb_test_assert(is_array($cond) && $cond['operator'] === 'in' && count((array) ($cond['values'] ?? array())) === 2, 'condition normalization should support list operators');
dcb_test_assert(dcb_normalize_condition(array('field' => 'age', 'operator' => 'bad_op')) === null, 'invalid operator should be rejected');

$normalized = dcb_normalize_single_form(array(
    'label' => 'Intake',
    'fields' => array(
        array('key' => 'age', 'label' => 'Age', 'type' => 'number', 'required' => true),
    ),
    'hard_stops' => array(
        array(
            'label' => 'Adult Check',
            'severity' => 'error',
            'type' => 'eligibility',
            'message' => 'Adults only.',
            'when' => array(array('field' => 'age', 'operator' => 'lt', 'value' => '18')),
        ),
    ),
));
dcb_test_assert(is_array($normalized), 'normalized form should exist');
dcb_test_assert(!empty($normalized['hard_stops'][0]['label']) && $normalized['hard_stops'][0]['label'] === 'Adult Check', 'hard stop label metadata should persist');
$hard_stop_errors = dcb_apply_generic_hard_stops($normalized, array('age' => '17'), array('age' => '17'));
dcb_test_assert(count($hard_stop_errors) === 1, 'hard stop evaluation should trigger blocking message');

$validation = dcb_builder_validate_form_schema(array(
    'fields' => array(
        array('key' => 'dup_key', 'label' => 'A', 'type' => 'text'),
        array('key' => 'dup_key', 'label' => '', 'type' => 'text', 'conditions' => array(array('field' => '', 'operator' => 'eq', 'value' => 'x'))),
    ),
    'sections' => array(array('key' => 'general', 'label' => 'General', 'field_keys' => array('missing_field'))),
    'steps' => array(array('key' => 'step_1', 'label' => 'Step 1', 'section_keys' => array('missing_section'))),
    'repeaters' => array(array('key' => 'rep_1', 'label' => 'Rep', 'field_keys' => array('missing_field'))),
    'hard_stops' => array(array('message' => '', 'when' => array())),
    'template_blocks' => array(array('block_id' => 'blk_1', 'type' => 'paragraph', 'text' => 'Hi')),
    'document_nodes' => array(array('type' => 'field', 'field_key' => 'missing_field')),
));
dcb_test_assert(!empty($validation['errors']) && count($validation['errors']) >= 5, 'validation should report actionable schema errors');

$GLOBALS['dcb_test_options']['dcb_forms_custom'] = array(
    'legacy_intake' => array(
        'label' => 'Legacy Intake',
        'fields' => array(array('key' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true)),
        'hard_stops' => array(array('message' => 'Need first name', 'when' => array(array('field' => 'first_name', 'operator' => 'eq', 'value' => '')))),
        'sections' => array(array('key' => 'general', 'label' => 'General', 'field_keys' => array('first_name'))),
        'steps' => array(array('key' => 'step_1', 'label' => 'Step 1', 'section_keys' => array('general'))),
    ),
);
$custom_forms = dcb_get_custom_forms();
$for_js = dcb_form_definitions(true);
dcb_test_assert(isset($custom_forms['legacy_intake']), 'legacy form should normalize and load');
dcb_test_assert(isset($for_js['legacy_intake']['hardStops']), 'legacy hard stops should stay export-compatible for JS runtime');

$reviewed = dcb_apply_ocr_candidate_review(
    array('label' => 'OCR Draft', 'fields' => array(), 'template_blocks' => array(), 'hard_stops' => array()),
    array(
        array('field_label' => 'Patient Name', 'suggested_key' => 'patient_name', 'suggested_type' => 'text', 'required_guess' => true, 'confidence_bucket' => 'high', 'confidence_score' => 0.9, 'decision' => 'accept'),
        array('field_label' => 'Discard Me', 'suggested_key' => 'discard_me', 'suggested_type' => 'text', 'required_guess' => false, 'confidence_bucket' => 'low', 'confidence_score' => 0.3, 'decision' => 'reject'),
    )
);
dcb_test_assert(count((array) ($reviewed['fields'] ?? array())) === 1, 'OCR review acceptance should keep accepted candidates only');
dcb_test_assert((int) ($reviewed['ocr_review']['accepted_count'] ?? 0) === 1, 'OCR review metadata should track accepted count');

$mixed = dcb_normalize_single_form(array(
    'label' => 'Mixed Builder Save',
    'fields' => array(
        array('key' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true),
        array('key' => 'signature_date', 'label' => 'Signature Date', 'type' => 'date', 'required' => true),
    ),
    'sections' => array(array('key' => 'general', 'label' => 'General', 'field_keys' => array('first_name', 'signature_date'))),
    'steps' => array(array('key' => 'step_1', 'label' => 'Step 1', 'section_keys' => array('general'))),
    'repeaters' => array(array('key' => 'contacts', 'label' => 'Contacts', 'field_keys' => array('first_name'), 'min' => 0, 'max' => 3)),
    'template_blocks' => array(array('block_id' => 'blk_intro', 'type' => 'heading', 'text' => 'Intro', 'level' => 2)),
    'document_nodes' => array(array('type' => 'block', 'block_id' => 'blk_intro'), array('type' => 'field', 'field_key' => 'first_name')),
));
dcb_test_assert(!empty($mixed['sections']) && !empty($mixed['steps']) && !empty($mixed['repeaters']) && !empty($mixed['document_nodes']), 'mixed builder schema should normalize and preserve structural arrays');

echo "builder_maturity_smoke:ok\n";
