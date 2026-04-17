<?php

if (!defined('ABSPATH')) {
    exit;
}

function dcb_text_confidence_proxy(string $text): float {
    $text = trim($text);
    if ($text === '') {
        return 0.0;
    }
    $len = (float) strlen($text);
    $alnum = (float) preg_match_all('/[A-Za-z0-9]/', $text);
    $words = (float) preg_match_all('/\b[A-Za-z][A-Za-z0-9]{1,}\b/', $text);

    $density = $len > 0 ? min(1.0, $alnum / $len) : 0.0;
    $word_factor = min(1.0, $words / 120.0);
    $length_factor = min(1.0, $len / 1400.0);

    return max(0.0, min(1.0, (0.50 * $density) + (0.30 * $word_factor) + (0.20 * $length_factor)));
}

function dcb_confidence_bucket(float $score): string {
    if ($score >= 0.72) {
        return 'high';
    }
    if ($score >= 0.42) {
        return 'medium';
    }
    return 'low';
}

function dcb_ocr_shell_exec_enabled(): bool {
    if (!function_exists('shell_exec')) {
        return false;
    }

    $disabled = (string) ini_get('disable_functions');
    if ($disabled === '') {
        return true;
    }

    $disabled_list = array_map('trim', explode(',', $disabled));
    return !in_array('shell_exec', $disabled_list, true);
}

function dcb_ocr_normalize_candidate_path(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $parts = preg_split('/\r\n|\r|\n/', $value);
    if (is_array($parts) && isset($parts[0])) {
        $value = trim((string) $parts[0]);
    }
    return $value;
}

function dcb_ocr_resolve_candidate_to_executable(string $candidate): string {
    $candidate = dcb_ocr_normalize_candidate_path($candidate);
    if ($candidate === '') {
        return '';
    }

    if (strpos($candidate, '/') !== false) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
        return '';
    }

    if (!dcb_ocr_shell_exec_enabled()) {
        return '';
    }

    $found = @shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null');
    $resolved = dcb_ocr_normalize_candidate_path((string) $found);
    if ($resolved !== '' && is_file($resolved) && is_executable($resolved)) {
        return $resolved;
    }

    return '';
}

function dcb_ocr_resolve_binary_path(string $binary, string $constant_name, string $option_name, array $known_paths): array {
    $warnings = array();
    $shell_ok = dcb_ocr_shell_exec_enabled();
    $candidates = array();

    if ($constant_name !== '' && defined($constant_name)) {
        $constant_val = dcb_ocr_normalize_candidate_path((string) constant($constant_name));
        if ($constant_val !== '') {
            $candidates[] = array('source' => 'constant', 'label' => $constant_name, 'value' => $constant_val);
        }
    }

    if ($option_name !== '') {
        $option_val = dcb_ocr_normalize_candidate_path((string) get_option($option_name, ''));
        if ($option_val !== '') {
            $candidates[] = array('source' => 'option', 'label' => $option_name, 'value' => $option_val);
        }
    }

    foreach ($known_paths as $known_path) {
        $known_path = dcb_ocr_normalize_candidate_path((string) $known_path);
        if ($known_path !== '') {
            $candidates[] = array('source' => 'known_path', 'label' => $known_path, 'value' => $known_path);
        }
    }

    if ($shell_ok) {
        $found = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        $fallback_path = dcb_ocr_normalize_candidate_path((string) $found);
        if ($fallback_path !== '') {
            $candidates[] = array('source' => 'command_v', 'label' => 'command -v ' . $binary, 'value' => $fallback_path);
        }
    }

    foreach ($candidates as $candidate) {
        $path = dcb_ocr_resolve_candidate_to_executable((string) ($candidate['value'] ?? ''));
        if ($path !== '') {
            return array(
                'binary' => $binary,
                'path' => $path,
                'source' => (string) ($candidate['source'] ?? 'unknown'),
                'source_label' => (string) ($candidate['label'] ?? ''),
                'warnings' => $warnings,
            );
        }

        if (($candidate['source'] ?? '') === 'constant' || ($candidate['source'] ?? '') === 'option') {
            $warnings[] = sprintf('%s override is set but not executable: %s', (string) ($candidate['label'] ?? 'override'), (string) ($candidate['value'] ?? ''));
        }
    }

    if (!$shell_ok) {
        $warnings[] = 'shell_exec is disabled; PATH fallback (command -v) is unavailable.';
    }

    return array(
        'binary' => $binary,
        'path' => '',
        'source' => 'none',
        'source_label' => '',
        'warnings' => $warnings,
    );
}

function dcb_ocr_get_binary_resolution(string $binary): array {
    static $cache = array();
    if (isset($cache[$binary]) && is_array($cache[$binary])) {
        return $cache[$binary];
    }

    if ($binary === 'tesseract') {
        $cache[$binary] = dcb_ocr_resolve_binary_path('tesseract', 'DCB_TESSERACT_PATH', 'dcb_upload_tesseract_path', array('/usr/bin/tesseract'));
    } elseif ($binary === 'pdftotext') {
        $cache[$binary] = dcb_ocr_resolve_binary_path('pdftotext', 'DCB_PDFTOTEXT_PATH', 'dcb_upload_pdftotext_path', array('/usr/bin/pdftotext'));
    } elseif ($binary === 'pdftoppm') {
        $cache[$binary] = dcb_ocr_resolve_binary_path('pdftoppm', 'DCB_PDFTOPPM_PATH', 'dcb_upload_pdftoppm_path', array('/usr/bin/pdftoppm'));
    } else {
        $cache[$binary] = dcb_ocr_resolve_binary_path($binary, '', '', array());
    }

    return $cache[$binary];
}

function dcb_ocr_get_tesseract_path(): string {
    return (string) (dcb_ocr_get_binary_resolution('tesseract')['path'] ?? '');
}

function dcb_ocr_get_pdftotext_path(): string {
    return (string) (dcb_ocr_get_binary_resolution('pdftotext')['path'] ?? '');
}

function dcb_ocr_get_pdftoppm_path(): string {
    return (string) (dcb_ocr_get_binary_resolution('pdftoppm')['path'] ?? '');
}

function dcb_ocr_binary_execution_check(string $path): array {
    $shell_ok = dcb_ocr_shell_exec_enabled();
    $exists = $path !== '' && file_exists($path);
    $executable = $exists && is_executable($path);
    $warnings = array();

    if (!$shell_ok) {
        $warnings[] = 'shell_exec is disabled in PHP.';
    }
    if ($path === '') {
        $warnings[] = 'Binary path not resolved.';
    }
    if ($path !== '' && !$exists) {
        $warnings[] = 'Binary path does not exist.';
    }
    if ($exists && !$executable) {
        $warnings[] = 'Binary exists but is not executable by PHP/web user context.';
    }

    return array(
        'shell_exec_enabled' => $shell_ok,
        'exists' => $exists,
        'executable' => $executable,
        'ready' => $shell_ok && $exists && $executable,
        'warnings' => $warnings,
    );
}

function dcb_ocr_exec_binary(string $binary_path, array $args = array(), bool $capture_stderr = true): array {
    $check = dcb_ocr_binary_execution_check($binary_path);
    if (empty($check['ready'])) {
        return array('ok' => false, 'output' => '', 'warnings' => (array) ($check['warnings'] ?? array()));
    }

    $command = escapeshellarg($binary_path);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string) $arg);
    }
    $command .= $capture_stderr ? ' 2>&1' : ' 2>/dev/null';

    $output = @shell_exec($command);
    if (!is_string($output)) {
        return array('ok' => false, 'output' => '', 'warnings' => array('Command execution returned no output and may have failed.'));
    }

    return array('ok' => true, 'output' => (string) $output, 'warnings' => array());
}

function dcb_upload_is_command_available(string $command): bool {
    if (!dcb_ocr_shell_exec_enabled()) {
        return false;
    }
    $found = @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
    return is_string($found) && trim($found) !== '';
}

function dcb_upload_normalize_text(string $text): string {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]+/i', ' ', $text);
    $text = preg_replace('/\s+/', ' ', (string) $text);
    return trim((string) $text);
}

function dcb_upload_ocr_debug_log_option_key(): string {
    return 'dcb_upload_ocr_image_debug_log';
}

function dcb_upload_ocr_debug_runtime_warnings_add(string $code, string $message): void {
    if (!isset($GLOBALS['dcb_upload_runtime_ocr_warnings']) || !is_array($GLOBALS['dcb_upload_runtime_ocr_warnings'])) {
        $GLOBALS['dcb_upload_runtime_ocr_warnings'] = array();
    }

    $GLOBALS['dcb_upload_runtime_ocr_warnings'][] = array('code' => sanitize_key($code), 'message' => sanitize_text_field($message));
}

function dcb_upload_ocr_debug_runtime_warnings_get(): array {
    $rows = isset($GLOBALS['dcb_upload_runtime_ocr_warnings']) && is_array($GLOBALS['dcb_upload_runtime_ocr_warnings'])
        ? $GLOBALS['dcb_upload_runtime_ocr_warnings']
        : array();

    $clean = array();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $clean[] = array(
            'code' => sanitize_key((string) ($row['code'] ?? 'ocr_warning')),
            'message' => sanitize_text_field((string) ($row['message'] ?? 'OCR warning')),
        );
    }
    return $clean;
}

function dcb_upload_ocr_debug_log_add(array $entry): void {
    $logs = get_option(dcb_upload_ocr_debug_log_option_key(), array());
    if (!is_array($logs)) {
        $logs = array();
    }

    $clean = array(
        'time' => sanitize_text_field((string) ($entry['time'] ?? current_time('mysql'))),
        'engine' => sanitize_key((string) ($entry['engine'] ?? 'tesseract-image')),
        'source_file_path' => sanitize_text_field((string) ($entry['source_file_path'] ?? '')),
        'mime' => sanitize_text_field((string) ($entry['mime'] ?? '')),
        'extension' => sanitize_key((string) ($entry['extension'] ?? '')),
        'temp_output_base' => sanitize_text_field((string) ($entry['temp_output_base'] ?? '')),
        'temp_output_txt' => sanitize_text_field((string) ($entry['temp_output_txt'] ?? '')),
        'tesseract_path' => sanitize_text_field((string) ($entry['tesseract_path'] ?? '')),
        'command' => sanitize_text_field((string) ($entry['command'] ?? '')),
        'exit_status' => isset($entry['exit_status']) && is_numeric($entry['exit_status']) ? (int) $entry['exit_status'] : null,
        'stdout_stderr_snippet' => sanitize_text_field((string) ($entry['stdout_stderr_snippet'] ?? '')),
        'output_file_exists' => !empty($entry['output_file_exists']) ? 1 : 0,
        'output_file_readable' => !empty($entry['output_file_readable']) ? 1 : 0,
        'extracted_text_length' => max(0, (int) ($entry['extracted_text_length'] ?? 0)),
        'confidence_proxy' => isset($entry['confidence_proxy']) && is_numeric($entry['confidence_proxy']) ? round(max(0, min(1, (float) $entry['confidence_proxy'])), 4) : 0,
        'warning_code' => sanitize_key((string) ($entry['warning_code'] ?? '')),
        'warning_message' => sanitize_text_field((string) ($entry['warning_message'] ?? '')),
    );

    $logs[] = $clean;
    if (count($logs) > 30) {
        $logs = array_slice($logs, -30);
    }

    update_option(dcb_upload_ocr_debug_log_option_key(), $logs, false);
}

function dcb_upload_ocr_debug_log_recent(int $limit = 8): array {
    $logs = get_option(dcb_upload_ocr_debug_log_option_key(), array());
    if (!is_array($logs) || empty($logs)) {
        return array();
    }

    $limit = max(1, min(20, $limit));
    return array_reverse(array_slice($logs, -$limit));
}

