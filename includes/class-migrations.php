<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Migrations {
    private const OPTION_KEY = 'dcb_schema_version';
    private const TARGET_VERSION = 7;

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
            if (get_option('dcb_workflow_routing_rules', null) === null) {
                add_option('dcb_workflow_routing_rules', array(), '', false);
            }
            if (get_option('dcb_workflow_queue_groups', null) === null) {
                add_option('dcb_workflow_queue_groups', array(), '', false);
            }
            if (get_option('dcb_workflow_packet_definitions', null) === null) {
                add_option('dcb_workflow_packet_definitions', array(), '', false);
            }
            return;
        }

        if ($version === 5) {
            if (get_option('dcb_ocr_correction_rules', null) === null) {
                add_option('dcb_ocr_correction_rules', array(), '', false);
            }
            return;
        }

        if ($version === 6) {
            if (get_option('dcb_ocr_input_normalization_enabled', null) === null) {
                add_option('dcb_ocr_input_normalization_enabled', '1', '', false);
            }
            if (get_option('dcb_ocr_input_max_dimension', null) === null) {
                add_option('dcb_ocr_input_max_dimension', 2200, '', false);
            }
            return;
        }

        if ($version === 7) {
            if (get_option('dcb_chart_routing_mode', null) === null) {
                add_option('dcb_chart_routing_mode', 'none_manual', '', false);
            }
            if (get_option('dcb_chart_routing_connector_config', null) === null) {
                add_option('dcb_chart_routing_connector_config', array(), '', false);
            }
        }
    }
}
