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
        echo '<ul>';
        echo '<li><a class="button" href="' . esc_url(admin_url('admin.php?page=dcb-builder')) . '">Open Builder</a></li>';
        echo '<li><a class="button" href="' . esc_url(admin_url('edit.php?post_type=dcb_form_submission')) . '">View Submissions</a></li>';
        echo '<li><a class="button" href="' . esc_url(admin_url('admin.php?page=dcb-ocr-diagnostics')) . '">OCR Diagnostics</a></li>';
        echo '</ul>';
        echo '</div>';
    }
}
