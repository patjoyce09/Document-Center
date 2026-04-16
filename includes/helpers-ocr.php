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

    return array(
        'text' => $text,
        'normalized' => $normalized,
        'engine' => $engine,
        'pages' => $page_meta,
        'warnings' => $warnings,
        'stages' => array('file_type_inspection', 'text_extraction', 'page_rasterization', 'ocr_fallback'),
    );
}

function dcb_upload_extract_text_from_file(string $file_path, string $mime): array {
    if (class_exists('DCB_OCR_Engine_Manager')) {
        $result = DCB_OCR_Engine_Manager::extract($file_path, $mime);
        dcb_ocr_maybe_enqueue_review_item($file_path, $mime, $result);
        return $result;
    }

    $result = dcb_upload_extract_text_from_file_local($file_path, $mime);
    dcb_ocr_maybe_enqueue_review_item($file_path, $mime, $result);
    return $result;
}

function dcb_ocr_maybe_enqueue_review_item(string $file_path, string $mime, array $result): void {
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
        return;
    }

    $title = sprintf('OCR Review: %s', sanitize_file_name((string) basename($file_path)));
    $post_id = wp_insert_post(array(
        'post_type' => 'dcb_ocr_review_queue',
        'post_status' => 'publish',
        'post_title' => $title . ' — ' . current_time('mysql'),
    ));
    if (is_wp_error($post_id) || (int) $post_id < 1) {
        return;
    }

    update_post_meta((int) $post_id, '_dcb_ocr_review_file', sanitize_text_field((string) basename($file_path)));
    update_post_meta((int) $post_id, '_dcb_ocr_review_mime', sanitize_text_field($mime));
    update_post_meta((int) $post_id, '_dcb_ocr_review_confidence', round(max(0.0, min(1.0, $confidence)), 4));
    update_post_meta((int) $post_id, '_dcb_ocr_review_threshold', $threshold);
    update_post_meta((int) $post_id, '_dcb_ocr_review_failure_reason', $failure_reason !== '' ? $failure_reason : 'low_confidence');
    update_post_meta((int) $post_id, '_dcb_ocr_review_provider', sanitize_key((string) ($result['provider'] ?? 'local')));
    update_post_meta((int) $post_id, '_dcb_ocr_review_provenance', wp_json_encode((array) ($result['provenance'] ?? array())));
}

function dcb_upload_stage_line_block_normalization(array $pages): array {
    $lines = array();
    $blocks = array();

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
                $blocks[] = array('page_number' => $page_number, 'text' => $clean_block);
            }
        }

        $raw_lines = preg_split('/\R+/', $text) ?: array();
        foreach ($raw_lines as $line) {
            $clean_line = trim((string) preg_replace('/\s+/', ' ', $line));
            if ($clean_line === '' || strlen($clean_line) < 2 || strlen($clean_line) > 180) {
                continue;
            }
            $lines[] = array('page_number' => $page_number, 'text' => $clean_line);
        }
    }

    return array('lines' => $lines, 'blocks' => $blocks);
}

