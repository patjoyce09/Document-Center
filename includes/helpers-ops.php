<?php

if (!defined('ABSPATH')) {
    exit;
}

function dcb_ops_status_label(string $status): string {
    $status = sanitize_key($status);
    if ($status === 'ok') {
        return 'Ready';
    }
    if ($status === 'warn') {
        return 'Needs Attention';
    }
    return 'Blocked';
}

function dcb_ops_permissions_matrix(?array $role_caps_map = null): array {
    $caps = array(
        'manage_forms' => class_exists('DCB_Permissions') ? DCB_Permissions::CAP_MANAGE_FORMS : 'dcb_manage_forms',
        'review_submissions' => class_exists('DCB_Permissions') ? DCB_Permissions::CAP_REVIEW_SUBMISSIONS : 'dcb_review_submissions',
        'run_ocr_tools' => class_exists('DCB_Permissions') ? DCB_Permissions::CAP_RUN_OCR_TOOLS : 'dcb_run_ocr_tools',
        'manage_workflows' => class_exists('DCB_Permissions') ? DCB_Permissions::CAP_MANAGE_WORKFLOWS : 'dcb_manage_workflows',
        'manage_settings' => class_exists('DCB_Permissions') ? DCB_Permissions::CAP_MANAGE_SETTINGS : 'dcb_manage_settings',
    );

    if ($role_caps_map === null && class_exists('DCB_Permissions')) {
        $role_caps_map = DCB_Permissions::role_caps_map();
    }
    if (!is_array($role_caps_map)) {
        $role_caps_map = array();
    }

    $rows = array();
    foreach ($caps as $key => $cap_name) {
        $granted_roles = array();
        foreach ($role_caps_map as $role_name => $role_caps) {
            $role_name = sanitize_key((string) $role_name);
            if ($role_name === '' || !is_array($role_caps)) {
                continue;
            }
            $normalized_caps = array_values(array_filter(array_map('sanitize_key', $role_caps)));
            if (in_array(sanitize_key($cap_name), $normalized_caps, true)) {
                $granted_roles[] = $role_name;
            }
        }

        $rows[$key] = array(
            'key' => $key,
            'capability' => sanitize_key($cap_name),
            'roles' => $granted_roles,
            'roles_label' => empty($granted_roles) ? 'No roles granted' : implode(', ', $granted_roles),
        );
    }

    return array_values($rows);
}

