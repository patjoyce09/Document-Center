<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

if (!class_exists(__NAMESPACE__ . '\\WP_CLI_Mock')) {
    class WP_CLI_Mock {
        public static array $logs = array();

        public static function add_command($name, $callable): void {}

        public static function log($message): void {
            self::$logs[] = (string) $message;
        }

        public static function success($message): void {
            self::$logs[] = 'SUCCESS: ' . (string) $message;
        }

        public static function warning($message): void {
            self::$logs[] = 'WARNING: ' . (string) $message;
        }

        public static function error($message): void {
            throw new \RuntimeException((string) $message);
        }
    }
}

if (!class_exists('WP_CLI')) {
    class_alias(__NAMESPACE__ . '\\WP_CLI_Mock', 'WP_CLI');
}

require_once dirname(__DIR__, 2) . '/includes/class-cli.php';

final class CliParityReportTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['dcb_options'] = array();
        $GLOBALS['dcb_post_meta'] = array();
        $GLOBALS['dcb_posts'] = array();
        $GLOBALS['dcb_next_post_id'] = 1000;
        $GLOBALS['dcb_registered_post_types'] = array();
        \WP_CLI::$logs = array();
        \DCB_Form_Repository::register_post_type();

        \update_option('dcb_forms_custom', array(
            'intake' => array('label' => 'Intake', 'version' => 1, 'fields' => array(array('key' => 'name', 'type' => 'text'))),
        ));
        \update_option('dcb_forms_storage_mode', 'cpt');
        \DCB_Form_Repository::save_all_raw(array(
            'intake' => array('label' => 'Intake Updated', 'version' => 2, 'fields' => array(array('key' => 'name', 'type' => 'textarea'))),
        ));
    }

    public function testJsonParityReportOutputShape(): void {
        \DCB_CLI::forms_parity_report(array(), array(
            'source' => 'option',
            'target' => 'cpt',
            'format' => 'json',
        ));

        $this->assertNotEmpty(\WP_CLI::$logs);
        $json = (string) end(\WP_CLI::$logs);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('parity', $decoded);
        $this->assertArrayHasKey('rows', $decoded);
    }

    public function testCsvParityReportOutputIncludesHeaderAndMismatchData(): void {
        \DCB_CLI::forms_parity_report(array(), array(
            'source' => 'option',
            'target' => 'cpt',
            'format' => 'csv',
        ));

        $this->assertNotEmpty(\WP_CLI::$logs);
        $csv = (string) end(\WP_CLI::$logs);
        $this->assertStringContainsString('form_key,issue,detail,verified_ratio', $csv);
        $this->assertStringContainsString('checksum_mismatch', $csv);
    }

    public function testSummaryOnlySchemaShapeIsStable(): void {
        \DCB_CLI::forms_parity_report(array(), array(
            'source' => 'option',
            'target' => 'cpt',
            'summary-only' => true,
        ));

        $this->assertNotEmpty(\WP_CLI::$logs);
        $json = (string) end(\WP_CLI::$logs);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        $this->assertArrayHasKey('schema', $decoded);
        $this->assertSame('dcb.forms-parity.summary', (string) (($decoded['schema']['name'] ?? '')));
        $this->assertSame('1.0.0', (string) (($decoded['schema']['version'] ?? '')));
        $this->assertArrayHasKey('generated_at', $decoded);
        $this->assertArrayHasKey('command', $decoded);
        $this->assertArrayHasKey('source_mode', $decoded);
        $this->assertArrayHasKey('target_mode', $decoded);
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('counts', $decoded);

        $summary = (array) ($decoded['summary'] ?? array());
        $this->assertArrayHasKey('exact_match', $summary);
        $this->assertArrayHasKey('severity', $summary);
        $this->assertArrayHasKey('verified_ratio', $summary);
        $this->assertArrayHasKey('missing_count', $summary);
        $this->assertArrayHasKey('extra_count', $summary);
        $this->assertArrayHasKey('checksum_mismatch_count', $summary);
    }
}
