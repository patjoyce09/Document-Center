<?php

if (!defined('ABSPATH')) {
    exit;
}

$connector_file = __DIR__ . '/class-real-connector-skeleton.php';
if (is_readable($connector_file)) {
    require_once $connector_file;
}

if (!class_exists('DCB_Real_Connector_Skeleton')) {
    return;
}

if (!function_exists('add_filter')) {
    return;
}

add_filter('dcb_chart_routing_connector_adapter', static function ($connector, string $mode, array $config) {
    $mode = sanitize_key($mode);
    $provider_key = sanitize_key((string) ($config['provider_key'] ?? ''));
    if ($mode !== 'api') {
        return $connector;
    }
    if ($provider_key !== 'real_connector_skeleton') {
        return $connector;
    }

    return new DCB_Real_Connector_Skeleton($config);
}, 10, 3);
