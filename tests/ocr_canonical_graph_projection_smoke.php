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
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-22 00:00:00'; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('do_action')) {
    function do_action() {}
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { return true; }
}
if (!function_exists('mb_substr')) {
    function mb_substr($text, $start, $length = null) {
        return $length === null ? substr((string) $text, (int) $start) : substr((string) $text, (int) $start, (int) $length);
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12) { return substr(str_repeat('a', $len), 0, $len); }
}

require_once dirname(__DIR__) . '/includes/helpers-schema.php';
require_once dirname(__DIR__) . '/includes/helpers-ocr.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$draft_fields = array(
    array(
        'key' => 'patient_name',
        'label' => 'Patient Name (old)',
        'type' => 'text',
        'required' => true,
        'ocr_meta' => array(
            'widget_id' => 'widget_patient_name',
            'stable_id' => 'widget_patient_name',
        ),
    ),
    array(
        'key' => 'consent_signature',
        'label' => 'Signature',
        'type' => 'text',
        'required' => false,
        'ocr_meta' => array('widget_id' => 'widget_signature', 'stable_id' => 'widget_signature'),
    ),
    array(
        'key' => 'consent_date',
        'label' => 'Date',
        'type' => 'date',
        'required' => false,
        'ocr_meta' => array('widget_id' => 'widget_date', 'stable_id' => 'widget_date'),
    ),
    array(
        'key' => 'consent_checkbox_a',
        'label' => 'Consent A',
        'type' => 'checkbox',
        'required' => false,
        'ocr_meta' => array('widget_id' => 'widget_checkbox_a', 'stable_id' => 'widget_checkbox_a'),
    ),
    array(
        'key' => 'consent_checkbox_b',
        'label' => 'Consent B',
        'type' => 'checkbox',
        'required' => false,
        'ocr_meta' => array('widget_id' => 'widget_checkbox_b', 'stable_id' => 'widget_checkbox_b'),
    ),
    array(
        'key' => 'demographics_gender',
        'label' => 'Gender',
        'type' => 'select',
        'required' => false,
        'ocr_meta' => array('widget_id' => 'widget_demographic_gender', 'stable_id' => 'widget_demographic_gender'),
    ),
    array(
        'key' => 'sparse_employer',
        'label' => 'Employer',
        'type' => 'text',
        'required' => false,
        'ocr_meta' => array('widget_id' => 'widget_sparse_employer', 'stable_id' => 'widget_sparse_employer'),
    ),
);

$canonical_graph = array(
    'pages' => array(
        array(
            'stable_id' => 'page_1',
            'widgets' => array(
                array(
                    'stable_id' => 'widget_patient_name',
                    'widget_id' => 'widget_patient_name',
                    'widget_type' => 'text_input',
                    'classification' => 'fillable',
                    'is_fillable' => 1,
                    'label_text' => 'Patient Legal Name',
                    'section_hint' => 'demographics',
                    'region_hint' => 'left',
                    'page_number' => 1,
                    'line_index' => 3,
                ),
                array(
                    'stable_id' => 'widget_instruction_fixed',
                    'widget_id' => 'widget_instruction_fixed',
                    'widget_type' => 'fixed_text',
                    'classification' => 'fixed_text',
                    'is_fillable' => 0,
                    'label_text' => 'FOR OFFICE USE ONLY',
                    'section_hint' => 'instructions',
                    'region_hint' => 'center',
                    'page_number' => 1,
                    'line_index' => 1,
                ),
            ),
            'grouped_controls' => array(
                array(
                    'stable_id' => 'group_consent_checkboxes',
                    'group_type' => 'checkbox_group',
                    'widget_ids' => array('widget_checkbox_a', 'widget_checkbox_b'),
                ),
            ),
            'approval_blocks' => array(
                array(
                    'stable_id' => 'approval_block_main',
                    'widget_ids' => array('widget_signature', 'widget_date'),
                ),
            ),
            'relations' => array(
                array(
                    'stable_id' => 'relation_sig_date',
                    'relation_type' => 'signature_date_pair',
                    'from_widget_id' => 'widget_signature',
                    'to_widget_id' => 'widget_date',
                ),
            ),
        ),
    ),
);