function dcb_upload_guess_field_shape(string $label, string $source_line): array {
    $lower = strtolower($label . ' ' . $source_line);
    $shape = array('suggested_type' => 'text', 'form_type' => 'text', 'max' => 180, 'options' => array());

    if (preg_match('/\b(consent|agree|attest|authorize|acknowledge|permission)\b/', $lower)) {
        return array('suggested_type' => 'checkbox', 'form_type' => 'checkbox', 'max' => 0, 'options' => array());
    }
    if (preg_match('/\byes\s*\/?\s*no\b|\bno\s*\/?\s*yes\b|\[[ xX]?\]\s*yes.*\[[ xX]?\]\s*no/', $lower)) {
        return array('suggested_type' => 'radio_yes_no', 'form_type' => 'select', 'max' => 0, 'options' => array('yes' => 'Yes', 'no' => 'No'));
    }
    if (preg_match('/\b(e\-?mail)\b/', $lower)) {
        return array('suggested_type' => 'email', 'form_type' => 'email', 'max' => 190, 'options' => array());
    }
    if (preg_match('/\b(date|dob|birth|mm\/dd|yyyy)\b/', $lower)) {
        return array('suggested_type' => 'date', 'form_type' => 'date', 'max' => 180, 'options' => array());
    }
    if (preg_match('/\btime\b|\bam\b|\bpm\b/', $lower)) {
        return array('suggested_type' => 'time', 'form_type' => 'time', 'max' => 180, 'options' => array());
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

function dcb_upload_stage_field_candidate_extraction(array $normalized, array $page_meta): array {
    $lines = isset($normalized['lines']) && is_array($normalized['lines']) ? $normalized['lines'] : array();
    $candidates = array();
    $seen = array();

    foreach ($lines as $line_row) {
        if (!is_array($line_row)) {
            continue;
        }
        $line = (string) ($line_row['text'] ?? '');
        $page_number = max(1, (int) ($line_row['page_number'] ?? 1));
        if ($line === '') {
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

        $key = sanitize_key(str_replace(array('/', '-', '.'), '_', $candidate_label));
        if ($key === '') {
            continue;
        }

        $shape = dcb_upload_guess_field_shape($candidate_label, $line);
        $required = dcb_upload_guess_required($candidate_label, $line, $signal);
        $snippet = trim(substr($line, 0, 140));

        $page_conf = isset($page_meta[$page_number]['confidence_proxy']) ? (float) $page_meta[$page_number]['confidence_proxy'] : 0.0;
        $score = max(0.0, min(1.0, $base_confidence + (0.18 * $page_conf)));
        $source_engine = isset($page_meta[$page_number]['engine']) ? sanitize_key((string) $page_meta[$page_number]['engine']) : '';

        $candidate = array(
            'field_label' => $candidate_label,
            'suggested_key' => $key,
            'suggested_type' => (string) ($shape['suggested_type'] ?? 'text'),
            'required_guess' => $required,
            'page_number' => $page_number,
            'source_text_snippet' => $snippet,
            'confidence_score' => round($score, 4),
            'confidence_bucket' => dcb_confidence_bucket($score),
            'signal' => $signal,
            'source_engine' => $source_engine,
            'warning_state' => $score < 0.42 ? 'review_needed' : 'none',
            'form_type' => (string) ($shape['form_type'] ?? 'text'),
            'max' => isset($shape['max']) ? (int) $shape['max'] : 180,
            'options' => isset($shape['options']) && is_array($shape['options']) ? $shape['options'] : array(),
        );

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

function dcb_upload_stage_template_block_extraction(array $normalized): array {
    $blocks = isset($normalized['blocks']) && is_array($normalized['blocks']) ? $normalized['blocks'] : array();
    $template_blocks = array();
    $seen = array();

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

        $entry = array('type' => 'paragraph', 'text' => $text, 'source_page' => $page_number, 'source_text_snippet' => mb_substr($text, 0, 120));

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

function dcb_upload_stage_draft_schema_generation(array $candidates, string $label, array $page_meta): array {
    usort($candidates, static function ($a, $b) {
        $page_a = (int) ($a['page_number'] ?? 1);
        $page_b = (int) ($b['page_number'] ?? 1);
        if ($page_a !== $page_b) {
            return $page_a <=> $page_b;
        }
        return ((float) ($b['confidence_score'] ?? 0)) <=> ((float) ($a['confidence_score'] ?? 0));
    });

    $fields = array();
    $seen = array();
    $confidence_counts = array('low' => 0, 'medium' => 0, 'high' => 0);

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
                'source_text_snippet' => sanitize_text_field((string) ($candidate['source_text_snippet'] ?? '')),
                'confidence_bucket' => sanitize_key((string) ($candidate['confidence_bucket'] ?? 'low')),
                'confidence_score' => (float) ($candidate['confidence_score'] ?? 0),
                'suggested_type' => sanitize_key((string) ($candidate['suggested_type'] ?? 'text')),
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

        if (count($fields) >= 50) {
            break;
        }
    }

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
        'template_blocks' => array(),
        'fields' => $fields,
        'hard_stops' => array(),
        'ocr_candidates' => dcb_normalize_ocr_candidates($candidates),
        'ocr_review' => array(
            'origin' => 'ocr_import',
            'created_at' => current_time('mysql'),
            'pipeline_stages' => array('file_type_inspection', 'text_extraction', 'page_rasterization', 'ocr_fallback', 'line_block_normalization', 'field_candidate_extraction', 'draft_schema_generation'),
            'page_extraction' => $page_review,
            'field_confidence_counts' => $confidence_counts,
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

    $normalized = dcb_upload_stage_line_block_normalization($pages);
    $candidates = dcb_upload_stage_field_candidate_extraction($normalized, $page_meta);
    $template_blocks = dcb_upload_stage_template_block_extraction($normalized);

    $draft = dcb_upload_stage_draft_schema_generation($candidates, $label, $page_meta);
    $draft['template_blocks'] = $template_blocks;
    if (!isset($draft['ocr_review']) || !is_array($draft['ocr_review'])) {
        $draft['ocr_review'] = array();
    }
    $draft['ocr_review']['template_block_count'] = count($template_blocks);

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
