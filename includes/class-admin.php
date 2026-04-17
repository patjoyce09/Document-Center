<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Admin {
    public static function init(): void {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
    }

    public static function register_menu(): void {
        add_menu_page(
            __('Document Center', 'document-center-builder'),
            __('Document Center', 'document-center-builder'),
            DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'dcb-dashboard',
            array(__CLASS__, 'render_dashboard'),
            'dashicons-forms',
            35
        );

        add_submenu_page('dcb-dashboard', __('Forms Builder', 'document-center-builder'), __('Builder', 'document-center-builder'), DCB_Permissions::CAP_MANAGE_FORMS, 'dcb-builder', array('DCB_Builder', 'render_page'));
        add_submenu_page('dcb-dashboard', __('Submissions', 'document-center-builder'), __('Submissions', 'document-center-builder'), DCB_Permissions::CAP_REVIEW_SUBMISSIONS, 'edit.php?post_type=dcb_form_submission');
        add_submenu_page('dcb-dashboard', __('Upload Artifacts', 'document-center-builder'), __('Upload Artifacts', 'document-center-builder'), DCB_Permissions::CAP_REVIEW_SUBMISSIONS, 'edit.php?post_type=dcb_upload_log');
        add_submenu_page('dcb-dashboard', __('OCR Review Queue', 'document-center-builder'), __('OCR Review Queue', 'document-center-builder'), DCB_Permissions::CAP_RUN_OCR_TOOLS, 'edit.php?post_type=dcb_ocr_review_queue');
        add_submenu_page('dcb-dashboard', __('OCR Diagnostics', 'document-center-builder'), __('OCR Diagnostics', 'document-center-builder'), DCB_Permissions::CAP_RUN_OCR_TOOLS, 'dcb-ocr-diagnostics', array('DCB_OCR', 'render_diagnostics_page'));
        add_submenu_page('dcb-dashboard', __('Settings', 'document-center-builder'), __('Settings', 'document-center-builder'), DCB_Permissions::CAP_MANAGE_SETTINGS, 'dcb-settings', array('DCB_Diagnostics', 'render_settings_page'));
    }

    public static function render_dashboard(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_REVIEW_SUBMISSIONS)) {
            wp_die('Unauthorized');
        }

        echo '<div class="wrap">';
        echo '<h1>Document Center Builder</h1>';
        echo '<p>Reusable digital document/form system with OCR-assisted workflows.</p>';

        $snapshot = function_exists('dcb_health_snapshot_payload') ? dcb_health_snapshot_payload() : array();
        $readiness = isset($snapshot['readiness_summary']) && is_array($snapshot['readiness_summary']) ? $snapshot['readiness_summary'] : array('ok' => 0, 'warn' => 0, 'fail' => 0);
        $ocr = isset($snapshot['ocr']) && is_array($snapshot['ocr']) ? $snapshot['ocr'] : array();
        $workflow_counts = isset($snapshot['workflow_status_counts']) && is_array($snapshot['workflow_status_counts']) ? $snapshot['workflow_status_counts'] : array();
        $ops_last = isset($snapshot['ops_last_action']) && is_array($snapshot['ops_last_action']) ? $snapshot['ops_last_action'] : array();
        $links = isset($snapshot['links']) && is_array($snapshot['links']) ? $snapshot['links'] : array();

        echo '<h2>System Health Snapshot</h2>';
        echo '<table class="widefat striped" style="max-width:1020px"><tbody>';
        echo '<tr><th style="width:280px">Setup Readiness</th><td>Ready: <strong>' . esc_html((string) (int) ($readiness['ok'] ?? 0)) . '</strong> • Needs Attention: <strong>' . esc_html((string) (int) ($readiness['warn'] ?? 0)) . '</strong> • Blocked: <strong>' . esc_html((string) (int) ($readiness['fail'] ?? 0)) . '</strong></td></tr>';
        echo '<tr><th>OCR Config / Mode</th><td>Mode: <strong>' . esc_html((string) ($ocr['mode'] ?? 'auto')) . '</strong> • Health: <strong>' . esc_html((string) ($ocr['health_status'] ?? 'unknown')) . '</strong></td></tr>';
        echo '<tr><th>Upload / Storage</th><td>' . esc_html((string) ((isset($snapshot['upload_storage']) && is_array($snapshot['upload_storage']) ? ($snapshot['upload_storage']['note'] ?? '') : ''))) . '</td></tr>';

        $ocr_counts = isset($ocr['queue_status_counts']) && is_array($ocr['queue_status_counts']) ? $ocr['queue_status_counts'] : array();
        $ocr_count_bits = array();
        foreach ($ocr_counts as $status => $count) {
            $ocr_count_bits[] = sanitize_key((string) $status) . ': ' . (int) $count;
        }
        if (empty($ocr_count_bits)) {
            $ocr_count_bits[] = 'No queue items';
        }
        echo '<tr><th>OCR Review Queue</th><td>' . esc_html(implode(' • ', $ocr_count_bits)) . '</td></tr>';
        echo '<tr><th>Unresolved OCR Risk</th><td><strong>' . esc_html((string) (int) ($snapshot['unresolved_ocr_risk_count'] ?? 0)) . '</strong></td></tr>';

        $workflow_bits = array();
        foreach ($workflow_counts as $status => $count) {
            $workflow_bits[] = sanitize_key((string) $status) . ': ' . (int) $count;
        }
        if (empty($workflow_bits)) {
            $workflow_bits[] = 'No workflow items';
        }
        echo '<tr><th>Workflow Summary</th><td>' . esc_html(implode(' • ', $workflow_bits)) . '</td></tr>';

        $last_action = sanitize_key((string) ($ops_last['action'] ?? ''));
        $last_status = sanitize_key((string) ($ops_last['status'] ?? ''));
        $last_message = sanitize_text_field((string) ($ops_last['message'] ?? 'No recent setup/import/export/sample action logged.'));
        $last_time = sanitize_text_field((string) ($ops_last['time'] ?? ''));
        $last_render = trim($last_action . ' ' . $last_status . ' ' . $last_message . ' ' . $last_time);
        echo '<tr><th>Recent Ops Action</th><td>' . esc_html($last_render !== '' ? $last_render : 'No recent setup/import/export/sample action logged.') . '</td></tr>';
        echo '</tbody></table>';

        echo '<p style="margin:10px 0 16px;">';
        echo '<a class="button button-secondary" href="' . esc_url((string) ($links['setup_ops'] ?? admin_url('admin.php?page=dcb-ops'))) . '">Setup &amp; Operations</a> ';
        echo '<a class="button button-secondary" href="' . esc_url((string) ($links['ocr_diagnostics'] ?? admin_url('admin.php?page=dcb-ocr-diagnostics'))) . '">OCR Diagnostics</a> ';
        echo '<a class="button button-secondary" href="' . esc_url((string) ($links['ocr_queue'] ?? admin_url('edit.php?post_type=dcb_ocr_review_queue'))) . '">OCR Review Queue</a> ';
        echo '<a class="button button-secondary" href="' . esc_url((string) ($links['intake_trace'] ?? admin_url('admin.php?page=dcb-intake-trace'))) . '">Intake Trace Timeline</a> ';
        echo '<a class="button button-secondary" href="' . esc_url((string) ($links['upload_artifacts'] ?? admin_url('edit.php?post_type=dcb_upload_log'))) . '">Upload Artifacts</a> ';
        echo '<a class="button button-secondary" href="' . esc_url((string) ($links['workflow_queue'] ?? admin_url('admin.php?page=dcb-workflow-queues'))) . '">Workflow Queue</a>';
        echo '</p>';

        echo '<ul>';
        echo '<li><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=dcb-ops')) . '">Setup &amp; Operations</a></li>';
        echo '<li><a class="button" href="' . esc_url(admin_url('admin.php?page=dcb-builder')) . '">Open Builder</a></li>';
        echo '<li><a class="button" href="' . esc_url(admin_url('edit.php?post_type=dcb_form_submission')) . '">View Submissions</a></li>';
        echo '<li><a class="button" href="' . esc_url(admin_url('admin.php?page=dcb-workflow-queues')) . '">Workflow Queues</a></li>';
        echo '<li><a class="button" href="' . esc_url(admin_url('admin.php?page=dcb-chart-routing')) . '">Chart Routing Queue</a></li>';
        echo '<li><a class="button" href="' . esc_url(admin_url('admin.php?page=dcb-workflow-config')) . '">Workflow Routing</a></li>';
        echo '<li><a class="button" href="' . esc_url(admin_url('admin.php?page=dcb-ocr-diagnostics')) . '">OCR Diagnostics</a></li>';
        echo '</ul>';
        echo '</div>';
    }
}
