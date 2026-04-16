<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Form_Repository {
    private const STORAGE_MODE_OPTION = 'dcb_forms_storage_mode';
    private const OPTION_KEY = 'dcb_forms_custom';
    private const DUAL_READ_OPTION = 'dcb_forms_storage_dual_read';
    private const DUAL_WRITE_OPTION = 'dcb_forms_storage_dual_write';

    private const FORM_CPT = 'dcb_form_definition';
    private const META_FORM_KEY = '_dcb_form_key';
    private const META_FORM_PAYLOAD = '_dcb_form_payload';
    private const META_FORM_VERSION = '_dcb_form_version';
    private const META_FORM_UPDATED_AT = '_dcb_form_updated_at';

    public static function init(): void {
        add_action('init', array(__CLASS__, 'register_post_type'), 20);
    }

    public static function register_post_type(): void {
        register_post_type(self::FORM_CPT, array(
            'labels' => array(
                'name' => __('Form Definitions', 'document-center-builder'),
                'singular_name' => __('Form Definition', 'document-center-builder'),
            ),
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => array('title', 'revisions'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));
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

    public static function get_form_raw(string $form_key): ?array {
        $form_key = sanitize_key($form_key);
        if ($form_key === '') {
            return null;
        }

        $rows = self::get_all_raw();
        return isset($rows[$form_key]) && is_array($rows[$form_key]) ? $rows[$form_key] : null;
    }

    public static function save_form_raw(string $form_key, array $form): bool {
        $form_key = sanitize_key($form_key);
        if ($form_key === '') {
            return false;
        }

        $all = self::get_all_raw();
        $all[$form_key] = $form;
        self::save_all_raw($all);
        return true;
    }

    public static function delete_form_raw(string $form_key): bool {
        $form_key = sanitize_key($form_key);
        if ($form_key === '') {
            return false;
        }

        $all = self::get_all_raw();
        if (!isset($all[$form_key])) {
            return false;
        }

        unset($all[$form_key]);
        self::save_all_raw($all);
        return true;
    }

    public static function migration_readiness(): array {
        $mode = self::storage_mode();
        $option_forms = self::read_mode_raw('option');
        $cpt_forms = self::read_mode_raw('cpt');
        $target_forms = $mode === 'cpt' ? $cpt_forms : ($mode === 'option' ? $option_forms : self::read_mode_raw('table'));
        $parity = self::parity_report($option_forms, $cpt_forms);

        return array(
            'mode' => $mode,
            'dual_read' => self::dual_read_enabled(),
            'dual_write' => self::dual_write_enabled(),
            'option_count' => count($option_forms),
            'cpt_count' => count($cpt_forms),
            'target_count' => count($target_forms),
            'target_mode_ready' => $mode !== 'cpt' || self::cpt_backend_ready(),
            'target_has_data' => !empty($target_forms),
            'cpt_backend_ready' => self::cpt_backend_ready(),
            'parity' => $parity,
            'verification' => array(
                'summary' => self::parity_summary($parity),
                'mismatch_rows' => self::mismatch_rows($parity),
            ),
        );
    }

    public static function parity_between_modes(string $source_mode, string $target_mode): array {
        $source_mode = sanitize_key($source_mode);
        $target_mode = sanitize_key($target_mode);

        if (!in_array($source_mode, self::available_modes(), true) || !in_array($target_mode, self::available_modes(), true)) {
            return array(
                'ok' => false,
                'message' => 'Invalid mode(s) requested.',
                'source_mode' => $source_mode,
                'target_mode' => $target_mode,
                'parity' => array(),
                'summary' => array(),
                'rows' => array(),
            );
        }

        $source = self::read_mode_raw($source_mode);
        $target = self::read_mode_raw($target_mode);
        $parity = self::parity_report($source, $target);

        return array(
            'ok' => true,
            'source_mode' => $source_mode,
            'target_mode' => $target_mode,
            'source_count' => count($source),
            'target_count' => count($target),
            'parity' => $parity,
            'summary' => self::parity_summary($parity),
            'rows' => self::mismatch_rows($parity),
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
            $target_preview = self::read_mode_raw($target_mode);
            $parity = self::parity_report($source, $target_preview);
            return array(
                'ok' => true,
                'dry_run' => true,
                'target_mode' => $target_mode,
                'source_count' => count($source),
                'target_count' => count($target_preview),
                'migrated' => count($source),
                'parity' => $parity,
                'verification' => array(
                    'summary' => self::parity_summary($parity),
                    'mismatch_rows' => self::mismatch_rows($parity),
                ),
                'message' => 'Dry run complete. No data written.',
            );
        }

        self::write_mode_raw($target_mode, $source);
        $target_after = self::read_mode_raw($target_mode);
        $parity = self::parity_report($source, $target_after);
        update_option('dcb_forms_storage_last_migrated_at', current_time('mysql'), false);
        update_option('dcb_forms_storage_last_migrated_target', $target_mode, false);

        return array(
            'ok' => true,
            'dry_run' => false,
            'target_mode' => $target_mode,
            'source_count' => count($source),
            'target_count' => count($target_after),
            'migrated' => count($source),
            'parity' => $parity,
            'verification' => array(
                'summary' => self::parity_summary($parity),
                'mismatch_rows' => self::mismatch_rows($parity),
            ),
            'message' => 'Migration completed.',
        );
    }

    private static function read_mode_raw(string $mode): array {
        $mode = sanitize_key($mode);
        if ($mode === 'option') {
            return self::read_option_raw();
        }
        if ($mode === 'cpt') {
            return self::read_cpt_raw();
        }

        $raw = get_option('dcb_forms_custom_table_shadow', array());
        return is_array($raw) ? $raw : array();
    }

    private static function write_mode_raw(string $mode, array $forms): void {
        $mode = sanitize_key($mode);
        if ($mode === 'option') {
            self::write_option_raw($forms);
            return;
        }
        if ($mode === 'cpt') {
            self::write_cpt_raw($forms);
            return;
        }

        update_option('dcb_forms_custom_table_shadow', $forms, false);
    }

    private static function read_option_raw(): array {
        $raw = get_option(self::OPTION_KEY, array());
        return is_array($raw) ? $raw : array();
    }

    private static function write_option_raw(array $forms): void {
        update_option(self::OPTION_KEY, $forms, false);
    }

    private static function cpt_backend_ready(): bool {
        return post_type_exists(self::FORM_CPT);
    }

    private static function read_cpt_raw(): array {
        if (!self::cpt_backend_ready()) {
            return array();
        }

        $ids = get_posts(array(
            'post_type' => self::FORM_CPT,
            'post_status' => array('publish', 'private', 'draft'),
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        $out = array();
        foreach ((array) $ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id < 1) {
                continue;
            }

            $form_key = sanitize_key((string) get_post_meta($post_id, self::META_FORM_KEY, true));
            if ($form_key === '') {
                continue;
            }

            $payload_raw = (string) get_post_meta($post_id, self::META_FORM_PAYLOAD, true);
            $payload = json_decode($payload_raw, true);
            if (!is_array($payload)) {
                $payload = array();
            }
            $out[$form_key] = $payload;
        }

        return $out;
    }

    private static function write_cpt_raw(array $forms): void {
        if (!self::cpt_backend_ready()) {
            self::register_post_type();
        }

        $current_map = self::cpt_index_by_key();
        $seen = array();

        foreach ($forms as $form_key => $form) {
            if (!is_array($form)) {
                continue;
            }
            $key = sanitize_key((string) $form_key);
            if ($key === '') {
                continue;
            }

            $seen[$key] = true;
            $existing_id = isset($current_map[$key]) ? (int) $current_map[$key] : 0;
            self::upsert_cpt_form($key, $form, $existing_id);
        }

        foreach ($current_map as $key => $post_id) {
            if (isset($seen[$key])) {
                continue;
            }
            wp_delete_post((int) $post_id, true);
        }
    }

    private static function cpt_index_by_key(): array {
        if (!self::cpt_backend_ready()) {
            return array();
        }

        $rows = array();
        $ids = get_posts(array(
            'post_type' => self::FORM_CPT,
            'post_status' => array('publish', 'private', 'draft'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ));

        foreach ((array) $ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id < 1) {
                continue;
            }
            $form_key = sanitize_key((string) get_post_meta($post_id, self::META_FORM_KEY, true));
            if ($form_key === '') {
                continue;
            }
            $rows[$form_key] = $post_id;
        }

        return $rows;
    }

    private static function upsert_cpt_form(string $form_key, array $form, int $existing_id = 0): void {
        $label = sanitize_text_field((string) ($form['label'] ?? $form_key));
        if ($label === '') {
            $label = $form_key;
        }

        $payload_json = wp_json_encode($form, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload_json) || $payload_json === '') {
            $payload_json = '{}';
        }

        $post_arr = array(
            'post_type' => self::FORM_CPT,
            'post_status' => 'publish',
            'post_title' => $label,
            'post_name' => sanitize_title($form_key),
            'post_content' => $payload_json,
        );
        if ($existing_id > 0) {
            $post_arr['ID'] = $existing_id;
        }

        $post_id = wp_insert_post($post_arr);
        if (is_wp_error($post_id) || (int) $post_id < 1) {
            return;
        }

        $post_id = (int) $post_id;
        update_post_meta($post_id, self::META_FORM_KEY, $form_key);
        update_post_meta($post_id, self::META_FORM_PAYLOAD, $payload_json);
        update_post_meta($post_id, self::META_FORM_VERSION, max(1, (int) ($form['version'] ?? 1)));
        update_post_meta($post_id, self::META_FORM_UPDATED_AT, current_time('mysql'));
    }

    private static function parity_report(array $source, array $target): array {
        $source_keys = array_values(array_unique(array_map('sanitize_key', array_keys($source))));
        $target_keys = array_values(array_unique(array_map('sanitize_key', array_keys($target))));
        $shared_keys = array_values(array_intersect($source_keys, $target_keys));

        $missing_in_target = array_values(array_diff($source_keys, $target_keys));
        $extra_in_target = array_values(array_diff($target_keys, $source_keys));

        $checksum_matches = 0;
        $checksum_mismatches = array();

        foreach ($shared_keys as $form_key) {
            $source_form = isset($source[$form_key]) && is_array($source[$form_key]) ? $source[$form_key] : array();
            $target_form = isset($target[$form_key]) && is_array($target[$form_key]) ? $target[$form_key] : array();

            $source_profile = self::form_checksum_profile($source_form);
            $target_profile = self::form_checksum_profile($target_form);

            if ((string) ($source_profile['root_checksum'] ?? '') === (string) ($target_profile['root_checksum'] ?? '')) {
                $checksum_matches++;
                continue;
            }

            $checksum_mismatches[] = array(
                'form_key' => $form_key,
                'source_root_checksum' => (string) ($source_profile['root_checksum'] ?? ''),
                'target_root_checksum' => (string) ($target_profile['root_checksum'] ?? ''),
                'source_field_count' => (int) ($source_profile['field_count'] ?? 0),
                'target_field_count' => (int) ($target_profile['field_count'] ?? 0),
                'source_updated_at' => sanitize_text_field((string) ($source_form['updated_at'] ?? '')),
                'target_updated_at' => sanitize_text_field((string) ($target_form['updated_at'] ?? '')),
                'source_version' => (int) ($source_form['version'] ?? 0),
                'target_version' => (int) ($target_form['version'] ?? 0),
                'paths_missing_in_target' => array_values(array_slice(array_diff(array_keys((array) ($source_profile['field_checksums'] ?? array())), array_keys((array) ($target_profile['field_checksums'] ?? array()))), 0, 20)),
                'paths_extra_in_target' => array_values(array_slice(array_diff(array_keys((array) ($target_profile['field_checksums'] ?? array())), array_keys((array) ($source_profile['field_checksums'] ?? array()))), 0, 20)),
                'paths_changed' => self::field_checksum_diffs((array) ($source_profile['field_checksums'] ?? array()), (array) ($target_profile['field_checksums'] ?? array())),
            );
        }

        return array(
            'source_count' => count($source_keys),
            'target_count' => count($target_keys),
            'shared_count' => count($shared_keys),
            'missing_in_target' => $missing_in_target,
            'extra_in_target' => $extra_in_target,
            'checksum_matches' => $checksum_matches,
            'checksum_mismatch_count' => count($checksum_mismatches),
            'checksum_mismatches' => $checksum_mismatches,
            'exact_match' => empty($missing_in_target) && empty($extra_in_target) && empty($checksum_mismatches),
        );
    }

    private static function parity_summary(array $parity): array {
        $source_count = (int) ($parity['source_count'] ?? 0);
        $shared_count = (int) ($parity['shared_count'] ?? 0);
        $missing_count = is_array($parity['missing_in_target'] ?? null) ? count($parity['missing_in_target']) : 0;
        $extra_count = is_array($parity['extra_in_target'] ?? null) ? count($parity['extra_in_target']) : 0;
        $checksum_mismatch_count = (int) ($parity['checksum_mismatch_count'] ?? 0);
        $verified_ratio = $source_count > 0 ? min(100, (int) round((($shared_count - $checksum_mismatch_count) / $source_count) * 100)) : 100;

        return array(
            'exact_match' => !empty($parity['exact_match']),
            'missing_count' => $missing_count,
            'extra_count' => $extra_count,
            'checksum_mismatch_count' => $checksum_mismatch_count,
            'verified_ratio' => $verified_ratio,
        );
    }

    private static function mismatch_rows(array $parity): array {
        $rows = array();

        foreach ((array) ($parity['missing_in_target'] ?? array()) as $form_key) {
            $rows[] = array(
                'form_key' => sanitize_key((string) $form_key),
                'issue' => 'missing_in_target',
                'detail' => 'Form exists in source but not target.',
            );
        }

        foreach ((array) ($parity['extra_in_target'] ?? array()) as $form_key) {
            $rows[] = array(
                'form_key' => sanitize_key((string) $form_key),
                'issue' => 'extra_in_target',
                'detail' => 'Form exists in target but not source.',
            );
        }

        foreach ((array) ($parity['checksum_mismatches'] ?? array()) as $mismatch) {
            if (!is_array($mismatch)) {
                continue;
            }
            $form_key = sanitize_key((string) ($mismatch['form_key'] ?? ''));
            $changed_paths = is_array($mismatch['paths_changed'] ?? null) ? implode(', ', array_slice((array) $mismatch['paths_changed'], 0, 10)) : '';
            $rows[] = array(
                'form_key' => $form_key,
                'issue' => 'checksum_mismatch',
                'detail' => $changed_paths !== '' ? ('Changed paths: ' . $changed_paths) : 'Checksums differ between source and target.',
            );
        }

        return $rows;
    }

    private static function form_checksum_profile(array $form): array {
        $normalized = self::normalize_for_checksum($form);
        $field_checksums = array();
        self::flatten_checksum_paths($normalized, '', $field_checksums);

        return array(
            'root_checksum' => sha1((string) wp_json_encode($normalized, JSON_UNESCAPED_SLASHES)),
            'field_count' => count($field_checksums),
            'field_checksums' => $field_checksums,
        );
    }

    private static function normalize_for_checksum($value) {
        if (is_array($value)) {
            $is_assoc = array_keys($value) !== range(0, count($value) - 1);
            if ($is_assoc) {
                $normalized = array();
                $keys = array_keys($value);
                sort($keys, SORT_STRING);
                foreach ($keys as $key) {
                    $normalized[(string) $key] = self::normalize_for_checksum($value[$key]);
                }
                return $normalized;
            }

            return array_map(array(__CLASS__, 'normalize_for_checksum'), $value);
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return is_scalar($value) || $value === null ? $value : (string) wp_json_encode($value);
    }

    private static function flatten_checksum_paths($value, string $prefix, array &$out): void {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $path = $prefix === '' ? (string) $key : ($prefix . '.' . $key);
                self::flatten_checksum_paths($item, $path, $out);
            }
            return;
        }

        $path = $prefix === '' ? 'root' : $prefix;
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES);
        $out[$path] = sha1(is_string($encoded) ? $encoded : (string) $value);
    }

    private static function field_checksum_diffs(array $source_checksums, array $target_checksums): array {
        $paths = array_values(array_unique(array_merge(array_keys($source_checksums), array_keys($target_checksums))));
        $changed = array();
        foreach ($paths as $path) {
            $source_hash = (string) ($source_checksums[$path] ?? '');
            $target_hash = (string) ($target_checksums[$path] ?? '');
            if ($source_hash === '' || $target_hash === '') {
                continue;
            }
            if ($source_hash !== $target_hash) {
                $changed[] = (string) $path;
            }
            if (count($changed) >= 20) {
                break;
            }
        }
        return $changed;
    }
}
