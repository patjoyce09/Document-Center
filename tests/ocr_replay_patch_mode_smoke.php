<?php

define('ABSPATH', __DIR__ . '/');

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$tmp_dir = sys_get_temp_dir() . '/dcb_patch_replay_' . substr(md5((string) microtime(true)), 0, 8);
if (!@mkdir($tmp_dir, 0777, true) && !is_dir($tmp_dir)) {
    fwrite(STDERR, "Failed to create temp directory\n");
    exit(1);
}

$manifest_path = $tmp_dir . '/manifest.json';
$patch_manifest_path = $tmp_dir . '/patches.json';
$runner_path = __DIR__ . '/ocr_local_replay_runner.php';

$manifest = array(
    'fixture_version' => '1.0',
    'cases' => array(
        array(
            'case_id' => 'patch_mode_case',
            'label' => 'Patch Mode Smoke Case',
            'source_type' => 'pdf',
            'sample_path' => 'fixtures/ocr-realworld/notice_sparse_sample.txt',
            'required_local_binary' => false,
            'dense_vs_sparse' => 'sparse',
            'expected' => array(
                'fields' => array(
                    array('key' => 'applicant_signature', 'type' => 'text'),
                ),
                'sections' => array(),
                'min_repeater_count' => 0,
            ),
        ),
    ),
);

$patch_manifest = array(
    'fixture_version' => '1.0',
    'case_patches' => array(
        'patch_mode_case' => array(
            'patch_categories' => array('false_positive_removal'),
            'candidate_fields' => array(
                array(
                    'field_label' => 'Applicant Signature',
                    'suggested_key' => 'applicant_signature',
                    'suggested_type' => 'text',
                    'confidence_bucket' => 'high',
                    'decision' => 'accept',
                ),
                array(
                    'field_label' => 'Noise Header',
                    'suggested_key' => 'noise_header',
                    'suggested_type' => 'text',
                    'confidence_bucket' => 'low',
                    'decision' => 'reject',
                ),
            ),
        ),
    ),
);

file_put_contents($manifest_path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($patch_manifest_path, json_encode($patch_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$cmd = sprintf(
    '%s %s --json --evaluate-patches --manifest=%s --patch-manifest=%s',
    escapeshellarg((string) PHP_BINARY),
    escapeshellarg($runner_path),
    escapeshellarg($manifest_path),
    escapeshellarg($patch_manifest_path)
);

$output = array();
$code = 0;
exec($cmd, $output, $code);
assert_true($code === 0, 'replay runner should exit cleanly in patch mode');
assert_true(!empty($output), 'replay runner should emit json output');

$payload = json_decode((string) implode("\n", $output), true);
assert_true(is_array($payload), 'runner output should be valid json');

$summary = isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : array();
$cases = isset($payload['cases']) && is_array($payload['cases']) ? $payload['cases'] : array();
$patch_eval = isset($summary['patch_evaluation']) && is_array($summary['patch_evaluation']) ? $summary['patch_evaluation'] : array();

assert_true(!empty($patch_eval['enabled']), 'patch evaluation summary should be enabled');
assert_true(max(0, (int) ($patch_eval['cases_with_patch'] ?? 0)) === 1, 'summary should report one patched case');
assert_true(isset($patch_eval['avg_false_positive_delta']), 'summary should include false positive delta');
assert_true(isset($patch_eval['avg_cleanup_burden_delta']), 'summary should include cleanup burden delta');
assert_true(isset($patch_eval['avg_grouped_control_projection_quality_delta']), 'summary should include grouped-control projection delta');
assert_true(isset($patch_eval['avg_approval_block_projection_quality_delta']), 'summary should include approval-block projection delta');
assert_true(isset($patch_eval['avg_semantic_hard_stop_generation_coverage_delta']), 'summary should include semantic hard-stop generation delta');
assert_true(isset($patch_eval['avg_patched_graph_to_draft_consistency_delta']), 'summary should include patched-graph consistency delta');
assert_true(isset($patch_eval['avg_digital_twin_hint_completeness_delta']), 'summary should include digital twin completeness delta');
assert_true(isset($patch_eval['by_category']) && is_array($patch_eval['by_category']), 'summary should include per-category patch rollups');
assert_true(isset($patch_eval['by_category']['false_positive_removal']), 'summary should include false_positive_removal category rollup');

assert_true(!empty($cases), 'runner should return case reports');
$case0 = isset($cases[0]) && is_array($cases[0]) ? $cases[0] : array();
assert_true(isset($case0['patch_evaluation']) && is_array($case0['patch_evaluation']), 'case report should include patch evaluation payload');
assert_true(isset($case0['patch_evaluation']['delta']) && is_array($case0['patch_evaluation']['delta']), 'case patch payload should include delta block');
assert_true(isset($case0['patch_evaluation']['validation']) && is_array($case0['patch_evaluation']['validation']), 'case patch payload should include validation block');
assert_true(isset($case0['patch_evaluation']['patch_categories']) && is_array($case0['patch_evaluation']['patch_categories']), 'case patch payload should include inferred categories');
assert_true(isset($case0['draft_projection_quality']) && is_array($case0['draft_projection_quality']), 'case report should expose draft projection quality payload');
assert_true(isset($case0['patch_evaluation']['delta']['grouped_control_projection_quality']), 'case patch delta should include grouped-control projection quality');
assert_true(isset($case0['patch_evaluation']['delta']['approval_block_projection_quality']), 'case patch delta should include approval-block projection quality');
assert_true(isset($case0['patch_evaluation']['delta']['semantic_hard_stop_generation_coverage']), 'case patch delta should include hard-stop generation coverage');
assert_true(isset($case0['patch_evaluation']['delta']['patched_graph_to_draft_consistency']), 'case patch delta should include patched-graph consistency');

$cmd_filter = sprintf(
    '%s %s --json --evaluate-patches --manifest=%s --patch-manifest=%s --patch-category=relation_correction',
    escapeshellarg((string) PHP_BINARY),
    escapeshellarg($runner_path),
    escapeshellarg($manifest_path),
    escapeshellarg($patch_manifest_path)
);
$output_filter = array();
$code_filter = 0;
exec($cmd_filter, $output_filter, $code_filter);
assert_true($code_filter === 0, 'category-filtered replay runner should exit cleanly');
assert_true(!empty($output_filter), 'category-filtered replay runner should emit json output');
$payload_filter = json_decode((string) implode("\n", $output_filter), true);
assert_true(is_array($payload_filter), 'category-filtered output should be valid json');
$summary_filter = isset($payload_filter['summary']) && is_array($payload_filter['summary']) ? $payload_filter['summary'] : array();
$patch_eval_filter = isset($summary_filter['patch_evaluation']) && is_array($summary_filter['patch_evaluation']) ? $summary_filter['patch_evaluation'] : array();
assert_true(max(0, (int) ($patch_eval_filter['cases_with_patch_available'] ?? 0)) === 1, 'category filter run should report one available patch case');
assert_true(max(0, (int) ($patch_eval_filter['cases_with_patch'] ?? 0)) === 0, 'non-matching category filter should skip applying patch case');

echo "ocr_replay_patch_mode_smoke:ok\n";
