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
    private const EXPECTED_REMOTE_LANG = 'eng';

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
        $diagnostics = array();

        if (empty($health['ok'])) {
            $warnings[] = 'Health endpoint unavailable: ' . sanitize_text_field((string) ($health['message'] ?? 'unknown'));
            $diagnostics[] = $this->diagnostic_row_from_call($health, 'health');
        }
        if (empty($caps['ok'])) {
            $warnings[] = 'Capabilities endpoint unavailable: ' . sanitize_text_field((string) ($caps['message'] ?? 'unknown'));
            $diagnostics[] = $this->diagnostic_row_from_call($caps, 'capabilities');
        }

        $health_body = isset($health['body']) && is_array($health['body']) ? $health['body'] : array();
        $caps_body = isset($caps['body']) && is_array($caps['body']) ? $caps['body'] : array();

        $health_contract = sanitize_text_field((string) ($health_body['contract_version'] ?? ''));
        $caps_contract = sanitize_text_field((string) ($caps_body['contract_version'] ?? ''));
        $remote_contract = $caps_contract !== '' ? $caps_contract : $health_contract;

        $contract_ok = ($remote_contract === '' || $remote_contract === self::CONTRACT_VERSION);
        if (!$contract_ok) {
            $warnings[] = 'Remote OCR contract version mismatch.';
            $diagnostics[] = array(
                'code' => 'schema_mismatch',
                'endpoint' => 'contract',
                'message' => 'Expected contract ' . self::CONTRACT_VERSION . ' but got ' . $remote_contract,
            );
        }

        $provider_version = sanitize_text_field((string) ($health_body['version'] ?? $health_body['provider_version'] ?? ''));
        $provider_name = sanitize_text_field((string) ($health_body['service'] ?? $health_body['provider'] ?? ''));

        $missing_capabilities = $this->missing_capabilities($caps_body);
        if (!empty($missing_capabilities)) {
            $warnings[] = 'Remote OCR capabilities are missing required features.';
            $diagnostics[] = array(
                'code' => 'missing_capability',
                'endpoint' => 'capabilities',
                'message' => 'Missing: ' . implode(', ', $missing_capabilities),
            );
        }

        $calls_ok = !empty($health['ok']) && !empty($caps['ok']);
        $ready = $calls_ok && $contract_ok && empty($missing_capabilities);

        return array(
            'ready' => $ready,
            'remote_healthy' => $ready,
            'status' => $ready ? 'ready' : 'degraded',
            'warnings' => $warnings,
            'diagnostics' => $diagnostics,
            'contract_version' => self::CONTRACT_VERSION,
            'remote_contract_version' => $remote_contract,
            'provider' => $provider_name,
            'provider_version' => $provider_version,
            'auth_header' => $auth_header,
            'missing_capabilities' => $missing_capabilities,
            'health' => array(
                'ok' => !empty($health['ok']),
                'http_status' => (int) ($health['status_code'] ?? 0),
                'body' => $health_body,
            ),
            'capabilities' => array(
                'ok' => !empty($caps['ok']),
                'http_status' => (int) ($caps['status_code'] ?? 0),
                'body' => $caps_body,
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

        $preflight = $this->preflight_capability_check($mime, $timeout, $request_id, $auth_header, $api_key);
        if (empty($preflight['ok'])) {
            return $this->error_result(
                sanitize_key((string) ($preflight['failure_reason'] ?? 'remote_missing_capability')),
                sanitize_text_field((string) ($preflight['message'] ?? 'Remote OCR capability check failed for this file type.')),
                $request_id,
                (int) ($preflight['http_status'] ?? 0),
                array(
                    'provider' => sanitize_text_field((string) ($preflight['provider'] ?? 'remote')),
                    'provider_version' => sanitize_text_field((string) ($preflight['provider_version'] ?? '')),
                    'contract_version' => sanitize_text_field((string) ($preflight['contract_version'] ?? self::CONTRACT_VERSION)),
                    'request_url' => esc_url_raw((string) ($preflight['request_url'] ?? '')),
                    'engine_used' => 'remote',
                )
            );
        }

        $preflight_warnings = isset($preflight['warnings']) && is_array($preflight['warnings'])
            ? $this->sanitize_warnings($preflight['warnings'])
            : array();

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
            $mapped_reason = $this->map_remote_failure_reason((string) ($extract['error_code'] ?? 'remote_request_failed'));
            return $this->error_result(
                $mapped_reason,
                sanitize_text_field((string) ($extract['message'] ?? 'Remote OCR request failed.')),
                $request_id,
                (int) ($extract['status_code'] ?? 0),
                array(
                    'request_url' => esc_url_raw((string) ($extract['url'] ?? '')),
                )
            );
        }

        $decoded = isset($extract['body']) && is_array($extract['body']) ? $extract['body'] : array();
        $normalized = $this->normalize_extract_payload($decoded, $request_id);

        $shape = $this->validate_extract_response_shape($normalized);
        if (empty($shape['ok'])) {
            return $this->error_result(
                'remote_contract_invalid_shape',
                sanitize_text_field((string) ($shape['message'] ?? 'Remote OCR response shape was invalid.')),
                $request_id,
                (int) ($extract['status_code'] ?? 0)
            );
        }

        $response_contract = sanitize_text_field((string) ($normalized['contract_version'] ?? self::CONTRACT_VERSION));
        if ($response_contract !== '' && $response_contract !== self::CONTRACT_VERSION) {
            return $this->error_result(
                'remote_contract_version_mismatch',
                'Remote OCR contract version mismatch.',
                $request_id,
                (int) ($extract['status_code'] ?? 0)
            );
        }

        $text = trim((string) ($normalized['text'] ?? ''));
        $pages = $this->sanitize_pages(isset($normalized['pages']) && is_array($normalized['pages']) ? $normalized['pages'] : array());
        $warnings = $this->sanitize_warnings(isset($normalized['warnings']) && is_array($normalized['warnings']) ? $normalized['warnings'] : array());
        if (!empty($preflight_warnings)) {
            $warnings = array_merge($warnings, $preflight_warnings);
        }
        $failure_reason = sanitize_key((string) ($normalized['failure_reason'] ?? ''));
        if ($failure_reason === '' && $text === '') {
            $failure_reason = 'empty_extraction';
            $warnings[] = array('code' => 'remote_empty_text', 'message' => 'Remote OCR returned an empty text response.');
        }

        if ($failure_reason !== '' && $failure_reason !== 'empty_extraction') {
            $warnings[] = array('code' => 'remote_service_failure', 'message' => 'Remote service declared failure_reason: ' . $failure_reason);
        }

        $provider_name = sanitize_text_field((string) ($normalized['provider'] ?? 'remote'));
        $provider_version = sanitize_text_field((string) ($normalized['provider_version'] ?? ''));
        $response_request_id = sanitize_text_field((string) ($normalized['request_id'] ?? $request_id));
        $engine_used = sanitize_text_field((string) ($normalized['engine_used'] ?? 'remote-api'));
        $confidence = $this->resolve_confidence($normalized, $pages, $text);
        $timings = $this->sanitize_timings(isset($normalized['timings']) && is_array($normalized['timings']) ? $normalized['timings'] : array());

        return array(
            'text' => $text,
            'normalized' => dcb_upload_normalize_text($text),
            'engine' => $engine_used,
            'engine_used' => $engine_used,
            'pages' => $pages,
            'warnings' => $warnings,
            'failure_reason' => $failure_reason,
            'provider' => $provider_name !== '' ? $provider_name : 'remote',
            'confidence' => $confidence,
            'confidence_proxy' => $confidence,
            'timings' => $timings,
            'provenance' => array(
                'mode' => 'remote',
                'provider' => $provider_name !== '' ? $provider_name : 'remote',
                'provider_version' => $provider_version,
                'timestamp' => current_time('mysql'),
                'request_id' => $response_request_id,
                'request_url' => esc_url_raw((string) ($extract['url'] ?? '')),
                'http_status' => (int) ($extract['status_code'] ?? 0),
                'contract_version' => $response_contract,
                'engine_used' => $engine_used,
                'warnings' => $warnings,
                'failure_reason' => $failure_reason,
                'confidence' => $confidence,
                'timings' => $timings,
            ),
        );
    }

    private function validate_extract_response_shape(array $decoded): array {
        if (!isset($decoded['request_id']) || trim((string) $decoded['request_id']) === '') {
            return array('ok' => false, 'message' => 'request_id is required.');
        }
        if (!isset($decoded['provider']) || trim((string) $decoded['provider']) === '') {
            return array('ok' => false, 'message' => 'provider is required.');
        }
        if (!isset($decoded['provider_version']) || trim((string) $decoded['provider_version']) === '') {
            return array('ok' => false, 'message' => 'provider_version is required.');
        }
        if (!isset($decoded['contract_version']) || trim((string) $decoded['contract_version']) === '') {
            return array('ok' => false, 'message' => 'contract_version is required.');
        }
        if (!array_key_exists('text', $decoded) || !is_string($decoded['text'])) {
            return array('ok' => false, 'message' => 'text must be a string.');
        }
        if (isset($decoded['warnings']) && !is_array($decoded['warnings'])) {
            return array('ok' => false, 'message' => 'warnings must be an array.');
        }
        if (isset($decoded['pages']) && !is_array($decoded['pages'])) {
            return array('ok' => false, 'message' => 'pages must be an array.');
        }
        if (isset($decoded['timings']) && !is_array($decoded['timings'])) {
            return array('ok' => false, 'message' => 'timings must be an object.');
        }
        return array('ok' => true);
    }

    private function normalize_extract_payload(array $decoded, string $request_id): array {
        if (isset($decoded['result']) && is_array($decoded['result'])) {
            $provider = isset($decoded['provider']) && is_array($decoded['provider']) ? $decoded['provider'] : array();
            $result = $decoded['result'];

            return array(
                'request_id' => sanitize_text_field((string) ($decoded['request_id'] ?? $request_id)),
                'provider' => sanitize_text_field((string) ($provider['name'] ?? '')),
                'provider_version' => sanitize_text_field((string) ($provider['version'] ?? '')),
                'contract_version' => sanitize_text_field((string) ($decoded['contract_version'] ?? '')),
                'engine_used' => sanitize_text_field((string) ($result['engine_used'] ?? $result['engine'] ?? 'remote-api')),
                'text' => (string) ($result['text'] ?? ''),
                'normalized_text' => (string) ($result['normalized_text'] ?? ''),
                'pages' => isset($result['pages']) && is_array($result['pages']) ? $result['pages'] : array(),
                'warnings' => isset($result['warnings']) && is_array($result['warnings']) ? $result['warnings'] : array(),
                'failure_reason' => sanitize_key((string) ($result['failure_reason'] ?? '')),
                'confidence' => isset($result['confidence']) && is_numeric($result['confidence']) ? (float) $result['confidence'] : null,
                'timings' => isset($result['timings']) && is_array($result['timings']) ? $result['timings'] : array(),
            );
        }

        return array(
            'request_id' => sanitize_text_field((string) ($decoded['request_id'] ?? $request_id)),
            'provider' => sanitize_text_field((string) ($decoded['provider'] ?? '')),
            'provider_version' => sanitize_text_field((string) ($decoded['provider_version'] ?? '')),
            'contract_version' => sanitize_text_field((string) ($decoded['contract_version'] ?? '')),
            'engine_used' => sanitize_text_field((string) ($decoded['engine_used'] ?? 'remote-api')),
            'text' => (string) ($decoded['text'] ?? ''),
            'normalized_text' => (string) ($decoded['normalized_text'] ?? ''),
            'pages' => isset($decoded['pages']) && is_array($decoded['pages']) ? $decoded['pages'] : array(),
            'warnings' => isset($decoded['warnings']) && is_array($decoded['warnings']) ? $decoded['warnings'] : array(),
            'failure_reason' => sanitize_key((string) ($decoded['failure_reason'] ?? '')),
            'confidence' => isset($decoded['confidence']) && is_numeric($decoded['confidence']) ? (float) $decoded['confidence'] : null,
            'timings' => isset($decoded['timings']) && is_array($decoded['timings']) ? $decoded['timings'] : array(),
        );
    }

    private function sanitize_pages(array $pages): array {
        $clean = array();
        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $page_text = isset($page['extracted_text']) ? (string) $page['extracted_text'] : (string) ($page['text'] ?? '');
            $confidence = isset($page['confidence_proxy']) && is_numeric($page['confidence_proxy']) ? (float) $page['confidence_proxy'] : $this->text_confidence_proxy($page_text);

            $clean[] = array(
                'page_number' => max(1, (int) ($page['page_number'] ?? 1)),
                'engine' => sanitize_text_field((string) ($page['engine'] ?? 'remote-api')),
                'text' => $page_text,
                'text_length' => max(0, (int) ($page['text_length'] ?? strlen($page_text))),
                'confidence_proxy' => round(max(0.0, min(1.0, $confidence)), 4),
                'warnings' => $this->sanitize_warnings(isset($page['warnings']) && is_array($page['warnings']) ? $page['warnings'] : array()),
            );
        }
        return $clean;
    }

    private function sanitize_warnings(array $warnings): array {
        $clean = array();
        foreach ($warnings as $warning) {
            if (is_array($warning)) {
                $clean[] = array(
                    'code' => sanitize_key((string) ($warning['code'] ?? 'remote_warning')),
                    'message' => sanitize_text_field((string) ($warning['message'] ?? 'Remote OCR warning')),
                );
                continue;
            }

            $msg = sanitize_text_field((string) $warning);
            if ($msg !== '') {
                $clean[] = array('code' => 'remote_warning', 'message' => $msg);
            }
        }
        return $clean;
    }

    private function sanitize_timings(array $timings): array {
        return array(
            'total_ms' => max(0, (int) ($timings['total_ms'] ?? 0)),
            'validation_ms' => max(0, (int) ($timings['validation_ms'] ?? 0)),
            'extraction_ms' => max(0, (int) ($timings['extraction_ms'] ?? 0)),
            'normalization_ms' => max(0, (int) ($timings['normalization_ms'] ?? 0)),
        );
    }

    private function resolve_confidence(array $normalized, array $pages, string $text): float {
        if (isset($normalized['confidence']) && is_numeric($normalized['confidence'])) {
            return round(max(0.0, min(1.0, (float) $normalized['confidence'])), 4);
        }

        if (!empty($pages)) {
            $total = 0.0;
            $count = 0;
            foreach ($pages as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $total += (float) ($page['confidence_proxy'] ?? 0.0);
                $count++;
            }
            if ($count > 0) {
                return round(max(0.0, min(1.0, $total / $count)), 4);
            }
        }

        return round(max(0.0, min(1.0, $this->text_confidence_proxy($text))), 4);
    }

    private function text_confidence_proxy(string $text): float {
        if (function_exists('dcb_text_confidence_proxy')) {
            return (float) dcb_text_confidence_proxy($text);
        }

        $text = trim($text);
        if ($text === '') {
            return 0.0;
        }
        $len = strlen($text);
        if ($len < 20) {
            return 0.25;
        }
        if ($len < 80) {
            return 0.5;
        }
        if ($len < 220) {
            return 0.72;
        }
        return 0.9;
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
            $message = sanitize_text_field((string) $response->get_error_message());
            $error_code = method_exists($response, 'get_error_code') ? sanitize_key((string) $response->get_error_code()) : '';

            $is_timeout = stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false || $error_code === 'timeout';
            return array(
                'ok' => false,
                'error_code' => $is_timeout ? 'remote_timeout' : 'remote_network_failed',
                'message' => $message !== '' ? $message : 'Remote request failed.',
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
            $service_failure = sanitize_key((string) ($decoded['failure_reason'] ?? ''));
            if ($service_failure !== '') {
                return array(
                    'ok' => false,
                    'error_code' => 'remote_service_failure',
                    'service_failure_reason' => $service_failure,
                    'message' => sanitize_text_field((string) ($decoded['message'] ?? ('Remote OCR service failure: ' . $service_failure))),
                    'status_code' => $status_code,
                    'body' => $decoded,
                    'url' => $url,
                );
            }

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

    private function error_result(string $code, string $message, string $request_id, int $http_status = 0, array $extra = array()): array {
        $warnings = array(array('code' => sanitize_key($code), 'message' => sanitize_text_field($message)));

        $provenance = array(
            'mode' => 'remote',
            'provider' => sanitize_text_field((string) ($extra['provider'] ?? 'remote')),
            'provider_version' => sanitize_text_field((string) ($extra['provider_version'] ?? '')),
            'timestamp' => current_time('mysql'),
            'request_id' => $request_id,
            'http_status' => $http_status,
            'request_url' => esc_url_raw((string) ($extra['request_url'] ?? '')),
            'contract_version' => sanitize_text_field((string) ($extra['contract_version'] ?? self::CONTRACT_VERSION)),
            'engine_used' => sanitize_text_field((string) ($extra['engine_used'] ?? 'remote')),
            'warnings' => $warnings,
            'failure_reason' => sanitize_key($code),
            'confidence' => 0.0,
            'timings' => array('total_ms' => 0, 'validation_ms' => 0, 'extraction_ms' => 0, 'normalization_ms' => 0),
        );

        return array(
            'text' => '',
            'normalized' => '',
            'engine' => 'remote',
            'engine_used' => 'remote',
            'pages' => array(),
            'warnings' => $warnings,
            'failure_reason' => sanitize_key($code),
            'confidence' => 0.0,
            'confidence_proxy' => 0.0,
            'timings' => array('total_ms' => 0, 'validation_ms' => 0, 'extraction_ms' => 0, 'normalization_ms' => 0),
            'provider' => 'remote',
            'provenance' => $provenance,
        );
    }

    private function map_remote_failure_reason(string $error_code): string {
        $error_code = sanitize_key($error_code);
        if ($error_code === 'remote_auth_failed') {
            return 'remote_auth_failed';
        }
        if ($error_code === 'remote_timeout') {
            return 'remote_timeout';
        }
        if ($error_code === 'remote_network_failed') {
            return 'remote_network_failed';
        }
        if ($error_code === 'remote_service_failure') {
            return 'remote_service_failure';
        }
        if ($error_code === 'remote_http_error') {
            return 'remote_http_error';
        }
        return 'remote_request_failed';
    }

    private function map_diagnostic_code(string $error_code): string {
        $error_code = sanitize_key($error_code);
        if ($error_code === 'remote_auth_failed') {
            return 'bad_api_key';
        }
        if ($error_code === 'remote_timeout') {
            return 'timeout';
        }
        if ($error_code === 'remote_network_failed') {
            return 'network_failure';
        }
        if ($error_code === 'remote_contract_invalid_shape' || $error_code === 'remote_contract_version_mismatch') {
            return 'schema_mismatch';
        }
        if ($error_code === 'remote_service_failure') {
            return 'service_declared_failure';
        }
        if ($error_code === 'remote_http_error') {
            return 'remote_http_error';
        }
        return 'remote_error';
    }

    private function diagnostic_row_from_call(array $call, string $endpoint): array {
        $error_code = sanitize_key((string) ($call['error_code'] ?? 'remote_error'));
        return array(
            'code' => $this->map_diagnostic_code($error_code),
            'endpoint' => sanitize_key($endpoint),
            'message' => sanitize_text_field((string) ($call['message'] ?? 'Remote endpoint unavailable.')),
        );
    }

    private function missing_capabilities(array $caps_body): array {
        if (empty($caps_body)) {
            return array('capabilities_payload_missing');
        }

        $required_flags = array(
            'supports_pdf_text_extraction',
            'supports_scanned_pdf_rasterization',
            'supports_image_ocr',
        );

        $missing = array();
        foreach ($required_flags as $flag) {
            if (!array_key_exists($flag, $caps_body)) {
                $missing[] = $flag;
            }
        }

        if (!empty($caps_body['supported_file_types']) && is_array($caps_body['supported_file_types'])) {
            $types = array_map('strtolower', array_map('strval', $caps_body['supported_file_types']));
            foreach (array('application/pdf', 'image/jpeg', 'image/png', 'image/webp') as $required_type) {
                if (!in_array($required_type, $types, true)) {
                    $missing[] = 'file_type:' . $required_type;
                }
            }
        } else {
            $missing[] = 'supported_file_types';
        }

        return array_values(array_unique($missing));
    }

    private function preflight_capability_check(string $mime, int $timeout, string $request_id, string $auth_header, string $api_key): array {
        $caps = $this->remote_call('capabilities', 'GET', array(), $timeout, $request_id, $auth_header, $api_key);
        if (empty($caps['ok'])) {
            return array(
                'ok' => true,
                'warnings' => array(
                    array(
                        'code' => 'capabilities_unavailable',
                        'message' => 'Remote capabilities endpoint unavailable; OCR extraction will proceed without preflight capability guarantees.',
                    ),
                ),
            );
        }

        $body = isset($caps['body']) && is_array($caps['body']) ? $caps['body'] : array();
        $has_capability_fields = array_key_exists('supported_file_types', $body)
            || array_key_exists('supports_pdf_text_extraction', $body)
            || array_key_exists('supports_scanned_pdf_rasterization', $body)
            || array_key_exists('supports_image_ocr', $body);
        if (!$has_capability_fields) {
            return array(
                'ok' => true,
                'warnings' => array(
                    array(
                        'code' => 'capabilities_shape_unknown',
                        'message' => 'Remote capabilities payload did not expose expected feature fields; extraction will proceed.',
                    ),
                ),
            );
        }

        $remote_contract = sanitize_text_field((string) ($body['contract_version'] ?? ''));
        if ($remote_contract !== '' && $remote_contract !== self::CONTRACT_VERSION) {
            return array(
                'ok' => false,
                'failure_reason' => 'remote_contract_version_mismatch',
                'message' => 'Remote OCR contract mismatch during capability preflight.',
                'contract_version' => $remote_contract,
                'http_status' => (int) ($caps['status_code'] ?? 0),
                'request_url' => (string) ($caps['url'] ?? ''),
            );
        }

        $supported_types = isset($body['supported_file_types']) && is_array($body['supported_file_types'])
            ? array_map('strtolower', array_map('strval', $body['supported_file_types']))
            : array();

        if (!empty($supported_types) && !in_array(strtolower($mime), $supported_types, true)) {
            return array(
                'ok' => false,
                'failure_reason' => 'remote_missing_capability',
                'message' => 'Remote OCR provider does not advertise support for mime: ' . sanitize_text_field($mime),
                'contract_version' => $remote_contract !== '' ? $remote_contract : self::CONTRACT_VERSION,
                'provider' => sanitize_text_field((string) ($body['provider'] ?? 'remote')),
                'provider_version' => sanitize_text_field((string) ($body['provider_version'] ?? '')),
                'http_status' => (int) ($caps['status_code'] ?? 0),
                'request_url' => (string) ($caps['url'] ?? ''),
            );
        }

        $is_pdf = strtolower($mime) === 'application/pdf';
        $is_image = strpos(strtolower($mime), 'image/') === 0;

        if ($is_pdf && (array_key_exists('supports_pdf_text_extraction', $body) || array_key_exists('supports_scanned_pdf_rasterization', $body))) {
            $pdf_text = !empty($body['supports_pdf_text_extraction']);
            $pdf_scan = !empty($body['supports_scanned_pdf_rasterization']);
            if (!$pdf_text && !$pdf_scan) {
                return array(
                    'ok' => false,
                    'failure_reason' => 'remote_missing_capability',
                    'message' => 'Remote OCR provider lacks both PDF text extraction and scanned PDF rasterization support.',
                    'contract_version' => $remote_contract !== '' ? $remote_contract : self::CONTRACT_VERSION,
                    'provider' => sanitize_text_field((string) ($body['provider'] ?? 'remote')),
                    'provider_version' => sanitize_text_field((string) ($body['provider_version'] ?? '')),
                    'http_status' => (int) ($caps['status_code'] ?? 0),
                    'request_url' => (string) ($caps['url'] ?? ''),
                );
            }
        }

        if ($is_image && array_key_exists('supports_image_ocr', $body) && empty($body['supports_image_ocr'])) {
            return array(
                'ok' => false,
                'failure_reason' => 'remote_missing_capability',
                'message' => 'Remote OCR provider lacks image OCR support for this workflow.',
                'contract_version' => $remote_contract !== '' ? $remote_contract : self::CONTRACT_VERSION,
                'provider' => sanitize_text_field((string) ($body['provider'] ?? 'remote')),
                'provider_version' => sanitize_text_field((string) ($body['provider_version'] ?? '')),
                'http_status' => (int) ($caps['status_code'] ?? 0),
                'request_url' => (string) ($caps['url'] ?? ''),
            );
        }

        $warnings = array();
        $langs = isset($body['languages_available']) && is_array($body['languages_available'])
            ? array_map('strtolower', array_map('strval', $body['languages_available']))
            : array();
        if (($is_image || $is_pdf) && !empty($langs) && !in_array(self::EXPECTED_REMOTE_LANG, $langs, true)) {
            $warnings[] = array(
                'code' => 'language_mismatch',
                'message' => 'Remote OCR provider languages do not include expected OCR language: ' . self::EXPECTED_REMOTE_LANG,
            );
        }

        return array(
            'ok' => true,
            'warnings' => $warnings,
            'contract_version' => $remote_contract !== '' ? $remote_contract : self::CONTRACT_VERSION,
            'provider' => sanitize_text_field((string) ($body['provider'] ?? 'remote')),
            'provider_version' => sanitize_text_field((string) ($body['provider_version'] ?? '')),
            'http_status' => (int) ($caps['status_code'] ?? 0),
            'request_url' => (string) ($caps['url'] ?? ''),
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

        $used_remote = $engine->slug() === 'remote';
        $remote_empty = trim((string) ($result['text'] ?? '')) === '';
        $failure_reason = sanitize_key((string) ($result['failure_reason'] ?? ''));
        if ($mode === 'auto' && $used_remote && ($remote_empty || $failure_reason === 'empty_extraction')) {
            self::record_remote_runtime_event($result, true);
            $fallback = (new DCB_OCR_Engine_Local())->extract($file_path, $mime);
            $fallback['warnings'][] = array('code' => 'auto_fallback_to_local', 'message' => 'Remote OCR returned empty result. Fell back to local OCR.');
            $fallback['provenance']['fallback_from'] = 'remote';
            $fallback['provenance']['fallback_reason'] = $failure_reason !== '' ? $failure_reason : 'empty_extraction';
            return $fallback;
        }

        if ($used_remote) {
            self::record_remote_runtime_event($result, false);
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

    private static function record_remote_runtime_event(array $result, bool $fallback_used): void {
        $stats = get_option('dcb_ocr_remote_runtime_stats', array());
        if (!is_array($stats)) {
            $stats = array();
        }

        $stats['total_calls'] = max(0, (int) ($stats['total_calls'] ?? 0)) + 1;
        $stats['success_count'] = max(0, (int) ($stats['success_count'] ?? 0));
        $stats['failure_count'] = max(0, (int) ($stats['failure_count'] ?? 0));
        $stats['fallback_count'] = max(0, (int) ($stats['fallback_count'] ?? 0));
        $stats['unhealthy_streak'] = max(0, (int) ($stats['unhealthy_streak'] ?? 0));

        $failure_reason = sanitize_key((string) ($result['failure_reason'] ?? ''));
        $success = $failure_reason === '' && trim((string) ($result['text'] ?? '')) !== '';
        if ($success) {
            $stats['success_count']++;
            $stats['last_success_at'] = current_time('mysql');
            $stats['unhealthy_streak'] = 0;
        } else {
            $stats['failure_count']++;
            $stats['last_failure_at'] = current_time('mysql');
            $stats['last_failure_reason'] = $failure_reason !== '' ? $failure_reason : 'empty_extraction';
            $stats['unhealthy_streak'] = max(0, (int) ($stats['unhealthy_streak'] ?? 0)) + 1;
        }

        if ($fallback_used) {
            $stats['fallback_count']++;
            $events = isset($stats['recent_fallbacks']) && is_array($stats['recent_fallbacks']) ? $stats['recent_fallbacks'] : array();
            $events[] = array(
                'timestamp' => current_time('mysql'),
                'request_id' => sanitize_text_field((string) (($result['provenance']['request_id'] ?? ''))),
                'reason' => $failure_reason !== '' ? $failure_reason : 'empty_extraction',
            );
            if (count($events) > 15) {
                $events = array_slice($events, -15);
            }
            $stats['recent_fallbacks'] = $events;
        }

        update_option('dcb_ocr_remote_runtime_stats', $stats, false);
    }
}