function dcb_upload_ocr_image_ext_supported(string $extension): bool {
    $extension = strtolower(trim($extension));
    return in_array($extension, array('jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'bmp', 'gif'), true);
}

function dcb_upload_ocr_image_capture_with_status(string $command): array {
    $output = '';
    $exit_status = null;

    if (function_exists('exec') && dcb_ocr_shell_exec_enabled()) {
        $lines = array();
        @exec($command, $lines, $exit_status);
        $output = is_array($lines) ? implode("\n", $lines) : '';
        return array('output' => trim((string) $output), 'exit_status' => is_numeric($exit_status) ? (int) $exit_status : null);
    }

    $raw = @shell_exec($command);
    if (is_string($raw)) {
        $output = trim($raw);
    }

    return array('output' => $output, 'exit_status' => null);
}

function dcb_upload_ocr_detect_source_type(array $inspection, string $file_path): string {
    $kind = sanitize_key((string) ($inspection['kind'] ?? 'other'));
    if ($kind === 'pdf') {
        return 'pdf';
    }
    if ($kind !== 'image') {
        return $kind !== '' ? $kind : 'other';
    }

    $name = strtolower((string) basename($file_path));
    if (preg_match('/\b(img|scan|camera|photo|screenshot|dsc)_?\d*/', $name)) {
        return 'photo';
    }

    return 'image';
}

function dcb_upload_ocr_capture_quality_from_image(string $image_path): array {
    $warnings = array();
    $stats = array(
        'width' => 0,
        'height' => 0,
        'pixel_count' => 0,
        'quality_bucket' => 'unknown',
        'low_resolution_risk' => false,
        'low_contrast_risk' => false,
        'dark_capture_risk' => false,
        'rotation_skew_risk' => false,
        'crop_border_risk' => false,
        'contrast_proxy' => 0.0,
        'luma_mean' => 0.0,
    );

    if ($image_path === '' || !file_exists($image_path) || !is_readable($image_path)) {
        $warnings[] = array('code' => 'image_unreadable', 'message' => 'Source image is unreadable for quality checks.');
        return array('stats' => $stats, 'warnings' => $warnings);
    }

    $image_size = @getimagesize($image_path);
    if (!is_array($image_size) || empty($image_size[0]) || empty($image_size[1])) {
        $warnings[] = array('code' => 'image_dimensions_unknown', 'message' => 'Could not read image dimensions for OCR quality checks.');
        return array('stats' => $stats, 'warnings' => $warnings);
    }

    $width = max(1, (int) $image_size[0]);
    $height = max(1, (int) $image_size[1]);
    $pixel_count = $width * $height;
    $short_edge = min($width, $height);
    $long_edge = max($width, $height);

    if ($short_edge < 900) {
        $warnings[] = array('code' => 'low_resolution_capture', 'message' => 'Image short edge is low; OCR accuracy may be reduced.');
    }

    $ratio = $short_edge / max(1, max($width, $height));
    if ($ratio < 0.58) {
        $warnings[] = array('code' => 'photo_aspect_risk', 'message' => 'Image aspect ratio suggests camera capture; verify perspective/rotation in review.');
    }

    if ($width > ($height * 1.55) || $height > ($width * 1.90)) {
        $warnings[] = array('code' => 'rotation_skew_risk', 'message' => 'Image orientation/aspect may indicate rotation or skew risk.');
    }
    if ($ratio < 0.46 || $long_edge > ($short_edge * 2.5)) {
        $warnings[] = array('code' => 'crop_border_risk', 'message' => 'Image framing may be tightly cropped or include excess borders.');
    }

    $luma = array('ok' => false, 'mean' => 0.0, 'stddev' => 0.0);
    $ext = strtolower((string) pathinfo($image_path, PATHINFO_EXTENSION));
    $gd_ok = function_exists('imagecreatetruecolor');
    if ($gd_ok) {
        $im = null;
        if (($ext === 'jpg' || $ext === 'jpeg' || $ext === 'jfif') && function_exists('imagecreatefromjpeg')) {
            $im = @imagecreatefromjpeg($image_path);
        } elseif ($ext === 'png' && function_exists('imagecreatefrompng')) {
            $im = @imagecreatefrompng($image_path);
        } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $im = @imagecreatefromwebp($image_path);
        }

        if (is_resource($im) || (is_object($im) && get_class($im) === 'GdImage')) {
            $sample_x = max(8, min(28, (int) floor($width / 120)));
            $sample_y = max(8, min(28, (int) floor($height / 120)));
            $vals = array();
            for ($x = 0; $x < $sample_x; $x++) {
                for ($y = 0; $y < $sample_y; $y++) {
                    $px = (int) floor(($x + 0.5) * $width / max(1, $sample_x));
                    $py = (int) floor(($y + 0.5) * $height / max(1, $sample_y));
                    $rgb = @imagecolorat($im, min($width - 1, max(0, $px)), min($height - 1, max(0, $py)));
                    if (!is_int($rgb)) {
                        continue;
                    }
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $vals[] = (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
                }
            }
            if (!empty($vals)) {
                $mean = array_sum($vals) / count($vals);
                $var = 0.0;
                foreach ($vals as $v) {
                    $var += ($v - $mean) * ($v - $mean);
                }
                $stddev = sqrt($var / max(1, count($vals)));
                $luma = array('ok' => true, 'mean' => round($mean, 2), 'stddev' => round($stddev, 2));
            }
            @imagedestroy($im);
        }
    }

    if (!empty($luma['ok'])) {
        $mean = (float) ($luma['mean'] ?? 0.0);
        $stddev = (float) ($luma['stddev'] ?? 0.0);
        if ($mean < 78) {
            $warnings[] = array('code' => 'dark_capture_risk', 'message' => 'Image appears dark; increase lighting for better OCR fidelity.');
        }
        if ($stddev < 24) {
            $warnings[] = array('code' => 'low_contrast_risk', 'message' => 'Image appears low contrast; OCR may miss labels and anchors.');
        }
        $stats['contrast_proxy'] = round(max(0.0, min(1.0, $stddev / 64.0)), 4);
        $stats['luma_mean'] = round(max(0.0, min(255.0, $mean)), 2);
    }

    $quality_bucket = 'high';
    if ($short_edge < 900 || $pixel_count < 1200000) {
        $quality_bucket = 'medium';
    }
    if ($short_edge < 650 || $pixel_count < 600000) {
        $quality_bucket = 'low';
    }

    $stats = array(
        'width' => $width,
        'height' => $height,
        'pixel_count' => $pixel_count,
        'quality_bucket' => $quality_bucket,
        'low_resolution_risk' => $short_edge < 900,
        'low_contrast_risk' => !empty(array_filter($warnings, static function ($row) {
            return is_array($row) && (string) ($row['code'] ?? '') === 'low_contrast_risk';
        })),
        'dark_capture_risk' => !empty(array_filter($warnings, static function ($row) {
            return is_array($row) && (string) ($row['code'] ?? '') === 'dark_capture_risk';
        })),
        'rotation_skew_risk' => !empty(array_filter($warnings, static function ($row) {
            return is_array($row) && (string) ($row['code'] ?? '') === 'rotation_skew_risk';
        })),
        'crop_border_risk' => !empty(array_filter($warnings, static function ($row) {
            return is_array($row) && (string) ($row['code'] ?? '') === 'crop_border_risk';
        })),
        'contrast_proxy' => isset($stats['contrast_proxy']) ? (float) $stats['contrast_proxy'] : 0.0,
        'luma_mean' => isset($stats['luma_mean']) ? (float) $stats['luma_mean'] : 0.0,
    );

    return array('stats' => $stats, 'warnings' => $warnings);
}

function dcb_upload_ocr_capture_recommendations_from_warnings(array $warnings): array {
    $recommendations = array();
    foreach ($warnings as $warning) {
        if (!is_array($warning)) {
            continue;
        }
        $code = sanitize_key((string) ($warning['code'] ?? ''));
        if ($code === 'low_resolution_capture') {
            $recommendations[] = 'Retake image at higher resolution or closer framing.';
        } elseif ($code === 'dark_capture_risk') {
            $recommendations[] = 'Increase lighting and avoid shadows during capture.';
        } elseif ($code === 'low_contrast_risk') {
            $recommendations[] = 'Improve contrast or use scanner mode for clearer text boundaries.';
        } elseif ($code === 'rotation_skew_risk') {
            $recommendations[] = 'Align page edges with camera frame before capture.';
        } elseif ($code === 'crop_border_risk') {
            $recommendations[] = 'Include full page borders; avoid clipping labels/signature lines.';
        } elseif ($code === 'photo_aspect_risk') {
            $recommendations[] = 'Capture from directly above to reduce perspective distortion.';
        } elseif ($code === 'rasterization_coverage_warning') {
            $recommendations[] = 'Verify all PDF pages were rasterized and processed.';
        }
    }

    return array_values(array_unique($recommendations));
}

function dcb_upload_stage_preprocess_image_file(string $source_path, string $dest_path, int $max_dimension = 2200): array {
    $operations = array('orientation_correction', 'deskew', 'crop_cleanup', 'contrast_cleanup', 'max_dimension_normalization');
    $warnings = array();
    $source_size = @getimagesize($source_path);
    $source_w = is_array($source_size) ? max(1, (int) ($source_size[0] ?? 0)) : 0;
    $source_h = is_array($source_size) ? max(1, (int) ($source_size[1] ?? 0)) : 0;
    $stage_diagnostics = array(
        'orientation_correction' => array('attempted' => false, 'applied' => false),
        'deskew' => array('attempted' => false, 'applied' => false),
        'crop_cleanup' => array('attempted' => false, 'applied' => false),
        'contrast_cleanup' => array('attempted' => false, 'applied' => false),
        'max_dimension_normalization' => array('attempted' => false, 'applied' => false),
    );

    $max_dimension = max(900, min(4200, $max_dimension));
    $magick = dcb_upload_is_command_available('magick') ? 'magick' : (dcb_upload_is_command_available('convert') ? 'convert' : '');

    if ($magick === '') {
        $copied = @copy($source_path, $dest_path);
        if (!$copied) {
            return array(
                'ok' => false,
                'path' => $source_path,
                'processor' => 'none',
                'operations' => array(),
                'stage_diagnostics' => $stage_diagnostics,
                'warnings' => array(array('code' => 'normalization_tool_missing', 'message' => 'Image normalization tool unavailable; using original input.')),
                'dimension_reduction_ratio' => 0.0,
            );
        }

        return array(
            'ok' => true,
            'path' => $dest_path,
            'processor' => 'copy',
            'operations' => array('copy_passthrough'),
            'stage_diagnostics' => $stage_diagnostics,
            'warnings' => array(array('code' => 'normalization_tool_missing', 'message' => 'ImageMagick not found; OCR input preprocessing is limited.')),
            'dimension_reduction_ratio' => 0.0,
        );
    }

    foreach (array_keys($stage_diagnostics) as $stage_key) {
        $stage_diagnostics[$stage_key]['attempted'] = true;
    }

    $cmd = $magick
        . ' ' . escapeshellarg($source_path)
        . ' -auto-orient'
        . ' -colorspace Gray'
        . ' -contrast-stretch 1%x1%'
        . ' -deskew 40%'
        . ' -trim +repage'
        . ' -resize ' . escapeshellarg((string) $max_dimension . 'x' . (string) $max_dimension . '>')
        . ' -background white -alpha remove -alpha off'
        . ' -quality 92'
        . ' ' . escapeshellarg($dest_path)
        . ' 2>/dev/null';

    $run = dcb_upload_ocr_image_capture_with_status($cmd);
    $ok = file_exists($dest_path) && filesize($dest_path) > 0;
    if (!$ok) {
        $warnings[] = array('code' => 'normalization_failed', 'message' => 'Image preprocessing failed; using original source for OCR.');
        return array(
            'ok' => false,
            'path' => $source_path,
            'processor' => sanitize_key($magick),
            'operations' => $operations,
            'stage_diagnostics' => $stage_diagnostics,
            'warnings' => $warnings,
            'debug' => array('exit_status' => $run['exit_status'] ?? null, 'output' => sanitize_text_field((string) ($run['output'] ?? ''))),
            'dimension_reduction_ratio' => 0.0,
        );
    }

    $dest_size = @getimagesize($dest_path);
    $dest_w = is_array($dest_size) ? max(1, (int) ($dest_size[0] ?? 0)) : 0;
    $dest_h = is_array($dest_size) ? max(1, (int) ($dest_size[1] ?? 0)) : 0;
    $source_area = max(1, $source_w * $source_h);
    $dest_area = max(1, $dest_w * $dest_h);
    $dimension_reduction_ratio = $source_area > 0 ? round(max(0.0, min(1.0, 1.0 - ($dest_area / $source_area))), 4) : 0.0;
    foreach (array_keys($stage_diagnostics) as $stage_key) {
        $stage_diagnostics[$stage_key]['applied'] = true;
    }
    if ($source_w > 0 && $source_h > 0 && max($source_w, $source_h) <= $max_dimension) {
        $stage_diagnostics['max_dimension_normalization']['applied'] = false;
    }

    return array(
        'ok' => true,
        'path' => $dest_path,
        'processor' => sanitize_key($magick),
        'operations' => $operations,
        'stage_diagnostics' => $stage_diagnostics,
        'warnings' => $warnings,
        'debug' => array('exit_status' => $run['exit_status'] ?? null),
        'dimension_reduction_ratio' => $dimension_reduction_ratio,
    );
}

function dcb_upload_stage_input_normalization(string $file_path, array $inspection, array $rasterized = array()): array {
    $enabled = (string) get_option('dcb_ocr_input_normalization_enabled', '1') !== '0';
    $max_dimension = max(900, min(4200, (int) get_option('dcb_ocr_input_max_dimension', 2200)));
    $source_type = dcb_upload_ocr_detect_source_type($inspection, $file_path);

    $out = array(
        'enabled' => $enabled,
        'source_type' => $source_type,
        'max_dimension' => $max_dimension,
        'pages' => array(),
        'stages' => array('orientation_correction', 'deskew', 'crop_cleanup', 'contrast_cleanup', 'pdf_rasterization', 'max_dimension_normalization'),
        'warnings' => array(),
        'cleanup_paths' => array(),
        'quality' => array(),
        'capture_recommendations' => array(),
        'stage_application_counts' => array(),
        'stage_attempt_counts' => array(),
        'average_warning_count' => 0.0,
        'normalization_improvement_proxy' => 0.0,
    );

    if (!$enabled) {
        return $out;
    }

    $temp_token = function_exists('wp_generate_password') ? wp_generate_password(10, false, false) : uniqid('ocr_norm_', true);
    $work_dir = trailingslashit(sys_get_temp_dir()) . 'dcb_ocr_norm_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $temp_token);
    wp_mkdir_p($work_dir);

    $input_pages = array();
    if (!empty($inspection['is_image'])) {
        $input_pages[] = array('page_number' => 1, 'path' => $file_path, 'source' => 'upload_image');
    }

    $raster_pages = isset($rasterized['pages']) && is_array($rasterized['pages']) ? $rasterized['pages'] : array();
    foreach ($raster_pages as $raster) {
        if (!is_array($raster)) {
            continue;
        }
        $path = (string) ($raster['path'] ?? '');
        if ($path === '') {
            continue;
        }
        $input_pages[] = array(
            'page_number' => max(1, (int) ($raster['page_number'] ?? (count($input_pages) + 1))),
            'path' => $path,
            'source' => 'pdf_raster_page',
        );
    }

    foreach ($input_pages as $idx => $page) {
        if (!is_array($page)) {
            continue;
        }
        $page_number = max(1, (int) ($page['page_number'] ?? ($idx + 1)));
        $source_path = (string) ($page['path'] ?? '');
        if ($source_path === '' || !file_exists($source_path)) {
            continue;
        }

        $dest_path = $work_dir . '/page-' . sprintf('%03d', $page_number) . '.png';
        $normalized = dcb_upload_stage_preprocess_image_file($source_path, $dest_path, $max_dimension);

        $quality = dcb_upload_ocr_capture_quality_from_image((string) ($normalized['path'] ?? $source_path));
        $quality_stats = isset($quality['stats']) && is_array($quality['stats']) ? $quality['stats'] : array();
        $quality_warnings = isset($quality['warnings']) && is_array($quality['warnings']) ? $quality['warnings'] : array();
        $stage_diagnostics = isset($normalized['stage_diagnostics']) && is_array($normalized['stage_diagnostics']) ? $normalized['stage_diagnostics'] : array();

        foreach ((array) ($normalized['warnings'] ?? array()) as $warning) {
            if (is_array($warning)) {
                $out['warnings'][] = array('code' => sanitize_key((string) ($warning['code'] ?? 'normalization_warning')), 'message' => sanitize_text_field((string) ($warning['message'] ?? 'Normalization warning.')));
            }
        }
        foreach ($quality_warnings as $warning) {
            if (is_array($warning)) {
                $out['warnings'][] = array('code' => sanitize_key((string) ($warning['code'] ?? 'capture_quality_warning')), 'message' => sanitize_text_field((string) ($warning['message'] ?? 'Capture quality warning.')));
            }
        }

        $normalized_path = (string) ($normalized['path'] ?? $source_path);
        $out['pages'][] = array(
            'page_number' => $page_number,
            'path' => $normalized_path,
            'source_path' => $source_path,
            'source' => sanitize_key((string) ($page['source'] ?? 'upload')),
            'processor' => sanitize_key((string) ($normalized['processor'] ?? 'none')),
            'operations' => array_values(array_filter(array_map('sanitize_key', (array) ($normalized['operations'] ?? array())))),
            'stage_diagnostics' => $stage_diagnostics,
            'dimension_reduction_ratio' => round(max(0.0, min(1.0, (float) ($normalized['dimension_reduction_ratio'] ?? 0.0))), 4),
            'quality' => $quality_stats,
            'capture_warnings' => $quality_warnings,
        );

        if (function_exists('apply_filters')) {
            $out['pages'][count($out['pages']) - 1] = (array) apply_filters('dcb_ocr_input_normalization_page', $out['pages'][count($out['pages']) - 1], $inspection, $normalized, $source_path);
        }

        $out['quality'][] = array_merge(array('page_number' => $page_number), $quality_stats);
        foreach ($stage_diagnostics as $stage_key => $stage_meta) {
            $stage = sanitize_key((string) $stage_key);
            if ($stage === '') {
                continue;
            }
            if (!isset($out['stage_attempt_counts'][$stage])) {
                $out['stage_attempt_counts'][$stage] = 0;
            }
            if (!isset($out['stage_application_counts'][$stage])) {
                $out['stage_application_counts'][$stage] = 0;
            }
            if (!empty($stage_meta['attempted'])) {
                $out['stage_attempt_counts'][$stage]++;
            }
            if (!empty($stage_meta['applied'])) {
                $out['stage_application_counts'][$stage]++;
            }
        }

        if (function_exists('apply_filters')) {
            $out['stage_application_counts'] = (array) apply_filters('dcb_ocr_input_normalization_stage_counts', $out['stage_application_counts'], $out['stage_attempt_counts'], $out['pages'][count($out['pages']) - 1], $inspection);
        }

        if ($normalized_path !== $source_path && strpos($normalized_path, $work_dir . '/') === 0) {
            $out['cleanup_paths'][] = $normalized_path;
        }
    }

    $warning_count = count($out['warnings']);
    $page_count = max(1, count($out['pages']));
    $out['average_warning_count'] = round($warning_count / $page_count, 4);
    $dim_ratios = array();
    foreach ((array) $out['pages'] as $norm_page) {
        if (!is_array($norm_page)) {
            continue;
        }
        $dim_ratios[] = (float) ($norm_page['dimension_reduction_ratio'] ?? 0.0);
    }
    $out['normalization_improvement_proxy'] = !empty($dim_ratios)
        ? round(array_sum($dim_ratios) / max(1, count($dim_ratios)), 4)
        : 0.0;
    $out['capture_recommendations'] = dcb_upload_ocr_capture_recommendations_from_warnings((array) $out['warnings']);

    if (!empty($raster_pages)) {
        if (count($raster_pages) !== count($out['pages']) && !empty($inspection['is_pdf'])) {
            $out['warnings'][] = array('code' => 'rasterization_coverage_warning', 'message' => 'Rasterized PDF pages and normalized pages differ; verify page-split coverage.');
        }
        $out['rasterization_coverage'] = round(count($out['pages']) / max(1, count($raster_pages)), 4);
    }

    if (empty($out['pages']) && is_dir($work_dir)) {
        @rmdir($work_dir);
    }

    if (function_exists('apply_filters')) {
        $out = (array) apply_filters('dcb_ocr_input_normalization_result', $out, $inspection, $file_path);
    }

    return $out;
}

function dcb_upload_stage_cleanup_normalized_images(array $normalization): void {
    $paths = isset($normalization['cleanup_paths']) && is_array($normalization['cleanup_paths']) ? $normalization['cleanup_paths'] : array();
    foreach ($paths as $path) {
        $file = sanitize_text_field((string) $path);
        if ($file !== '' && file_exists($file)) {
            @unlink($file);
        }
    }

    foreach ((array) ($normalization['pages'] ?? array()) as $page) {
        if (!is_array($page)) {
            continue;
        }
        $normalized_path = sanitize_text_field((string) ($page['path'] ?? ''));
        $source_path = sanitize_text_field((string) ($page['source_path'] ?? ''));
        if ($normalized_path !== '' && $normalized_path !== $source_path && file_exists($normalized_path)) {
            @unlink($normalized_path);
        }
    }

    if (!empty($paths)) {
        $first = sanitize_text_field((string) $paths[0]);
        $dir = dirname($first);
        if ($dir !== '' && is_dir($dir)) {
            @rmdir($dir);
        }
    }
}

function dcb_upload_read_docx_text(string $file_path): string {
    if (!class_exists('ZipArchive')) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($file_path) !== true) {
        return '';
    }
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === '') {
        return '';
    }
    $xml = preg_replace('/<w:p[^>]*>/', "\n", $xml);
    $text = wp_strip_all_tags((string) $xml, true);
    return trim((string) $text);
}

function dcb_upload_allowed_mimes(): array {
    return array(
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jfif' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
    );
}

