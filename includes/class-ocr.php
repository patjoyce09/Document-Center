<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_OCR {
    public static function init(): void {
        add_action('wp_ajax_dcb_ocr_smoke_validation', array(__CLASS__, 'smoke_validation_ajax'));
    }

    public static function smoke_validation_ajax(): void {
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $diag = dcb_ocr_collect_environment_diagnostics();
        $warnings = isset($diag['warnings']) && is_array($diag['warnings']) ? $diag['warnings'] : array();
        $languages = isset($diag['tesseract_languages']) && is_array($diag['tesseract_languages']) ? $diag['tesseract_languages'] : array();
        $logs = dcb_upload_ocr_debug_log_recent(10);

        echo '<div class="wrap">';
        echo '<h1>OCR Diagnostics</h1>';
        echo '<p>Environment readiness and runtime diagnostics for local OCR binaries.</p>';

        echo '<table class="widefat striped" style="max-width:920px">';
        echo '<tbody>';
        echo '<tr><th style="width:240px">Overall Status</th><td><strong>' . esc_html((string) ($diag['status'] ?? 'unknown')) . '</strong></td></tr>';
        echo '<tr><th>Tesseract</th><td>' . esc_html((string) ($diag['binaries']['tesseract']['path'] ?? 'Not found')) . '</td></tr>';
        echo '<tr><th>pdftotext</th><td>' . esc_html((string) ($diag['binaries']['pdftotext']['path'] ?? 'Not found')) . '</td></tr>';
        echo '<tr><th>pdftoppm</th><td>' . esc_html((string) ($diag['binaries']['pdftoppm']['path'] ?? 'Not found')) . '</td></tr>';
        echo '<tr><th>Tesseract Languages</th><td>' . esc_html(implode(', ', $languages)) . '</td></tr>';
        echo '</tbody></table>';

        if (!empty($warnings)) {
            echo '<h2>Warnings</h2><ul style="list-style:disc;padding-left:18px;">';
            foreach ($warnings as $warning) {
                echo '<li>' . esc_html((string) $warning) . '</li>';
            }
            echo '</ul>';
        }

        echo '<h2>Smoke Validation</h2>';
        $smoke = dcb_ocr_smoke_validation();
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
}
