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

    public static function can(string $cap): bool {
        return current_user_can($cap);
    }
}