function dcb_upload_stage_file_type_inspection(string $file_path, string $mime): array {
    $ext = strtolower((string) pathinfo($file_path, PATHINFO_EXTENSION));
    $safe_mime = strtolower(trim($mime));

    if ($safe_mime === '' && function_exists('mime_content_type')) {
        $detected = @mime_content_type($file_path);
        if (is_string($detected)) {
            $safe_mime = strtolower(trim($detected));
        }
    }

    $is_pdf = strpos($safe_mime, 'application/pdf') !== false || $ext === 'pdf';
    $is_image = strpos($safe_mime, 'image/') === 0 || in_array($ext, array('jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'jfif'), true);
    $is_docx = strpos($safe_mime, 'officedocument.wordprocessingml.document') !== false || $ext === 'docx';
    $is_text = in_array($safe_mime, array('text/plain', 'text/csv'), true) || in_array($ext, array('txt', 'csv'), true);

    $kind = 'other';
    if ($is_pdf) {
        $kind = 'pdf';
    } elseif ($is_image) {
        $kind = 'image';
    } elseif ($is_docx) {
        $kind = 'docx';
    } elseif ($is_text) {
        $kind = 'text';
    }

    return array('kind' => $kind, 'mime' => $safe_mime !== '' ? $safe_mime : $mime, 'ext' => $ext, 'is_pdf' => $is_pdf, 'is_image' => $is_image);
}

function dcb_upload_stage_text_extraction_pdf(string $file_path): array {
    $pdftotext_path = dcb_ocr_get_pdftotext_path();
    if ($pdftotext_path === '') {
        return array('pages' => array(), 'text' => '', 'engine' => 'none');
    }

    $run = dcb_ocr_exec_binary($pdftotext_path, array('-nopgbrk', $file_path, '-'), false);
    $text = !empty($run['ok']) ? trim((string) ($run['output'] ?? '')) : '';
    if ($text === '') {
        return array('pages' => array(), 'text' => '', 'engine' => 'pdftotext');
    }

    $raw_pages = preg_split('/\f+/', str_replace("\r", "\n", $text));
    if (!is_array($raw_pages) || empty($raw_pages)) {
        $raw_pages = array($text);
    }

    $pages = array();
    $combined = array();
    foreach ($raw_pages as $idx => $page_text) {
        $page_clean = trim((string) $page_text);
        if ($page_clean === '') {
            continue;
        }
        $proxy = dcb_text_confidence_proxy($page_clean);
        $pages[] = array(
            'page_number' => (int) $idx + 1,
            'engine' => 'pdftotext',
            'text' => $page_clean,
            'text_length' => strlen($page_clean),
            'confidence_proxy' => round($proxy, 4),
        );
        $combined[] = $page_clean;
    }

    return array('pages' => $pages, 'text' => implode("\n\n", $combined), 'engine' => 'pdftotext');
}

function dcb_upload_stage_text_extraction(string $file_path, array $inspection): array {
    $kind = (string) ($inspection['kind'] ?? 'other');

    if ($kind === 'text') {
        $raw = @file_get_contents($file_path, false, null, 0, 1024 * 256);
        $text = is_string($raw) ? trim($raw) : '';
        return array(
            'pages' => array(array('page_number' => 1, 'engine' => 'native-text', 'text' => $text, 'text_length' => strlen($text), 'confidence_proxy' => round(dcb_text_confidence_proxy($text), 4))),
            'text' => $text,
            'engine' => 'native-text',
        );
    }

    if ($kind === 'docx') {
        $text = dcb_upload_read_docx_text($file_path);
        return array(
            'pages' => array(array('page_number' => 1, 'engine' => 'docx-xml', 'text' => $text, 'text_length' => strlen($text), 'confidence_proxy' => round(dcb_text_confidence_proxy($text), 4))),
            'text' => $text,
            'engine' => $text !== '' ? 'docx-xml' : 'none',
        );
    }

    if ($kind === 'pdf') {
        return dcb_upload_stage_text_extraction_pdf($file_path);
    }

    return array('pages' => array(), 'text' => '', 'engine' => 'none');
}

function dcb_upload_stage_pdf_text_is_weak(array $pages, string $combined_text): bool {
    $combined_norm = dcb_upload_normalize_text($combined_text);
    if (strlen($combined_norm) < 140) {
        return true;
    }

    if (empty($pages)) {
        return true;
    }

    $non_empty_pages = 0;
    $proxy_total = 0.0;
    foreach ($pages as $page) {
        $len = (int) ($page['text_length'] ?? 0);
        if ($len > 20) {
            $non_empty_pages++;
        }
        $proxy_total += (float) ($page['confidence_proxy'] ?? 0);
    }

    $page_count = max(1, count($pages));
    $proxy_avg = $proxy_total / $page_count;

    return $non_empty_pages < max(1, (int) floor($page_count * 0.45)) || $proxy_avg < 0.20;
}

function dcb_upload_stage_page_rasterization(string $file_path, array $inspection, int $max_pages = 12): array {
    if (empty($inspection['is_pdf'])) {
        return array('pages' => array(), 'work_dir' => '', 'engine' => 'none');
    }

    $token = function_exists('wp_generate_password') ? wp_generate_password(10, false, false) : uniqid('ocr_', true);
    $work_dir = trailingslashit(sys_get_temp_dir()) . 'dcb_pdf_ocr_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $token);
    wp_mkdir_p($work_dir);

    $images = array();
    $engine = 'none';

    $pdftoppm_path = dcb_ocr_get_pdftoppm_path();
    if ($pdftoppm_path !== '') {
        $prefix = $work_dir . '/page';
        dcb_ocr_exec_binary($pdftoppm_path, array('-f', '1', '-l', (string) ((int) $max_pages), '-r', '220', '-png', $file_path, $prefix), false);
        $glob = glob($prefix . '-*.png');
        if (is_array($glob) && !empty($glob)) {
            natsort($glob);
            foreach ($glob as $file) {
                if (!is_string($file) || !file_exists($file)) {
                    continue;
                }
                $page_number = 1;
                if (preg_match('/-(\d+)\.png$/', $file, $m)) {
                    $page_number = max(1, (int) $m[1]);
                }
                $images[] = array('page_number' => $page_number, 'path' => $file);
            }
            $engine = 'pdftoppm';
        }
    }

    if (empty($images) && (dcb_upload_is_command_available('magick') || dcb_upload_is_command_available('convert'))) {
        $img_cmd = dcb_upload_is_command_available('magick') ? 'magick' : 'convert';
        $target = $work_dir . '/page-%03d.png';
        $max_idx = max(0, $max_pages - 1);
        $cmd = $img_cmd . ' -density 220 ' . escapeshellarg($file_path . '[0-' . $max_idx . ']') . ' -background white -alpha remove -quality 92 ' . escapeshellarg($target) . ' 2>/dev/null';
        @shell_exec($cmd);

        $glob = glob($work_dir . '/page-*.png');
        if (is_array($glob) && !empty($glob)) {
            natsort($glob);
            $idx = 1;
            foreach ($glob as $file) {
                if (!is_string($file) || !file_exists($file)) {
                    continue;
                }
                $images[] = array('page_number' => $idx, 'path' => $file);
                $idx++;
            }
            $engine = $img_cmd;
        }
    }

    return array('pages' => $images, 'work_dir' => $work_dir, 'engine' => $engine);
}

function dcb_upload_stage_ocr_image_file(string $image_path, string $engine, int $page_number, array $normalization_meta = array()): array {
    $extension = strtolower((string) pathinfo($image_path, PATHINFO_EXTENSION));
    $mime = '';
    if (function_exists('mime_content_type') && $image_path !== '' && file_exists($image_path)) {
        $maybe_mime = @mime_content_type($image_path);
        if (is_string($maybe_mime)) {
            $mime = strtolower(trim($maybe_mime));
        }
    }

    $tesseract_path = dcb_ocr_get_tesseract_path();
    if ($image_path === '' || !file_exists($image_path) || !is_readable($image_path)) {
        dcb_upload_ocr_debug_runtime_warnings_add('image_missing_or_unreadable', 'Image OCR skipped: source image is missing or not readable.');
        return array();
    }

    if ($engine === 'tesseract-image' && in_array($extension, array('heic', 'heif'), true)) {
        dcb_upload_ocr_debug_runtime_warnings_add('heic_heif_preferred_convert', 'HEIC/HEIF image detected. OCR may fail without converter support; JPG/PNG is recommended.');
    }

    if ($engine === 'tesseract-image' && !dcb_upload_ocr_image_ext_supported($extension) && !in_array($extension, array('heic', 'heif'), true)) {
        dcb_upload_ocr_debug_runtime_warnings_add('unsupported_image_extension', 'Image extension appears unsupported for direct OCR. Prefer JPG or PNG.');
    }

    if ($tesseract_path === '') {
        dcb_upload_ocr_debug_runtime_warnings_add('tesseract_path_missing', 'Image OCR skipped: tesseract path could not be resolved.');
        return array();
    }

    $token = function_exists('wp_generate_password') ? wp_generate_password(8, false, false) : uniqid('img_ocr_', true);
    $base = trailingslashit(sys_get_temp_dir()) . 'dcb_img_ocr_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $token);
    $output_txt = $base . '.txt';

    $command = escapeshellarg($tesseract_path) . ' ' . escapeshellarg($image_path) . ' ' . escapeshellarg($base) . ' -l eng --psm 6 2>&1';

    $run = dcb_upload_ocr_image_capture_with_status($command);
    $stdout = (string) ($run['output'] ?? '');

    $output_file_exists = file_exists($output_txt);
    $output_file_readable = $output_file_exists && is_readable($output_txt);
    $text = '';
    if ($output_file_readable) {
        $raw_text = @file_get_contents($output_txt);
        if (is_string($raw_text)) {
            $text = trim($raw_text);
        }
    }

    $text_length = strlen($text);
    $confidence_proxy = round(dcb_text_confidence_proxy($text), 4);

    dcb_upload_ocr_debug_log_add(array(
        'engine' => $engine,
        'source_file_path' => $image_path,
        'mime' => $mime,
        'extension' => $extension,
        'temp_output_base' => $base,
        'temp_output_txt' => $output_txt,
        'tesseract_path' => $tesseract_path,
        'command' => $command,
        'exit_status' => $run['exit_status'] ?? null,
        'stdout_stderr_snippet' => substr($stdout, 0, 280),
        'output_file_exists' => $output_file_exists,
        'output_file_readable' => $output_file_readable,
        'extracted_text_length' => $text_length,
        'confidence_proxy' => $confidence_proxy,
    ));

    if (file_exists($output_txt)) {
        @unlink($output_txt);
    }

    if ($text === '') {
        return array();
    }

    return array(
        'page_number' => max(1, $page_number),
        'engine' => $engine,
        'text' => $text,
        'text_length' => $text_length,
        'confidence_proxy' => $confidence_proxy,
        'normalization' => array(
            'processor' => sanitize_key((string) ($normalization_meta['processor'] ?? 'none')),
            'operations' => array_values(array_filter(array_map('sanitize_key', (array) ($normalization_meta['operations'] ?? array())))),
            'stage_diagnostics' => isset($normalization_meta['stage_diagnostics']) && is_array($normalization_meta['stage_diagnostics']) ? $normalization_meta['stage_diagnostics'] : array(),
            'dimension_reduction_ratio' => round(max(0.0, min(1.0, (float) ($normalization_meta['dimension_reduction_ratio'] ?? 0.0))), 4),
            'source' => sanitize_key((string) ($normalization_meta['source'] ?? 'upload')),
            'quality' => isset($normalization_meta['quality']) && is_array($normalization_meta['quality']) ? $normalization_meta['quality'] : array(),
            'capture_warnings' => isset($normalization_meta['capture_warnings']) && is_array($normalization_meta['capture_warnings']) ? $normalization_meta['capture_warnings'] : array(),
        ),
    );
}

function dcb_upload_stage_ocr_fallback(string $file_path, array $inspection, array $rasterized, array $normalization = array()): array {
    $kind = (string) ($inspection['kind'] ?? 'other');
    $pages = array();
    $normalized_pages = isset($normalization['pages']) && is_array($normalization['pages']) ? $normalization['pages'] : array();

    if (!empty($normalized_pages)) {
        foreach ($normalized_pages as $norm_page) {
            if (!is_array($norm_page)) {
                continue;
            }
            $image_path = (string) ($norm_page['path'] ?? '');
            $page_number = max(1, (int) ($norm_page['page_number'] ?? (count($pages) + 1)));
            if ($image_path === '') {
                continue;
            }
            $row = dcb_upload_stage_ocr_image_file($image_path, !empty($inspection['is_pdf']) ? 'tesseract-pdf-raster' : 'tesseract-image', $page_number, $norm_page);
            if (!empty($row)) {
                $pages[] = $row;
            }
        }
        return $pages;
    }

    if ($kind === 'image') {
        $row = dcb_upload_stage_ocr_image_file($file_path, 'tesseract-image', 1, array('source' => 'upload_image'));
        if (!empty($row)) {
            $pages[] = $row;
        }
        return $pages;
    }

    if ($kind !== 'pdf') {
        return $pages;
    }

    $rasters = isset($rasterized['pages']) && is_array($rasterized['pages']) ? $rasterized['pages'] : array();
    foreach ($rasters as $raster) {
        if (!is_array($raster)) {
            continue;
        }
        $image_path = (string) ($raster['path'] ?? '');
        $page_number = max(1, (int) ($raster['page_number'] ?? 1));
        if ($image_path === '') {
            continue;
        }
        $row = dcb_upload_stage_ocr_image_file($image_path, 'tesseract-pdf-raster', $page_number, array('source' => 'pdf_raster_page'));
        if (!empty($row)) {
            $pages[] = $row;
        }
    }

    return $pages;
}

function dcb_upload_stage_cleanup_raster_pages(array $rasterized): void {
    $rasters = isset($rasterized['pages']) && is_array($rasterized['pages']) ? $rasterized['pages'] : array();
    foreach ($rasters as $row) {
        $path = is_array($row) ? (string) ($row['path'] ?? '') : '';
        if ($path !== '' && file_exists($path)) {
            @unlink($path);
        }
    }

    $work_dir = (string) ($rasterized['work_dir'] ?? '');
    if ($work_dir !== '' && is_dir($work_dir)) {
        @rmdir($work_dir);
    }
}

function dcb_upload_stage_merge_pages(array $primary_pages, array $fallback_pages): array {
    $merged = array();
    foreach ($primary_pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $num = max(1, (int) ($page['page_number'] ?? 1));
        $merged[$num] = $page;
    }

    foreach ($fallback_pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $num = max(1, (int) ($page['page_number'] ?? 1));
        if (!isset($merged[$num])) {
            $merged[$num] = $page;
            continue;
        }

        $existing_text = (string) ($merged[$num]['text'] ?? '');
        $fallback_text = (string) ($page['text'] ?? '');
        $existing_norm_len = strlen(dcb_upload_normalize_text($existing_text));
        $fallback_norm_len = strlen(dcb_upload_normalize_text($fallback_text));

        if ($fallback_norm_len > ($existing_norm_len + 24)) {
            $merged[$num]['text'] = $existing_text !== '' ? ($existing_text . "\n" . $fallback_text) : $fallback_text;
            $merged[$num]['text_length'] = strlen((string) $merged[$num]['text']);
            $merged[$num]['engine'] = (string) ($merged[$num]['engine'] ?? 'pdftotext') . '+tesseract';
            $merged[$num]['confidence_proxy'] = round(max((float) ($merged[$num]['confidence_proxy'] ?? 0), (float) ($page['confidence_proxy'] ?? 0)), 4);
        }
    }

    ksort($merged);
    return array_values($merged);
}

function dcb_upload_extract_text_from_file_local(string $file_path, string $mime): array {
    $GLOBALS['dcb_upload_runtime_ocr_warnings'] = array();

    $inspection = dcb_upload_stage_file_type_inspection($file_path, $mime);
    $source_type = dcb_upload_ocr_detect_source_type($inspection, $file_path);
    $text_stage = dcb_upload_stage_text_extraction($file_path, $inspection);
    $pages = isset($text_stage['pages']) && is_array($text_stage['pages']) ? $text_stage['pages'] : array();
    $combined_text = (string) ($text_stage['text'] ?? '');

    $rasterized = array('pages' => array(), 'work_dir' => '', 'engine' => 'none');
    $normalization = array('enabled' => false, 'pages' => array(), 'warnings' => array(), 'stages' => array(), 'quality' => array());
    $ocr_pages = array();
    if (!empty($inspection['is_pdf']) && dcb_upload_stage_pdf_text_is_weak($pages, $combined_text)) {
        $rasterized = dcb_upload_stage_page_rasterization($file_path, $inspection, 12);
        $normalization = dcb_upload_stage_input_normalization($file_path, $inspection, $rasterized);
        $ocr_pages = dcb_upload_stage_ocr_fallback($file_path, $inspection, $rasterized, $normalization);
    } elseif (!empty($inspection['is_image'])) {
        $normalization = dcb_upload_stage_input_normalization($file_path, $inspection, $rasterized);
        $ocr_pages = dcb_upload_stage_ocr_fallback($file_path, $inspection, $rasterized, $normalization);
    }

    $pages = dcb_upload_stage_merge_pages($pages, $ocr_pages);
    dcb_upload_stage_cleanup_raster_pages($rasterized);
    dcb_upload_stage_cleanup_normalized_images($normalization);

    $engine_set = array();
    $all_text = array();
    $page_meta = array();
    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $page_number = max(1, (int) ($page['page_number'] ?? 1));
        $page_text = trim((string) ($page['text'] ?? ''));
        $engine = sanitize_text_field((string) ($page['engine'] ?? 'none'));
        if ($engine !== '') {
            $engine_set[$engine] = true;
        }
        if ($page_text !== '') {
            $all_text[] = $page_text;
        }
        $proxy = isset($page['confidence_proxy']) ? (float) $page['confidence_proxy'] : dcb_text_confidence_proxy($page_text);
        $page_meta[] = array(
            'page_number' => $page_number,
            'engine' => $engine !== '' ? $engine : 'none',
            'text' => $page_text,
            'text_length' => strlen($page_text),
            'confidence_proxy' => round(max(0.0, min(1.0, $proxy)), 4),
            'normalization' => isset($page['normalization']) && is_array($page['normalization']) ? $page['normalization'] : array(),
        );
    }

    $text = trim(implode("\n\n", $all_text));
    if (strlen($text) > 50000) {
        $text = substr($text, 0, 50000);
    }

    $normalized = dcb_upload_normalize_text($text);
    $engine = !empty($engine_set) ? implode('+', array_keys($engine_set)) : 'none';
    $warnings = array();

    if (!dcb_ocr_shell_exec_enabled()) {
        $warnings[] = array('code' => 'shell_exec_disabled', 'message' => 'shell_exec is disabled in PHP; OCR/PDF binaries cannot be executed.');
    }
    if (!empty($inspection['is_pdf']) && dcb_ocr_get_pdftotext_path() === '') {
        $warnings[] = array('code' => 'pdftotext_missing', 'message' => 'pdftotext binary is not available.');
    }
    if ((!empty($inspection['is_pdf']) || !empty($inspection['is_image'])) && dcb_ocr_get_tesseract_path() === '') {
        $warnings[] = array('code' => 'tesseract_missing', 'message' => 'tesseract binary is not available for OCR fallback.');
    }
    if (!empty($inspection['is_pdf']) && dcb_ocr_get_pdftoppm_path() === '') {
        $warnings[] = array('code' => 'pdftoppm_missing', 'message' => 'pdftoppm binary is not available for scanned PDF rasterization.');
    }

    foreach (dcb_upload_ocr_debug_runtime_warnings_get() as $runtime_warning) {
        $warnings[] = array('code' => sanitize_key((string) ($runtime_warning['code'] ?? 'ocr_warning')), 'message' => sanitize_text_field((string) ($runtime_warning['message'] ?? 'OCR warning.')));
    }
    foreach ((array) ($normalization['warnings'] ?? array()) as $normalization_warning) {
        if (!is_array($normalization_warning)) {
            continue;
        }
        $warnings[] = array(
            'code' => sanitize_key((string) ($normalization_warning['code'] ?? 'normalization_warning')),
            'message' => sanitize_text_field((string) ($normalization_warning['message'] ?? 'Input normalization warning.')),
        );
    }

    $result = array(
        'text' => $text,
        'normalized' => $normalized,
        'engine' => $engine,
        'pages' => $page_meta,
        'warnings' => $warnings,
        'stages' => array('file_type_inspection', 'text_extraction', 'page_rasterization', 'input_normalization', 'ocr_fallback'),
        'input_source_type' => $source_type,
        'input_normalization' => array(
            'enabled' => !empty($normalization['enabled']),
            'source_type' => sanitize_key((string) ($normalization['source_type'] ?? $source_type)),
            'max_dimension' => max(0, (int) ($normalization['max_dimension'] ?? 0)),
            'stages' => array_values(array_filter(array_map('sanitize_key', (array) ($normalization['stages'] ?? array())))),
            'page_count' => isset($normalization['pages']) && is_array($normalization['pages']) ? count($normalization['pages']) : 0,
            'quality' => isset($normalization['quality']) && is_array($normalization['quality']) ? $normalization['quality'] : array(),
            'warnings' => isset($normalization['warnings']) && is_array($normalization['warnings']) ? $normalization['warnings'] : array(),
            'capture_recommendations' => isset($normalization['capture_recommendations']) && is_array($normalization['capture_recommendations']) ? $normalization['capture_recommendations'] : array(),
            'stage_application_counts' => isset($normalization['stage_application_counts']) && is_array($normalization['stage_application_counts']) ? $normalization['stage_application_counts'] : array(),
            'stage_attempt_counts' => isset($normalization['stage_attempt_counts']) && is_array($normalization['stage_attempt_counts']) ? $normalization['stage_attempt_counts'] : array(),
            'average_warning_count' => round(max(0.0, (float) ($normalization['average_warning_count'] ?? 0.0)), 4),
            'normalization_improvement_proxy' => round(max(0.0, min(1.0, (float) ($normalization['normalization_improvement_proxy'] ?? 0.0))), 4),
            'rasterization_coverage' => isset($normalization['rasterization_coverage']) ? round(max(0.0, min(1.0, (float) $normalization['rasterization_coverage'])), 4) : 0.0,
        ),
    );

    if ($text === '') {
        $result['failure_reason'] = dcb_ocr_normalize_failure_reason(!empty($inspection['is_image']) && dcb_ocr_get_tesseract_path() === '' ? 'local_binary_missing' : 'empty_extraction');
    }

    return dcb_ocr_normalize_result_shape($result, 'local');
}

function dcb_ocr_pages_snapshot_metrics(array $pages): array {
    $page_count = 0;
    $text_length = 0;
    $proxy_total = 0.0;
    $proxy_count = 0;

    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $page_count++;
        $text = (string) ($page['text'] ?? '');
        $len = max(0, (int) ($page['text_length'] ?? strlen($text)));
        $text_length += $len;
        $proxy = isset($page['confidence_proxy']) && is_numeric($page['confidence_proxy'])
            ? (float) $page['confidence_proxy']
            : dcb_text_confidence_proxy($text);
        $proxy_total += max(0.0, min(1.0, $proxy));
        $proxy_count++;
    }

    return array(
        'page_count' => max(0, $page_count),
        'text_length_proxy' => max(0, $text_length),
        'confidence_proxy' => round($proxy_count > 0 ? ($proxy_total / $proxy_count) : 0.0, 4),
    );
}

