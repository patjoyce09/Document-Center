<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Form_Repository {
    private const STORAGE_MODE_OPTION = 'dcb_forms_storage_mode';
    private const OPTION_KEY = 'dcb_forms_custom';
    private const DUAL_READ_OPTION = 'dcb_forms_storage_dual_read';
    private const DUAL_WRITE_OPTION = 'dcb_forms_storage_dual_write';

    /**
     * Storage backend expectations (mode contract):
     * - `read_all(mode)` returns associative array keyed by form key.
     * - `write_all(mode, forms)` persists a complete associative form map.
     * - mode `option` is canonical and fully supported.
     * - modes `cpt` and `table` are migration shadow stores in this pass.
     */
    private static function mode_to_option_key(string $mode): string {
        $mode = sanitize_key($mode);
        if ($mode === 'cpt') {
            return 'dcb_forms_custom_cpt_shadow';
        }
        if ($mode === 'table') {
            return 'dcb_forms_custom_table_shadow';
        }
        return self::OPTION_KEY;
    }

    public static function storage_mode(): string {
        $mode = sanitize_key((string) get_option(self::STORAGE_MODE_OPTION, 'option'));
        if (!in_array($mode, array('option', 'cpt', 'table'), true)) {
            $mode = 'option';
        }
        return $mode;
    }

    public static function dual_read_enabled(): bool {
        return get_option(self::DUAL_READ_OPTION, '1') === '1';
    }

    public static function dual_write_enabled(): bool {
        return get_option(self::DUAL_WRITE_OPTION, '0') === '1';
    }

    public static function available_modes(): array {
        return array('option', 'cpt', 'table');
    }

    private static function read_mode_raw(string $mode): array {
        $key = self::mode_to_option_key($mode);
        $raw = get_option($key, array());
        return is_array($raw) ? $raw : array();
    }

    private static function write_mode_raw(string $mode, array $forms): void {
        $key = self::mode_to_option_key($mode);
        update_option($key, $forms, false);
    }

    public static function get_all_raw(): array {
        $mode = self::storage_mode();

        $primary = self::read_mode_raw($mode);
        if (!empty($primary) || $mode === 'option' || !self::dual_read_enabled()) {
            return $primary;
        }

        return self::read_mode_raw('option');
    }

    public static function save_all_raw(array $forms): void {
        $mode = self::storage_mode();
        self::write_mode_raw($mode, $forms);

        if ($mode !== 'option' && self::dual_write_enabled()) {
            self::write_mode_raw('option', $forms);
        }
    }

    public static function migration_readiness(): array {
        $mode = self::storage_mode();
        $option_forms = self::read_mode_raw('option');
        $target_forms = $mode !== 'option' ? self::read_mode_raw($mode) : $option_forms;

        return array(
            'mode' => $mode,
            'dual_read' => self::dual_read_enabled(),
            'dual_write' => self::dual_write_enabled(),
            'option_count' => count($option_forms),
            'target_count' => count($target_forms),
            'target_mode_ready' => in_array($mode, self::available_modes(), true),
            'target_has_data' => !empty($target_forms),
            'target_option_key' => self::mode_to_option_key($mode),
        );
    }

    public static function migrate_option_to_mode(string $target_mode, bool $dry_run = true): array {
        $target_mode = sanitize_key($target_mode);
        if (!in_array($target_mode, self::available_modes(), true) || $target_mode === 'option') {
            return array(
                'ok' => false,
                'message' => 'Invalid migration target mode.',
                'target_mode' => $target_mode,
                'migrated' => 0,
            );
        }

        $source = self::read_mode_raw('option');
        if ($dry_run) {
            return array(
                'ok' => true,
                'dry_run' => true,
                'target_mode' => $target_mode,
                'source_count' => count($source),
                'migrated' => count($source),
                'message' => 'Dry run complete. No data written.',
            );
        }

        self::write_mode_raw($target_mode, $source);
        update_option('dcb_forms_storage_last_migrated_at', current_time('mysql'), false);
        update_option('dcb_forms_storage_last_migrated_target', $target_mode, false);

        return array(
            'ok' => true,
            'dry_run' => false,
            'target_mode' => $target_mode,
            'source_count' => count($source),
            'migrated' => count($source),
            'message' => 'Migration completed.',
        );
    }
}
