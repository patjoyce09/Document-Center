<?php

if (!defined('ABSPATH')) {
    exit;
}

interface DCB_OCR_Engine {
    public function slug(): string;

    public function capabilities(): array;

    public function extract(string $file_path, string $mime): array;
}

final class DCB_OCR_Engine_Local implements DCB_OCR_Engine {
    public function slug(): string {
        return 'local';
    }

    public function capabilities(): array {
        $diag = dcb_ocr_collect_environment_diagnostics(false);
        return array(
            'ready' => (string) ($diag['status'] ?? 'missing') !== 'missing',
            'status' => (string) ($diag['status'] ?? 'missing'),
            'warnings' => isset($diag['warnings']) && is_array($diag['warnings']) ? $diag['warnings'] : array(),
        );
    }

    public function extract(string $file_path, string $mime): array {
        $result = dcb_upload_extract_text_from_file_local($file_path, $mime);
        $result['provider'] = 'local';
        $result['provenance'] = array(
            'mode' => 'local',
            'provider' => 'local',
            'timestamp' => current_time('mysql'),
        );
        if (!isset($result['failure_reason']) && trim((string) ($result['text'] ?? '')) === '') {
            $result['failure_reason'] = 'empty_extraction';
        }
        return $result;
    }
}

final class DCB_OCR_Engine_Remote implements DCB_OCR_Engine {
    private function base_provenance(string $endpoint = '', int $http_status = 0): array {
        $out = array(
            'mode' => 'remote',
            'provider' => 'remote',
            'timestamp' => current_time('mysql'),
        );
        if ($endpoint !== '') {
            $out['request_url'] = esc_url_raw($endpoint);
        }
        if ($http_status > 0) {
            $out['http_status'] = $http_status;
        }
        return $out;
    }

    private function failure(string $reason, string $message, array $extra = array()): array {
        $reason = function_exists('dcb_ocr_normalize_failure_reason') ? dcb_ocr_normalize_failure_reason($reason) : sanitize_key($reason);
        $result = array(
            'text' => '',
            'normalized' => '',
            'engine' => 'remote',
            'pages' => array(),
            'warnings' => array(array('code' => $reason, 'message' => $message)),
            'failure_reason' => $reason,
            'provider' => 'remote',
            'provenance' => $this->base_provenance(),
        );

        if (!empty($extra)) {
            $result = array_merge($result, $extra);
        }

        if (function_exists('dcb_ocr_normalize_result_shape')) {
            return dcb_ocr_normalize_result_shape($result, 'remote');
        }

        return $result;
    }

    public function slug(): string {
        return 'remote';
    }