function dcb_ops_setup_readiness_payload(array $context = array()): array {
    $ocr_mode = isset($context['ocr_mode'])
        ? sanitize_key((string) $context['ocr_mode'])
        : sanitize_key((string) get_option('dcb_ocr_mode', 'auto'));
    if (!in_array($ocr_mode, array('local', 'remote', 'auto'), true)) {
        $ocr_mode = 'auto';
    }

    $ocr_api_base_url = isset($context['ocr_api_base_url'])
        ? trim((string) $context['ocr_api_base_url'])
        : trim((string) get_option('dcb_ocr_api_base_url', ''));
    $ocr_api_key = isset($context['ocr_api_key'])
        ? trim((string) $context['ocr_api_key'])
        : trim((string) get_option('dcb_ocr_api_key', ''));

    $upload_writable = isset($context['upload_writable'])
        ? !empty($context['upload_writable'])
        : null;
    $upload_dir = isset($context['upload_dir']) ? (array) $context['upload_dir'] : array();
    if ($upload_writable === null) {
        $wp_upload_dir = function_exists('wp_upload_dir') ? (array) wp_upload_dir() : array();
        $upload_dir = !empty($upload_dir) ? $upload_dir : $wp_upload_dir;
        $basedir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : '';
        $upload_writable = $basedir !== '' ? @is_writable($basedir) : false;
    }

    $permalink_structure = isset($context['permalink_structure'])
        ? (string) $context['permalink_structure']
        : (string) get_option('permalink_structure', '');

    $caps_check = isset($context['capabilities_ok']) ? !empty($context['capabilities_ok']) : null;
    if ($caps_check === null) {
        $required_caps = class_exists('DCB_Permissions') ? DCB_Permissions::all_caps() : array();
        $caps_check = !empty($required_caps);
        foreach ($required_caps as $cap) {
            $cap = sanitize_key((string) $cap);
            if ($cap === '') {
                continue;
            }
            if (!function_exists('current_user_can') || (!current_user_can($cap) && !current_user_can('manage_options'))) {
                $caps_check = false;
                break;
            }
        }
    }

    $ocr_status = 'ok';
    $ocr_note = 'OCR mode appears configured.';
    if ($ocr_mode === 'remote') {
        if ($ocr_api_base_url === '' || stripos($ocr_api_base_url, 'https://') !== 0 || $ocr_api_key === '') {
            $ocr_status = 'fail';
            $ocr_note = 'Remote OCR requires HTTPS API Base URL and API key.';
        }
    }
    if ($ocr_mode === 'auto' && ($ocr_api_base_url === '' || stripos($ocr_api_base_url, 'https://') !== 0 || $ocr_api_key === '')) {
        $ocr_status = 'warn';
        $ocr_note = 'Auto mode can run locally, but remote fallback is not fully configured.';
    }

    $items = array(
        array(
            'key' => 'capabilities',
            'label' => 'Capability model',
            'status' => $caps_check ? 'ok' : 'warn',
            'note' => $caps_check ? 'Current admin session has Document Center capabilities.' : 'Current admin session is missing one or more DCB capabilities.',
            'fix_url' => function_exists('admin_url') ? admin_url('admin.php?page=dcb-ops') : 'admin.php?page=dcb-ops',
            'fix_label' => 'Review permissions matrix',
        ),
        array(
            'key' => 'ocr',
            'label' => 'OCR mode + provider config',
            'status' => $ocr_status,
            'note' => $ocr_note,
            'fix_url' => function_exists('admin_url') ? admin_url('admin.php?page=dcb-settings') : 'admin.php?page=dcb-settings',
            'fix_label' => 'Open settings',
        ),
        array(
            'key' => 'uploads',
            'label' => 'Upload storage writable',
            'status' => $upload_writable ? 'ok' : 'fail',
            'note' => $upload_writable ? 'Upload directory is writable.' : 'Upload directory is not writable. OCR and intake uploads will fail.',
            'fix_url' => function_exists('admin_url') ? admin_url('admin.php?page=dcb-ocr-diagnostics') : 'admin.php?page=dcb-ocr-diagnostics',
            'fix_label' => 'Open diagnostics',
        ),
        array(
            'key' => 'permalinks',
            'label' => 'Permalink setup',
            'status' => $permalink_structure === '' ? 'warn' : 'ok',
            'note' => $permalink_structure === '' ? 'Plain permalinks are active. Portals work, but pretty links are recommended.' : 'Permalink structure configured.',
            'fix_url' => function_exists('admin_url') ? admin_url('options-permalink.php') : 'options-permalink.php',
            'fix_label' => 'Permalink settings',
        ),
    );

    $summary = array('ok' => 0, 'warn' => 0, 'fail' => 0);
    foreach ($items as $item) {
        $status = sanitize_key((string) ($item['status'] ?? 'warn'));
        if (!isset($summary[$status])) {
            $status = 'warn';
        }
        $summary[$status]++;
    }

    return array(
        'items' => $items,
        'summary' => $summary,
        'ocr_mode' => $ocr_mode,
        'upload_basedir' => sanitize_text_field((string) ($upload_dir['basedir'] ?? '')),
        'permalink_structure' => sanitize_text_field($permalink_structure),
    );
}

