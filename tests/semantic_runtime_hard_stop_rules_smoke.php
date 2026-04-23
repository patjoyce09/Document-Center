<?php

define('ABSPATH', __DIR__ . '/');

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
    function sanitize_email($email) { return trim((string) $email); }
}

require_once dirname(__DIR__) . '/includes/helpers-schema.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$form = array(
    'hard_stops' => array(
        array(
            'type' => 'approval_block_incomplete',
            'message' => 'Approval block is incomplete.',
            'when' => array(array('field' => 'approval_complete', 'operator' => 'not_filled')),
        ),
        array(
            'type' => 'signature_date_pair_missing',
            'message' => 'Signature/date pair is incomplete.',
            'when' => array(array('field' => 'signature_date_pair_ok', 'operator' => 'not_filled')),
        ),
        array(
            'type' => 'checkbox_group_incomplete',
            'message' => 'Checkbox group is incomplete.',
            'when' => array(array('field' => 'checkbox_group_ok', 'operator' => 'not_filled')),
        ),
        array(
            'type' => 'demographic_block_incomplete',
            'message' => 'Demographic block is incomplete.',
            'when' => array(array('field' => 'demographic_block_ok', 'operator' => 'not_filled')),
        ),
        array(
            'type' => 'sparse_form_critical_field_set_missing_field',
            'message' => 'Critical sparse field set is incomplete.',
            'when' => array(array('field' => 'sparse_critical_ok', 'operator' => 'not_filled')),
        ),
    ),
);

$clean = array();
$raw = array(
    'approval_complete' => '',
    'signature_date_pair_ok' => '',
    'checkbox_group_ok' => '',
    'demographic_block_ok' => '',
    'sparse_critical_ok' => '',
);

$errors = dcb_apply_generic_hard_stops($form, $clean, $raw);

assert_true(count($errors) === 5, 'all prioritized semantic hard-stop rules should trigger when corresponding required values are missing');
assert_true(in_array('Approval block is incomplete.', $errors, true), 'approval rule should trigger');
assert_true(in_array('Signature/date pair is incomplete.', $errors, true), 'signature-date rule should trigger');
assert_true(in_array('Checkbox group is incomplete.', $errors, true), 'checkbox-group rule should trigger');
assert_true(in_array('Demographic block is incomplete.', $errors, true), 'demographic rule should trigger');
assert_true(in_array('Critical sparse field set is incomplete.', $errors, true), 'sparse critical field-set rule should trigger');

echo "semantic_runtime_hard_stop_rules_smoke:ok\n";