function dcb_ocr_local_baseline_warnings(array $inspection): array {
    $warnings = array();
    if (!dcb_ocr_shell_exec_enabled()) {
        $warnings[] = array('code' => 'shell_exec_disabled', 'message' => 'shell_exec is disabled in PHP; OCR/PDF binaries cannot be executed.');
    }
    if (!empty($inspection['is_pdf']) && dcb_ocr_get_pdftotext_path() === '') {
        $warnings[] = array('code' => 'pdftotext_missing', 'message' => 'pdftotext binary is not available.');
    }
    if ((!empty($inspection['is_pdf']) || !empty($inspection['is_image'])) && dcb_ocr_get_tesseract_path() === '') {
        $warnings[] = array('code' => 'tesseract_missing', 'message' => 'tesseract binary is not available for OCR fallback.');
    }
    if (!empty($inspection['is_pdf']) && dcb_ocr_get_pdftoppm_path() === '') {
        $warnings[] = array('code' => 'pdftoppm_missing', 'message' => 'pdftoppm binary is not available for scanned PDF rasterization.');
    }
    return $warnings;
}

function dcb_ocr_local_replay_before_after_diagnostics(string $file_path, string $mime): array {
    $inspection = dcb_upload_stage_file_type_inspection($file_path, $mime);
    $source_type = dcb_upload_ocr_detect_source_type($inspection, $file_path);

    $text_stage = dcb_upload_stage_text_extraction($file_path, $inspection);
    $baseline_pages = isset($text_stage['pages']) && is_array($text_stage['pages']) ? $text_stage['pages'] : array();
    $baseline_text = (string) ($text_stage['text'] ?? '');

    $rasterized = array('pages' => array(), 'work_dir' => '', 'engine' => 'none');
    if (!empty($inspection['is_pdf']) && dcb_upload_stage_pdf_text_is_weak($baseline_pages, $baseline_text)) {
        $rasterized = dcb_upload_stage_page_rasterization($file_path, $inspection, 12);
    }

    $baseline_ocr_pages = array();
    if (!empty($inspection['is_image']) || !empty($inspection['is_pdf'])) {
        $baseline_ocr_pages = dcb_upload_stage_ocr_fallback($file_path, $inspection, $rasterized, array());
    }
    $merged_baseline = dcb_upload_stage_merge_pages($baseline_pages, $baseline_ocr_pages);
    $before_metrics = dcb_ocr_pages_snapshot_metrics($merged_baseline);
    $before_warnings = dcb_ocr_local_baseline_warnings($inspection);

    $after_result = dcb_upload_extract_text_from_file_local($file_path, $mime);
    $after_pages = isset($after_result['pages']) && is_array($after_result['pages']) ? $after_result['pages'] : array();
    $after_metrics = dcb_ocr_pages_snapshot_metrics($after_pages);
    $after_warnings = isset($after_result['warnings']) && is_array($after_result['warnings']) ? $after_result['warnings'] : array();
    $norm = isset($after_result['input_normalization']) && is_array($after_result['input_normalization']) ? $after_result['input_normalization'] : array();

    dcb_upload_stage_cleanup_raster_pages($rasterized);

    $stage_attempts = isset($norm['stage_attempt_counts']) && is_array($norm['stage_attempt_counts']) ? $norm['stage_attempt_counts'] : array();
    $stage_applied = isset($norm['stage_application_counts']) && is_array($norm['stage_application_counts']) ? $norm['stage_application_counts'] : array();

    $before_warning_count = count($before_warnings);
    $after_warning_count = count($after_warnings);

    $diag = array(
        'source_type' => $source_type,
        'before' => array_merge($before_metrics, array(
            'warning_count' => $before_warning_count,
        )),
        'after' => array_merge($after_metrics, array(
            'warning_count' => $after_warning_count,
            'normalization_warning_count' => isset($norm['warnings']) && is_array($norm['warnings']) ? count($norm['warnings']) : 0,
            'improvement_proxy' => round(max(0.0, min(1.0, (float) ($norm['normalization_improvement_proxy'] ?? 0.0))), 4),
        )),
        'deltas' => array(
            'text_length_delta' => (int) ($after_metrics['text_length_proxy'] - $before_metrics['text_length_proxy']),
            'confidence_proxy_delta' => round((float) $after_metrics['confidence_proxy'] - (float) $before_metrics['confidence_proxy'], 4),
            'warning_count_delta' => (int) ($after_warning_count - $before_warning_count),
        ),
        'normalization' => array(
            'stages' => isset($norm['stages']) && is_array($norm['stages']) ? $norm['stages'] : array(),
            'stage_attempt_counts' => $stage_attempts,
            'stage_application_counts' => $stage_applied,
            'capture_recommendations' => isset($norm['capture_recommendations']) && is_array($norm['capture_recommendations']) ? $norm['capture_recommendations'] : array(),
            'average_warning_count' => round(max(0.0, (float) ($norm['average_warning_count'] ?? 0.0)), 4),
            'rasterization_coverage' => isset($norm['rasterization_coverage']) ? round(max(0.0, min(1.0, (float) $norm['rasterization_coverage'])), 4) : 0.0,
            'warnings' => isset($norm['warnings']) && is_array($norm['warnings']) ? $norm['warnings'] : array(),
        ),
        'after_result' => $after_result,
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_ocr_local_replay_diagnostics', $diag, $file_path, $mime, $inspection) : $diag;
}

function dcb_ocr_failure_taxonomy(): array {
    return array(
        'empty_extraction' => array('label' => 'Empty extraction', 'recommendation' => 'Review file quality and rerun OCR.', 'severity' => 'warning'),
        'low_confidence' => array('label' => 'Low confidence', 'recommendation' => 'Review extracted fields and correct manually.', 'severity' => 'warning'),
        'remote_config_invalid' => array('label' => 'Remote config invalid', 'recommendation' => 'Set HTTPS base URL in OCR settings.', 'severity' => 'error'),
        'remote_api_key_missing' => array('label' => 'Remote API key missing', 'recommendation' => 'Set OCR API key in settings.', 'severity' => 'error'),
        'remote_request_failed' => array('label' => 'Remote request failed', 'recommendation' => 'Check network/provider availability and retry.', 'severity' => 'error'),
        'remote_http_error' => array('label' => 'Remote HTTP error', 'recommendation' => 'Check provider endpoint and response status.', 'severity' => 'error'),
        'max_file_size_exceeded' => array('label' => 'Max file size exceeded', 'recommendation' => 'Reduce file size or increase configured limit.', 'severity' => 'warning'),
        'unsupported_mime' => array('label' => 'Unsupported MIME', 'recommendation' => 'Upload supported file type.', 'severity' => 'warning'),
        'local_binary_missing' => array('label' => 'Local OCR binary missing', 'recommendation' => 'Install/configure local OCR binaries.', 'severity' => 'error'),
        'rasterization_failed' => array('label' => 'Rasterization failed', 'recommendation' => 'Check pdftoppm/ImageMagick availability and file integrity.', 'severity' => 'error'),
        'extraction_timeout' => array('label' => 'Extraction timeout', 'recommendation' => 'Increase timeout or process smaller files.', 'severity' => 'warning'),
        'parse_failed' => array('label' => 'Parse failed', 'recommendation' => 'Check provider response format and retry.', 'severity' => 'error'),
        'unknown' => array('label' => 'Unknown OCR issue', 'recommendation' => 'Inspect diagnostics and rerun OCR.', 'severity' => 'warning'),
    );
}

function dcb_ocr_normalize_failure_reason(string $reason): string {
    $reason = sanitize_key($reason);
    if ($reason === '') {
        return '';
    }

    $aliases = array(
        'tesseract_missing' => 'local_binary_missing',
        'pdftotext_missing' => 'local_binary_missing',
        'pdftoppm_missing' => 'local_binary_missing',
        'shell_exec_disabled' => 'local_binary_missing',
        'http_error' => 'remote_http_error',
        'request_failed' => 'remote_request_failed',
        'timeout' => 'extraction_timeout',
        'json_parse_failed' => 'parse_failed',
    );
    if (isset($aliases[$reason])) {
        $reason = $aliases[$reason];
    }

    $taxonomy = dcb_ocr_failure_taxonomy();
    if (!isset($taxonomy[$reason])) {
        return 'unknown';
    }

    return $reason;
}

function dcb_ocr_failure_meta(string $reason): array {
    $reason = dcb_ocr_normalize_failure_reason($reason);
    $taxonomy = dcb_ocr_failure_taxonomy();
    $fallback = $taxonomy['unknown'] ?? array('label' => 'Unknown OCR issue', 'recommendation' => 'Inspect diagnostics.', 'severity' => 'warning');
    $row = $reason !== '' && isset($taxonomy[$reason]) ? $taxonomy[$reason] : $fallback;
    $row['code'] = $reason !== '' ? $reason : 'unknown';
    return $row;
}

function dcb_ocr_normalize_result_shape(array $result, string $provider = ''): array {
    $text = sanitize_textarea_field((string) ($result['text'] ?? ''));
    $engine = sanitize_text_field((string) ($result['engine'] ?? ($provider !== '' ? $provider : 'none')));
    $provider = sanitize_key((string) ($result['provider'] ?? $provider));
    if ($provider === '') {
        $provider = 'local';
    }

    $warnings_in = isset($result['warnings']) && is_array($result['warnings']) ? $result['warnings'] : array();
    $warnings = array();
    foreach ($warnings_in as $warning) {
        if (is_array($warning)) {
            $warnings[] = array(
                'code' => sanitize_key((string) ($warning['code'] ?? 'ocr_warning')),
                'message' => sanitize_text_field((string) ($warning['message'] ?? 'OCR warning')),
            );
        } else {
            $warnings[] = array('code' => 'ocr_warning', 'message' => sanitize_text_field((string) $warning));
        }
    }

    $pages_in = isset($result['pages']) && is_array($result['pages']) ? $result['pages'] : array();
    $pages = array();
    $total_proxy = 0.0;
    $count_proxy = 0;
    foreach ($pages_in as $page) {
        if (!is_array($page)) {
            continue;
        }
        $page_text = sanitize_textarea_field((string) ($page['text'] ?? ''));
        $proxy = isset($page['confidence_proxy']) && is_numeric($page['confidence_proxy']) ? (float) $page['confidence_proxy'] : dcb_text_confidence_proxy($page_text);
        $proxy = round(max(0, min(1, $proxy)), 4);
        $pages[] = array(
            'page_number' => max(1, (int) ($page['page_number'] ?? (count($pages) + 1))),
            'engine' => sanitize_text_field((string) ($page['engine'] ?? $engine)),
            'text' => $page_text,
            'text_length' => max(0, (int) ($page['text_length'] ?? strlen($page_text))),
            'confidence_proxy' => $proxy,
            'normalization' => isset($page['normalization']) && is_array($page['normalization']) ? $page['normalization'] : array(),
        );
        $total_proxy += $proxy;
        $count_proxy++;
    }

    $confidence_proxy = isset($result['confidence_proxy']) && is_numeric($result['confidence_proxy'])
        ? round(max(0, min(1, (float) $result['confidence_proxy'])), 4)
        : ($count_proxy > 0 ? round($total_proxy / $count_proxy, 4) : round(dcb_text_confidence_proxy($text), 4));

    $failure_reason = dcb_ocr_normalize_failure_reason((string) ($result['failure_reason'] ?? ''));
    if ($failure_reason === '' && $text === '') {
        $failure_reason = 'empty_extraction';
    }

    $normalized = array(
        'text' => $text,
        'normalized' => dcb_upload_normalize_text($text),
        'engine' => $engine,
        'pages' => $pages,
        'warnings' => $warnings,
        'failure_reason' => $failure_reason,
        'provider' => $provider,
        'mode' => sanitize_key((string) ($result['mode'] ?? ($provider === 'remote' ? 'remote' : 'local'))),
        'confidence_proxy' => $confidence_proxy,
        'confidence_bucket' => dcb_confidence_bucket($confidence_proxy),
        'provenance' => isset($result['provenance']) && is_array($result['provenance']) ? $result['provenance'] : array(),
    );

    if (isset($result['stages']) && is_array($result['stages'])) {
        $normalized['stages'] = array_values(array_filter(array_map('sanitize_key', $result['stages'])));
    }

    if (isset($result['input_source_type'])) {
        $normalized['input_source_type'] = sanitize_key((string) $result['input_source_type']);
    }
    if (isset($result['input_normalization']) && is_array($result['input_normalization'])) {
        $norm = (array) $result['input_normalization'];
        $normalized['input_normalization'] = array(
            'enabled' => !empty($norm['enabled']),
            'source_type' => sanitize_key((string) ($norm['source_type'] ?? '')),
            'max_dimension' => max(0, (int) ($norm['max_dimension'] ?? 0)),
            'stages' => isset($norm['stages']) && is_array($norm['stages']) ? array_values(array_filter(array_map('sanitize_key', $norm['stages']))) : array(),
            'page_count' => max(0, (int) ($norm['page_count'] ?? 0)),
            'quality' => isset($norm['quality']) && is_array($norm['quality']) ? $norm['quality'] : array(),
            'warnings' => isset($norm['warnings']) && is_array($norm['warnings']) ? $norm['warnings'] : array(),
            'capture_recommendations' => isset($norm['capture_recommendations']) && is_array($norm['capture_recommendations']) ? array_values(array_filter(array_map('sanitize_text_field', $norm['capture_recommendations']))) : array(),
            'stage_application_counts' => isset($norm['stage_application_counts']) && is_array($norm['stage_application_counts']) ? $norm['stage_application_counts'] : array(),
            'stage_attempt_counts' => isset($norm['stage_attempt_counts']) && is_array($norm['stage_attempt_counts']) ? $norm['stage_attempt_counts'] : array(),
            'average_warning_count' => round(max(0.0, (float) ($norm['average_warning_count'] ?? 0.0)), 4),
            'normalization_improvement_proxy' => round(max(0.0, min(1.0, (float) ($norm['normalization_improvement_proxy'] ?? 0.0))), 4),
            'rasterization_coverage' => isset($norm['rasterization_coverage']) ? round(max(0.0, min(1.0, (float) $norm['rasterization_coverage'])), 4) : 0.0,
        );
    }

    return $normalized;
}

function dcb_ocr_review_statuses(): array {
    return array(
        'pending_review' => 'Pending Review',
        'corrected' => 'Corrected',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'reprocessed' => 'Reprocessed',
    );
}

function dcb_ocr_review_append_revision(int $review_id, string $event, array $payload = array()): void {
    $rows = get_post_meta($review_id, '_dcb_ocr_review_revisions', true);
    if (!is_array($rows)) {
        $rows = array();
    }

    $user = wp_get_current_user();
    $rows[] = array(
        'time' => current_time('mysql'),
        'event' => sanitize_key($event),
        'actor_user_id' => $user instanceof WP_User ? (int) $user->ID : 0,
        'actor_name' => $user instanceof WP_User ? (string) $user->display_name : '',
        'payload' => $payload,
    );
    if (count($rows) > 200) {
        $rows = array_slice($rows, -200);
    }

    update_post_meta($review_id, '_dcb_ocr_review_revisions', $rows);
}

function dcb_ocr_review_update_status(int $review_id, string $status, string $note = ''): bool {
    $status = sanitize_key($status);
    $allowed = dcb_ocr_review_statuses();
    if (!isset($allowed[$status])) {
        return false;
    }

    $prev = sanitize_key((string) get_post_meta($review_id, '_dcb_ocr_review_status', true));
    if ($prev === '') {
        $prev = 'pending_review';
    }

    update_post_meta($review_id, '_dcb_ocr_review_status', $status);
    update_post_meta($review_id, '_dcb_ocr_review_status_updated_at', current_time('mysql'));
    dcb_ocr_review_append_revision($review_id, 'status_changed', array(
        'from' => $prev,
        'to' => $status,
        'note' => sanitize_textarea_field($note),
    ));

    if (function_exists('do_action')) {
        do_action('dcb_ocr_review_status_changed', $review_id, $prev, $status, $note);
    }

    return true;
}

function dcb_ocr_review_apply_manual_corrections(int $review_id, array $corrections): void {
    $corrections_json = wp_json_encode($corrections);
    if (!is_string($corrections_json)) {
        $corrections_json = '{}';
    }

    update_post_meta($review_id, '_dcb_ocr_review_corrections', $corrections_json);

    $summary = sanitize_textarea_field((string) ($corrections['text_summary'] ?? ''));
    if ($summary !== '') {
        update_post_meta($review_id, '_dcb_ocr_review_corrected_text_summary', $summary);
    }

    if (isset($corrections['candidate_fields']) && is_array($corrections['candidate_fields'])) {
        update_post_meta($review_id, '_dcb_ocr_review_corrected_candidates', wp_json_encode($corrections['candidate_fields']));
        dcb_ocr_update_correction_rules_from_review((array) $corrections['candidate_fields']);
    }

    dcb_ocr_review_append_revision($review_id, 'manual_correction_saved', array(
        'candidate_field_count' => isset($corrections['candidate_fields']) && is_array($corrections['candidate_fields']) ? count($corrections['candidate_fields']) : 0,
        'summary_length' => strlen($summary),
    ));

    dcb_ocr_review_update_status($review_id, 'corrected', 'Manual OCR correction saved.');

    if (function_exists('do_action')) {
        do_action('dcb_ocr_review_corrected', $review_id, $corrections);
    }
}

function dcb_ocr_enrich_extraction_result(array $result): array {
    $result = dcb_ocr_normalize_result_shape($result, sanitize_key((string) ($result['provider'] ?? '')));

    $pages = isset($result['pages']) && is_array($result['pages']) ? $result['pages'] : array();
    if (empty($pages)) {
        $pages[] = array(
            'page_number' => 1,
            'text' => (string) ($result['text'] ?? ''),
            'engine' => sanitize_text_field((string) ($result['engine'] ?? 'unknown')),
            'text_length' => strlen((string) ($result['text'] ?? '')),
            'confidence_proxy' => (float) ($result['confidence_proxy'] ?? 0),
        );
    }

    $document_model = dcb_ocr_build_document_model($pages);
    $page_meta = array();
    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $pn = max(1, (int) ($page['page_number'] ?? 1));
        $norm = isset($page['normalization']) && is_array($page['normalization']) ? $page['normalization'] : array();
        $page_meta[$pn] = array(
            'page_number' => $pn,
            'engine' => sanitize_key((string) ($page['engine'] ?? 'unknown')),
            'text_length' => max(0, (int) ($page['text_length'] ?? strlen((string) ($page['text'] ?? '')))),
            'confidence_proxy' => round(max(0, min(1, (float) ($page['confidence_proxy'] ?? 0))), 4),
            'normalization' => $norm,
        );
    }

    $candidates = dcb_upload_stage_field_candidate_extraction($document_model, $page_meta);
    $template_blocks = dcb_upload_stage_template_block_extraction($document_model);

    $result['ocr_document_model'] = $document_model;
    $result['ocr_candidates'] = dcb_ocr_normalize_candidates_runtime($candidates);
    $result['template_blocks'] = $template_blocks;
    $result['quality_metrics'] = array(
        'field_candidate_count' => count($result['ocr_candidates']),
        'section_candidate_count' => isset($document_model['section_candidates']) && is_array($document_model['section_candidates']) ? count($document_model['section_candidates']) : 0,
        'table_candidate_count' => isset($document_model['table_candidates']) && is_array($document_model['table_candidates']) ? count($document_model['table_candidates']) : 0,
        'signature_candidate_count' => isset($document_model['signature_date_candidates']) && is_array($document_model['signature_date_candidates']) ? count($document_model['signature_date_candidates']) : 0,
        'layout_region_count' => isset($document_model['layout_regions']) && is_array($document_model['layout_regions']) ? count($document_model['layout_regions']) : 0,
        'signature_pair_count' => isset($document_model['signature_date_pairs']) && is_array($document_model['signature_date_pairs']) ? count($document_model['signature_date_pairs']) : 0,
        'false_positive_risk_count' => count(array_filter($result['ocr_candidates'], static function ($row) {
            return is_array($row) && (string) ($row['warning_state'] ?? '') === 'review_needed';
        })),
        'review_cleanup_burden_proxy' => count($result['ocr_candidates']) > 0
            ? round(count(array_filter($result['ocr_candidates'], static function ($row) {
                return is_array($row) && (string) ($row['confidence_bucket'] ?? 'low') === 'low';
            })) / max(1, count($result['ocr_candidates'])), 4)
            : 0.0,
        'normalization_page_count' => isset($result['input_normalization']['page_count']) ? max(0, (int) $result['input_normalization']['page_count']) : 0,
        'normalization_warning_count' => isset($result['input_normalization']['warnings']) && is_array($result['input_normalization']['warnings']) ? count($result['input_normalization']['warnings']) : 0,
        'average_capture_warning_count' => isset($result['input_normalization']['average_warning_count']) ? round(max(0.0, (float) $result['input_normalization']['average_warning_count']), 4) : 0.0,
        'normalization_improvement_proxy' => isset($result['input_normalization']['normalization_improvement_proxy']) ? round(max(0.0, min(1.0, (float) $result['input_normalization']['normalization_improvement_proxy'])), 4) : 0.0,
        'rasterization_coverage' => isset($result['input_normalization']['rasterization_coverage']) ? round(max(0.0, min(1.0, (float) $result['input_normalization']['rasterization_coverage'])), 4) : 0.0,
    );

    return $result;
}

