<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}

require_once dirname(__DIR__) . '/includes/helpers-ops.php';

$matrix = dcb_ops_permissions_matrix(array(
    'administrator' => array('dcb_manage_forms', 'dcb_review_submissions', 'dcb_manage_workflows', 'dcb_manage_settings', 'dcb_run_ocr_tools'),
    'editor' => array('dcb_review_submissions', 'dcb_manage_workflows'),
));

if (!is_array($matrix) || count($matrix) < 5) {
    fwrite(STDERR, "permissions matrix missing rows\n");
    exit(1);
}

$ocr_row = null;
foreach ($matrix as $row) {
    if ((string) ($row['capability'] ?? '') === 'dcb_run_ocr_tools') {
        $ocr_row = $row;
        break;
    }
}

if (!is_array($ocr_row) || strpos((string) ($ocr_row['roles_label'] ?? ''), 'administrator') === false) {
    fwrite(STDERR, "ocr roles mismatch\n");
    exit(1);
}

echo "permissions_matrix_ops_smoke:ok\n";
