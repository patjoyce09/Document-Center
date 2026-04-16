<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Permissions {
    public const CAP_MANAGE_FORMS = 'dcb_manage_forms';
    public const CAP_REVIEW_SUBMISSIONS = 'dcb_review_submissions';
    public const CAP_MANAGE_WORKFLOWS = 'dcb_manage_workflows';
    public const CAP_MANAGE_SETTINGS = 'dcb_manage_settings';
    public const CAP_RUN_OCR_TOOLS = 'dcb_run_ocr_tools';

    public static function all_caps(): array {
        return array(
            self::CAP_MANAGE_FORMS,
            self::CAP_REVIEW_SUBMISSIONS,
            self::CAP_MANAGE_WORKFLOWS,
            self::CAP_MANAGE_SETTINGS,
            self::CAP_RUN_OCR_TOOLS,
        );
    }

    public static function init(): void {
        add_action('init', array(__CLASS__, 'maybe_sync_caps'), 5);
    }

    public static function activate(): void {
        self::sync_role_caps();
    }

    public static function sync_role_caps(): void {
        $role_caps = self::role_caps_map();
        foreach ($role_caps as $role_name => $caps) {
            $role = get_role($role_name);
            if (!$role instanceof WP_Role) {
                continue;
            }

            $caps = is_array($caps) ? array_values(array_unique(array_filter(array_map('sanitize_key', $caps)))) : array();
            foreach ($caps as $cap) {
                if ($cap === '') {
                    continue;
                }
                $role->add_cap($cap);
            }
        }
    }

    public static function role_caps_map(): array {
        $map = array(
            'administrator' => self::all_caps(),
            'editor' => array(
                self::CAP_REVIEW_SUBMISSIONS,
                self::CAP_MANAGE_WORKFLOWS,
            ),
        );

        $filtered = apply_filters('dcb_permissions_role_caps', $map);
        return is_array($filtered) ? $filtered : $map;
    }

    public static function maybe_sync_caps(): void {
        $last_synced = (int) get_option('dcb_caps_last_synced', 0);
        $now = time();
        $sync_interval = 12 * 3600;

        if ($last_synced > 0 && ($last_synced + $sync_interval) > $now) {
            return;
        }

        self::sync_role_caps();
        update_option('dcb_caps_last_synced', $now, false);
    }

    public static function can(string $cap): bool {
        $cap = sanitize_key($cap);
        if ($cap === '') {
            return false;
        }

        return current_user_can($cap) || current_user_can('manage_options');
    }
}