function dcb_ocr_review_promote_builder_draft(int $review_id): array {
    $label = sanitize_text_field((string) get_the_title($review_id));
    if ($label === '') {
        $label = 'OCR Review Draft';
    }

    $corrections_raw = (string) get_post_meta($review_id, '_dcb_ocr_review_corrections', true);
    $corrections = json_decode($corrections_raw, true);
    if (!is_array($corrections)) {
        $corrections = array();
    }

    $extraction_raw = (string) get_post_meta($review_id, '_dcb_ocr_review_extraction', true);
    $extraction = json_decode($extraction_raw, true);
    if (!is_array($extraction)) {
        $extraction = array();
    }

    $text = sanitize_textarea_field((string) ($corrections['text_summary'] ?? ''));
    if ($text === '') {
        $text = sanitize_textarea_field((string) ($extraction['text'] ?? ''));
    }

    $draft = dcb_ocr_to_draft_form($text, $label, $extraction);
    if (isset($corrections['candidate_fields']) && is_array($corrections['candidate_fields'])) {
        $review_rows = array();
        foreach ($corrections['candidate_fields'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['decision'] = sanitize_key((string) ($row['decision'] ?? 'accept'));
            $review_rows[] = $row;
        }
        if (function_exists('dcb_apply_ocr_candidate_review')) {
            $draft = dcb_apply_ocr_candidate_review($draft, $review_rows);
        }
    }

    update_post_meta($review_id, '_dcb_ocr_review_promoted_draft', wp_json_encode($draft));
    dcb_ocr_review_append_revision($review_id, 'promoted_to_builder_draft', array(
        'field_count' => isset($draft['fields']) && is_array($draft['fields']) ? count($draft['fields']) : 0,
    ));

    if (function_exists('do_action')) {
        do_action('dcb_ocr_review_promoted_draft', $review_id, $draft);
    }

    return $draft;
}

function dcb_ocr_review_reprocess(int $review_id, string $mode = ''): array {
    $file_path = sanitize_text_field((string) get_post_meta($review_id, '_dcb_ocr_review_source_file_path', true));
    $mime = sanitize_text_field((string) get_post_meta($review_id, '_dcb_ocr_review_mime', true));
    if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
        return array('ok' => false, 'message' => 'Source file unavailable for reprocess.', 'failure_reason' => 'parse_failed');
    }

    $mode = sanitize_key($mode);
    if ($mode === '') {
        $mode = sanitize_key((string) get_option('dcb_ocr_mode', 'auto'));
    }

    $result = class_exists('DCB_OCR_Engine_Manager')
        ? DCB_OCR_Engine_Manager::extract_with_mode($file_path, $mime, $mode)
        : dcb_upload_extract_text_from_file_local($file_path, $mime);

    $result = dcb_ocr_enrich_extraction_result((array) $result);

    update_post_meta($review_id, '_dcb_ocr_review_last_reprocess_at', current_time('mysql'));
    update_post_meta($review_id, '_dcb_ocr_review_reprocess_count', max(0, (int) get_post_meta($review_id, '_dcb_ocr_review_reprocess_count', true)) + 1);
    update_post_meta($review_id, '_dcb_ocr_review_extraction', wp_json_encode($result));
    update_post_meta($review_id, '_dcb_ocr_review_text', (string) ($result['text'] ?? ''));
    update_post_meta($review_id, '_dcb_ocr_review_confidence', round((float) ($result['confidence_proxy'] ?? 0), 4));
    update_post_meta($review_id, '_dcb_ocr_review_confidence_bucket', sanitize_key((string) ($result['confidence_bucket'] ?? 'low')));
    update_post_meta($review_id, '_dcb_ocr_review_provider', sanitize_key((string) ($result['provider'] ?? 'local')));
    update_post_meta($review_id, '_dcb_ocr_review_mode', sanitize_key((string) ($result['mode'] ?? $mode)));
    update_post_meta($review_id, '_dcb_ocr_review_failure_reason', dcb_ocr_normalize_failure_reason((string) ($result['failure_reason'] ?? '')));
    update_post_meta($review_id, '_dcb_ocr_review_provenance', wp_json_encode((array) ($result['provenance'] ?? array())));
    update_post_meta($review_id, '_dcb_ocr_review_page_count', isset($result['pages']) && is_array($result['pages']) ? count($result['pages']) : 0);

    dcb_ocr_review_append_revision($review_id, 'reprocessed', array(
        'mode' => $mode,
        'provider' => (string) ($result['provider'] ?? ''),
        'failure_reason' => (string) ($result['failure_reason'] ?? ''),
    ));
    dcb_ocr_review_update_status($review_id, 'reprocessed', 'OCR item was reprocessed.');

    if (function_exists('do_action')) {
        do_action('dcb_ocr_review_reprocessed', $review_id, $result, $mode);
    }

    return array('ok' => true, 'result' => $result);
}

function dcb_ocr_review_queue_summary(): array {
    if (!class_exists('WP_Query')) {
        return array('status_counts' => array(), 'failure_counts' => array());
    }

    $items = get_posts(array(
        'post_type' => 'dcb_ocr_review_queue',
        'post_status' => 'publish',
        'posts_per_page' => 500,
        'fields' => 'ids',
    ));

    $status_counts = array();
    $failure_counts = array();
    foreach ((array) $items as $id) {
        $review_id = (int) $id;
        if ($review_id < 1) {
            continue;
        }

        $status = sanitize_key((string) get_post_meta($review_id, '_dcb_ocr_review_status', true));
        if ($status === '') {
            $status = 'pending_review';
        }
        if (!isset($status_counts[$status])) {
            $status_counts[$status] = 0;
        }
        $status_counts[$status]++;

        $failure = dcb_ocr_normalize_failure_reason((string) get_post_meta($review_id, '_dcb_ocr_review_failure_reason', true));
        if ($failure === '') {
            continue;
        }
        if (!isset($failure_counts[$failure])) {
            $failure_counts[$failure] = 0;
        }
        $failure_counts[$failure]++;
    }

    ksort($status_counts);
    arsort($failure_counts);

    return array('status_counts' => $status_counts, 'failure_counts' => $failure_counts);
}

function dcb_upload_extract_text_from_file(string $file_path, string $mime): array {
    if (class_exists('DCB_OCR_Engine_Manager')) {
        $result = DCB_OCR_Engine_Manager::extract($file_path, $mime);
        $result = dcb_ocr_enrich_extraction_result((array) $result);
        $review_item_id = dcb_ocr_maybe_enqueue_review_item($file_path, $mime, $result);
        if ($review_item_id > 0) {
            $result['review_item_id'] = $review_item_id;
        }
        return $result;
    }

    $result = dcb_upload_extract_text_from_file_local($file_path, $mime);
    $result = dcb_ocr_enrich_extraction_result((array) $result);
    $review_item_id = dcb_ocr_maybe_enqueue_review_item($file_path, $mime, $result);
    if ($review_item_id > 0) {
        $result['review_item_id'] = $review_item_id;
    }
    return $result;
}

function dcb_ocr_maybe_enqueue_review_item(string $file_path, string $mime, array $result): int {
    $confidence = 0.0;
    $pages = isset($result['pages']) && is_array($result['pages']) ? $result['pages'] : array();
    if (!empty($pages)) {
        $total = 0.0;
        $count = 0;
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $total += (float) ($page['confidence_proxy'] ?? 0);
            $count++;
        }
        if ($count > 0) {
            $confidence = $total / $count;
        }
    }

    if ($confidence <= 0.0 && isset($result['confidence_proxy']) && is_numeric($result['confidence_proxy'])) {
        $confidence = (float) $result['confidence_proxy'];
    }

    $threshold = max(0.0, min(1.0, (float) get_option('dcb_ocr_confidence_threshold', 0.45)));
    $failure_reason = sanitize_key((string) ($result['failure_reason'] ?? ''));
    $needs_review = $failure_reason !== '' || $confidence < $threshold;
    if (!$needs_review) {
        return 0;
    }

    $title = sprintf('OCR Review: %s', sanitize_file_name((string) basename($file_path)));
    $post_id = wp_insert_post(array(
        'post_type' => 'dcb_ocr_review_queue',
        'post_status' => 'publish',
        'post_title' => $title . ' — ' . current_time('mysql'),
    ));
    if (is_wp_error($post_id) || (int) $post_id < 1) {
        return 0;
    }

    $safe_provider = sanitize_key((string) ($result['provider'] ?? 'local'));
    $safe_mode = sanitize_key((string) ($result['mode'] ?? ($safe_provider === 'remote' ? 'remote' : 'local')));
    $safe_failure_reason = dcb_ocr_normalize_failure_reason($failure_reason !== '' ? $failure_reason : 'low_confidence');
    $failure_meta = dcb_ocr_failure_meta($safe_failure_reason);
    $pages = isset($result['pages']) && is_array($result['pages']) ? $result['pages'] : array();
    $candidate_count = isset($result['ocr_candidates']) && is_array($result['ocr_candidates']) ? count($result['ocr_candidates']) : 0;
    $block_count = isset($result['template_blocks']) && is_array($result['template_blocks']) ? count($result['template_blocks']) : 0;

    $extraction_json = wp_json_encode($result);
    if (!is_string($extraction_json)) {
        $extraction_json = '{}';
    }

    update_post_meta((int) $post_id, '_dcb_ocr_review_file', sanitize_text_field((string) basename($file_path)));
    update_post_meta((int) $post_id, '_dcb_ocr_review_source_file_path', sanitize_text_field($file_path));
    update_post_meta((int) $post_id, '_dcb_ocr_review_mime', sanitize_text_field($mime));
    update_post_meta((int) $post_id, '_dcb_ocr_review_confidence', round(max(0.0, min(1.0, $confidence)), 4));
    update_post_meta((int) $post_id, '_dcb_ocr_review_confidence_bucket', dcb_confidence_bucket($confidence));
    update_post_meta((int) $post_id, '_dcb_ocr_review_threshold', $threshold);
    update_post_meta((int) $post_id, '_dcb_ocr_review_failure_reason', $safe_failure_reason);
    update_post_meta((int) $post_id, '_dcb_ocr_review_failure_label', sanitize_text_field((string) ($failure_meta['label'] ?? 'OCR issue')));
    update_post_meta((int) $post_id, '_dcb_ocr_review_recommendation', sanitize_text_field((string) ($failure_meta['recommendation'] ?? 'Review OCR output.')));
    update_post_meta((int) $post_id, '_dcb_ocr_review_provider', $safe_provider);
    update_post_meta((int) $post_id, '_dcb_ocr_review_mode', $safe_mode);
    update_post_meta((int) $post_id, '_dcb_ocr_review_engine', sanitize_text_field((string) ($result['engine'] ?? 'none')));
    update_post_meta((int) $post_id, '_dcb_ocr_review_status', 'pending_review');
    update_post_meta((int) $post_id, '_dcb_ocr_review_page_count', count($pages));
    update_post_meta((int) $post_id, '_dcb_ocr_review_candidate_field_count', $candidate_count);
    update_post_meta((int) $post_id, '_dcb_ocr_review_block_count', $block_count);
    update_post_meta((int) $post_id, '_dcb_ocr_review_extraction', $extraction_json);
    update_post_meta((int) $post_id, '_dcb_ocr_review_text', sanitize_textarea_field((string) ($result['text'] ?? '')));
    update_post_meta((int) $post_id, '_dcb_ocr_review_provenance', wp_json_encode((array) ($result['provenance'] ?? array())));
    if (isset($result['input_normalization']) && is_array($result['input_normalization'])) {
        $capture_meta = array(
            'input_source_type' => sanitize_key((string) ($result['input_source_type'] ?? 'unknown')),
            'capture_warning_count' => isset($result['input_normalization']['warnings']) && is_array($result['input_normalization']['warnings']) ? count($result['input_normalization']['warnings']) : 0,
            'normalization_improvement_proxy' => isset($result['input_normalization']['normalization_improvement_proxy']) ? round(max(0.0, min(1.0, (float) $result['input_normalization']['normalization_improvement_proxy'])), 4) : 0.0,
            'capture_recommendations' => isset($result['input_normalization']['capture_recommendations']) && is_array($result['input_normalization']['capture_recommendations']) ? $result['input_normalization']['capture_recommendations'] : array(),
        );
        update_post_meta((int) $post_id, '_dcb_ocr_review_capture_meta', wp_json_encode($capture_meta));
    }

    dcb_ocr_review_append_revision((int) $post_id, 'created', array(
        'status' => 'pending_review',
        'failure_reason' => $safe_failure_reason,
        'provider' => $safe_provider,
        'mode' => $safe_mode,
        'confidence' => round(max(0.0, min(1.0, $confidence)), 4),
    ));

    if (function_exists('do_action')) {
        do_action('dcb_ocr_review_item_created', (int) $post_id, $result);
    }

    return (int) $post_id;
}

function dcb_upload_stage_line_block_normalization(array $pages): array {
    $lines = array();
    $blocks = array();
    $line_index = 0;
    $block_index = 0;

    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $page_number = max(1, (int) ($page['page_number'] ?? 1));
        $text = (string) ($page['text'] ?? '');
        if ($text === '') {
            continue;
        }

        $text_lf = str_replace("\r", "\n", $text);
        $raw_lines_all = preg_split('/\R+/', $text_lf) ?: array();
        $page_line_total = max(1, count($raw_lines_all));

        $raw_blocks = preg_split('/\n\s*\n+/', $text_lf) ?: array();
        $running_line = 0;
        foreach ($raw_blocks as $block) {
            $clean_block = trim((string) preg_replace('/[ \t]+/', ' ', $block));
            if ($clean_block !== '' && strlen($clean_block) >= 6) {
                $block_lines = preg_split('/\R+/', (string) $block) ?: array();
                $block_line_count = max(1, count(array_filter(array_map('trim', $block_lines), static function ($v) { return $v !== ''; })));
                $line_start = $running_line;
                $line_end = $running_line + $block_line_count - 1;
                $blocks[] = array(
                    'block_index' => $block_index++,
                    'page_number' => $page_number,
                    'line_start' => max(0, $line_start),
                    'line_end' => max(0, $line_end),
                    'text' => $clean_block,
                );
            }
            $running_line += max(1, count(preg_split('/\R+/', (string) $block) ?: array()));
        }

        foreach ($raw_lines_all as $page_line_index => $line) {
            $line_string = (string) $line;
            $clean_line = trim((string) preg_replace('/\s+/', ' ', $line_string));
            if ($clean_line === '' || strlen($clean_line) < 2 || strlen($clean_line) > 180) {
                continue;
            }

            preg_match('/^(\s*)/', $line_string, $indent_match);
            $indent_count = isset($indent_match[1]) ? strlen((string) $indent_match[1]) : 0;
            $zone = 'left';
            if ($indent_count >= 14) {
                $zone = 'right';
            } elseif ($indent_count >= 6) {
                $zone = 'center';
            }

            $lines[] = array(
                'line_index' => $line_index++,
                'page_number' => $page_number,
                'page_line_index' => max(0, (int) $page_line_index),
                'line_position_ratio' => round(min(1, max(0, ((int) $page_line_index + 1) / max(1, $page_line_total))), 4),
                'indent_level' => $indent_count,
                'region_hint' => $zone,
                'text' => $clean_line,
            );
        }
    }

    return array('lines' => $lines, 'blocks' => $blocks);
}