    public function capabilities(): array {
        $base_url = trim((string) get_option('dcb_ocr_api_base_url', ''));
        $api_key = trim((string) get_option('dcb_ocr_api_key', ''));
        $parsed = function_exists('wp_parse_url') ? wp_parse_url($base_url) : parse_url($base_url);
        $scheme = is_array($parsed) ? strtolower((string) ($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? strtolower((string) ($parsed['host'] ?? '')) : '';
        $is_https = $scheme === 'https' && $host !== '';

        $warnings = array();
        $status = 'configured';
        if ($base_url === '') {
            $status = 'not_configured';
            $warnings[] = 'Remote OCR base URL is empty.';
        } elseif (!$is_https) {
            $status = 'invalid_base_url';
            $warnings[] = 'Remote OCR base URL must use HTTPS and include a host.';
        }

        if ($api_key === '') {
            $warnings[] = 'Remote OCR API key is missing.';
            if ($status === 'configured') {
                $status = 'api_key_missing';
            }
        }

        return array(
            'ready' => $base_url !== '' && $api_key !== '' && $is_https,
            'status' => $status,
            'warnings' => $warnings,
        );
    }

    public function extract(string $file_path, string $mime): array {
        $base_url = trim((string) get_option('dcb_ocr_api_base_url', ''));
        $api_key = trim((string) get_option('dcb_ocr_api_key', ''));
        $timeout = max(5, min(120, (int) get_option('dcb_ocr_timeout_seconds', 30)));
        $max_mb = max(1, min(100, (int) get_option('dcb_ocr_max_file_size_mb', 15)));
        $allowed_mimes = function_exists('dcb_upload_allowed_mimes') ? (array) dcb_upload_allowed_mimes() : array();
        $allowed_values = array_values(array_unique(array_map('strtolower', array_filter(array_map('strval', $allowed_mimes)))));
        $safe_mime = strtolower(trim($mime));
        $parsed = function_exists('wp_parse_url') ? wp_parse_url($base_url) : parse_url($base_url);
        $scheme = is_array($parsed) ? strtolower((string) ($parsed['scheme'] ?? '')) : '';
        $host = is_array($parsed) ? strtolower((string) ($parsed['host'] ?? '')) : '';
        $is_https = $scheme === 'https' && $host !== '';

        if ($base_url === '' || !$is_https) {
            return $this->failure('remote_config_invalid', 'Remote OCR base URL must be configured with HTTPS.');
        }

        if ($api_key === '') {
            return $this->failure('remote_api_key_missing', 'Remote OCR API key is missing.');
        }

        if ($safe_mime !== '' && !empty($allowed_values) && !in_array($safe_mime, $allowed_values, true)) {
            return $this->failure('unsupported_mime', 'Unsupported MIME type for OCR request.', array(
                'provenance' => $this->base_provenance('', 0),
            ));
        }

        $file_size = @filesize($file_path);
        $max_bytes = $max_mb * 1024 * 1024;
        if (is_int($file_size) && $file_size > $max_bytes) {
            return $this->failure('max_file_size_exceeded', 'File exceeds configured OCR max file size.');
        }

        if (!file_exists($file_path) || !is_readable($file_path)) {
            return $this->failure('parse_failed', 'OCR source file is missing or unreadable.');
        }

        $file_binary = @file_get_contents($file_path);
        if (!is_string($file_binary) || $file_binary === '') {
            return $this->failure('parse_failed', 'Could not read OCR source file bytes.');
        }

        $endpoint = trailingslashit($base_url) . 'extract';
        $body = array(
            'file_name' => basename($file_path),
            'mime' => $safe_mime !== '' ? $safe_mime : $mime,
            'content_base64' => base64_encode($file_binary),
        );

        $response = wp_remote_post($endpoint, array(
            'timeout' => $timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-API-Key' => $api_key,
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            $message = (string) $response->get_error_message();
            $code = stripos($message, 'timed out') !== false ? 'extraction_timeout' : 'remote_request_failed';
            return $this->failure($code, $message !== '' ? $message : 'Remote OCR request failed.', array(
                'provenance' => $this->base_provenance($endpoint, 0),
            ));
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->failure('parse_failed', 'Remote OCR response was not valid JSON.', array(
                'provenance' => $this->base_provenance($endpoint, $status_code),
            ));
        }

        if ($status_code < 200 || $status_code >= 300) {
            return $this->failure('remote_http_error', 'Remote OCR HTTP error: ' . $status_code, array(
                'provenance' => $this->base_provenance($endpoint, $status_code),
            ));
        }

        $text = trim((string) ($decoded['text'] ?? ''));
        $pages = isset($decoded['pages']) && is_array($decoded['pages']) ? $decoded['pages'] : array();
        $warnings_raw = isset($decoded['warnings']) && is_array($decoded['warnings']) ? $decoded['warnings'] : array();
        $warnings = array();
        foreach ($warnings_raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $warnings[] = array(
                'code' => sanitize_key((string) ($row['code'] ?? 'ocr_warning')),
                'message' => sanitize_text_field((string) ($row['message'] ?? 'OCR warning')),
            );
        }

        $normalized_pages = array();
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }
            $page_text = sanitize_textarea_field((string) ($page['text'] ?? ''));
            $proxy = isset($page['confidence_proxy']) && is_numeric($page['confidence_proxy']) ? (float) $page['confidence_proxy'] : (function_exists('dcb_text_confidence_proxy') ? dcb_text_confidence_proxy($page_text) : 0.0);
            $normalized_pages[] = array(
                'page_number' => max(1, (int) ($page['page_number'] ?? count($normalized_pages) + 1)),
                'engine' => sanitize_text_field((string) ($page['engine'] ?? 'remote-api')),
                'text' => $page_text,
                'text_length' => max(0, (int) ($page['text_length'] ?? strlen($page_text))),
                'confidence_proxy' => round(max(0, min(1, $proxy)), 4),
            );
        }

        $result = array(
            'text' => $text,
            'normalized' => dcb_upload_normalize_text($text),
            'engine' => sanitize_text_field((string) ($decoded['engine'] ?? 'remote-api')),
            'pages' => $normalized_pages,
            'warnings' => $warnings,
            'failure_reason' => $text === '' ? 'empty_extraction' : sanitize_key((string) ($decoded['failure_reason'] ?? '')),
            'provider' => 'remote',
            'provenance' => array_merge($this->base_provenance($endpoint, $status_code), array(
                'response_warning_count' => count($warnings),
                'response_page_count' => count($normalized_pages),
            )),
        );

        if (function_exists('dcb_ocr_normalize_result_shape')) {
            return dcb_ocr_normalize_result_shape($result, 'remote');
        }

        return $result;
    }
}

final class DCB_OCR_Engine_Manager {
    public static function selected_mode(): string {
        $mode = sanitize_key((string) get_option('dcb_ocr_mode', 'auto'));
        if (!in_array($mode, array('local', 'remote', 'auto'), true)) {
            $mode = 'auto';
        }
        return $mode;
    }

