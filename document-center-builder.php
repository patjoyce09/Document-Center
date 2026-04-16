<?php
/**
 * Plugin Name: Document Center Builder
 * Plugin URI: https://example.com
 * Description: Standalone digital document/form system with builder UI, conditional logic, OCR-assisted drafting, submissions, signatures, and diagnostics.
 * Version: 0.2.4
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Document Center
 * Text Domain: document-center-builder
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DCB_VERSION')) {
    define('DCB_VERSION', '0.2.4');
}
if (!defined('DCB_PLUGIN_FILE')) {
    define('DCB_PLUGIN_FILE', __FILE__);
}
if (!defined('DCB_PLUGIN_BASENAME')) {
    define('DCB_PLUGIN_BASENAME', plugin_basename(__FILE__));
}
if (!defined('DCB_PLUGIN_DIR')) {
    define('DCB_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('DCB_PLUGIN_URL')) {
    define('DCB_PLUGIN_URL', plugin_dir_url(__FILE__));
}

require_once DCB_PLUGIN_DIR . 'includes/helpers-schema.php';
require_once DCB_PLUGIN_DIR . 'includes/helpers-ocr.php';
require_once DCB_PLUGIN_DIR . 'includes/helpers-render.php';
require_once DCB_PLUGIN_DIR . 'includes/class-loader.php';

register_activation_hook(__FILE__, array('DCB_Loader', 'activate'));

DCB_Loader::instance()->boot();
