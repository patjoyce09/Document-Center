<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_License_Boundary {
    public static function init(): void {
        // Placeholder boundary only. No remote calls or enforcement in this phase.
        add_filter('dcb_license_update_boundary', array(__CLASS__, 'filter_boundary_payload'), 10, 1);
    }

    public static function status(): array {
        $state = get_option('dcb_license_state', array());
        if (!is_array($state)) {
            $state = array();
        }

        $payload = array(
            'enabled' => false,
            'license_status' => sanitize_key((string) ($state['license_status'] ?? 'not_configured')),
            'update_channel' => sanitize_key((string) ($state['update_channel'] ?? 'none')),
            'last_check_at' => sanitize_text_field((string) ($state['last_check_at'] ?? '')),
        );

        return (array) apply_filters('dcb_license_update_boundary', $payload);
    }

    public static function filter_boundary_payload($payload): array {
        return is_array($payload) ? $payload : array();
    }
}
