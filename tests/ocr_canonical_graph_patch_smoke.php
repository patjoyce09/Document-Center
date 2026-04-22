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
if (!function_exists('mb_substr')) {
    function mb_substr($text, $start, $length = null) {
        return $length === null ? substr((string) $text, (int) $start) : substr((string) $text, (int) $start, (int) $length);
    }
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

function find_widget_by_stable_id(array $canonical, string $stable_id): array {
    foreach ((array) ($canonical['pages'] ?? array()) as $page) {
        if (!is_array($page)) {
            continue;
        }
        foreach ((array) ($page['widgets'] ?? array()) as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if (sanitize_key((string) ($widget['stable_id'] ?? '')) === $stable_id) {
                return $widget;
            }
        }
    }
    return array();
}

$pages = array(
    array(
        'page_number' => 1,
        'engine' => 'fixture',
        'text' => "Patient Name: __________\nDOB: ____/____/______\nDo you consent? Yes / No\nSignature: __________________\nDate: __________________",
        'text_length' => 180,
        'confidence_proxy' => 0.85,
    ),
);

$document_model = dcb_ocr_build_document_model($pages);
$page_meta = array(1 => array('page_number' => 1, 'engine' => 'fixture', 'confidence_proxy' => 0.85));
$widgets = dcb_ocr_detect_field_widgets($document_model, $page_meta, array());
$page_graph = dcb_ocr_build_page_relation_graph($document_model, $widgets);
$scene_graph = dcb_ocr_build_scene_graph($document_model, $widgets, $page_graph, $page_meta);
$canonical = dcb_ocr_build_canonical_form_graph($document_model, $widgets, $page_graph, $scene_graph, $page_meta, array());

$shape = dcb_ocr_canonical_patch_payload_shape();
assert_true(isset($shape['widgets']) && is_array($shape['widgets']), 'patch payload shape must include widgets');
assert_true(isset($shape['relations']) && is_array($shape['relations']), 'patch payload shape must include relations');

$first_widget = array();
$first_group = array();
$first_approval = array();
$first_relation = array();

$pages_out = isset($canonical['pages']) && is_array($canonical['pages']) ? $canonical['pages'] : array();
assert_true(!empty($pages_out), 'canonical pages should exist');

foreach ($pages_out as $page_row) {
    if (!is_array($page_row)) {
        continue;
    }
    if (empty($first_widget) && !empty($page_row['widgets'][0]) && is_array($page_row['widgets'][0])) {
        $first_widget = $page_row['widgets'][0];
    }
    if (empty($first_group) && !empty($page_row['grouped_controls'][0]) && is_array($page_row['grouped_controls'][0])) {
        $first_group = $page_row['grouped_controls'][0];
    }
    if (empty($first_approval) && !empty($page_row['approval_blocks'][0]) && is_array($page_row['approval_blocks'][0])) {
        $first_approval = $page_row['approval_blocks'][0];
    }
}
foreach ((array) ($canonical['relations'] ?? array()) as $relation_row) {
    if (!is_array($relation_row)) {
        continue;
    }
    $from = sanitize_key((string) ($relation_row['from'] ?? ''));
    $to = sanitize_key((string) ($relation_row['to'] ?? ''));
    if (strpos($from, 'widget_') !== 0 || strpos($to, 'widget_') !== 0) {
        continue;
    }
    $first_relation = $relation_row;
    break;
}

assert_true(!empty($first_widget), 'canonical should include at least one widget');
assert_true(!empty($first_group), 'canonical should include at least one group');
assert_true(!empty($first_approval), 'canonical should include at least one approval block');
assert_true(!empty($first_relation), 'canonical should include at least one relation');

$widget_stable = sanitize_key((string) ($first_widget['stable_id'] ?? ''));
$group_stable = sanitize_key((string) ($first_group['stable_id'] ?? ''));
$approval_stable = sanitize_key((string) ($first_approval['stable_id'] ?? ''));
$relation_stable = sanitize_key((string) ($first_relation['stable_id'] ?? ''));
$widget_id = sanitize_key((string) ($first_widget['widget_id'] ?? ''));

assert_true($widget_stable !== '' && $group_stable !== '' && $approval_stable !== '' && $relation_stable !== '', 'stable IDs should be present');

$patch = array(
    'patch_version' => '1.0',
    'patch_id' => 'test_patch_a',
    'meta' => array(
        'review_id' => 42,
        'reviewer_user_id' => 7,
        'source' => 'test_manual_review',
    ),
    'widgets' => array(
        array(
            'stable_id' => $widget_stable,
            'label_text' => 'Corrected Widget Label',
            'widget_type' => 'text_input',
            'classification' => 'fixed_text',
            'group_membership' => array('add' => array($group_stable), 'remove' => array()),
            'approval_block_membership' => array('add' => array($approval_stable), 'remove' => array()),
        ),
    ),
    'relations' => array(
        array(
            'stable_id' => $relation_stable,
            'decision' => 'upsert',
            'from' => sanitize_key((string) ($first_relation['from'] ?? '')),
            'to' => sanitize_key((string) ($first_relation['to'] ?? '')),
            'relation' => 'same_question_group',
            'group_key' => 'patched_group',
        ),
    ),
);

$patched = dcb_ocr_apply_canonical_graph_patch($canonical, $patch);

$patched_widget = find_widget_by_stable_id($patched, $widget_stable);
assert_true(!empty($patched_widget), 'patched widget should exist');
assert_true((string) ($patched_widget['label_text'] ?? '') === 'Corrected Widget Label', 'widget label should be patched');
assert_true((string) ($patched_widget['classification'] ?? '') === 'fixed_text', 'widget classification should be patched');
assert_true((int) ($patched_widget['is_fillable'] ?? 1) === 0, 'widget fillable classification should be patched to fixed text');

$group_has_widget = false;
$approval_has_widget = false;
foreach ((array) ($patched['pages'] ?? array()) as $page_row) {
    if (!is_array($page_row)) {
        continue;
    }
    foreach ((array) ($page_row['grouped_controls'] ?? array()) as $group_row) {
        if (!is_array($group_row)) {
            continue;
        }
        if (sanitize_key((string) ($group_row['stable_id'] ?? '')) !== $group_stable) {
            continue;
        }
        $ids = array_values(array_filter(array_map('sanitize_key', (array) ($group_row['widget_ids'] ?? array()))));
        if (in_array($widget_id, $ids, true)) {
            $group_has_widget = true;
        }
    }
    foreach ((array) ($page_row['approval_blocks'] ?? array()) as $block_row) {
        if (!is_array($block_row)) {
            continue;
        }
        if (sanitize_key((string) ($block_row['stable_id'] ?? '')) !== $approval_stable) {
            continue;
        }
        $ids = array_values(array_filter(array_map('sanitize_key', (array) ($block_row['widget_ids'] ?? array()))));
        if (in_array($widget_id, $ids, true)) {
            $approval_has_widget = true;
        }
    }
}

assert_true($group_has_widget, 'patched group membership should include widget');
assert_true($approval_has_widget, 'patched approval block membership should include widget');

$relation_ok = false;
foreach ((array) ($patched['relations'] ?? array()) as $relation_row) {
    if (!is_array($relation_row)) {
        continue;
    }
    if (sanitize_key((string) ($relation_row['stable_id'] ?? '')) !== $relation_stable) {
        continue;
    }
    if (sanitize_key((string) ($relation_row['relation'] ?? '')) === 'same_question_group') {
        $relation_ok = true;
    }
}
assert_true($relation_ok, 'patched relation should be updated');

assert_true(!empty($patched['reviewer_patch']['applied']), 'reviewer patch metadata should mark applied');
assert_true(isset($patched['correction_provenance']) && is_array($patched['correction_provenance']) && count($patched['correction_provenance']) >= 3, 'correction provenance should be preserved');

echo "ocr_canonical_graph_patch_smoke:ok\n";