$projected = dcb_ocr_project_draft_fields_from_canonical_graph($draft_fields, $canonical_graph);
assert_true(is_array($projected) && !empty($projected), 'projected fields should be generated');

$patient_field = null;
foreach ($projected as $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string) ($row['key'] ?? '') !== 'patient_name') {
        continue;
    }
    $patient_field = $row;
    break;
}
assert_true(is_array($patient_field), 'projected fields should keep existing patient field by mapped widget id');
assert_true((string) ($patient_field['label'] ?? '') === 'Patient Legal Name', 'canonical widget label should project into draft field label');
assert_true(!empty($patient_field['ocr_meta']['canonical_graph_applied']), 'projected field should indicate canonical graph projection');
assert_true((int) ($patient_field['ocr_meta']['is_fillable'] ?? 0) === 1, 'projected field should retain fillable classification from canonical graph');

$targets = array(
    array('stable_id' => 'approval_block_main', 'rule_type' => 'approval_block_incomplete', 'is_hard_stop' => true),
    array('stable_id' => 'relation_sig_date', 'rule_type' => 'signature_date_pair_missing', 'is_hard_stop' => true),
    array('stable_id' => 'group_consent_checkboxes', 'rule_type' => 'checkbox_group_incomplete', 'is_hard_stop' => true),
    array('stable_id' => 'anchor_demographic', 'rule_type' => 'required_demographic_missing', 'target_field' => 'widget_demographic_gender', 'is_hard_stop' => true),
    array('stable_id' => 'anchor_sparse', 'rule_type' => 'required_sparse_critical_missing', 'target_field' => 'widget_sparse_employer', 'is_hard_stop' => true),
);

$stops = dcb_ocr_generate_hard_stops_from_semantic_targets($targets, $canonical_graph, $projected, array());
assert_true(is_array($stops) && !empty($stops), 'semantic hard stop generation should return rules');

$hints = dcb_ocr_merge_digital_twin_hints_with_canonical_graph(array(), $canonical_graph, $projected);
$projection_quality = dcb_ocr_build_draft_projection_quality($projected, $canonical_graph, $targets, $stops, $hints);
assert_true(is_array($projection_quality), 'draft projection quality payload should be generated');
assert_true(isset($projection_quality['patched_graph_to_draft_consistency']), 'projection quality should include patched graph consistency');
assert_true(isset($projection_quality['grouped_control_projection_quality']), 'projection quality should include grouped control quality');
assert_true(isset($projection_quality['approval_block_projection_quality']), 'projection quality should include approval block quality');
assert_true(isset($projection_quality['semantic_hard_stop_generation_coverage']), 'projection quality should include hard-stop generation coverage');
assert_true(isset($projection_quality['digital_twin_hint_completeness']), 'projection quality should include digital twin completeness');

$stop_types = array();
foreach ($stops as $stop_row) {
    if (!is_array($stop_row)) {
        continue;
    }
    $stop_types[] = sanitize_key((string) ($stop_row['type'] ?? ''));
}

assert_true(in_array('approval_block_incomplete', $stop_types, true), 'should generate approval-block hard stop rule');
assert_true(in_array('signature_date_pair_missing', $stop_types, true), 'should generate signature/date pair hard stop rule');
assert_true(in_array('checkbox_group_incomplete', $stop_types, true), 'should generate grouped checkbox hard stop rule');
assert_true(in_array('required_demographic_missing', $stop_types, true), 'should generate demographic required hard stop rule');
assert_true(in_array('required_sparse_critical_missing', $stop_types, true), 'should generate sparse-critical required hard stop rule');

echo "ocr_canonical_graph_projection_smoke:ok\n";