    public static function engine_for_mode(string $mode): DCB_OCR_Engine {
        $mode = sanitize_key($mode);
        $local = new DCB_OCR_Engine_Local();
        $remote = new DCB_OCR_Engine_Remote();

        if ($mode === 'local') {
            return $local;
        }
        if ($mode === 'remote') {
            return $remote;
        }

        $remote_caps = $remote->capabilities();
        if (!empty($remote_caps['ready'])) {
            return $remote;
        }

        return $local;
    }

    public static function active_engine(): DCB_OCR_Engine {
        return self::engine_for_mode(self::selected_mode());
    }

    public static function extract(string $file_path, string $mime): array {
        return self::extract_with_mode($file_path, $mime, self::selected_mode());
    }

    public static function extract_with_mode(string $file_path, string $mime, string $mode): array {
        $mode = sanitize_key($mode);
        if (!in_array($mode, array('local', 'remote', 'auto'), true)) {
            $mode = self::selected_mode();
        }

        $engine = self::engine_for_mode($mode);
        $result = $engine->extract($file_path, $mime);

        if ($mode === 'auto' && $engine->slug() === 'remote' && trim((string) ($result['text'] ?? '')) === '') {
            $fallback = (new DCB_OCR_Engine_Local())->extract($file_path, $mime);
            $fallback['warnings'][] = array('code' => 'auto_fallback_to_local', 'message' => 'Remote OCR returned empty result. Fell back to local OCR.');
            if (function_exists('dcb_ocr_normalize_result_shape')) {
                return dcb_ocr_normalize_result_shape($fallback, 'local');
            }
            return $fallback;
        }

        if (function_exists('dcb_ocr_normalize_result_shape')) {
            return dcb_ocr_normalize_result_shape($result, $engine->slug());
        }

        return $result;
    }

    public static function diagnostics(): array {
        $local = new DCB_OCR_Engine_Local();
        $remote = new DCB_OCR_Engine_Remote();
        $active = self::active_engine();

        return array(
            'mode' => self::selected_mode(),
            'active' => $active->slug(),
            'engines' => array(
                'local' => $local->capabilities(),
                'remote' => $remote->capabilities(),
            ),
        );
    }
}
