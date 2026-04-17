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

function dcb_upload_stage_ocr_image_file(string $image_path, string $engine, int $page_number): array {
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
    );
}

function dcb_upload_stage_ocr_fallback(string $file_path, array $inspection, array $rasterized): array {
    $kind = (string) ($inspection['kind'] ?? 'other');
    $pages = array();

    if ($kind === 'image') {
        $row = dcb_upload_stage_ocr_image_file($file_path, 'tesseract-image', 1);
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
        $row = dcb_upload_stage_ocr_image_file($image_path, 'tesseract-pdf-raster', $page_number);
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
    $text_stage = dcb_upload_stage_text_extraction($file_path, $inspection);
    $pages = isset($text_stage['pages']) && is_array($text_stage['pages']) ? $text_stage['pages'] : array();
    $combined_text = (string) ($text_stage['text'] ?? '');

    $rasterized = array('pages' => array(), 'work_dir' => '', 'engine' => 'none');
    $ocr_pages = array();
    if (!empty($inspection['is_pdf']) && dcb_upload_stage_pdf_text_is_weak($pages, $combined_text)) {
        $rasterized = dcb_upload_stage_page_rasterization($file_path, $inspection, 12);
        $ocr_pages = dcb_upload_stage_ocr_fallback($file_path, $inspection, $rasterized);
    } elseif (!empty($inspection['is_image'])) {
        $ocr_pages = dcb_upload_stage_ocr_fallback($file_path, $inspection, $rasterized);
    }

    $pages = dcb_upload_stage_merge_pages($pages, $ocr_pages);
    dcb_upload_stage_cleanup_raster_pages($rasterized);

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

    $result = array(
        'text' => $text,
        'normalized' => $normalized,
        'engine' => $engine,
        'pages' => $page_meta,
        'warnings' => $warnings,
        'stages' => array('file_type_inspection', 'text_extraction', 'page_rasterization', 'ocr_fallback'),
    );

    if ($text === '') {
        $result['failure_reason'] = dcb_ocr_normalize_failure_reason(!empty($inspection['is_image']) && dcb_ocr_get_tesseract_path() === '' ? 'local_binary_missing' : 'empty_extraction');
    }

    return dcb_ocr_normalize_result_shape($result, 'local');
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
        $page_meta[$pn] = array(
            'page_number' => $pn,
            'engine' => sanitize_key((string) ($page['engine'] ?? 'unknown')),
            'text_length' => max(0, (int) ($page['text_length'] ?? strlen((string) ($page['text'] ?? '')))),
            'confidence_proxy' => round(max(0, min(1, (float) ($page['confidence_proxy'] ?? 0))), 4),
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
        'false_positive_risk_count' => count(array_filter($result['ocr_candidates'], static function ($row) {
            return is_array($row) && (string) ($row['warning_state'] ?? '') === 'review_needed';
        })),
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

        $raw_blocks = preg_split('/\n\s*\n+/', str_replace("\r", "\n", $text)) ?: array();
        foreach ($raw_blocks as $block) {
            $clean_block = trim((string) preg_replace('/[ \t]+/', ' ', $block));
            if ($clean_block !== '' && strlen($clean_block) >= 6) {
                $blocks[] = array(
                    'block_index' => $block_index++,
                    'page_number' => $page_number,
                    'text' => $clean_block,
                );
            }
        }

        $raw_lines = preg_split('/\R+/', $text) ?: array();
        foreach ($raw_lines as $line) {
            $clean_line = trim((string) preg_replace('/\s+/', ' ', $line));
            if ($clean_line === '' || strlen($clean_line) < 2 || strlen($clean_line) > 180) {
                continue;
            }
            $lines[] = array(
                'line_index' => $line_index++,
                'page_number' => $page_number,
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
        'model_version' => '1.0',
        'pages' => $model_pages,
        'blocks' => $blocks,
        'lines' => $lines,
        'anchors' => $anchors,
        'field_groups' => array(),
        'section_candidates' => $section_candidates,
        'table_candidates' => $table_candidates,
        'signature_date_candidates' => $signature_candidates,
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
        $required = dcb_upload_guess_required($candidate_label, $line, $signal);
        $snippet = trim(substr($line, 0, 140));

        $page_conf = isset($page_meta[$page_number]['confidence_proxy']) ? (float) $page_meta[$page_number]['confidence_proxy'] : 0.0;
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
        $score = max(0.0, min(1.0, $base_confidence + (0.18 * $page_conf) + $anchor_bonus));
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
            'source_text_snippet' => $snippet,
            'confidence_score' => round($score, 4),
            'confidence_bucket' => dcb_confidence_bucket($score),
            'signal' => $signal,
            'source_engine' => $source_engine,
            'warning_state' => $score < 0.42 ? 'review_needed' : 'none',
            'form_type' => (string) ($shape['form_type'] ?? 'text'),
            'max' => isset($shape['max']) ? (int) $shape['max'] : 180,
            'options' => isset($shape['options']) && is_array($shape['options']) ? $shape['options'] : array(),
            'confidence_reasons' => array('signal_' . $signal, 'page_confidence', 'anchor_context'),
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
            'required_guess' => !empty($candidate['required_guess']),
            'page_number' => max(1, (int) ($candidate['page_number'] ?? 1)),
            'source_text_snippet' => sanitize_text_field((string) ($candidate['source_text_snippet'] ?? '')),
            'confidence_bucket' => sanitize_key((string) ($candidate['confidence_bucket'] ?? 'low')),
            'confidence_score' => round(max(0, min(1, (float) ($candidate['confidence_score'] ?? 0))), 4),
            'source_engine' => sanitize_key((string) ($candidate['source_engine'] ?? '')),
            'warning_state' => sanitize_key((string) ($candidate['warning_state'] ?? 'none')),
        );
    }

    return $out;
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
        $page_review[] = array(
            'page_number' => max(1, (int) ($meta['page_number'] ?? 1)),
            'engine' => sanitize_text_field((string) ($meta['engine'] ?? 'none')),
            'text_length' => max(0, (int) ($meta['text_length'] ?? 0)),
            'confidence_proxy' => round(max(0, min(1, $proxy)), 4),
            'confidence_bucket' => dcb_confidence_bucket($proxy),
        );
    }

    return array(
        'label' => sanitize_text_field($label),
        'recipients' => '',
        'version' => 1,
        'template_blocks' => $template_blocks,
        'document_nodes' => $nodes,
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
    $draft['ocr_review']['low_confidence_warning'] = !empty($draft['ocr_review']['field_confidence_counts']['low']) && (int) $draft['ocr_review']['field_confidence_counts']['low'] > ((int) ($draft['ocr_review']['field_candidate_count'] ?? 0) / 2);

    $draft['ocr_document_model'] = array(
        'model_version' => sanitize_text_field((string) ($document_model['model_version'] ?? '1.0')),
        'page_count' => isset($document_model['pages']) && is_array($document_model['pages']) ? count($document_model['pages']) : count($pages),
        'block_count' => isset($document_model['blocks']) && is_array($document_model['blocks']) ? count($document_model['blocks']) : 0,
        'line_count' => isset($document_model['lines']) && is_array($document_model['lines']) ? count($document_model['lines']) : 0,
        'anchor_count' => isset($document_model['anchors']) && is_array($document_model['anchors']) ? count($document_model['anchors']) : 0,
        'section_candidates' => isset($document_model['section_candidates']) && is_array($document_model['section_candidates']) ? $document_model['section_candidates'] : array(),
        'table_candidates' => isset($document_model['table_candidates']) && is_array($document_model['table_candidates']) ? $document_model['table_candidates'] : array(),
        'signature_date_candidates' => isset($document_model['signature_date_candidates']) && is_array($document_model['signature_date_candidates']) ? $document_model['signature_date_candidates'] : array(),
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
