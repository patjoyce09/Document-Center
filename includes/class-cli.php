<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_CLI {
    public static function init(): void {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('dcb ocr-diagnostics', array(__CLASS__, 'ocr_diagnostics'));
            WP_CLI::add_command('dcb ocr-smoke', array(__CLASS__, 'ocr_smoke'));
            WP_CLI::add_command('dcb forms-migrate', array(__CLASS__, 'forms_migrate'));
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

    /**
     * ## OPTIONS
     *
     * [--target=<mode>]
     * : Target backend mode (`cpt` or `table`).
     *
     * [--dry-run]
     * : Run migration as dry run (default true when omitted).
     */
    public static function forms_migrate(array $args, array $assoc_args): void {
        if (!class_exists('WP_CLI')) {
            return;
        }
        if (!class_exists('DCB_Form_Repository')) {
            \WP_CLI::error('DCB_Form_Repository is unavailable.');
            return;
        }

        $target = sanitize_key((string) ($assoc_args['target'] ?? 'cpt'));
        $dry_run = !array_key_exists('dry-run', $assoc_args) || (string) $assoc_args['dry-run'] !== '0';

        $result = DCB_Form_Repository::migrate_option_to_mode($target, $dry_run);
        if (empty($result['ok'])) {
            \WP_CLI::error((string) ($result['message'] ?? 'Forms migration failed.'));
            return;
        }

        $summary = sprintf(
            'Forms migration %s complete: target=%s migrated=%d',
            $dry_run ? 'dry-run' : 'write',
            (string) ($result['target_mode'] ?? $target),
            (int) ($result['migrated'] ?? 0)
        );

        \WP_CLI::success($summary);
        \WP_CLI::log(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
