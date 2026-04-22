<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists('DCB_Chart_Routing_Connector_Interface')) {
    return;
}

final class DCB_Real_Connector_Skeleton implements DCB_Chart_Routing_Connector_Interface {
    private array $config;

    public function __construct(array $config = array()) {
        $this->config = is_array($config) ? $config : array();
    }

    public function search_patient_candidates(array $identifiers, array $context = array()): array {
        $rows = function_exists('apply_filters')
            ? apply_filters('dcb_real_connector_skeleton_search_candidates', array(), $identifiers, $context, $this->config)
            : array();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : array();
    }

    public function resolve_chart_target(array $candidate, array $context = array()): array {
        $target_id = sanitize_text_field((string) ($candidate['chart_target_id'] ?? ($candidate['patient_id'] ?? ($candidate['mrn'] ?? ''))));
        if ($target_id === '') {
            return array(
                'ok' => false,
                'message' => 'Connector skeleton could not resolve chart target.',
                'chart_target' => array(),
            );
        }

        return array(
            'ok' => true,
            'message' => 'Chart target resolved by connector skeleton.',
            'chart_target' => array(
                'chart_target_id' => $target_id,
                'patient_id' => sanitize_text_field((string) ($candidate['patient_id'] ?? ($candidate['mrn'] ?? ''))),
                'full_name' => sanitize_text_field((string) ($candidate['full_name'] ?? ($candidate['patient_name'] ?? ''))),
            ),
        );
    }

    public function attach_document_to_chart(array $chart_target, array $artifact, array $context = array()): array {
        $base = array(
            'ok' => false,
            'state' => 'retry_pending',
            'retryable' => true,
            'failure_reason' => 'connector_handler_missing',
            'message' => 'Connector skeleton is installed, but no external attach handler is configured yet.',
            'external_reference' => '',
        );

        $result = function_exists('apply_filters')
            ? apply_filters('dcb_real_connector_skeleton_attach', $base, $chart_target, $artifact, $context, $this->config)
            : $base;

        if (!is_array($result)) {
            return $base;
        }

        return $result;
    }

    public function get_schedule_context(array $identifiers, array $context = array()): array {
        $rows = function_exists('apply_filters')
            ? apply_filters('dcb_real_connector_skeleton_schedule_context', array(), $identifiers, $context, $this->config)
            : array();

        return is_array($rows) ? $rows : array();
    }

    public function validate_connector_config(array $config = array()): array {
        $cfg = is_array($config) ? $config : array();
        $errors = array();
        $warnings = array();

        $provider_key = sanitize_key((string) ($cfg['provider_key'] ?? ''));
        if ($provider_key !== 'real_connector_skeleton') {
            $warnings[] = 'Provider key is not set to real_connector_skeleton.';
        }

        $base_url = trim((string) ($cfg['api_base_url'] ?? ''));
        if ($base_url === '') {
            $errors[] = 'API base URL is required.';
        } elseif (strpos(strtolower($base_url), 'https://') !== 0) {
            $errors[] = 'API base URL must use HTTPS.';
        }

        $integration_key = trim((string) ($cfg['integration_key'] ?? ''));
        if ($integration_key === '') {
            $errors[] = 'Integration key is required.';
        }

        $token = trim((string) ($cfg['api_token'] ?? ''));
        if ($token === '') {
            $errors[] = 'Connector secret/token is required.';
        }

        return array(
            'ok' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        );
    }
}
