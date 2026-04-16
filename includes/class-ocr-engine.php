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
    private const CONTRACT_VERSION = 'dcb-ocr-v1';

    public function slug(): string {
        return 'remote';
    }

    public function capabilities(): array {
        $base_url = trim((string) get_option('dcb_ocr_api_base_url', ''));
        $api_key = trim((string) get_option('dcb_ocr_api_key', ''));
        $auth_header = $this->auth_header_name();
        $timeout = max(5, min(120, (int) get_option('dcb_ocr_timeout_seconds', 30)));
        $is_https = strpos(strtolower($base_url), 'https://') === 0;

        if ($base_url === '' || !$is_https || $api_key === '') {
            return array(
                'ready' => false,
                'status' => $is_https ? 'missing_credentials' : 'invalid_base_url',
                'warnings' => $is_https ? array('Remote OCR API key is required.') : array('Remote OCR base URL must use HTTPS.'),
                'contract_version' => self::CONTRACT_VERSION,
            );
        }

        $request_id = $this->new_request_id();
        $health = $this->remote_call('health', 'GET', array(), $timeout, $request_id, $auth_header, $api_key);
        $caps = $this->remote_call('capabilities', 'GET', array(), $timeout, $request_id, $auth_header, $api_key);

        $warnings = array();
        $ready = !empty($health['ok']) && !empty($caps['ok']);
        if (empty($health['ok'])) {
            $warnings[] = 'Health endpoint unavailable: ' . sanitize_text_field((string) ($health['message'] ?? 'unknown'));
        }
        if (empty($caps['ok'])) {
            $warnings[] = 'Capabilities endpoint unavailable: ' . sanitize_text_field((string) ($caps['message'] ?? 'unknown'));
        }

        return array(
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'degraded',
            'warnings' => $warnings,
            'contract_version' => self::CONTRACT_VERSION,
            'auth_header' => $auth_header,
            'health' => array(
                'ok' => !empty($health['ok']),
                'http_status' => (int) ($health['status_code'] ?? 0),
                'body' => isset($health['body']) && is_array($health['body']) ? $health['body'] : array(),
            ),
            'capabilities' => array(
                'ok' => !empty($caps['ok']),
                'http_status' => (int) ($caps['status_code'] ?? 0),
                'body' => isset($caps['body']) && is_array($caps['body']) ? $caps['body'] : array(),
            ),
        );
    }

    public function extract(string $file_path, string $mime): array {
        $base_url = trim((string) get_option('dcb_ocr_api_base_url', ''));
        $api_key = trim((string) get_option('dcb_ocr_api_key', ''));
        $auth_header = $this->auth_header_name();
        $timeout = max(5, min(120, (int) get_option('dcb_ocr_timeout_seconds', 30)));
        $max_mb = max(1, min(100, (int) get_option('dcb_ocr_max_file_size_mb', 15)));
        $request_id = $this->new_request_id();

        if ($base_url === '' || strpos(strtolower($base_url), 'https://') !== 0) {
            return $this->error_result('remote_config_invalid', 'Remote OCR base URL must be configured with HTTPS.', $request_id);
        }

        if ($api_key === '') {
            return $this->error_result('remote_api_key_missing', 'Remote OCR API key is missing.', $request_id);
        }

        $file_size = @filesize($file_path);
        $max_bytes = $max_mb * 1024 * 1024;
        if (is_int($file_size) && $file_size > $max_bytes) {
            return $this->error_result('max_file_size_exceeded', 'File exceeds configured OCR max file size.', $request_id);
        }

        $body = array(
            'contract_version' => self::CONTRACT_VERSION,
            'request_id' => $request_id,
            'file' => array(
                'name' => basename($file_path),
                'mime' => $mime,
                'content_base64' => base64_encode((string) file_get_contents($file_path)),
            ),
            'options' => array(
                'include_pages' => true,
                'include_warnings' => true,
            ),
        );

        $extract = $this->remote_call('extract', 'POST', $body, $timeout, $request_id, $auth_header, $api_key);
        if (empty($extract['ok'])) {
            return $this->error_result(
                sanitize_key((string) ($extract['error_code'] ?? 'remote_request_failed')),
                sanitize_text_field((string) ($extract['message'] ?? 'Remote OCR request failed.')),
                $request_id,
                (int) ($extract['status_code'] ?? 0)
            );
        }

        $decoded = isset($extract['body']) && is_array($extract['body']) ? $extract['body'] : array();
        $shape = $this->validate_extract_response_shape($decoded);
        if (empty($shape['ok'])) {
            return $this->error_result(
                'remote_contract_invalid_shape',
                sanitize_text_field((string) ($shape['message'] ?? 'Remote OCR response shape was invalid.')),
                $request_id,
                (int) ($extract['status_code'] ?? 0)
            );
        }

        $result = isset($decoded['result']) && is_array($decoded['result']) ? $decoded['result'] : $decoded;
        $text = trim((string) ($result['text'] ?? ''));
        $pages = isset($result['pages']) && is_array($result['pages']) ? $result['pages'] : array();
        $warnings = isset($result['warnings']) && is_array($result['warnings']) ? $result['warnings'] : array();
        $failure_reason = sanitize_key((string) ($result['failure_reason'] ?? ''));
        if ($failure_reason === '' && $text === '') {
            $failure_reason = 'empty_extraction';
        }

        $provider = isset($decoded['provider']) && is_array($decoded['provider']) ? $decoded['provider'] : array();
        $provider_name = sanitize_text_field((string) ($provider['name'] ?? 'remote'));
        $provider_version = sanitize_text_field((string) ($provider['version'] ?? ''));
        $response_request_id = sanitize_text_field((string) ($decoded['request_id'] ?? $request_id));
        $response_contract = sanitize_text_field((string) ($decoded['contract_version'] ?? self::CONTRACT_VERSION));

        return array(
            'text' => $text,
            'normalized' => dcb_upload_normalize_text($text),
            'engine' => sanitize_text_field((string) ($result['engine'] ?? 'remote-api')),
            'pages' => $pages,
            'warnings' => $warnings,
            'failure_reason' => $failure_reason,
            'provider' => 'remote',
            'provenance' => array(
                'mode' => 'remote',
                'provider' => $provider_name !== '' ? $provider_name : 'remote',
                'provider_version' => $provider_version,
                'timestamp' => current_time('mysql'),
                'request_id' => $response_request_id,
                'request_url' => esc_url_raw((string) ($extract['url'] ?? '')),
                'http_status' => (int) ($extract['status_code'] ?? 0),
                'contract_version' => $response_contract,
            ),
        );
    }

    private function validate_extract_response_shape(array $decoded): array {
        if (isset($decoded['result'])) {
            if (!is_array($decoded['result'])) {
                return array('ok' => false, 'message' => 'Result payload is not an object.');
            }
            $result = $decoded['result'];
            if (isset($result['warnings']) && !is_array($result['warnings'])) {
                return array('ok' => false, 'message' => 'Result warnings must be an array.');
            }
            if (isset($result['pages']) && !is_array($result['pages'])) {
                return array('ok' => false, 'message' => 'Result pages must be an array.');
            }
            return array('ok' => true);
        }

        if (isset($decoded['warnings']) && !is_array($decoded['warnings'])) {
            return array('ok' => false, 'message' => 'Warnings must be an array.');
        }
        if (isset($decoded['pages']) && !is_array($decoded['pages'])) {
            return array('ok' => false, 'message' => 'Pages must be an array.');
        }

        return array('ok' => true);
    }

    private function auth_header_name(): string {
        $header = trim((string) get_option('dcb_ocr_api_auth_header', 'X-API-Key'));
        return $header !== '' ? $header : 'X-API-Key';
    }

    private function new_request_id(): string {
        if (function_exists('wp_generate_uuid4')) {
            return (string) wp_generate_uuid4();
        }
        return uniqid('dcb_ocr_', true);
    }

    private function remote_call(string $path, string $method, array $payload, int $timeout, string $request_id, string $auth_header, string $api_key): array {
        $base_url = trim((string) get_option('dcb_ocr_api_base_url', ''));
        $url = trailingslashit($base_url) . ltrim($path, '/');
        $headers = array(
            'Accept' => 'application/json',
            'X-DCB-Contract-Version' => self::CONTRACT_VERSION,
            'X-DCB-Request-ID' => $request_id,
        );
        $headers[$auth_header] = $api_key;

        $args = array(
            'timeout' => $timeout,
            'headers' => $headers,
            'method' => strtoupper($method),
        );

        if (strtoupper($method) === 'POST') {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return array(
                'ok' => false,
                'error_code' => 'remote_request_failed',
                'message' => $response->get_error_message(),
                'url' => $url,
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = array();
        }

        if ($status_code === 401 || $status_code === 403) {
            return array(
                'ok' => false,
                'error_code' => 'remote_auth_failed',
                'message' => 'Remote OCR authentication failed.',
                'status_code' => $status_code,
                'body' => $decoded,
                'url' => $url,
            );
        }

        if ($status_code < 200 || $status_code >= 300) {
            return array(
                'ok' => false,
                'error_code' => 'remote_http_error',
                'message' => 'Remote OCR HTTP error: ' . $status_code,
                'status_code' => $status_code,
                'body' => $decoded,
                'url' => $url,
            );
        }

        return array(
            'ok' => true,
            'status_code' => $status_code,
            'body' => $decoded,
            'url' => $url,
        );
    }

    private function error_result(string $code, string $message, string $request_id, int $http_status = 0): array {
        return array(
            'text' => '',
            'normalized' => '',
            'engine' => 'remote',
            'pages' => array(),
            'warnings' => array(array('code' => sanitize_key($code), 'message' => sanitize_text_field($message))),
            'failure_reason' => sanitize_key($code),
            'provider' => 'remote',
            'provenance' => array(
                'mode' => 'remote',
                'provider' => 'remote',
                'timestamp' => current_time('mysql'),
                'request_id' => $request_id,
                'http_status' => $http_status,
                'contract_version' => self::CONTRACT_VERSION,
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
