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
if (!function_exists('mb_substr')) {
    function mb_substr($text, $start, $length = null) {
        return $length === null ? substr((string) $text, (int) $start) : substr((string) $text, (int) $start, (int) $length);
    }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-20 00:00:00'; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}

require_once dirname(__DIR__) . '/includes/helpers-schema.php';
require_once dirname(__DIR__) . '/includes/helpers-ocr.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$pages = array(
    array(
        'page_number' => 1,
        'engine' => 'fixture',
        'text' => "SECTION A\nDo you agree? Yes / No\nPatient Signature: __________________\nDate: __________________\nItem   Qty   Description   Amount\nService A   1   Follow-up   30.00",
        'text_length' => 190,
        'confidence_proxy' => 0.84,
    ),
);

$document_model = dcb_ocr_build_document_model($pages);
$page_meta = array(1 => array('page_number' => 1, 'engine' => 'fixture', 'confidence_proxy' => 0.84));
$widgets = dcb_ocr_detect_field_widgets($document_model, $page_meta, array());

$group_seed = '';
foreach ($widgets as $widget_row) {
    if (!is_array($widget_row)) {
        continue;
    }
    $group_key = sanitize_key((string) ($widget_row['group_key'] ?? ''));
    if ($group_key !== '' && (strpos($group_key, 'yes_no_') === 0 || strpos($group_key, 'checkbox_cluster_') === 0)) {
        $group_seed = $group_key;
        break;
    }
}
if ($group_seed !== '') {
    $widgets[] = array(
        'widget_id' => 'widget_group_probe_1',
        'widget_type' => 'checkbox',
        'page_number' => 1,
        'line_index' => 2,
        'label_text' => 'Group Probe A',
        'section_hint' => 'section_a',
        'group_key' => $group_seed,
        'confidence_score' => 0.80,
    );
    $widgets[] = array(
        'widget_id' => 'widget_group_probe_2',
        'widget_type' => 'checkbox',
        'page_number' => 1,
        'line_index' => 3,
        'label_text' => 'Group Probe B',
        'section_hint' => 'section_a',
        'group_key' => $group_seed,
        'confidence_score' => 0.80,
    );
}

$graph = dcb_ocr_build_page_relation_graph($document_model, $widgets);

$relations = array();
foreach ((array) ($graph['edges'] ?? array()) as $edge) {
    if (!is_array($edge)) {
        continue;
    }
    $relations[] = sanitize_key((string) ($edge['relation'] ?? ''));
}

assert_true(in_array('label_of', $relations, true), 'relation graph should include label_of');
assert_true(in_array('belongs_to_group', $relations, true), 'relation graph should include belongs_to_group');
assert_true(in_array('section_contains', $relations, true), 'relation graph should include section_contains');
assert_true(in_array('paired_signature_date', $relations, true), 'relation graph should include paired_signature_date');
assert_true(in_array('same_question_group', $relations, true), 'relation graph should include same_question_group');
assert_true(in_array('repeater_row_of', $relations, true), 'relation graph should include repeater_row_of');

echo "ocr_relation_graph_hardening_smoke:ok\n";
