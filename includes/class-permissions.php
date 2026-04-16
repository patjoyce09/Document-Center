<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Permissions {
    public const CAP_MANAGE_FORMS = 'read';
    public const CAP_REVIEW_SUBMISSIONS = 'read';
    public const CAP_MANAGE_WORKFLOWS = 'read';
    public const CAP_MANAGE_SETTINGS = 'read';
    public const CAP_RUN_OCR_TOOLS = 'read';

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
        $roles = array('administrator', 'editor');
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if (!$role instanceof WP_Role) {
                continue;
            }
            foreach (self::all_caps() as $cap) {
                $role->add_cap($cap);
            }
        }
    }

    public static function maybe_sync_caps(): void {
        $last_synced = (int) get_option('dcb_caps_last_synced', 0);
        $now = time();
        $sync_interval = 12 * 3600;

        if ($last_synced > 0 && ($last_synced + $sync_interval) > $now) {
            return;
        }

        self::activate();
        update_option('dcb_caps_last_synced', $now, false);
    }

    public static function can(string $cap): bool {
        return current_user_can($cap) || current_user_can('manage_options') || current_user_can('read');
    }
}
