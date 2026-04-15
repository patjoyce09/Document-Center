<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_CLI {
    public static function init(): void {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('dcb ocr-diagnostics', array(__CLASS__, 'ocr_diagnostics'));
            WP_CLI::add_command('dcb ocr-smoke', array(__CLASS__, 'ocr_smoke'));
        }
    }

    public static function ocr_diagnostics(): void {
        $diag = dcb_ocr_collect_environment_diagnostics();
        if (class_exists('WP_CLI')) {
            \WP_CLI::log(wp_json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public static function ocr_smoke(): void {
        $result = dcb_ocr_smoke_validation();
        if (!class_exists('WP_CLI')) {
            return;
        }

        if (!empty($result['ok'])) {
            \WP_CLI::success('OCR smoke validation passed.');
        } else {
            \WP_CLI::warning('OCR smoke validation failed.');
        }
        \WP_CLI::log(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
