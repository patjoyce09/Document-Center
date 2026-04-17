<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Ops {
    public static function init(): void {
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
        add_action('admin_post_dcb_ops_load_sample_pack', array(__CLASS__, 'handle_load_sample_pack'));
        add_action('admin_post_dcb_ops_import_forms', array(__CLASS__, 'handle_import_forms'));
        add_action('admin_post_dcb_ops_export_forms', array(__CLASS__, 'handle_export_forms'));
    }

    public static function register_admin_page(): void {
        add_submenu_page(
            'dcb-dashboard',
            __('Setup & Operations', 'document-center-builder'),
            __('Setup & Operations', 'document-center-builder'),
            DCB_Permissions::CAP_MANAGE_SETTINGS,
            'dcb-ops',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_SETTINGS)) {
            wp_die('Unauthorized');
        }

        $readiness = dcb_ops_setup_readiness_payload();
        $items = isset($readiness['items']) && is_array($readiness['items']) ? $readiness['items'] : array();
        $summary = isset($readiness['summary']) && is_array($readiness['summary']) ? $readiness['summary'] : array('ok' => 0, 'warn' => 0, 'fail' => 0);
        $permissions = dcb_ops_permissions_matrix();
        $can_manage_forms = DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_FORMS);
        $license_status = class_exists('DCB_License_Boundary') ? DCB_License_Boundary::status() : array();

        echo '<div class="wrap">';
        echo '<h1>Document Center Setup & Operations</h1>';
        echo '<p>Commercial-readiness checkpoints for setup, diagnostics, permissions, import/export, and sample forms.</p>';

        if (isset($_GET['ops_updated'])) {
            echo '<div class="notice notice-success"><p>' . esc_html(sanitize_text_field((string) $_GET['ops_updated'])) . '</p></div>';
        }
        if (isset($_GET['ops_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field((string) $_GET['ops_error'])) . '</p></div>';
        }

        echo '<h2>Setup Readiness</h2>';
        echo '<p><strong>Ready:</strong> ' . esc_html((string) (int) ($summary['ok'] ?? 0)) . ' &nbsp; <strong>Needs Attention:</strong> ' . esc_html((string) (int) ($summary['warn'] ?? 0)) . ' &nbsp; <strong>Blocked:</strong> ' . esc_html((string) (int) ($summary['fail'] ?? 0)) . '</p>';
        echo '<table class="widefat striped" style="max-width:1020px"><thead><tr><th style="width:220px">Check</th><th style="width:170px">Status</th><th>Details</th><th style="width:220px">Fix</th></tr></thead><tbody>';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $status = sanitize_key((string) ($item['status'] ?? 'warn'));
            echo '<tr>';
            echo '<td>' . esc_html((string) ($item['label'] ?? 'Readiness')) . '</td>';
            echo '<td><strong>' . esc_html(dcb_ops_status_label($status)) . '</strong></td>';
            echo '<td>' . esc_html((string) ($item['note'] ?? '')) . '</td>';
            $fix_url = esc_url((string) ($item['fix_url'] ?? ''));
            $fix_label = esc_html((string) ($item['fix_label'] ?? 'Review'));
            echo '<td>' . ($fix_url !== '' ? '<a class="button button-secondary" href="' . $fix_url . '">' . $fix_label . '</a>' : '&nbsp;') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:20px;">Actionable Diagnostics</h2>';
        echo '<ul style="list-style:disc;padding-left:20px;">';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=dcb-ocr-diagnostics')) . '">OCR diagnostics and review queue health</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=dcb-settings')) . '">Settings (OCR mode, API, workflow defaults, uninstall policy)</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=dcb-intake-trace')) . '">Intake trace timeline (chain-first debugging by trace_id)</a></li>';
        echo '</ul>';

        echo '<h2 style="margin-top:20px;">Permissions Matrix</h2>';
        echo '<table class="widefat striped" style="max-width:1020px"><thead><tr><th style="width:240px">Capability</th><th style="width:240px">Key</th><th>Roles</th></tr></thead><tbody>';
        foreach ($permissions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = str_replace('_', ' ', (string) ($row['key'] ?? 'capability'));
            echo '<tr>';
            echo '<td>' . esc_html(ucwords($label)) . '</td>';
            echo '<td><code>' . esc_html((string) ($row['capability'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['roles_label'] ?? 'No roles granted')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:20px;">Sample Template Pack</h2>';
        echo '<p>Loads 3 generic demo forms: intake form, consent/attestation form, and simple packet example.</p>';
        if (!$can_manage_forms) {
            echo '<p><em>You need forms-management capability to load sample forms.</em></p>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('dcb_ops_load_sample_pack', 'dcb_ops_nonce');
            echo '<input type="hidden" name="action" value="dcb_ops_load_sample_pack" />';
            submit_button(__('Load Generic Sample Pack', 'document-center-builder'), 'secondary', 'submit', false);
            echo '</form>';
        }

        echo '<h2 style="margin-top:20px;">Import / Export Forms</h2>';
        if (!$can_manage_forms) {
            echo '<p><em>You need forms-management capability to import/export forms.</em></p>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 14px;">';
            wp_nonce_field('dcb_ops_export_forms', 'dcb_ops_nonce');
            echo '<input type="hidden" name="action" value="dcb_ops_export_forms" />';
            submit_button(__('Export Forms JSON', 'document-center-builder'), 'secondary', 'submit', false);
            echo '</form>';

            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('dcb_ops_import_forms', 'dcb_ops_nonce');
            echo '<input type="hidden" name="action" value="dcb_ops_import_forms" />';
            echo '<p><label for="dcb-import-json"><strong>Import JSON</strong></label></p>';
            echo '<textarea id="dcb-import-json" name="dcb_import_json" class="large-text code" rows="10" placeholder="Paste export payload or forms JSON"></textarea>';
            echo '<p><label for="dcb-import-file"><strong>Or upload JSON file</strong></label></p>';
            echo '<input id="dcb-import-file" type="file" name="dcb_import_file" accept="application/json,.json,text/plain" />';
            echo '<p><label><input type="radio" name="dcb_import_mode" value="merge" checked /> Merge into existing forms</label><br />';
            echo '<label><input type="radio" name="dcb_import_mode" value="replace" /> Replace all forms</label></p>';
            submit_button(__('Validate + Import Forms', 'document-center-builder'), 'primary', 'submit', false);
            echo '</form>';
        }

        echo '<h2 style="margin-top:20px;">Uninstall Behavior</h2>';
        echo '<p>Current uninstall mode: <strong>' . esc_html((string) get_option('dcb_uninstall_remove_data', '0') === '1' ? 'Purge enabled' : 'Conservative (keep data)') . '</strong>. Change this in <a href="' . esc_url(admin_url('admin.php?page=dcb-settings')) . '">Settings</a>.</p>';

        echo '<h2 style="margin-top:20px;">License / Update Boundary</h2>';
        echo '<p>This release includes an isolated placeholder boundary only (no remote enforcement yet).</p>';
        echo '<table class="widefat striped" style="max-width:760px"><tbody>';
        echo '<tr><th style="width:220px">Boundary Enabled</th><td>' . esc_html(!empty($license_status['enabled']) ? 'yes' : 'no') . '</td></tr>';
        echo '<tr><th>License Status</th><td>' . esc_html((string) ($license_status['license_status'] ?? 'not_configured')) . '</td></tr>';
        echo '<tr><th>Update Channel</th><td>' . esc_html((string) ($license_status['update_channel'] ?? 'none')) . '</td></tr>';
        echo '<tr><th>Last Check</th><td>' . esc_html((string) ($license_status['last_check_at'] ?? '')) . '</td></tr>';
        echo '</tbody></table>';

        echo '</div>';
    }

    public static function handle_load_sample_pack(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_FORMS)) {
            wp_die('Unauthorized');
        }
        if (!isset($_POST['dcb_ops_nonce']) || !wp_verify_nonce((string) $_POST['dcb_ops_nonce'], 'dcb_ops_load_sample_pack')) {
            wp_die('Security check failed');
        }

        $existing_forms = dcb_get_custom_forms();
        $pack = dcb_ops_sample_template_pack();
        $validated = dcb_ops_validate_and_prepare_import(array('forms' => $pack), $existing_forms);
        if (empty($validated['ok'])) {
            if (function_exists('dcb_ops_record_action')) {
                dcb_ops_record_action('sample_pack_load', 'failed', 'Sample template pack failed validation.');
            }
            self::redirect_with_message('No sample forms were imported.', true);
        }

        $merged = $existing_forms;
        foreach ((array) ($validated['forms'] ?? array()) as $form_key => $form) {
            if (!is_array($form)) {
                continue;
            }
            $merged[sanitize_key((string) $form_key)] = $form;
        }

        update_option('dcb_forms_custom', $merged, false);
        $count = (int) (($validated['stats']['imported'] ?? 0));
        if (function_exists('dcb_ops_record_action')) {
            dcb_ops_record_action('sample_pack_load', 'ok', 'Loaded generic sample template pack.', array('imported' => $count));
        }
        self::redirect_with_message('Loaded sample template pack (' . $count . ' forms).', false);
    }

    public static function handle_import_forms(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_FORMS)) {
            wp_die('Unauthorized');
        }
        if (!isset($_POST['dcb_ops_nonce']) || !wp_verify_nonce((string) $_POST['dcb_ops_nonce'], 'dcb_ops_import_forms')) {
            wp_die('Security check failed');
        }

        $raw_json = isset($_POST['dcb_import_json']) ? trim((string) wp_unslash($_POST['dcb_import_json'])) : '';
        if ($raw_json === '' && isset($_FILES['dcb_import_file']) && is_array($_FILES['dcb_import_file'])) {
            $tmp = (string) ($_FILES['dcb_import_file']['tmp_name'] ?? '');
            $size = (int) ($_FILES['dcb_import_file']['size'] ?? 0);
            if ($tmp !== '' && $size > 0 && $size <= (2 * 1024 * 1024) && @is_uploaded_file($tmp)) {
                $raw_json = (string) file_get_contents($tmp);
            }
        }

        $parsed = dcb_ops_parse_import_payload($raw_json);
        if (empty($parsed['ok'])) {
            if (function_exists('dcb_ops_record_action')) {
                dcb_ops_record_action('forms_import', 'failed', (string) (($parsed['errors'][0] ?? 'Import payload invalid.')));
            }
            self::redirect_with_message((string) (($parsed['errors'][0] ?? 'Import payload invalid.')), true);
        }

        $existing_forms = dcb_get_custom_forms();
        $validated = dcb_ops_validate_and_prepare_import((array) ($parsed['payload'] ?? array()), $existing_forms);
        if (empty($validated['ok'])) {
            if (function_exists('dcb_ops_record_action')) {
                dcb_ops_record_action('forms_import', 'failed', (string) (($validated['errors'][0] ?? 'No valid forms were imported.')));
            }
            self::redirect_with_message((string) (($validated['errors'][0] ?? 'No valid forms were imported.')), true);
        }

        $mode = isset($_POST['dcb_import_mode']) ? sanitize_key((string) $_POST['dcb_import_mode']) : 'merge';
        $target_forms = $mode === 'replace' ? array() : $existing_forms;
        foreach ((array) ($validated['forms'] ?? array()) as $form_key => $form) {
            if (!is_array($form)) {
                continue;
            }
            $target_forms[sanitize_key((string) $form_key)] = $form;
        }

        update_option('dcb_forms_custom', $target_forms, false);

        $message = 'Import complete. Added/updated ' . (int) (($validated['stats']['imported'] ?? 0)) . ' forms';
        $warnings = (array) ($validated['warnings'] ?? array());
        if (!empty($warnings)) {
            $message .= ' (with warnings).';
        } else {
            $message .= '.';
        }
        if (function_exists('dcb_ops_record_action')) {
            dcb_ops_record_action('forms_import', 'ok', 'Forms import completed.', array(
                'imported' => (int) (($validated['stats']['imported'] ?? 0)),
                'warnings' => count($warnings),
                'mode' => $mode,
            ));
        }
        self::redirect_with_message($message, false);
    }

    public static function handle_export_forms(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_FORMS)) {
            wp_die('Unauthorized');
        }
        if (!isset($_POST['dcb_ops_nonce']) || !wp_verify_nonce((string) $_POST['dcb_ops_nonce'], 'dcb_ops_export_forms')) {
            wp_die('Security check failed');
        }

        $forms = dcb_get_custom_forms();
        $payload = dcb_ops_export_forms_payload($forms, array('source' => 'admin_export'));
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            if (function_exists('dcb_ops_record_action')) {
                dcb_ops_record_action('forms_export', 'failed', 'Forms export payload could not be encoded.');
            }
            wp_die('Export failed');
        }

        if (function_exists('dcb_ops_record_action')) {
            dcb_ops_record_action('forms_export', 'ok', 'Forms export generated.', array(
                'form_count' => is_array($forms) ? count($forms) : 0,
            ));
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="dcb-forms-export-' . gmdate('Ymd-His') . '.json"');
        echo $json;
        exit;
    }

    private static function redirect_with_message(string $message, bool $is_error): void {
        $args = array('page' => 'dcb-ops');
        if ($is_error) {
            $args['ops_error'] = rawurlencode($message);
        } else {
            $args['ops_updated'] = rawurlencode($message);
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
