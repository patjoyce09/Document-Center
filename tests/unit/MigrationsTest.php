<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

final class MigrationsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['dcb_options'] = array();
    }

    public function testMigrationAddsStorageModeOption(): void {
        \DCB_Migrations::run();

        $schemaVersion = (int) \get_option('dcb_schema_version', 0);
        $storageMode = (string) \get_option('dcb_forms_storage_mode', '');
        $dualRead = (string) \get_option('dcb_forms_storage_dual_read', '');
        $dualWrite = (string) \get_option('dcb_forms_storage_dual_write', '');
        $monitorEnabled = (string) \get_option('dcb_forms_parity_monitor_enabled', '');
        $alertEnabled = (string) \get_option('dcb_forms_parity_alert_enabled', '');

        $this->assertGreaterThanOrEqual(7, $schemaVersion);
        $this->assertSame('option', $storageMode);
        $this->assertSame('1', $dualRead);
        $this->assertSame('0', $dualWrite);
        $this->assertSame('1', $monitorEnabled);
        $this->assertSame('0', $alertEnabled);
    }
}