function dcb_ocr_correction_rules_default(): array {
    return array(
        'label_aliases' => array(),
        'type_overrides' => array(),
        'section_patterns' => array(),
    );
}

function dcb_ocr_get_correction_rules(): array {
    $raw = get_option('dcb_ocr_correction_rules', array());
    if (!is_array($raw)) {
        return dcb_ocr_correction_rules_default();
    }

    $defaults = dcb_ocr_correction_rules_default();
    $aliases = isset($raw['label_aliases']) && is_array($raw['label_aliases']) ? $raw['label_aliases'] : array();
    $type_overrides = isset($raw['type_overrides']) && is_array($raw['type_overrides']) ? $raw['type_overrides'] : array();
    $section_patterns = isset($raw['section_patterns']) && is_array($raw['section_patterns']) ? $raw['section_patterns'] : array();

    $clean_aliases = array();
    foreach ($aliases as $from => $to) {
        $from_key = dcb_upload_normalize_text((string) $from);
        $to_value = sanitize_text_field((string) $to);
        if ($from_key !== '' && $to_value !== '') {
            $clean_aliases[$from_key] = $to_value;
        }
    }

    $clean_types = array();
    foreach ($type_overrides as $label_key => $type) {
        $k = dcb_upload_normalize_text((string) $label_key);
        $t = sanitize_key((string) $type);
        if ($k !== '' && $t !== '') {
            $clean_types[$k] = $t;
        }
    }

    $clean_sections = array();
    foreach ($section_patterns as $pattern => $section_label) {
        $p = dcb_upload_normalize_text((string) $pattern);
        $s = sanitize_text_field((string) $section_label);
        if ($p !== '' && $s !== '') {
            $clean_sections[$p] = $s;
        }
    }

    return array_merge($defaults, array(
        'label_aliases' => $clean_aliases,
        'type_overrides' => $clean_types,
        'section_patterns' => $clean_sections,
    ));
}

function dcb_ocr_update_correction_rules_from_review(array $review_rows): void {
    if (empty($review_rows)) {
        return;
    }

    $rules = dcb_ocr_get_correction_rules();
    $aliases = isset($rules['label_aliases']) && is_array($rules['label_aliases']) ? $rules['label_aliases'] : array();
    $type_overrides = isset($rules['type_overrides']) && is_array($rules['type_overrides']) ? $rules['type_overrides'] : array();

    foreach ($review_rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $decision = sanitize_key((string) ($row['decision'] ?? 'accept'));
        if ($decision === 'reject') {
            continue;
        }

        $label = sanitize_text_field((string) ($row['field_label'] ?? ''));
        $source = sanitize_text_field((string) ($row['source_text_snippet'] ?? ''));
        $suggested_type = sanitize_key((string) ($row['suggested_type'] ?? ''));

        $source_key = dcb_upload_normalize_text($source);
        $label_key = dcb_upload_normalize_text($label);
        if ($source_key !== '' && $label !== '' && $source_key !== $label_key) {
            $aliases[$source_key] = $label;
        }
        if ($label_key !== '' && $suggested_type !== '') {
            $type_overrides[$label_key] = $suggested_type;
        }
    }

    if (count($aliases) > 250) {
        $aliases = array_slice($aliases, -250, null, true);
    }
    if (count($type_overrides) > 250) {
        $type_overrides = array_slice($type_overrides, -250, null, true);
    }

    $updated = array(
        'label_aliases' => $aliases,
        'type_overrides' => $type_overrides,
        'section_patterns' => isset($rules['section_patterns']) && is_array($rules['section_patterns']) ? $rules['section_patterns'] : array(),
    );

    update_option('dcb_ocr_correction_rules', $updated, false);

    if (function_exists('do_action')) {
        do_action('dcb_ocr_correction_rules_updated', $updated);
    }
}

function dcb_ocr_is_probable_heading(string $line): bool {
    $line = trim($line);
    if ($line === '') {
        return false;
    }
    if (preg_match('/^(section|part|page)\s+[a-z0-9]+[:\-]?/i', $line)) {
        return true;
    }
    $alpha = (int) preg_match_all('/[A-Za-z]/', $line);
    $upper = (int) preg_match_all('/[A-Z]/', $line);
    $ratio = $alpha > 0 ? ($upper / max(1, $alpha)) : 0;
    return strlen($line) <= 90 && $ratio >= 0.72 && !preg_match('/[:_]{1,}/', $line);
}

function dcb_ocr_is_instructional_line(string $line): bool {
    $line = strtolower(trim($line));
    if ($line === '') {
        return false;
    }
    return (bool) preg_match('/\b(please|instructions?|complete all|attach|submit|for office use|do not write|read carefully|return this form)\b/', $line);
}

function dcb_ocr_extract_anchors_from_line(string $line): array {
    $anchors = array();
    if (preg_match('/_{3,}|\.{3,}|-{3,}/', $line)) {
        $anchors[] = 'blank_line';
    }
    if (preg_match('/:\s*$/', $line) || preg_match('/^[^:]{2,80}:/', $line)) {
        $anchors[] = 'colon_label';
    }
    if (preg_match('/\[[ xX]?\]/', $line) || strpos($line, '☐') !== false || strpos($line, '☑') !== false) {
        $anchors[] = 'checkbox_marker';
    }
    if (preg_match('/\byes\s*\/?\s*no\b|\bno\s*\/?\s*yes\b/i', $line)) {
        $anchors[] = 'yes_no_pair';
    }
    if (preg_match('/\b(signature|sign here|signed by)\b/i', $line)) {
        $anchors[] = 'signature_line';
    }
    if (preg_match('/\b(date|dob|birth date|mm\/dd|yyyy)\b/i', $line)) {
        $anchors[] = 'date_line';
    }
    return array_values(array_unique($anchors));
}

function dcb_ocr_detect_section_candidates(array $lines): array {
    $sections = array();
    foreach ($lines as $line_row) {
        if (!is_array($line_row)) {
            continue;
        }
        $text = (string) ($line_row['text'] ?? '');
        if ($text === '' || strlen($text) < 4 || strlen($text) > 110) {
            continue;
        }
        if (!dcb_ocr_is_probable_heading($text) && !preg_match('/^(section|part)\b/i', $text)) {
            continue;
        }
        $label = sanitize_text_field(trim(preg_replace('/\s+/', ' ', $text)));
        $sections[] = array(
            'section_key' => sanitize_key($label),
            'label' => $label,
            'page_number' => max(1, (int) ($line_row['page_number'] ?? 1)),
            'line_index' => max(0, (int) ($line_row['line_index'] ?? 0)),
            'confidence_score' => 0.70,
        );
    }

    if (empty($sections)) {
        $sections[] = array('section_key' => 'main_section', 'label' => 'Main Section', 'page_number' => 1, 'line_index' => 0, 'confidence_score' => 0.50);
    }
    return $sections;
}

function dcb_ocr_detect_table_candidates(array $lines): array {
    $rows = array();
    foreach ($lines as $line_row) {
        if (!is_array($line_row)) {
            continue;
        }
        $line = (string) ($line_row['text'] ?? '');
        $looks_table = preg_match('/\|/', $line)
            || preg_match('/\s{3,}[A-Za-z0-9]/', $line)
            || preg_match('/\b(item|qty|quantity|description|amount|date|time)\b/i', $line);
        if (!$looks_table) {
            continue;
        }
        $rows[] = array(
            'page_number' => max(1, (int) ($line_row['page_number'] ?? 1)),
            'line_index' => max(0, (int) ($line_row['line_index'] ?? 0)),
            'text' => sanitize_text_field($line),
        );
    }

    if (count($rows) < 2) {
        return array();
    }

    return array(array(
        'table_key' => 'table_1',
        'row_count_hint' => count($rows),
        'page_number' => (int) ($rows[0]['page_number'] ?? 1),
        'line_start' => (int) ($rows[0]['line_index'] ?? 0),
        'line_end' => (int) ($rows[count($rows) - 1]['line_index'] ?? 0),
        'confidence_score' => min(0.9, 0.50 + (0.05 * count($rows))),
    ));
}

function dcb_ocr_detect_signature_candidates(array $lines): array {
    $out = array();
    foreach ($lines as $line_row) {
        if (!is_array($line_row)) {
            continue;
        }
        $line = (string) ($line_row['text'] ?? '');
        if (!preg_match('/\b(signature|initials|sign here|date)\b/i', $line)) {
            continue;
        }
        $out[] = array(
            'page_number' => max(1, (int) ($line_row['page_number'] ?? 1)),
            'line_index' => max(0, (int) ($line_row['line_index'] ?? 0)),
            'text' => sanitize_text_field($line),
            'kind' => preg_match('/\binitials\b/i', $line) ? 'initials' : (preg_match('/\bdate\b/i', $line) ? 'date' : 'signature'),
            'confidence_score' => 0.74,
        );
    }
    return $out;
}

function dcb_ocr_detect_signature_date_pairs(array $signature_candidates): array {
    if (empty($signature_candidates)) {
        return array();
    }

    $pairs = array();
    foreach ($signature_candidates as $idx => $row) {
        if (!is_array($row)) {
            continue;
        }
        $kind = sanitize_key((string) ($row['kind'] ?? 'signature'));
        if ($kind !== 'signature' && $kind !== 'initials') {
            continue;
        }
        $page = max(1, (int) ($row['page_number'] ?? 1));
        $line_index = max(0, (int) ($row['line_index'] ?? 0));

        $best_date = null;
        $best_distance = 9999;
        foreach ($signature_candidates as $date_row) {
            if (!is_array($date_row)) {
                continue;
            }
            if (sanitize_key((string) ($date_row['kind'] ?? '')) !== 'date') {
                continue;
            }
            if (max(1, (int) ($date_row['page_number'] ?? 1)) !== $page) {
                continue;
            }
            $distance = abs($line_index - max(0, (int) ($date_row['line_index'] ?? 0)));
            if ($distance < $best_distance && $distance <= 10) {
                $best_distance = $distance;
                $best_date = $date_row;
            }
        }

        $pairs[] = array(
            'pair_key' => 'sig_pair_' . ($idx + 1),
            'signature_page_number' => $page,
            'signature_line_index' => $line_index,
            'signature_text' => sanitize_text_field((string) ($row['text'] ?? '')),
            'signature_kind' => $kind,
            'date_page_number' => is_array($best_date) ? max(1, (int) ($best_date['page_number'] ?? $page)) : 0,
            'date_line_index' => is_array($best_date) ? max(0, (int) ($best_date['line_index'] ?? 0)) : 0,
            'date_text' => is_array($best_date) ? sanitize_text_field((string) ($best_date['text'] ?? '')) : '',
            'confidence_score' => is_array($best_date) ? 0.82 : 0.61,
        );
    }

    return $pairs;
}

function dcb_ocr_detect_layout_regions(array $lines, array $sections, array $tables, array $signature_candidates): array {
    $regions = array();

    foreach ($sections as $idx => $section) {
        if (!is_array($section)) {
            continue;
        }
        $regions[] = array(
            'region_key' => 'section_region_' . ($idx + 1),
            'region_type' => 'section',
            'region_label' => sanitize_text_field((string) ($section['label'] ?? 'Section')),
            'page_number' => max(1, (int) ($section['page_number'] ?? 1)),
            'line_start' => max(0, (int) ($section['line_index'] ?? 0)),
            'line_end' => max(0, (int) ($section['line_index'] ?? 0)) + 20,
            'region_hint' => 'left',
        );
    }

    foreach ($tables as $idx => $table) {
        if (!is_array($table)) {
            continue;
        }
        $regions[] = array(
            'region_key' => 'table_region_' . ($idx + 1),
            'region_type' => 'table',
            'region_label' => 'Table Area',
            'page_number' => max(1, (int) ($table['page_number'] ?? 1)),
            'line_start' => max(0, (int) ($table['line_start'] ?? 0)),
            'line_end' => max(0, (int) ($table['line_end'] ?? 0)),
            'region_hint' => 'left',
        );
    }

    foreach ($signature_candidates as $idx => $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $kind = sanitize_key((string) ($candidate['kind'] ?? 'signature'));
        if ($kind !== 'signature' && $kind !== 'initials') {
            continue;
        }
        $regions[] = array(
            'region_key' => 'signature_region_' . ($idx + 1),
            'region_type' => 'signature',
            'region_label' => 'Signature/Attestation Area',
            'page_number' => max(1, (int) ($candidate['page_number'] ?? 1)),
            'line_start' => max(0, (int) ($candidate['line_index'] ?? 0)),
            'line_end' => max(0, (int) ($candidate['line_index'] ?? 0)) + 4,
            'region_hint' => 'right',
        );
    }

    $zone_counts = array();
    foreach ($lines as $line_row) {
        if (!is_array($line_row)) {
            continue;
        }
        $zone = sanitize_key((string) ($line_row['region_hint'] ?? 'left'));
        if ($zone === '') {
            $zone = 'left';
        }
        if (!isset($zone_counts[$zone])) {
            $zone_counts[$zone] = 0;
        }
        $zone_counts[$zone]++;
    }
    foreach ($zone_counts as $zone => $count) {
        if ($count < 4) {
            continue;
        }
        $regions[] = array(
            'region_key' => 'zone_' . sanitize_key($zone),
            'region_type' => 'field_zone',
            'region_label' => ucwords(str_replace('_', ' ', (string) $zone)) . ' Field Zone',
            'page_number' => 1,
            'line_start' => 0,
            'line_end' => 999,
            'region_hint' => sanitize_key((string) $zone),
        );
    }

    return $regions;
}

function dcb_ocr_build_document_model(array $pages): array {
    $normalized = dcb_upload_stage_line_block_normalization($pages);
    $lines = isset($normalized['lines']) && is_array($normalized['lines']) ? $normalized['lines'] : array();
    $blocks = isset($normalized['blocks']) && is_array($normalized['blocks']) ? $normalized['blocks'] : array();

    $anchors = array();
    foreach ($lines as $line_row) {
        if (!is_array($line_row)) {
            continue;
        }
        $line = (string) ($line_row['text'] ?? '');
        foreach (dcb_ocr_extract_anchors_from_line($line) as $anchor) {
            $anchors[] = array(
                'anchor' => $anchor,
                'page_number' => max(1, (int) ($line_row['page_number'] ?? 1)),
                'line_index' => max(0, (int) ($line_row['line_index'] ?? 0)),
                'source_text' => sanitize_text_field(mb_substr($line, 0, 140)),
            );
        }
    }

    $section_candidates = dcb_ocr_detect_section_candidates($lines);
    $table_candidates = dcb_ocr_detect_table_candidates($lines);
    $signature_candidates = dcb_ocr_detect_signature_candidates($lines);
    $signature_pairs = dcb_ocr_detect_signature_date_pairs($signature_candidates);
    $layout_regions = dcb_ocr_detect_layout_regions($lines, $section_candidates, $table_candidates, $signature_candidates);

    $model_pages = array();
    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $model_pages[] = array(
            'page_number' => max(1, (int) ($page['page_number'] ?? 1)),
            'engine' => sanitize_text_field((string) ($page['engine'] ?? 'unknown')),
            'text_length' => max(0, (int) ($page['text_length'] ?? strlen((string) ($page['text'] ?? '')))),
            'confidence_proxy' => round(max(0, min(1, (float) ($page['confidence_proxy'] ?? 0))), 4),
        );
    }

    $model = array(
        'model_version' => '1.1',
        'pages' => $model_pages,
        'blocks' => $blocks,
        'lines' => $lines,
        'anchors' => $anchors,
        'field_groups' => array(),
        'section_candidates' => $section_candidates,
        'table_candidates' => $table_candidates,
        'signature_date_candidates' => $signature_candidates,
        'signature_date_pairs' => $signature_pairs,
        'layout_regions' => $layout_regions,
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_ocr_document_model', $model, $pages) : $model;
}

function dcb_upload_guess_field_shape(string $label, string $source_line): array {
    $lower = strtolower($label . ' ' . $source_line);
    $shape = array('suggested_type' => 'text', 'form_type' => 'text', 'max' => 180, 'options' => array(), 'detected_type' => 'text');

    if (preg_match('/\b(consent|agree|attest|authorize|acknowledge|permission)\b/', $lower)) {
        return array('suggested_type' => 'checkbox', 'form_type' => 'checkbox', 'max' => 0, 'options' => array(), 'detected_type' => 'checkbox');
    }
    if (preg_match('/\byes\s*\/?\s*no\b|\bno\s*\/?\s*yes\b|\[[ xX]?\]\s*yes.*\[[ xX]?\]\s*no/', $lower)) {
        return array('suggested_type' => 'yes_no', 'form_type' => 'yes_no', 'max' => 0, 'options' => array('yes' => 'Yes', 'no' => 'No'), 'detected_type' => 'yes_no');
    }
    if (preg_match('/\b(e\-?mail)\b/', $lower)) {
        return array('suggested_type' => 'email', 'form_type' => 'email', 'max' => 190, 'options' => array(), 'detected_type' => 'email');
    }
    if (preg_match('/\b(phone|mobile|cell|fax|tel)\b/', $lower)) {
        return array('suggested_type' => 'phone', 'form_type' => 'text', 'max' => 40, 'options' => array(), 'detected_type' => 'phone');
    }
    if (preg_match('/\b(date of birth|dob|birth date)\b/', $lower)) {
        return array('suggested_type' => 'dob', 'form_type' => 'date', 'max' => 30, 'options' => array(), 'detected_type' => 'dob');
    }
    if (preg_match('/\b(date|mm\/dd|yyyy)\b/', $lower)) {
        return array('suggested_type' => 'date', 'form_type' => 'date', 'max' => 30, 'options' => array(), 'detected_type' => 'date');
    }
    if (preg_match('/\btime\b|\bam\b|\bpm\b/', $lower)) {
        return array('suggested_type' => 'time', 'form_type' => 'time', 'max' => 30, 'options' => array(), 'detected_type' => 'time');
    }
    if (preg_match('/\b(signature|sign here|signed by)\b/', $lower)) {
        return array('suggested_type' => 'signature', 'form_type' => 'text', 'max' => 180, 'options' => array(), 'detected_type' => 'signature');
    }
    if (preg_match('/\binitials?\b/', $lower)) {
        return array('suggested_type' => 'initials', 'form_type' => 'text', 'max' => 12, 'options' => array(), 'detected_type' => 'initials');
    }
    if (preg_match('/\b(amount|total|qty|quantity|number|no\.)\b/', $lower)) {
        return array('suggested_type' => 'number', 'form_type' => 'number', 'max' => 40, 'options' => array(), 'detected_type' => 'number');
    }
    if (preg_match('/\b(address|notes|comments|description|history|reason)\b/', $lower)) {
        return array('suggested_type' => 'textarea', 'form_type' => 'text', 'max' => 400, 'options' => array(), 'detected_type' => 'textarea');
    }
    if (preg_match('/\b(select|choose|option)\b/', $lower)) {
        return array('suggested_type' => 'select', 'form_type' => 'select', 'max' => 0, 'options' => array('option_1' => 'Option 1', 'option_2' => 'Option 2'), 'detected_type' => 'select');
    }

    return $shape;
}

