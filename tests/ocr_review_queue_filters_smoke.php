<?php

define('ABSPATH', __DIR__ . '/');

$_GET = array(
    'dcb_ocr_status_filter' => 'pending_review',
    'dcb_ocr_source_filter' => 'photo',
    'dcb_ocr_capture_risk_filter' => 'unresolved',
);

if (!class_exists('WP_Query')) {
    class WP_Query {
        public array $query;
        private array $store = array();

        public function __construct(array $query = array()) {
            $this->query = $query;
        }

        public function is_main_query(): bool {
            return true;
        }

        public function get(string $key) {
            return $this->store[$key] ?? null;
        }

        public function set(string $key, $value): void {
            $this->store[$key] = $value;
        }
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public string $post_type = 'dcb_ocr_review_queue';
        public int $ID = 1;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key));
    }
}
if (!function_exists('is_admin')) {
    function is_admin() {
        return true;
    }
}
if (!function_exists('__')) {
    function __($text) {
        return $text;
    }
}
if (!function_exists('selected')) {
    function selected($a, $b, $echo = true) {
        return ((string) $a === (string) $b) ? 'selected="selected"' : '';
    }
}

$GLOBALS['pagenow'] = 'edit.php';

require_once dirname(__DIR__) . '/includes/class-ocr.php';

$q = new WP_Query(array('post_type' => 'dcb_ocr_review_queue'));
DCB_OCR::apply_review_queue_filters($q);

$meta_query = $q->get('meta_query');
if (!is_array($meta_query) || count($meta_query) < 3) {
    fwrite(STDERR, "meta_query not applied\n");
    exit(1);
}

$keys = array();
foreach ($meta_query as $row) {
    if (is_array($row) && isset($row['key'])) {
        $keys[] = (string) $row['key'];
    }
}

$required = array(
    '_dcb_ocr_review_status',
    '_dcb_ocr_review_source_type',
    '_dcb_ocr_review_capture_risk_unresolved',
);
foreach ($required as $k) {
    if (!in_array($k, $keys, true)) {
        fwrite(STDERR, "missing filter key: $k\n");
        exit(1);
    }
}

echo "ocr_review_queue_filters_smoke:ok\n";
