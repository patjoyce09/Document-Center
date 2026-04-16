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
        $diag = dcb_ocr_collect_environment_diagnostics();
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
    public function slug(): string {
        return 'remote';
    }

    public function capabilities(): array {
        $base_url = trim((string) get_option('dcb_ocr_api_base_url', ''));
        $api_key = trim((string) get_option('dcb_ocr_api_key', ''));
        $is_https = strpos(strtolower($base_url), 'https://') === 0;

        return array(
            'ready' => $base_url !== '' && $api_key !== '' && $is_https,
            'status' => $is_https ? 'configured' : 'invalid_base_url',
            'warnings' => $is_https ? array() : array('Remote OCR base URL must use HTTPS.'),
        );
    }

    public function extract(string $file_path, string $mime): array {
        $base_url = trim((string) get_option('dcb_ocr_api_base_url', ''));
        $api_key = trim((string) get_option('dcb_ocr_api_key', ''));
        $timeout = max(5, min(120, (int) get_option('dcb_ocr_timeout_seconds', 30)));
        $max_mb = max(1, min(100, (int) get_option('dcb_ocr_max_file_size_mb', 15)));

        if ($base_url === '' || strpos(strtolower($base_url), 'https://') !== 0) {
            return array(
                'text' => '',
                'normalized' => '',
                'engine' => 'remote',
                'pages' => array(),
                'warnings' => array(array('code' => 'remote_config_invalid', 'message' => 'Remote OCR base URL must be configured with HTTPS.')),
                'failure_reason' => 'remote_config_invalid',
                'provider' => 'remote',
                'provenance' => array('mode' => 'remote', 'provider' => 'remote', 'timestamp' => current_time('mysql')),
            );
        }

        if ($api_key === '') {
            return array(
                'text' => '',
                'normalized' => '',
                'engine' => 'remote',
                'pages' => array(),
                'warnings' => array(array('code' => 'remote_api_key_missing', 'message' => 'Remote OCR API key is missing.')),
                'failure_reason' => 'remote_api_key_missing',
                'provider' => 'remote',
                'provenance' => array('mode' => 'remote', 'provider' => 'remote', 'timestamp' => current_time('mysql')),
            );
        }

        $file_size = @filesize($file_path);
        $max_bytes = $max_mb * 1024 * 1024;
        if (is_int($file_size) && $file_size > $max_bytes) {
            return array(
                'text' => '',
                'normalized' => '',
                'engine' => 'remote',
                'pages' => array(),
                'warnings' => array(array('code' => 'max_file_size_exceeded', 'message' => 'File exceeds configured OCR max file size.')),
                'failure_reason' => 'max_file_size_exceeded',
                'provider' => 'remote',
                'provenance' => array('mode' => 'remote', 'provider' => 'remote', 'timestamp' => current_time('mysql')),
            );
        }

        $endpoint = trailingslashit($base_url) . 'extract';
        $body = array(
            'file_name' => basename($file_path),
            'mime' => $mime,
            'content_base64' => base64_encode((string) file_get_contents($file_path)),
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
            return array(
                'text' => '',
                'normalized' => '',
                'engine' => 'remote',
                'pages' => array(),
                'warnings' => array(array('code' => 'remote_request_failed', 'message' => $response->get_error_message())),
                'failure_reason' => 'remote_request_failed',
                'provider' => 'remote',
                'provenance' => array('mode' => 'remote', 'provider' => 'remote', 'timestamp' => current_time('mysql')),
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = array();
        }

        if ($status_code < 200 || $status_code >= 300) {
            return array(
                'text' => '',
                'normalized' => '',
                'engine' => 'remote',
                'pages' => array(),
                'warnings' => array(array('code' => 'remote_http_error', 'message' => 'Remote OCR HTTP error: ' . $status_code)),
                'failure_reason' => 'remote_http_error',
                'provider' => 'remote',
                'provenance' => array('mode' => 'remote', 'provider' => 'remote', 'timestamp' => current_time('mysql')),
            );
        }

        $text = trim((string) ($decoded['text'] ?? ''));
        $pages = isset($decoded['pages']) && is_array($decoded['pages']) ? $decoded['pages'] : array();
        $warnings = isset($decoded['warnings']) && is_array($decoded['warnings']) ? $decoded['warnings'] : array();

        return array(
            'text' => $text,
            'normalized' => dcb_upload_normalize_text($text),
            'engine' => sanitize_text_field((string) ($decoded['engine'] ?? 'remote-api')),
            'pages' => $pages,
            'warnings' => $warnings,
            'failure_reason' => $text === '' ? 'empty_extraction' : '',
            'provider' => 'remote',
            'provenance' => array(
                'mode' => 'remote',
                'provider' => 'remote',
                'timestamp' => current_time('mysql'),
                'request_url' => esc_url_raw($endpoint),
                'http_status' => $status_code,
            ),
        );
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

    public static function active_engine(): DCB_OCR_Engine {
        $mode = self::selected_mode();
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

    public static function extract(string $file_path, string $mime): array {
        $mode = self::selected_mode();
        $engine = self::active_engine();
        $result = $engine->extract($file_path, $mime);

        if ($mode === 'auto' && $engine->slug() === 'remote' && trim((string) ($result['text'] ?? '')) === '') {
            $fallback = (new DCB_OCR_Engine_Local())->extract($file_path, $mime);
            $fallback['warnings'][] = array('code' => 'auto_fallback_to_local', 'message' => 'Remote OCR returned empty result. Fell back to local OCR.');
            return $fallback;
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
