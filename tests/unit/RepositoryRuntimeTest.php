<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

final class RepositoryRuntimeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['dcb_options'] = array();
    }

    public function testDualReadFallsBackToOptionStoreWhenTargetIsEmpty(): void {
        \update_option('dcb_forms_storage_mode', 'cpt');
        \update_option('dcb_forms_storage_dual_read', '1');
        \update_option('dcb_forms_custom', array(
            'intake' => array('label' => 'Intake', 'version' => 1, 'fields' => array()),
        ));
        \update_option('dcb_forms_custom_cpt_shadow', array());

        $rows = \DCB_Form_Repository::get_all_raw();
        $this->assertArrayHasKey('intake', $rows);
    }

    public function testDualWritePersistsToOptionDuringNonOptionModeWrites(): void {
        \update_option('dcb_forms_storage_mode', 'table');
        \update_option('dcb_forms_storage_dual_write', '1');

        \DCB_Form_Repository::save_all_raw(array(
            'annual_review' => array('label' => 'Annual Review', 'version' => 1, 'fields' => array()),
        ));

        $tableRows = (array) \get_option('dcb_forms_custom_table_shadow', array());
        $optionRows = (array) \get_option('dcb_forms_custom', array());

        $this->assertArrayHasKey('annual_review', $tableRows);
        $this->assertArrayHasKey('annual_review', $optionRows);
    }

    public function testMigrationUtilityDryRunAndWriteModes(): void {
        \update_option('dcb_forms_custom', array(
            'discharge' => array('label' => 'Discharge', 'version' => 3, 'fields' => array()),
        ));

        $dryRun = \DCB_Form_Repository::migrate_option_to_mode('cpt', true);
        $this->assertTrue((bool) ($dryRun['ok'] ?? false));
        $this->assertTrue((bool) ($dryRun['dry_run'] ?? false));

        $write = \DCB_Form_Repository::migrate_option_to_mode('cpt', false);
        $this->assertTrue((bool) ($write['ok'] ?? false));
        $this->assertFalse((bool) ($write['dry_run'] ?? true));

        $shadow = (array) \get_option('dcb_forms_custom_cpt_shadow', array());
        $this->assertArrayHasKey('discharge', $shadow);
    }
}
