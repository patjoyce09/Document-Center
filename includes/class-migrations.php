<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Migrations {
    private const OPTION_KEY = 'dcb_schema_version';
    private const TARGET_VERSION = 6;

    public static function activate(): void {
        self::run();
    }

    public static function run(): void {
        $current = (int) get_option(self::OPTION_KEY, 0);
        if ($current >= self::TARGET_VERSION) {
            return;
        }

        for ($version = $current + 1; $version <= self::TARGET_VERSION; $version++) {
            self::run_single($version);
            update_option(self::OPTION_KEY, $version, false);
        }
    }

    private static function run_single(int $version): void {
        if ($version === 1) {
            if (get_option('dcb_workflow_default_status', null) === null) {
                add_option('dcb_workflow_default_status', 'submitted', '', false);
            }
            if (get_option('dcb_workflow_enable_activity_timeline', null) === null) {
                add_option('dcb_workflow_enable_activity_timeline', '1', '', false);
            }
            return;
        }

        if ($version === 2) {
            if (get_option('dcb_ocr_mode', null) === null) {
                add_option('dcb_ocr_mode', 'auto', '', false);
            }
            if (get_option('dcb_ocr_api_base_url', null) === null) {
                add_option('dcb_ocr_api_base_url', '', '', false);
            }
            if (get_option('dcb_ocr_api_key', null) === null) {
                add_option('dcb_ocr_api_key', '', '', false);
            }
            if (get_option('dcb_ocr_timeout_seconds', null) === null) {
                add_option('dcb_ocr_timeout_seconds', 30, '', false);
            }
            if (get_option('dcb_ocr_max_file_size_mb', null) === null) {
                add_option('dcb_ocr_max_file_size_mb', 15, '', false);
            }
            if (get_option('dcb_ocr_confidence_threshold', null) === null) {
                add_option('dcb_ocr_confidence_threshold', 0.45, '', false);
            }
            return;
        }

        if ($version === 3) {
            if (get_option('dcb_tutor_integration_enabled', null) === null) {
                add_option('dcb_tutor_integration_enabled', '0', '', false);
            }
            if (get_option('dcb_tutor_mapping', null) === null) {
                add_option('dcb_tutor_mapping', array(), '', false);
            }
            return;
        }

        if ($version === 4) {
            if (get_option('dcb_forms_storage_mode', null) === null) {
                add_option('dcb_forms_storage_mode', 'option', '', false);
            }
            if (get_option('dcb_ocr_api_auth_header', null) === null) {
                add_option('dcb_ocr_api_auth_header', 'X-API-Key', '', false);
            }
            return;
        }

        if ($version === 5) {
            if (get_option('dcb_forms_storage_dual_read', null) === null) {
                add_option('dcb_forms_storage_dual_read', '1', '', false);
            }
            if (get_option('dcb_forms_storage_dual_write', null) === null) {
                add_option('dcb_forms_storage_dual_write', '0', '', false);
            }
            if (get_option('dcb_forms_storage_last_migrated_at', null) === null) {
                add_option('dcb_forms_storage_last_migrated_at', '', '', false);
            }
            if (get_option('dcb_forms_storage_last_migrated_target', null) === null) {
                add_option('dcb_forms_storage_last_migrated_target', '', '', false);
            }
            return;
        }

        if ($version === 6) {
            if (get_option('dcb_workflow_action_replay_tokens', null) === null) {
                add_option('dcb_workflow_action_replay_tokens', array(), '', false);
            }
            if (get_option('dcb_workflow_action_audit_log', null) === null) {
                add_option('dcb_workflow_action_audit_log', array(), '', false);
            }
            if (get_option('dcb_forms_storage_drift_last', null) === null) {
                add_option('dcb_forms_storage_drift_last', array(), '', false);
            }
            if (get_option('dcb_forms_storage_drift_log', null) === null) {
                add_option('dcb_forms_storage_drift_log', array(), '', false);
            }
            if (get_option('dcb_forms_parity_monitor_enabled', null) === null) {
                add_option('dcb_forms_parity_monitor_enabled', '1', '', false);
            }
        }
    }
}
