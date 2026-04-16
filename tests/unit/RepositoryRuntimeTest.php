<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

final class RepositoryRuntimeTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['dcb_options'] = array();
        $GLOBALS['dcb_post_meta'] = array();
        $GLOBALS['dcb_posts'] = array();
        $GLOBALS['dcb_next_post_id'] = 1000;
        $GLOBALS['dcb_registered_post_types'] = array();
        \DCB_Form_Repository::register_post_type();
    }

    public function testDualReadFallsBackToOptionStoreWhenTargetIsEmpty(): void {
        \update_option('dcb_forms_storage_mode', 'cpt');
        \update_option('dcb_forms_storage_dual_read', '1');
        \update_option('dcb_forms_custom', array(
            'intake' => array('label' => 'Intake', 'version' => 1, 'fields' => array()),
        ));

        $rows = \DCB_Form_Repository::get_all_raw();
        $this->assertArrayHasKey('intake', $rows);
    }

    public function testDualWritePersistsToOptionDuringNonOptionModeWrites(): void {
        \update_option('dcb_forms_storage_mode', 'cpt');
        \update_option('dcb_forms_storage_dual_write', '1');

        \DCB_Form_Repository::save_all_raw(array(
            'annual_review' => array('label' => 'Annual Review', 'version' => 1, 'fields' => array()),
        ));

        $cptRows = \DCB_Form_Repository::get_all_raw();
        $optionRows = (array) \get_option('dcb_forms_custom', array());

        $this->assertArrayHasKey('annual_review', $cptRows);
        $this->assertArrayHasKey('annual_review', $optionRows);
    }

    public function testMigrationUtilityDryRunAndWriteModes(): void {
        $source = array(
            'discharge' => array('label' => 'Discharge', 'version' => 3, 'fields' => array()),
        );
        \update_option('dcb_forms_custom', $source);

        $dryRun = \DCB_Form_Repository::migrate_option_to_mode('cpt', true);
        $this->assertTrue((bool) ($dryRun['ok'] ?? false));
        $this->assertTrue((bool) ($dryRun['dry_run'] ?? false));
        $this->assertArrayHasKey('verification', $dryRun);
        $this->assertArrayHasKey('summary', (array) ($dryRun['verification'] ?? array()));

        $write = \DCB_Form_Repository::migrate_option_to_mode('cpt', false);
        $this->assertTrue((bool) ($write['ok'] ?? false));
        $this->assertFalse((bool) ($write['dry_run'] ?? true));
        $this->assertTrue((bool) (($write['parity']['exact_match'] ?? false)));

        $cptRows = \DCB_Form_Repository::get_all_raw();
        $this->assertArrayHasKey('discharge', $cptRows);
        $this->assertSame($source, (array) \get_option('dcb_forms_custom', array()));
    }

    public function testRepositorySaveAndDeleteFormInCptMode(): void {
        \update_option('dcb_forms_storage_mode', 'cpt');

        $saved = \DCB_Form_Repository::save_form_raw('intake_form', array('label' => 'Intake Form', 'version' => 1, 'fields' => array()));
        $this->assertTrue($saved);

        $loaded = \DCB_Form_Repository::get_form_raw('intake_form');
        $this->assertIsArray($loaded);
        $this->assertSame('Intake Form', (string) ($loaded['label'] ?? ''));

        $deleted = \DCB_Form_Repository::delete_form_raw('intake_form');
        $this->assertTrue($deleted);
        $this->assertNull(\DCB_Form_Repository::get_form_raw('intake_form'));
    }

    public function testParityReportIncludesChecksumMismatchRows(): void {
        \update_option('dcb_forms_custom', array(
            'intake' => array('label' => 'Intake', 'version' => 1, 'fields' => array(array('key' => 'name', 'type' => 'text'))),
        ));

        \update_option('dcb_forms_storage_mode', 'cpt');
        \DCB_Form_Repository::save_all_raw(array(
            'intake' => array('label' => 'Intake Updated', 'version' => 2, 'fields' => array(array('key' => 'name', 'type' => 'textarea'))),
        ));

        $report = \DCB_Form_Repository::parity_between_modes('option', 'cpt');
        $this->assertTrue((bool) ($report['ok'] ?? false));
        $this->assertArrayHasKey('parity', $report);
        $this->assertGreaterThan(0, (int) ($report['parity']['checksum_mismatch_count'] ?? 0));
        $rows = (array) ($report['rows'] ?? array());
        $this->assertNotEmpty($rows);
        $first = (array) $rows[0];
        $this->assertArrayHasKey('issue', $first);
    }

    public function testScheduledParityMonitorStoresDriftSummaryWhenMismatchExists(): void {
        \update_option('dcb_forms_storage_mode', 'cpt');
        \update_option('dcb_forms_custom', array(
            'intake' => array('label' => 'Intake', 'version' => 1, 'fields' => array(array('key' => 'name', 'type' => 'text'))),
        ));
        \DCB_Form_Repository::save_all_raw(array(
            'intake' => array('label' => 'Intake', 'version' => 2, 'fields' => array(array('key' => 'name', 'type' => 'textarea'))),
        ));

        $result = \DCB_Form_Repository::run_scheduled_parity_monitor();
        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertTrue((bool) ($result['drift_detected'] ?? false));

        $last = (array) \get_option('dcb_forms_storage_drift_last', array());
        $this->assertTrue((bool) ($last['drift_detected'] ?? false));
        $this->assertArrayHasKey('summary', $last);

        $log = (array) \get_option('dcb_forms_storage_drift_log', array());
        $this->assertNotEmpty($log);
    }
}