function dcb_ops_sample_template_pack(): array {
    $pack = array(
        'generic_intake_form' => array(
            'label' => 'Generic Intake Form',
            'recipients' => '',
            'fields' => array(
                array('key' => 'applicant_first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true),
                array('key' => 'applicant_last_name', 'label' => 'Last Name', 'type' => 'text', 'required' => true),
                array('key' => 'contact_phone', 'label' => 'Phone Number', 'type' => 'text', 'required' => false),
                array('key' => 'contact_email', 'label' => 'Email Address', 'type' => 'email', 'required' => false),
                array('key' => 'intake_notes', 'label' => 'Intake Notes', 'type' => 'text', 'required' => false),
            ),
            'sections' => array(
                array('key' => 'basic_identity', 'label' => 'Identity', 'field_keys' => array('applicant_first_name', 'applicant_last_name')),
                array('key' => 'contact', 'label' => 'Contact', 'field_keys' => array('contact_phone', 'contact_email')),
                array('key' => 'summary', 'label' => 'Summary', 'field_keys' => array('intake_notes')),
            ),
            'steps' => array(
                array('key' => 'intake_step', 'label' => 'Intake', 'section_keys' => array('basic_identity', 'contact', 'summary')),
            ),
        ),
        'consent_attestation_form' => array(
            'label' => 'Consent and Attestation',
            'recipients' => '',
            'fields' => array(
                array('key' => 'consent_to_process', 'label' => 'I consent to document processing', 'type' => 'checkbox', 'required' => true),
                array('key' => 'attest_truth', 'label' => 'I attest all provided information is accurate', 'type' => 'checkbox', 'required' => true),
                array('key' => 'attestation_date', 'label' => 'Attestation Date', 'type' => 'date', 'required' => true),
                array('key' => 'signature_name', 'label' => 'Signature Name', 'type' => 'text', 'required' => true),
            ),
        ),
        'simple_document_packet' => array(
            'label' => 'Simple Document Packet',
            'recipients' => '',
            'required_bundles' => array('identity_packet'),
            'fields' => array(
                array('key' => 'packet_reference_id', 'label' => 'Packet Reference', 'type' => 'text', 'required' => true),
                array('key' => 'packet_status', 'label' => 'Packet Status', 'type' => 'select', 'required' => true, 'options' => array('draft' => 'Draft', 'submitted' => 'Submitted', 'complete' => 'Complete')),
                array('key' => 'packet_comments', 'label' => 'Comments', 'type' => 'text', 'required' => false),
            ),
            'routing_rules' => array(
                array(
                    'name' => 'Route complete packets',
                    'status' => 'approved',
                    'assignee_role' => 'editor',
                    'priority' => 20,
                ),
            ),
        ),
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_sample_template_pack', $pack) : $pack;
}

function dcb_ops_export_forms_payload(array $forms, array $meta = array()): array {
    $normalized_forms = array();
    foreach ($forms as $form_key => $form) {
        if (!is_array($form)) {
            continue;
        }
        $key = sanitize_key((string) $form_key);
        if ($key === '') {
            continue;
        }
        $normalized = function_exists('dcb_normalize_single_form') ? dcb_normalize_single_form($form) : $form;
        if ($normalized !== null && is_array($normalized)) {
            $normalized_forms[$key] = $normalized;
        }
    }

    $payload = array(
        'contract' => 'dcb_forms_export',
        'version' => '1.0',
        'generated_at' => gmdate('c'),
        'forms' => $normalized_forms,
        'meta' => array(
            'source' => sanitize_key((string) ($meta['source'] ?? 'manual')),
            'plugin_version' => sanitize_text_field((string) ($meta['plugin_version'] ?? (defined('DCB_VERSION') ? DCB_VERSION : 'unknown'))),
        ),
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_forms_export_payload', $payload, $forms) : $payload;
}

function dcb_ops_parse_import_payload(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') {
        return array('ok' => false, 'errors' => array('Import payload is empty.'), 'payload' => array());
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        return array('ok' => false, 'errors' => array('Import payload is not valid JSON.'), 'payload' => array());
    }

    return array('ok' => true, 'errors' => array(), 'payload' => $decoded);
}

function dcb_ops_validate_and_prepare_import(array $payload, array $existing_forms = array()): array {
    $errors = array();
    $warnings = array();
    $forms = array();

    if (isset($payload['contract']) && (string) $payload['contract'] !== 'dcb_forms_export') {
        $warnings[] = 'Unknown import contract. Attempting compatibility import.';
    }

    $source_forms = array();
    if (isset($payload['forms']) && is_array($payload['forms'])) {
        $source_forms = $payload['forms'];
    } elseif (isset($payload['templates']) && is_array($payload['templates'])) {
        $source_forms = $payload['templates'];
    } elseif (!empty($payload) && isset($payload['label']) && isset($payload['fields'])) {
        $source_forms = array('imported_form' => $payload);
    } else {
        $source_forms = $payload;
    }

    if (!is_array($source_forms) || empty($source_forms)) {
        $errors[] = 'No forms found in import payload.';
        return array('ok' => false, 'errors' => $errors, 'warnings' => $warnings, 'forms' => array(), 'stats' => array('imported' => 0, 'skipped' => 0));
    }

    $imported = 0;
    $skipped = 0;
    foreach ($source_forms as $form_key => $form) {
        if (!is_array($form)) {
            $skipped++;
            continue;
        }
        $key = sanitize_key((string) $form_key);
        if ($key === '') {
            $key = sanitize_key((string) ($form['key'] ?? $form['form_key'] ?? ''));
        }
        if ($key === '') {
            $skipped++;
            continue;
        }

        $normalized = function_exists('dcb_normalize_single_form') ? dcb_normalize_single_form($form) : $form;
        if (!is_array($normalized)) {
            $skipped++;
            continue;
        }

        if (function_exists('dcb_apply_versioning')) {
            $existing = isset($existing_forms[$key]) && is_array($existing_forms[$key]) ? $existing_forms[$key] : null;
            $normalized = dcb_apply_versioning($normalized, $existing);
        }

        $forms[$key] = $normalized;
        $imported++;
    }

    if ($imported < 1) {
        $errors[] = 'No valid forms were imported after validation.';
    }

    return array(
        'ok' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
        'forms' => $forms,
        'stats' => array(
            'imported' => $imported,
            'skipped' => $skipped,
        ),
    );
}

function dcb_ops_uninstall_should_purge(array $context = array()): bool {
    if (isset($context['purge'])) {
        return !empty($context['purge']);
    }

    if (!function_exists('get_option')) {
        return false;
    }

    return (string) get_option('dcb_uninstall_remove_data', '0') === '1';
}
