<?php

if (!defined('ABSPATH')) {
    exit;
}

interface DCB_Chart_Routing_Connector_Interface {
    public function search_patient_candidates(array $identifiers, array $context = array()): array;

    public function resolve_chart_target(array $candidate, array $context = array()): array;

    public function attach_document_to_chart(array $chart_target, array $artifact, array $context = array()): array;

    public function get_schedule_context(array $identifiers, array $context = array()): array;

    public function validate_connector_config(array $config = array()): array;
}

final class DCB_Chart_Routing_Connector_Factory {
    public static function create(string $mode, array $config = array()): DCB_Chart_Routing_Connector_Interface {
        $mode = sanitize_key($mode);

        if ($mode === 'report_import') {
            return new DCB_Chart_Routing_Report_Import_Connector($config);
        }

        if ($mode === 'api' || $mode === 'bot') {
            return new DCB_Chart_Routing_Placeholder_Connector($mode, $config);
        }

        return new DCB_Chart_Routing_Manual_Connector($config);
    }
}

final class DCB_Chart_Routing_Manual_Connector implements DCB_Chart_Routing_Connector_Interface {
    private array $config;

    public function __construct(array $config = array()) {
        $this->config = $config;
    }

    public function search_patient_candidates(array $identifiers, array $context = array()): array {
        $rows = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_manual_candidates', array(), $identifiers, $context, $this->config)
            : array();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : array();
    }

    public function resolve_chart_target(array $candidate, array $context = array()): array {
        $chart_target = array(
            'chart_target_id' => sanitize_text_field((string) ($candidate['chart_target_id'] ?? ($candidate['mrn'] ?? ''))),
            'patient_id' => sanitize_text_field((string) ($candidate['patient_id'] ?? ($candidate['mrn'] ?? ''))),
            'full_name' => sanitize_text_field((string) ($candidate['full_name'] ?? ($candidate['patient_name'] ?? ''))),
        );

        if ($chart_target['chart_target_id'] === '' && $chart_target['patient_id'] === '') {
            return array(
                'ok' => false,
                'message' => 'Manual mode requires explicit chart target confirmation.',
                'chart_target' => array(),
            );
        }

        return array(
            'ok' => true,
            'message' => 'Manual chart target resolved.',
            'chart_target' => $chart_target,
        );
    }

    public function attach_document_to_chart(array $chart_target, array $artifact, array $context = array()): array {
        return array(
            'ok' => false,
            'message' => 'Manual connector is no-op. Export/attach externally after confirmation.',
            'external_reference' => '',
            'chart_target' => $chart_target,
            'artifact' => array(
                'upload_log_id' => max(0, (int) ($artifact['upload_log_id'] ?? 0)),
                'trace_id' => sanitize_text_field((string) ($artifact['trace_id'] ?? '')),
            ),
        );
    }

    public function get_schedule_context(array $identifiers, array $context = array()): array {
        $rows = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_schedule_context', array(), $identifiers, $context, $this->config)
            : array();

        return is_array($rows) ? $rows : array();
    }

    public function validate_connector_config(array $config = array()): array {
        return array(
            'ok' => true,
            'errors' => array(),
            'warnings' => array('Manual mode is active. Documents will not be auto-attached.'),
        );
    }
}

final class DCB_Chart_Routing_Report_Import_Connector implements DCB_Chart_Routing_Connector_Interface {
    private array $config;

    public function __construct(array $config = array()) {
        $this->config = $config;
    }

    public function search_patient_candidates(array $identifiers, array $context = array()): array {
        $rows = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_report_import_candidates', array(), $identifiers, $context, $this->config)
            : array();
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : array();
    }

    public function resolve_chart_target(array $candidate, array $context = array()): array {
        $chart_target = array(
            'chart_target_id' => sanitize_text_field((string) ($candidate['chart_target_id'] ?? ($candidate['mrn'] ?? ''))),
            'patient_id' => sanitize_text_field((string) ($candidate['patient_id'] ?? ($candidate['mrn'] ?? ''))),
            'full_name' => sanitize_text_field((string) ($candidate['full_name'] ?? ($candidate['patient_name'] ?? ''))),
            'route_channel' => 'report_import',
        );

        if ($chart_target['chart_target_id'] === '' && $chart_target['patient_id'] === '') {
            return array('ok' => false, 'message' => 'Report import connector could not resolve chart target.', 'chart_target' => array());
        }

        return array('ok' => true, 'message' => 'Chart target resolved for report import.', 'chart_target' => $chart_target);
    }

    public function attach_document_to_chart(array $chart_target, array $artifact, array $context = array()): array {
        $result = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_report_import_attach', array(
                'ok' => false,
                'message' => 'Report import connector placeholder: no direct attach performed.',
                'external_reference' => '',
            ), $chart_target, $artifact, $context, $this->config)
            : array(
                'ok' => false,
                'message' => 'Report import connector placeholder: no direct attach performed.',
                'external_reference' => '',
            );

        return is_array($result) ? $result : array('ok' => false, 'message' => 'Invalid report import adapter response.');
    }

    public function get_schedule_context(array $identifiers, array $context = array()): array {
        $rows = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_report_import_schedule_context', array(), $identifiers, $context, $this->config)
            : array();

        return is_array($rows) ? $rows : array();
    }

    public function validate_connector_config(array $config = array()): array {
        return array(
            'ok' => true,
            'errors' => array(),
            'warnings' => array('Report import mode enabled. External adapter is required for final attachment.'),
        );
    }
}

final class DCB_Chart_Routing_Placeholder_Connector implements DCB_Chart_Routing_Connector_Interface {
    private string $mode;
    private array $config;

    public function __construct(string $mode, array $config = array()) {
        $this->mode = sanitize_key($mode);
        $this->config = $config;
    }

    public function search_patient_candidates(array $identifiers, array $context = array()): array {
        $rows = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_connector_candidates', array(), $this->mode, $identifiers, $context, $this->config)
            : array();
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : array();
    }

    public function resolve_chart_target(array $candidate, array $context = array()): array {
        $resolved = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_connector_resolve_target', array(), $this->mode, $candidate, $context, $this->config)
            : array();

        if (!is_array($resolved) || empty($resolved['ok'])) {
            return array(
                'ok' => false,
                'message' => 'Connector resolve target not implemented for mode: ' . $this->mode,
                'chart_target' => array(),
            );
        }

        return $resolved;
    }

    public function attach_document_to_chart(array $chart_target, array $artifact, array $context = array()): array {
        $result = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_connector_attach', array(), $this->mode, $chart_target, $artifact, $context, $this->config)
            : array();

        if (!is_array($result) || !array_key_exists('ok', $result)) {
            return array(
                'ok' => false,
                'message' => 'Connector attach not implemented for mode: ' . $this->mode,
                'external_reference' => '',
            );
        }

        return $result;
    }

    public function get_schedule_context(array $identifiers, array $context = array()): array {
        $rows = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_connector_schedule_context', array(), $this->mode, $identifiers, $context, $this->config)
            : array();

        return is_array($rows) ? $rows : array();
    }

    public function validate_connector_config(array $config = array()): array {
        $result = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_connector_validate_config', array(), $this->mode, $config)
            : array();

        if (!is_array($result) || !array_key_exists('ok', $result)) {
            return array(
                'ok' => false,
                'errors' => array('Connector validation adapter missing for mode: ' . $this->mode),
                'warnings' => array(),
            );
        }

        return $result;
    }
}
