<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key));
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) {
        return trim((string) $text);
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = '') {
        return $default;
    }
}

require_once dirname(__DIR__) . '/includes/helpers-ocr.php';

$meta = dcb_ocr_extract_capture_meta(array(
    'input_source_type' => 'photo',
    'input_normalization' => array(
        'warnings' => array(
            array('code' => 'low_resolution_capture', 'message' => 'Low resolution'),
            array('code' => 'rotation_skew_risk', 'message' => 'Rotation risk'),
            array('code' => 'crop_border_risk', 'message' => 'Crop risk'),
        ),
        'capture_recommendations' => array(
            'Retake image at higher resolution.',
            'Retake image at higher resolution.',
            'Align page edges with frame.',
        ),
        'normalization_improvement_proxy' => 0.617,
    ),
));

if ((string) ($meta['input_source_type'] ?? '') !== 'photo') {
    fwrite(STDERR, "source type mismatch\n");
    exit(1);
}
if ((int) ($meta['capture_warning_count'] ?? -1) !== 3) {
    fwrite(STDERR, "warning count mismatch\n");
    exit(1);
}
if ((string) ($meta['capture_risk_bucket'] ?? '') !== 'high') {
    fwrite(STDERR, "risk bucket mismatch\n");
    exit(1);
}
if (!dcb_ocr_review_unresolved_capture_risk('pending_review', 2)) {
    fwrite(STDERR, "expected unresolved risk for pending review\n");
    exit(1);
}
if (dcb_ocr_review_unresolved_capture_risk('approved', 2)) {
    fwrite(STDERR, "approved should not be unresolved\n");
    exit(1);
}

echo "ocr_capture_diagnostics_smoke:ok\n";
