<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Form_Repository {
    private const STORAGE_MODE_OPTION = 'dcb_forms_storage_mode';
    private const OPTION_KEY = 'dcb_forms_custom';

    public static function storage_mode(): string {
        $mode = sanitize_key((string) get_option(self::STORAGE_MODE_OPTION, 'option'));
        if (!in_array($mode, array('option', 'cpt', 'table'), true)) {
            $mode = 'option';
        }
        return $mode;
    }

    public static function get_all_raw(): array {
        $mode = self::storage_mode();

        /**
         * Future migration target:
         * - cpt mode: load from form definition CPT + revisions
         * - table mode: load from custom tables
         */
        if ($mode !== 'option') {
            // Temporary fallback until storage migration is implemented.
            $mode = 'option';
        }

        $raw = get_option(self::OPTION_KEY, array());
        return is_array($raw) ? $raw : array();
    }

    public static function save_all_raw(array $forms): void {
        update_option(self::OPTION_KEY, $forms, false);
    }
}