function dcb_upload_guess_required(string $label, string $source_line, string $signal): bool {
    $lower = strtolower($label . ' ' . $source_line);
    if (strpos($source_line, '*') !== false) {
        return true;
    }
    if (preg_match('/\b(required|mandatory|must complete)\b/', $lower)) {
        return true;
    }
    if ($signal === 'checkbox_line' || $signal === 'yes_no_pair') {
        return true;
    }
    if (preg_match('/\b(signature|date of birth|dob|patient name|consent)\b/', $lower)) {
        return true;
    }
    return false;
}

function dcb_upload_stage_field_candidate_extraction(array $document_model, array $page_meta): array {
    $lines = isset($document_model['lines']) && is_array($document_model['lines']) ? $document_model['lines'] : array();
    $sections = isset($document_model['section_candidates']) && is_array($document_model['section_candidates']) ? $document_model['section_candidates'] : array();
    $rules = dcb_ocr_get_correction_rules();
    $candidates = array();
    $seen = array();

    usort($lines, static function ($a, $b) {
        $pa = (int) ($a['page_number'] ?? 1);
        $pb = (int) ($b['page_number'] ?? 1);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        return ((int) ($a['line_index'] ?? 0)) <=> ((int) ($b['line_index'] ?? 0));
    });

    $current_section = 'main_section';
    foreach ($sections as $section_row) {
        if (!is_array($section_row)) {
            continue;
        }
        if ((int) ($section_row['line_index'] ?? 0) <= 0) {
            continue;
        }
        $current_section = sanitize_key((string) ($section_row['section_key'] ?? 'main_section'));
        break;
    }

    foreach ($lines as $line_row) {
        if (!is_array($line_row)) {
            continue;
        }
        $line = (string) ($line_row['text'] ?? '');
        $page_number = max(1, (int) ($line_row['page_number'] ?? 1));
        if ($line === '') {
            continue;
        }

        if (dcb_ocr_is_probable_heading($line)) {
            $current_section = sanitize_key($line);
            if ($current_section === '') {
                $current_section = 'main_section';
            }
            continue;
        }

        if (dcb_ocr_is_instructional_line($line)) {
            continue;
        }

        $candidate_label = '';
        $signal = '';
        $base_confidence = 0.40;

        if (preg_match('/^([A-Za-z][A-Za-z0-9 \-\(\)\/\.,&\']{2,85})\s*:\s*(?:[_\.\- ]{2,})?$/', $line, $m)) {
            $candidate_label = trim((string) $m[1]);
            $signal = 'colon_label';
            $base_confidence = 0.68;
        } elseif (preg_match('/^([A-Za-z][A-Za-z0-9 \-\(\)\/\.,&\']{2,85})\s+_{3,}$/', $line, $m)) {
            $candidate_label = trim((string) $m[1]);
            $signal = 'underscore_label';
            $base_confidence = 0.71;
        } elseif (preg_match('/^([A-Za-z][A-Za-z0-9 \-\(\)\/\.,&\']{2,90})\s*(?:\[[ xX]?\]|☐|☑).*(?:\[[ xX]?\]|☐|☑)/u', $line, $m)) {
            $candidate_label = trim((string) $m[1]);
            $signal = 'checkbox_line';
            $base_confidence = 0.67;
        } elseif (preg_match('/^([A-Za-z][A-Za-z0-9 \-\(\)\/\.,&\']{2,90})\s*(?:yes\s*\/?\s*no|no\s*\/?\s*yes)\b/i', $line, $m)) {
            $candidate_label = trim((string) $m[1]);
            $signal = 'yes_no_pair';
            $base_confidence = 0.72;
        } elseif (preg_match('/^([A-Za-z][A-Za-z0-9 \-\(\)\/\.,&\']{2,90})\s*(?:signature|sign here|initials|date)\b/i', $line, $m)) {
            $candidate_label = trim((string) $m[1]);
            $signal = 'signature_date_cue';
            $base_confidence = 0.65;
        }

        if ($candidate_label === '' || strlen($candidate_label) < 3 || strlen($candidate_label) > 95) {
            continue;
        }

        if (preg_match('/\b(please|instructions?|for office use|return this|do not write|reviewed by)\b/i', $candidate_label)) {
            continue;
        }

        $label_key = dcb_upload_normalize_text($candidate_label);
        if ($label_key !== '' && !empty($rules['label_aliases'][$label_key])) {
            $candidate_label = sanitize_text_field((string) $rules['label_aliases'][$label_key]);
        }

        $key = sanitize_key(str_replace(array('/', '-', '.'), '_', $candidate_label));
        if ($key === '') {
            continue;
        }

        $shape = dcb_upload_guess_field_shape($candidate_label, $line);
        if ($signal === 'checkbox_line') {
            $shape = array('suggested_type' => 'checkbox', 'form_type' => 'checkbox', 'max' => 0, 'options' => array(), 'detected_type' => 'checkbox');
        } elseif ($signal === 'yes_no_pair') {
            $shape = array('suggested_type' => 'yes_no', 'form_type' => 'yes_no', 'max' => 0, 'options' => array('yes' => 'Yes', 'no' => 'No'), 'detected_type' => 'yes_no');
        }
        $required = dcb_upload_guess_required($candidate_label, $line, $signal);
        $snippet = trim(substr($line, 0, 140));

        $page_conf = isset($page_meta[$page_number]['confidence_proxy']) ? (float) $page_meta[$page_number]['confidence_proxy'] : 0.0;
        $page_norm = isset($page_meta[$page_number]['normalization']) && is_array($page_meta[$page_number]['normalization']) ? $page_meta[$page_number]['normalization'] : array();
        $quality = isset($page_norm['quality']) && is_array($page_norm['quality']) ? $page_norm['quality'] : array();
        $quality_bucket = sanitize_key((string) ($quality['quality_bucket'] ?? ''));
        $anchor_bonus = 0.0;
        if (preg_match('/_{3,}|\.{3,}/', $line)) {
            $anchor_bonus += 0.08;
        }
        if (preg_match('/\[[ xX]?\]/', $line)) {
            $anchor_bonus += 0.07;
        }
        if (preg_match('/\b(signature|date)\b/i', $line)) {
            $anchor_bonus += 0.06;
        }
        $normalization_bonus = 0.0;
        $stage_diag = isset($page_norm['stage_diagnostics']) && is_array($page_norm['stage_diagnostics']) ? $page_norm['stage_diagnostics'] : array();
        foreach (array('orientation_correction', 'deskew', 'crop_cleanup', 'contrast_cleanup') as $stage_key) {
            if (!empty($stage_diag[$stage_key]['applied'])) {
                $normalization_bonus += 0.01;
            }
        }
        $warning_penalty = 0.0;
        $capture_warning_count = isset($page_norm['capture_warnings']) && is_array($page_norm['capture_warnings']) ? count($page_norm['capture_warnings']) : 0;
        if ($capture_warning_count >= 3) {
            $warning_penalty += 0.08;
        } elseif ($capture_warning_count >= 1) {
            $warning_penalty += 0.03;
        }
        if ($quality_bucket === 'low') {
            $warning_penalty += 0.07;
        } elseif ($quality_bucket === 'medium') {
            $warning_penalty += 0.02;
        }

        $score = max(0.0, min(1.0, $base_confidence + (0.18 * $page_conf) + $anchor_bonus + $normalization_bonus - $warning_penalty));
        $source_engine = isset($page_meta[$page_number]['engine']) ? sanitize_key((string) $page_meta[$page_number]['engine']) : '';

        if ($label_key !== '' && !empty($rules['type_overrides'][$label_key])) {
            $shape['suggested_type'] = sanitize_key((string) $rules['type_overrides'][$label_key]);
            if (in_array($shape['suggested_type'], dcb_allowed_field_types(), true)) {
                $shape['form_type'] = $shape['suggested_type'];
            }
        }

        $candidate = array(
            'field_label' => $candidate_label,
            'suggested_key' => $key,
            'suggested_type' => (string) ($shape['suggested_type'] ?? 'text'),
            'detected_type' => (string) ($shape['detected_type'] ?? ($shape['suggested_type'] ?? 'text')),
            'required_guess' => $required,
            'page_number' => $page_number,
            'line_index' => max(0, (int) ($line_row['line_index'] ?? 0)),
            'section_hint' => $current_section,
            'region_hint' => sanitize_key((string) ($line_row['region_hint'] ?? 'left')),
            'source_text_snippet' => $snippet,
            'confidence_score' => round($score, 4),
            'confidence_bucket' => dcb_confidence_bucket($score),
            'signal' => $signal,
            'source_engine' => $source_engine,
            'warning_state' => $score < 0.42 ? 'review_needed' : 'none',
            'form_type' => (string) ($shape['form_type'] ?? 'text'),
            'max' => isset($shape['max']) ? (int) $shape['max'] : 180,
            'options' => isset($shape['options']) && is_array($shape['options']) ? $shape['options'] : array(),
            'confidence_reasons' => array_values(array_filter(array('signal_' . $signal, 'page_confidence', 'anchor_context', $normalization_bonus > 0 ? 'normalization_applied' : '', $warning_penalty > 0 ? 'capture_warning_penalty' : ''))),
        );

        if (function_exists('apply_filters')) {
            $candidate = (array) apply_filters('dcb_ocr_candidate_enriched', $candidate, $line_row, $document_model, $page_meta);
        }

        if (isset($seen[$key])) {
            $existing_idx = (int) $seen[$key];
            if (($candidates[$existing_idx]['confidence_score'] ?? 0) < $candidate['confidence_score']) {
                $candidates[$existing_idx] = $candidate;
            }
            continue;
        }

        $seen[$key] = count($candidates);
        $candidates[] = $candidate;

        if (count($candidates) >= 80) {
            break;
        }
    }

    return $candidates;
}

function dcb_upload_stage_template_block_extraction(array $document_model): array {
    $blocks = isset($document_model['blocks']) && is_array($document_model['blocks']) ? $document_model['blocks'] : array();
    $lines = isset($document_model['lines']) && is_array($document_model['lines']) ? $document_model['lines'] : array();
    $template_blocks = array();
    $seen = array();

    foreach ($lines as $line_row) {
        if (!is_array($line_row)) {
            continue;
        }
        $line = trim((string) ($line_row['text'] ?? ''));
        if ($line === '' || strlen($line) < 3 || strlen($line) > 120) {
            continue;
        }
        if (dcb_ocr_is_probable_heading($line)) {
            $template_blocks[] = array('type' => 'section_header', 'text' => $line);
        }
    }

    foreach ($blocks as $row) {
        if (!is_array($row)) {
            continue;
        }
        $text = trim((string) ($row['text'] ?? ''));
        $page_number = max(1, (int) ($row['page_number'] ?? 1));
        if ($text === '' || strlen($text) < 3) {
            continue;
        }

        $key = strtolower(preg_replace('/\s+/', ' ', $text));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $entry_type = dcb_ocr_is_instructional_line($text) ? 'paragraph' : (dcb_ocr_is_probable_heading($text) ? 'section_header' : 'paragraph');
        $entry = array('type' => $entry_type, 'text' => $text, 'source_page' => $page_number, 'source_text_snippet' => mb_substr($text, 0, 120));

        $clean = dcb_normalize_template_block($entry);
        if ($clean !== null) {
            $template_blocks[] = $clean;
        }

        if (count($template_blocks) >= 40) {
            break;
        }
    }

    if (empty($template_blocks)) {
        $template_blocks[] = array('type' => 'heading', 'text' => 'Scanned Form', 'level' => 2);
        $template_blocks[] = array('type' => 'paragraph', 'text' => 'Please complete all required fields. OCR-generated draft content should be reviewed before publishing.');
    }

    return $template_blocks;
}

function dcb_ocr_normalize_candidates_runtime(array $candidates): array {
    if (function_exists('dcb_normalize_ocr_candidates')) {
        return dcb_normalize_ocr_candidates($candidates);
    }

    $out = array();
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $field_label = sanitize_text_field((string) ($candidate['field_label'] ?? ''));
        $suggested_key = sanitize_key((string) ($candidate['suggested_key'] ?? ''));
        if ($field_label === '' || $suggested_key === '') {
            continue;
        }
        $out[] = array(
            'field_label' => $field_label,
            'suggested_key' => $suggested_key,
            'suggested_type' => sanitize_key((string) ($candidate['suggested_type'] ?? 'text')),
            'detected_type' => sanitize_key((string) ($candidate['detected_type'] ?? '')),
            'required_guess' => !empty($candidate['required_guess']),
            'page_number' => max(1, (int) ($candidate['page_number'] ?? 1)),
            'line_index' => max(0, (int) ($candidate['line_index'] ?? 0)),
            'section_hint' => sanitize_key((string) ($candidate['section_hint'] ?? '')),
            'region_hint' => sanitize_key((string) ($candidate['region_hint'] ?? '')),
            'source_text_snippet' => sanitize_text_field((string) ($candidate['source_text_snippet'] ?? '')),
            'confidence_bucket' => sanitize_key((string) ($candidate['confidence_bucket'] ?? 'low')),
            'confidence_score' => round(max(0, min(1, (float) ($candidate['confidence_score'] ?? 0))), 4),
            'source_engine' => sanitize_key((string) ($candidate['source_engine'] ?? '')),
            'warning_state' => sanitize_key((string) ($candidate['warning_state'] ?? 'none')),
        );
    }

    return $out;
}

