<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_OCR {
    public static function init(): void {
        add_action('wp_ajax_dcb_ocr_smoke_validation', array(__CLASS__, 'smoke_validation_ajax'));
        add_action('admin_post_dcb_ocr_remote_probe', array(__CLASS__, 'remote_probe_action'));
    }

    public static function smoke_validation_ajax(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }

        check_ajax_referer('dcb_ocr_smoke_validation', 'nonce');

        $result = dcb_ocr_smoke_validation();
        if (!empty($result['ok'])) {
            wp_send_json_success($result);
        }

        wp_send_json_error($result, 422);
    }

    public static function render_diagnostics_page(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            wp_die('Unauthorized');
        }

        $diag = dcb_ocr_collect_environment_diagnostics();
        $warnings = isset($diag['warnings']) && is_array($diag['warnings']) ? $diag['warnings'] : array();
        $languages = isset($diag['tesseract_languages']) && is_array($diag['tesseract_languages']) ? $diag['tesseract_languages'] : array();
        $checks = isset($diag['checks']) && is_array($diag['checks']) ? $diag['checks'] : array();
        $provider_diag = isset($diag['provider_diagnostics']) && is_array($diag['provider_diagnostics']) ? $diag['provider_diagnostics'] : array();
        $remote_caps = isset($provider_diag['engines']['remote']) && is_array($provider_diag['engines']['remote']) ? $provider_diag['engines']['remote'] : array();
        $last_probe = get_option('dcb_ocr_remote_probe_last', array());
        if (!is_array($last_probe)) {
            $last_probe = array();
        }
        $logs = dcb_upload_ocr_debug_log_recent(10);

        echo '<div class="wrap">';
        echo '<h1>OCR Diagnostics</h1>';
        echo '<p>Environment readiness and runtime diagnostics for local/remote OCR providers (HTTPS API supported, no SSH).</p>';

        echo '<table class="widefat striped" style="max-width:920px">';
        echo '<tbody>';
        echo '<tr><th style="width:240px">Overall Status</th><td><strong>' . esc_html((string) ($diag['status'] ?? 'unknown')) . '</strong></td></tr>';
        echo '<tr><th>Tesseract</th><td>' . esc_html((string) ($checks['tesseract']['path'] ?? 'Not found')) . '</td></tr>';
        echo '<tr><th>pdftotext</th><td>' . esc_html((string) ($checks['pdftotext']['path'] ?? 'Not found')) . '</td></tr>';
        echo '<tr><th>pdftoppm</th><td>' . esc_html((string) ($checks['pdftoppm']['path'] ?? 'Not found')) . '</td></tr>';
        echo '<tr><th>Tesseract Languages</th><td>' . esc_html(implode(', ', $languages)) . '</td></tr>';
        echo '<tr><th>OCR Mode</th><td>' . esc_html((string) ($provider_diag['mode'] ?? 'local')) . ' (active: ' . esc_html((string) ($provider_diag['active'] ?? 'local')) . ')</td></tr>';
        if (!empty($remote_caps)) {
            echo '<tr><th>Remote OCR Healthy</th><td>' . esc_html(!empty($remote_caps['remote_healthy']) ? 'yes' : 'no') . '</td></tr>';
            echo '<tr><th>Remote Contract Version</th><td>' . esc_html((string) ($remote_caps['remote_contract_version'] ?? $remote_caps['contract_version'] ?? '')) . '</td></tr>';
            echo '<tr><th>Remote Provider Version</th><td>' . esc_html((string) ($remote_caps['provider_version'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Remote OCR Probe</h2>';
        echo '<p>Runs fresh checks against remote <code>/health</code> and <code>/capabilities</code>.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dcb_ocr_remote_probe', 'dcb_ocr_remote_probe_nonce');
        echo '<input type="hidden" name="action" value="dcb_ocr_remote_probe" />';
        submit_button('Run Remote OCR Probe', 'secondary', 'submit', false);
        echo '</form>';

        if (!empty($last_probe)) {
            echo '<p><strong>Last probe:</strong> ' . esc_html((string) ($last_probe['timestamp'] ?? '')) . ' — ' . esc_html((string) ($last_probe['status'] ?? 'unknown')) . '</p>';
            if (!empty($last_probe['diagnostics']) && is_array($last_probe['diagnostics'])) {
                echo '<ul style="list-style:disc;padding-left:18px;">';
                foreach ($last_probe['diagnostics'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $code = sanitize_key((string) ($row['code'] ?? 'remote_error'));
                    $endpoint = sanitize_key((string) ($row['endpoint'] ?? 'remote'));
                    $message = sanitize_text_field((string) ($row['message'] ?? 'Remote OCR issue detected.'));
                    echo '<li><strong>' . esc_html($code) . '</strong> (' . esc_html($endpoint) . '): ' . esc_html($message) . '</li>';
                }
                echo '</ul>';
            }
        }

        if (!empty($provider_diag['engines']) && is_array($provider_diag['engines'])) {
            echo '<h2>Provider Capabilities</h2><ul style="list-style:disc;padding-left:18px;">';
            foreach ($provider_diag['engines'] as $slug => $caps) {
                if (!is_array($caps)) {
                    continue;
                }
                $ready = !empty($caps['ready']) ? 'ready' : 'not ready';
                $status_text = sanitize_text_field((string) ($caps['status'] ?? 'unknown'));
                echo '<li><strong>' . esc_html((string) $slug) . '</strong>: ' . esc_html($ready . ' (' . $status_text . ')') . '</li>';
                if ($slug === 'remote') {
                    $contract = sanitize_text_field((string) ($caps['contract_version'] ?? '')); 
                    $remote_contract = sanitize_text_field((string) ($caps['remote_contract_version'] ?? ''));
                    $provider_version = sanitize_text_field((string) ($caps['provider_version'] ?? ''));
                    $auth_header = sanitize_text_field((string) ($caps['auth_header'] ?? '')); 
                    if ($contract !== '') {
                        echo '<li style="margin-left:18px;">Contract: ' . esc_html($contract) . '</li>';
                    }
                    if ($remote_contract !== '') {
                        echo '<li style="margin-left:18px;">Remote Contract: ' . esc_html($remote_contract) . '</li>';
                    }
                    if ($provider_version !== '') {
                        echo '<li style="margin-left:18px;">Provider Version: ' . esc_html($provider_version) . '</li>';
                    }
                    if ($auth_header !== '') {
                        echo '<li style="margin-left:18px;">Auth Header: ' . esc_html($auth_header) . '</li>';
                    }
                    $health_ok = !empty($caps['health']['ok']) ? 'ok' : 'failed';
                    $caps_ok = !empty($caps['capabilities']['ok']) ? 'ok' : 'failed';
                    echo '<li style="margin-left:18px;">Health Endpoint: ' . esc_html($health_ok) . '</li>';
                    echo '<li style="margin-left:18px;">Capabilities Endpoint: ' . esc_html($caps_ok) . '</li>';
                    $cap_body = isset($caps['capabilities']['body']) && is_array($caps['capabilities']['body']) ? $caps['capabilities']['body'] : array();
                    if (!empty($cap_body)) {
                        $types = isset($cap_body['supported_file_types']) && is_array($cap_body['supported_file_types']) ? implode(', ', array_map('strval', $cap_body['supported_file_types'])) : '';
                        $engines = isset($cap_body['ocr_engines_available']) && is_array($cap_body['ocr_engines_available']) ? implode(', ', array_map('strval', $cap_body['ocr_engines_available'])) : '';
                        $langs = isset($cap_body['languages_available']) && is_array($cap_body['languages_available']) ? implode(', ', array_map('strval', $cap_body['languages_available'])) : '';
                        if ($types !== '') {
                            echo '<li style="margin-left:18px;">Supported File Types: ' . esc_html($types) . '</li>';
                        }
                        if ($engines !== '') {
                            echo '<li style="margin-left:18px;">OCR Engines: ' . esc_html($engines) . '</li>';
                        }
                        if ($langs !== '') {
                            echo '<li style="margin-left:18px;">Languages: ' . esc_html($langs) . '</li>';
                        }
                        echo '<li style="margin-left:18px;">PDF Text Extraction: ' . esc_html(!empty($cap_body['supports_pdf_text_extraction']) ? 'yes' : 'no') . '</li>';
                        echo '<li style="margin-left:18px;">Scanned PDF Rasterization: ' . esc_html(!empty($cap_body['supports_scanned_pdf_rasterization']) ? 'yes' : 'no') . '</li>';
                        echo '<li style="margin-left:18px;">Image OCR: ' . esc_html(!empty($cap_body['supports_image_ocr']) ? 'yes' : 'no') . '</li>';
                    }
                    if (!empty($caps['missing_capabilities']) && is_array($caps['missing_capabilities'])) {
                        echo '<li style="margin-left:18px;">Missing Capabilities: ' . esc_html(implode(', ', array_map('strval', $caps['missing_capabilities']))) . '</li>';
                    }
                    if (!empty($caps['diagnostics']) && is_array($caps['diagnostics'])) {
                        foreach ($caps['diagnostics'] as $diag_row) {
                            if (!is_array($diag_row)) {
                                continue;
                            }
                            $code = sanitize_key((string) ($diag_row['code'] ?? 'remote_error'));
                            $endpoint = sanitize_key((string) ($diag_row['endpoint'] ?? 'remote'));
                            $message = sanitize_text_field((string) ($diag_row['message'] ?? 'Remote OCR issue detected.'));
                            echo '<li style="margin-left:18px;"><strong>' . esc_html($code) . '</strong> (' . esc_html($endpoint) . '): ' . esc_html($message) . '</li>';
                        }
                    }
                }
            }
            echo '</ul>';
        }

        if (!empty($warnings)) {
            echo '<h2>Warnings</h2><ul style="list-style:disc;padding-left:18px;">';
            foreach ($warnings as $warning) {
                echo '<li>' . esc_html((string) $warning) . '</li>';
            }
            echo '</ul>';
        }

        echo '<h2>Smoke Validation</h2>';
        $smoke = dcb_ocr_smoke_validation($diag);
        echo '<p><strong>' . esc_html(!empty($smoke['ok']) ? 'OK' : 'Failed') . '</strong></p>';
        if (!empty($smoke['messages']) && is_array($smoke['messages'])) {
            echo '<ul style="list-style:disc;padding-left:18px;">';
            foreach ($smoke['messages'] as $message) {
                echo '<li>' . esc_html((string) $message) . '</li>';
            }
            echo '</ul>';
        }

        echo '<h2>Recent OCR Runtime Logs</h2>';
        if (empty($logs)) {
            echo '<p>No OCR logs yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Engine</th><th>File</th><th>Confidence</th><th>Warning</th></tr></thead><tbody>';
            foreach ($logs as $row) {
                if (!is_array($row)) {
                    continue;
                }
                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['time'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['engine'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['source_file_path'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['confidence_proxy'] ?? '0')) . '</td>';
                echo '<td>' . esc_html((string) ($row['warning_message'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public static function remote_probe_action(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            wp_die('Unauthorized');
        }

        if (!isset($_POST['dcb_ocr_remote_probe_nonce']) || !wp_verify_nonce((string) $_POST['dcb_ocr_remote_probe_nonce'], 'dcb_ocr_remote_probe')) {
            wp_die('Security check failed');
        }

        $diag = class_exists('DCB_OCR_Engine_Manager') ? DCB_OCR_Engine_Manager::diagnostics() : array();
        $remote = isset($diag['engines']['remote']) && is_array($diag['engines']['remote']) ? $diag['engines']['remote'] : array();

        $row = array(
            'timestamp' => current_time('mysql'),
            'status' => !empty($remote['remote_healthy']) ? 'healthy' : 'degraded',
            'diagnostics' => isset($remote['diagnostics']) && is_array($remote['diagnostics']) ? $remote['diagnostics'] : array(),
            'contract_version' => sanitize_text_field((string) ($remote['remote_contract_version'] ?? $remote['contract_version'] ?? '')),
            'provider_version' => sanitize_text_field((string) ($remote['provider_version'] ?? '')),
            'missing_capabilities' => isset($remote['missing_capabilities']) && is_array($remote['missing_capabilities']) ? $remote['missing_capabilities'] : array(),
        );

        update_option('dcb_ocr_remote_probe_last', $row, false);

        wp_safe_redirect(add_query_arg(array('page' => 'dcb-ocr-diagnostics', 'probe' => '1'), admin_url('admin.php')));
        exit;
    }
}
