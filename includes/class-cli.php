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
            WP_CLI::add_command('dcb forms-parity-report', array(__CLASS__, 'forms_parity_report'));
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

    /**
     * ## OPTIONS
     *
     * [--source=<mode>]
     * : Source mode (`option|cpt|table`). Default: option.
     *
     * [--target=<mode>]
     * : Target mode (`option|cpt|table`). Default: cpt.
     *
     * [--format=<format>]
     * : Output format (`json|csv`). Default: json.
     *
     * [--summary-only]
     * : Emit only top-level summary metadata (useful for CI).
     */
    public static function forms_parity_report(array $args, array $assoc_args): void {
        if (!class_exists('WP_CLI')) {
            return;
        }
        if (!class_exists('DCB_Form_Repository')) {
            \WP_CLI::error('DCB_Form_Repository is unavailable.');
            return;
        }

        $source = sanitize_key((string) ($assoc_args['source'] ?? 'option'));
        $target = sanitize_key((string) ($assoc_args['target'] ?? 'cpt'));
        $format = sanitize_key((string) ($assoc_args['format'] ?? 'json'));
        if (!in_array($format, array('json', 'csv'), true)) {
            \WP_CLI::error('Invalid format. Use json or csv.');
            return;
        }

        $report = DCB_Form_Repository::parity_between_modes($source, $target);
        if (empty($report['ok'])) {
            \WP_CLI::error((string) ($report['message'] ?? 'Parity report failed.'));
            return;
        }

        $summary_only = array_key_exists('summary-only', $assoc_args);
        if ($summary_only) {
            $summary_payload = array(
                'ok' => true,
                'source_mode' => $source,
                'target_mode' => $target,
                'summary' => isset($report['summary']) && is_array($report['summary']) ? $report['summary'] : array(),
                'counts' => array(
                    'source_count' => (int) ($report['source_count'] ?? 0),
                    'target_count' => (int) ($report['target_count'] ?? 0),
                ),
            );
            \WP_CLI::log(wp_json_encode($summary_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        if ($format === 'json') {
            \WP_CLI::log(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        $rows = isset($report['rows']) && is_array($report['rows']) ? $report['rows'] : array();
        $parity = isset($report['parity']) && is_array($report['parity']) ? $report['parity'] : array();
        $mismatch_details = isset($parity['checksum_mismatches']) && is_array($parity['checksum_mismatches']) ? $parity['checksum_mismatches'] : array();
        $detail_by_form = array();
        foreach ($mismatch_details as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = sanitize_key((string) ($item['form_key'] ?? ''));
            if ($key !== '') {
                $detail_by_form[$key] = $item;
            }
        }

        $csv = array();
        $csv[] = 'form_key,issue,detail,verified_ratio,missing_count,extra_count,checksum_mismatch_count,changed_paths';
        $summary = isset($report['summary']) && is_array($report['summary']) ? $report['summary'] : array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $form_key = sanitize_key((string) ($row['form_key'] ?? ''));
            $issue = sanitize_key((string) ($row['issue'] ?? ''));
                $detail = str_replace('"', '""', sanitize_text_field((string) ($row['detail'] ?? '')));
            $changed_paths = '';
            if ($form_key !== '' && isset($detail_by_form[$form_key]) && is_array($detail_by_form[$form_key])) {
                $changed_paths = isset($detail_by_form[$form_key]['paths_changed']) && is_array($detail_by_form[$form_key]['paths_changed'])
                    ? implode('|', array_slice($detail_by_form[$form_key]['paths_changed'], 0, 20))
                    : '';
            }
                $changed_paths = str_replace('"', '""', $changed_paths);

            $csv[] = sprintf(
                '"%1$s","%2$s","%3$s",%4$d,%5$d,%6$d,%7$d,"%8$s"',
                $form_key,
                $issue,
                $detail,
                (int) ($summary['verified_ratio'] ?? 0),
                (int) ($summary['missing_count'] ?? 0),
                (int) ($summary['extra_count'] ?? 0),
                (int) ($summary['checksum_mismatch_count'] ?? 0),
                $changed_paths
            );
        }

        if (count($csv) === 1) {
            $csv[] = sprintf(
                '"","summary","No row-level mismatches",%1$d,%2$d,%3$d,%4$d,""',
                (int) ($summary['verified_ratio'] ?? 0),
                (int) ($summary['missing_count'] ?? 0),
                (int) ($summary['extra_count'] ?? 0),
                (int) ($summary['checksum_mismatch_count'] ?? 0)
            );
        }

        \WP_CLI::log(implode("\n", $csv));
    }
}