function dcb_ocr_build_digital_twin_hints(array $document_model, array $fields): array {
    $layout_regions = isset($document_model['layout_regions']) && is_array($document_model['layout_regions']) ? $document_model['layout_regions'] : array();
    $signature_pairs = isset($document_model['signature_date_pairs']) && is_array($document_model['signature_date_pairs']) ? $document_model['signature_date_pairs'] : array();
    $table_regions = isset($document_model['table_candidates']) && is_array($document_model['table_candidates']) ? $document_model['table_candidates'] : array();

    $field_layout = array();
    foreach ($fields as $index => $field) {
        if (!is_array($field)) {
            continue;
        }
        $key = sanitize_key((string) ($field['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $meta = isset($field['ocr_meta']) && is_array($field['ocr_meta']) ? $field['ocr_meta'] : array();
        $field_layout[] = array(
            'field_key' => $key,
            'page_number' => max(1, (int) ($meta['page_number'] ?? 1)),
            'line_index' => max(0, (int) ($meta['line_index'] ?? $index)),
            'section_hint' => sanitize_key((string) ($field['section_hint'] ?? ($meta['section_hint'] ?? 'main_section'))),
            'region_hint' => sanitize_key((string) ($meta['region_hint'] ?? 'left')),
            'order_index' => $index,
            'confidence_bucket' => sanitize_key((string) ($meta['confidence_bucket'] ?? 'low')),
        );
    }

    $hints = array(
        'hint_version' => '1.0',
        'render_style' => 'paper_like',
        'page_count' => isset($document_model['pages']) && is_array($document_model['pages']) ? count($document_model['pages']) : 1,
        'layout_regions' => $layout_regions,
        'field_layout' => $field_layout,
        'signature_pairs' => $signature_pairs,
        'table_regions' => $table_regions,
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_ocr_digital_twin_hints', $hints, $document_model, $fields) : $hints;
}

function dcb_upload_stage_draft_schema_generation(array $candidates, string $label, array $page_meta, array $document_model = array(), array $template_blocks = array()): array {
    usort($candidates, static function ($a, $b) {
        $page_a = (int) ($a['page_number'] ?? 1);
        $page_b = (int) ($b['page_number'] ?? 1);
        if ($page_a !== $page_b) {
            return $page_a <=> $page_b;
        }
        $line_a = (int) ($a['line_index'] ?? 0);
        $line_b = (int) ($b['line_index'] ?? 0);
        if ($line_a !== $line_b) {
            return $line_a <=> $line_b;
        }
        return ((float) ($b['confidence_score'] ?? 0)) <=> ((float) ($a['confidence_score'] ?? 0));
    });

    $fields = array();
    $seen = array();
    $sections_index = array();
    $section_order = array();
    $confidence_counts = array('low' => 0, 'medium' => 0, 'high' => 0);

    $section_rows = isset($document_model['section_candidates']) && is_array($document_model['section_candidates']) ? $document_model['section_candidates'] : array();
    foreach ($section_rows as $section_row) {
        if (!is_array($section_row)) {
            continue;
        }
        $section_key = sanitize_key((string) ($section_row['section_key'] ?? ''));
        $section_label = sanitize_text_field((string) ($section_row['label'] ?? ''));
        if ($section_key === '' || $section_label === '') {
            continue;
        }
        if (!isset($sections_index[$section_key])) {
            $sections_index[$section_key] = array('key' => $section_key, 'label' => $section_label, 'field_keys' => array());
            $section_order[] = $section_key;
        }
    }

    if (empty($sections_index)) {
        $sections_index['main_section'] = array('key' => 'main_section', 'label' => 'Main Section', 'field_keys' => array());
        $section_order[] = 'main_section';
    }

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }

        $key = sanitize_key((string) ($candidate['suggested_key'] ?? ''));
        $field_label = sanitize_text_field((string) ($candidate['field_label'] ?? ''));
        if ($key === '' || $field_label === '' || isset($seen[$key])) {
            continue;
        }

        $form_type = sanitize_key((string) ($candidate['form_type'] ?? 'text'));
        if (!in_array($form_type, dcb_allowed_field_types(), true)) {
            $form_type = 'text';
        }

        $field = array(
            'key' => $key,
            'label' => $field_label,
            'type' => $form_type,
            'required' => !empty($candidate['required_guess']),
            'ocr_meta' => array(
                'page_number' => max(1, (int) ($candidate['page_number'] ?? 1)),
                'line_index' => max(0, (int) ($candidate['line_index'] ?? 0)),
                'region_hint' => sanitize_key((string) ($candidate['region_hint'] ?? 'left')),
                'section_hint' => sanitize_key((string) ($candidate['section_hint'] ?? 'main_section')),
                'source_text_snippet' => sanitize_text_field((string) ($candidate['source_text_snippet'] ?? '')),
                'confidence_bucket' => sanitize_key((string) ($candidate['confidence_bucket'] ?? 'low')),
                'confidence_score' => (float) ($candidate['confidence_score'] ?? 0),
                'suggested_type' => sanitize_key((string) ($candidate['suggested_type'] ?? 'text')),
                'detected_type' => sanitize_key((string) ($candidate['detected_type'] ?? ($candidate['suggested_type'] ?? 'text'))),
                'signal' => sanitize_key((string) ($candidate['signal'] ?? 'heuristic')),
                'source_engine' => sanitize_key((string) ($candidate['source_engine'] ?? '')),
                'warning_state' => sanitize_key((string) ($candidate['warning_state'] ?? 'none')),
                'review_state' => 'pending',
            ),
        );

        if ($form_type === 'text' && !empty($candidate['max']) && (int) $candidate['max'] > 0) {
            $field['max'] = (int) $candidate['max'];
        }
        if ($form_type === 'select') {
            $field['options'] = !empty($candidate['options']) && is_array($candidate['options']) ? $candidate['options'] : array('yes' => 'Yes', 'no' => 'No');
        }

        $bucket = (string) ($field['ocr_meta']['confidence_bucket'] ?? 'low');
        if (!isset($confidence_counts[$bucket])) {
            $bucket = 'low';
        }
        $confidence_counts[$bucket]++;

        $fields[] = $field;
        $seen[$key] = true;

        $section_hint = sanitize_key((string) ($candidate['section_hint'] ?? 'main_section'));
        if ($section_hint === '' || !isset($sections_index[$section_hint])) {
            $section_hint = 'main_section';
            if (!isset($sections_index[$section_hint])) {
                $sections_index[$section_hint] = array('key' => $section_hint, 'label' => 'Main Section', 'field_keys' => array());
                $section_order[] = $section_hint;
            }
        }
        $sections_index[$section_hint]['field_keys'][] = $key;

        if (count($fields) >= 50) {
            break;
        }
    }

    $sections = array();
    foreach ($section_order as $section_key) {
        if (empty($sections_index[$section_key]['field_keys'])) {
            continue;
        }
        $sections[] = array(
            'key' => $sections_index[$section_key]['key'],
            'label' => $sections_index[$section_key]['label'],
            'field_keys' => array_values(array_unique($sections_index[$section_key]['field_keys'])),
        );
    }

    if (empty($sections)) {
        $sections[] = array('key' => 'main_section', 'label' => 'Main Section', 'field_keys' => array_map(static function ($f) { return (string) ($f['key'] ?? ''); }, $fields));
    }

    $repeaters = array();
    $tables = isset($document_model['table_candidates']) && is_array($document_model['table_candidates']) ? $document_model['table_candidates'] : array();
    if (!empty($tables) && count($fields) >= 3) {
        $repeater_fields = array();
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (preg_match('/(item|qty|quantity|description|amount|date|time|service|code)/i', (string) ($field['label'] ?? ''))) {
                $repeater_fields[] = $key;
            }
            if (count($repeater_fields) >= 6) {
                break;
            }
        }
        if (count($repeater_fields) >= 2) {
            $repeaters[] = array(
                'key' => 'line_items',
                'label' => 'Line Items',
                'field_keys' => array_values(array_unique($repeater_fields)),
                'min' => 0,
                'max' => 25,
            );
        }
    }

    $nodes = function_exists('dcb_default_document_nodes') ? dcb_default_document_nodes($template_blocks, $fields) : array();

    $page_review = array();
    foreach ($page_meta as $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $proxy = (float) ($meta['confidence_proxy'] ?? 0);
        $norm = isset($meta['normalization']) && is_array($meta['normalization']) ? $meta['normalization'] : array();
        $page_review[] = array(
            'page_number' => max(1, (int) ($meta['page_number'] ?? 1)),
            'engine' => sanitize_text_field((string) ($meta['engine'] ?? 'none')),
            'text_length' => max(0, (int) ($meta['text_length'] ?? 0)),
            'confidence_proxy' => round(max(0, min(1, $proxy)), 4),
            'confidence_bucket' => dcb_confidence_bucket($proxy),
            'normalization_processor' => sanitize_key((string) ($norm['processor'] ?? 'none')),
            'capture_warning_count' => isset($norm['capture_warnings']) && is_array($norm['capture_warnings']) ? count($norm['capture_warnings']) : 0,
            'dimension_reduction_ratio' => round(max(0.0, min(1.0, (float) ($norm['dimension_reduction_ratio'] ?? 0.0))), 4),
        );
    }

    $review_cleanup_burden = 0.0;
    $low_conf = (int) ($confidence_counts['low'] ?? 0);
    $candidate_count = max(1, count($candidates));
    $review_cleanup_burden = round(($low_conf / $candidate_count), 4);

    $digital_twin_hints = dcb_ocr_build_digital_twin_hints($document_model, $fields);

    return array(
        'label' => sanitize_text_field($label),
        'recipients' => '',
        'version' => 1,
        'template_blocks' => $template_blocks,
        'document_nodes' => $nodes,
        'digital_twin_hints' => $digital_twin_hints,
        'sections' => $sections,
        'repeaters' => $repeaters,
        'fields' => $fields,
        'hard_stops' => array(),
        'ocr_candidates' => dcb_ocr_normalize_candidates_runtime($candidates),
        'ocr_review' => array(
            'origin' => 'ocr_import',
            'created_at' => current_time('mysql'),
            'pipeline_stages' => array('file_type_inspection', 'text_extraction', 'page_rasterization', 'ocr_fallback', 'line_block_normalization', 'document_modeling', 'field_candidate_extraction', 'draft_schema_generation'),
            'page_extraction' => $page_review,
            'field_confidence_counts' => $confidence_counts,
            'section_count' => count($sections),
            'repeater_count' => count($repeaters),
            'template_block_count' => count($template_blocks),
            'model_version' => sanitize_text_field((string) ($document_model['model_version'] ?? '1.0')),
            'review_cleanup_burden_proxy' => $review_cleanup_burden,
        ),
    );
}

function dcb_ocr_to_draft_form(string $ocr_text, string $label = 'Scanned Form', array $extraction = array()): array {
    $pages = array();
    if (!empty($extraction['pages']) && is_array($extraction['pages'])) {
        foreach ($extraction['pages'] as $page) {
            if (!is_array($page)) {
                continue;
            }
            $pages[] = array(
                'page_number' => max(1, (int) ($page['page_number'] ?? 1)),
                'text' => (string) ($page['text'] ?? ''),
                'engine' => sanitize_text_field((string) ($page['engine'] ?? 'unknown')),
                'text_length' => max(0, (int) ($page['text_length'] ?? strlen((string) ($page['text'] ?? '')))),
                'confidence_proxy' => isset($page['confidence_proxy']) ? (float) $page['confidence_proxy'] : dcb_text_confidence_proxy((string) ($page['text'] ?? '')),
                'normalization' => isset($page['normalization']) && is_array($page['normalization']) ? $page['normalization'] : array(),
            );
        }
    }

    if (empty($pages)) {
        $pages[] = array(
            'page_number' => 1,
            'text' => $ocr_text,
            'engine' => 'legacy-ocr-text',
            'text_length' => strlen($ocr_text),
            'confidence_proxy' => dcb_text_confidence_proxy($ocr_text),
        );
    }

    $page_meta = array();
    foreach ($pages as $page) {
        $page_number = (int) ($page['page_number'] ?? 1);
        $page_meta[$page_number] = array(
            'page_number' => $page_number,
            'engine' => (string) ($page['engine'] ?? 'unknown'),
            'text_length' => max(0, (int) ($page['text_length'] ?? 0)),
            'confidence_proxy' => round(max(0, min(1, (float) ($page['confidence_proxy'] ?? 0))), 4),
            'normalization' => isset($page['normalization']) && is_array($page['normalization']) ? $page['normalization'] : array(),
        );
    }

    $document_model = isset($extraction['ocr_document_model']) && is_array($extraction['ocr_document_model'])
        ? $extraction['ocr_document_model']
        : dcb_ocr_build_document_model($pages);

    $candidates = dcb_upload_stage_field_candidate_extraction($document_model, $page_meta);
    $template_blocks = dcb_upload_stage_template_block_extraction($document_model);

    $draft = dcb_upload_stage_draft_schema_generation($candidates, $label, $page_meta, $document_model, $template_blocks);
    if (!isset($draft['ocr_review']) || !is_array($draft['ocr_review'])) {
        $draft['ocr_review'] = array();
    }
    $draft['ocr_review']['template_block_count'] = count($template_blocks);
    $draft['ocr_review']['field_candidate_count'] = count($candidates);
    $draft['ocr_review']['table_candidate_count'] = isset($document_model['table_candidates']) && is_array($document_model['table_candidates']) ? count($document_model['table_candidates']) : 0;
    $draft['ocr_review']['signature_candidate_count'] = isset($document_model['signature_date_candidates']) && is_array($document_model['signature_date_candidates']) ? count($document_model['signature_date_candidates']) : 0;
    $draft['ocr_review']['layout_region_count'] = isset($document_model['layout_regions']) && is_array($document_model['layout_regions']) ? count($document_model['layout_regions']) : 0;
    $draft['ocr_review']['signature_pair_count'] = isset($document_model['signature_date_pairs']) && is_array($document_model['signature_date_pairs']) ? count($document_model['signature_date_pairs']) : 0;
    $draft['ocr_review']['low_confidence_warning'] = !empty($draft['ocr_review']['field_confidence_counts']['low']) && (int) $draft['ocr_review']['field_confidence_counts']['low'] > ((int) ($draft['ocr_review']['field_candidate_count'] ?? 0) / 2);
    $draft['ocr_review']['review_cleanup_burden_proxy'] = isset($draft['ocr_review']['review_cleanup_burden_proxy']) && is_numeric($draft['ocr_review']['review_cleanup_burden_proxy'])
        ? round(max(0, min(1, (float) $draft['ocr_review']['review_cleanup_burden_proxy'])), 4)
        : 0.0;
    if (isset($extraction['input_source_type'])) {
        $draft['ocr_review']['input_source_type'] = sanitize_key((string) $extraction['input_source_type']);
    }
    if (isset($extraction['input_normalization']) && is_array($extraction['input_normalization'])) {
        $draft['ocr_review']['input_normalization'] = $extraction['input_normalization'];
        $draft['ocr_review']['capture_recommendations'] = isset($extraction['input_normalization']['capture_recommendations']) && is_array($extraction['input_normalization']['capture_recommendations'])
            ? $extraction['input_normalization']['capture_recommendations']
            : array();
        $draft['ocr_review']['average_capture_warning_count'] = isset($extraction['input_normalization']['average_warning_count'])
            ? round(max(0.0, (float) $extraction['input_normalization']['average_warning_count']), 4)
            : 0.0;
        $draft['ocr_review']['normalization_improvement_proxy'] = isset($extraction['input_normalization']['normalization_improvement_proxy'])
            ? round(max(0.0, min(1.0, (float) $extraction['input_normalization']['normalization_improvement_proxy'])), 4)
            : 0.0;
    }

    $draft['source_capture_meta'] = array(
        'input_source_type' => isset($extraction['input_source_type']) ? sanitize_key((string) $extraction['input_source_type']) : 'unknown',
        'capture_warning_count' => isset($extraction['input_normalization']['warnings']) && is_array($extraction['input_normalization']['warnings']) ? count($extraction['input_normalization']['warnings']) : 0,
        'capture_recommendations' => isset($extraction['input_normalization']['capture_recommendations']) && is_array($extraction['input_normalization']['capture_recommendations']) ? $extraction['input_normalization']['capture_recommendations'] : array(),
        'normalization_stage_application_counts' => isset($extraction['input_normalization']['stage_application_counts']) && is_array($extraction['input_normalization']['stage_application_counts']) ? $extraction['input_normalization']['stage_application_counts'] : array(),
        'normalization_improvement_proxy' => isset($extraction['input_normalization']['normalization_improvement_proxy']) ? round(max(0.0, min(1.0, (float) $extraction['input_normalization']['normalization_improvement_proxy'])), 4) : 0.0,
    );

    $draft['ocr_document_model'] = array(
        'model_version' => sanitize_text_field((string) ($document_model['model_version'] ?? '1.0')),
        'page_count' => isset($document_model['pages']) && is_array($document_model['pages']) ? count($document_model['pages']) : count($pages),
        'block_count' => isset($document_model['blocks']) && is_array($document_model['blocks']) ? count($document_model['blocks']) : 0,
        'line_count' => isset($document_model['lines']) && is_array($document_model['lines']) ? count($document_model['lines']) : 0,
        'anchor_count' => isset($document_model['anchors']) && is_array($document_model['anchors']) ? count($document_model['anchors']) : 0,
        'section_candidates' => isset($document_model['section_candidates']) && is_array($document_model['section_candidates']) ? $document_model['section_candidates'] : array(),
        'table_candidates' => isset($document_model['table_candidates']) && is_array($document_model['table_candidates']) ? $document_model['table_candidates'] : array(),
        'signature_date_candidates' => isset($document_model['signature_date_candidates']) && is_array($document_model['signature_date_candidates']) ? $document_model['signature_date_candidates'] : array(),
        'signature_date_pairs' => isset($document_model['signature_date_pairs']) && is_array($document_model['signature_date_pairs']) ? $document_model['signature_date_pairs'] : array(),
        'layout_regions' => isset($document_model['layout_regions']) && is_array($document_model['layout_regions']) ? $document_model['layout_regions'] : array(),
    );

    return $draft;
}

function dcb_ocr_pick_version_line(string $output): string {
    $output = trim($output);
    if ($output === '') {
        return '';
    }
    $lines = preg_split('/\r\n|\r|\n/', $output);
    if (!is_array($lines) || empty($lines)) {
        return '';
    }
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            return $line;
        }
    }
    return '';
}

function dcb_ocr_get_tesseract_languages(string $tesseract_path): array {
    if ($tesseract_path === '') {
        return array();
    }

    $run = dcb_ocr_exec_binary($tesseract_path, array('--list-langs'), true);
    if (empty($run['ok'])) {
        return array();
    }

    $langs = array();
    $lines = preg_split('/\r\n|\r|\n/', (string) ($run['output'] ?? ''));
    if (!is_array($lines)) {
        return array();
    }

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || stripos($line, 'list of available languages') !== false) {
            continue;
        }
        if (preg_match('/^[a-z0-9_\-\.]+$/i', $line)) {
            $langs[] = strtolower($line);
        }
    }

    $langs = array_values(array_unique($langs));
    sort($langs);
    return $langs;
}

function dcb_ocr_collect_environment_diagnostics(bool $include_provider_diagnostics = true): array {
    $shell_enabled = dcb_ocr_shell_exec_enabled();

    $resolved = array(
        'tesseract' => dcb_ocr_get_binary_resolution('tesseract'),
        'pdftotext' => dcb_ocr_get_binary_resolution('pdftotext'),
        'pdftoppm' => dcb_ocr_get_binary_resolution('pdftoppm'),
    );

    $checks = array();
    foreach ($resolved as $key => $row) {
        $path = (string) ($row['path'] ?? '');
        $check = dcb_ocr_binary_execution_check($path);
        $version = '';

        if (!empty($check['ready'])) {
            $version_args = $key === 'tesseract' ? array('--version') : array('-v');
            $ver = dcb_ocr_exec_binary($path, $version_args, true);
            $version = !empty($ver['ok']) ? dcb_ocr_pick_version_line((string) ($ver['output'] ?? '')) : '';
            if ($version === '') {
                $version = 'Version output unavailable';
            }
        }

        $checks[$key] = array(
            'binary' => $key,
            'path' => $path,
            'source' => (string) ($row['source'] ?? 'none'),
            'source_label' => (string) ($row['source_label'] ?? ''),
            'warnings' => array_values(array_merge(is_array($row['warnings'] ?? null) ? $row['warnings'] : array(), is_array($check['warnings'] ?? null) ? $check['warnings'] : array())),
            'ready' => !empty($check['ready']),
            'version' => $version,
        );
    }

    $languages = dcb_ocr_get_tesseract_languages((string) ($checks['tesseract']['path'] ?? ''));
    $has_eng = in_array('eng', $languages, true);

    $text_pdf_ready = !empty($checks['pdftotext']['ready']);
    $image_ocr_ready = !empty($checks['tesseract']['ready']) && $has_eng;
    $scanned_pdf_ready = $image_ocr_ready && !empty($checks['pdftoppm']['ready']);

    $warnings = array();
    if (!$shell_enabled) {
        $warnings[] = 'shell_exec is disabled. OCR/PDF binary execution is unavailable.';
    }
    if (empty($checks['pdftoppm']['ready'])) {
        $warnings[] = 'pdftoppm is missing. Scanned PDF rasterization OCR is not fully ready.';
    }
    if (!$has_eng) {
        $warnings[] = 'Tesseract language "eng" is missing. Install English language data.';
    }

    $status = 'missing';
    if ($text_pdf_ready && $image_ocr_ready && $scanned_pdf_ready) {
        $status = 'ready';
    } elseif ($text_pdf_ready || $image_ocr_ready || !empty($checks['pdftoppm']['ready'])) {
        $status = 'partial';
    }

    $out = array(
        'status' => $status,
        'shell_exec_enabled' => $shell_enabled,
        'checks' => $checks,
        'tesseract_languages' => $languages,
        'has_eng' => $has_eng,
        'readiness' => array('text_pdf' => $text_pdf_ready, 'image_ocr' => $image_ocr_ready, 'scanned_pdf' => $scanned_pdf_ready),
        'warnings' => array_values(array_unique($warnings)),
    );

    if ($include_provider_diagnostics && class_exists('DCB_OCR_Engine_Manager')) {
        $out['provider_diagnostics'] = DCB_OCR_Engine_Manager::diagnostics();
    }

    return $out;
}

function dcb_ocr_smoke_validation(?array $diag = null): array {
    if ($diag === null) {
        $diag = dcb_ocr_collect_environment_diagnostics();
    }
    $checks = isset($diag['checks']) && is_array($diag['checks']) ? $diag['checks'] : array();
    $readiness = isset($diag['readiness']) && is_array($diag['readiness']) ? $diag['readiness'] : array();
    $langs = isset($diag['tesseract_languages']) && is_array($diag['tesseract_languages']) ? $diag['tesseract_languages'] : array();

    $plain_ok = !empty($readiness['text_pdf']) && !empty($checks['pdftotext']['version']);
    $image_ok = !empty($readiness['image_ocr']) && in_array('eng', $langs, true);
    $scan_ok = !empty($readiness['scanned_pdf']) && !empty($checks['pdftoppm']['version']);

    return array(
        'plain_text_pdf_path' => array('ok' => $plain_ok, 'message' => $plain_ok ? 'pdftotext command is executable and responds to version probe.' : 'pdftotext is missing or not executable by PHP/web user.'),
        'image_ocr_path' => array('ok' => $image_ok, 'message' => $image_ok ? 'Tesseract command is executable and English OCR language is available.' : 'Tesseract is missing/not executable or English language data is unavailable.'),
        'scanned_pdf_rasterization_path' => array('ok' => $scan_ok, 'message' => $scan_ok ? 'pdftoppm rasterization command is executable for scanned PDF OCR fallback.' : 'pdftoppm is missing/not executable; scanned PDF OCR fallback is partial.'),
    );
}
