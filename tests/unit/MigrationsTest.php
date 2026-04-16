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

        $this->assertGreaterThanOrEqual(4, $schemaVersion);
        $this->assertSame('option', $storageMode);
    }
}
