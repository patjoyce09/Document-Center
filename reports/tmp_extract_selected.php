<?php
$ids = array('nomnc_filled_returned_002', 'consent_filled_returned_002', 'consent_filled_returned_003', 'packet_filled_returned_001_full');
$metricsKeys = array(
    'false_positive_count',
    'yes_no_grouping_accuracy',
    'checkbox_grouping_accuracy',
    'signature_date_pairing_accuracy',
    'review_cleanup_burden_proxy',
    'canonical_graph_completeness_proxy',
);

$baselinePath = __DIR__ . '/private_realforms_baseline_full.json';
$readinessPath = __DIR__ . '/private_patch_readiness_selected.json';

$baseline = json_decode((string) file_get_contents($baselinePath), true);
$readiness = json_decode((string) file_get_contents($readinessPath), true);

$selected_case_metrics = array();
foreach ($ids as $id) {
    $selected_case_metrics[$id] = array();
}

foreach ((array) ($baseline['cases'] ?? array()) as $case) {
    $id = (string) ($case['case_id'] ?? '');
    if (!in_array($id, $ids, true)) {
        continue;
    }
    $row = array();
    foreach ($metricsKeys as $k) {
        $row[$k] = $case[$k] ?? null;
    }
    $selected_case_metrics[$id] = $row;
}

$patch_targets = array();
foreach ($ids as $id) {
    $case = (array) ($readiness[$id] ?? array());
    $widgets = (array) ($case['widgets'] ?? array());
    $groups = (array) ($case['groups'] ?? array());
    $approvals = (array) ($case['approvals'] ?? array());
    $relations = (array) ($case['relations'] ?? array());

    $signature_like = array();
    $date_like = array();
    $first_group = null;

    foreach ($widgets as $w) {
        if (!is_array($w)) {
            continue;
        }
        $type = strtolower((string) ($w['widget_type'] ?? ''));
        if (($type === 'signature_line' || $type === 'initials_line') && count($signature_like) < 2) {
            $signature_like[] = array(
                'stable_id' => (string) ($w['stable_id'] ?? ''),
                'page' => (int) ($w['page'] ?? 0),
            );
        }
        if ($type === 'date_field' && count($date_like) < 2) {
            $date_like[] = array(
                'stable_id' => (string) ($w['stable_id'] ?? ''),
                'page' => (int) ($w['page'] ?? 0),
            );
        }
        if ($first_group === null && ($type === 'yes_no_group' || $type === 'checkbox')) {
            $first_group = array(
                'stable_id' => (string) ($w['stable_id'] ?? ''),
                'page' => (int) ($w['page'] ?? 0),
            );
        }
    }

    if ($first_group === null) {
        foreach ($groups as $g) {
            if (!is_array($g)) {
                continue;
            }
            $gt = strtolower((string) ($g['group_type'] ?? ''));
            if ($gt === 'yes_no_group' || $gt === 'checkbox_group' || $gt === 'checkbox') {
                $first_group = array(
                    'stable_id' => (string) ($g['stable_id'] ?? ''),
                    'page' => (int) ($g['page'] ?? 0),
                );
                break;
            }
        }
    }

    $first_approval = null;
    if (!empty($approvals[0]) && is_array($approvals[0])) {
        $first_approval = array(
            'stable_id' => (string) ($approvals[0]['stable_id'] ?? ''),
            'page' => (int) ($approvals[0]['page'] ?? 0),
        );
    }

    $paired_signature_date_exists = false;
    foreach ($relations as $rel) {
        if (!is_array($rel)) {
            continue;
        }
        if (strtolower((string) ($rel['relation'] ?? '')) === 'paired_signature_date') {
            $paired_signature_date_exists = true;
            break;
        }
    }

    $patch_targets[$id] = array(
        'signature_like_widgets' => $signature_like,
        'date_like_widgets' => $date_like,
        'first_yes_no_or_checkbox_group' => $first_group,
        'first_approval_block' => $first_approval,
        'paired_signature_date_relation_exists' => $paired_signature_date_exists,
    );
}

$out = array(
    'selected_case_metrics' => $selected_case_metrics,
    'patch_targets' => $patch_targets,
);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
