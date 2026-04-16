<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

final class SchemaHelperTest extends TestCase {
    public function testNormalizeCondition(): void {
        $condition = \dcb_normalize_condition(array(
            'field' => 'age',
            'operator' => 'gte',
            'value' => '18',
        ));

        $this->assertIsArray($condition);
        $this->assertSame('age', $condition['field']);
        $this->assertSame('gte', $condition['operator']);
        $this->assertSame('18', $condition['value']);
    }

    public function testNormalizeFormIncludesSectionsAndSteps(): void {
        $form = \dcb_normalize_single_form(array(
            'label' => 'Intake',
            'fields' => array(
                array('key' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true),
            ),
            'sections' => array(
                array('key' => 'general', 'label' => 'General', 'field_keys' => array('first_name')),
            ),
            'steps' => array(
                array('key' => 'step_1', 'label' => 'Step 1', 'section_keys' => array('general')),
            ),
            'repeaters' => array(
                array('key' => 'meds', 'label' => 'Medications', 'field_keys' => array('first_name'), 'min' => 0, 'max' => 3),
            ),
        ));

        $this->assertIsArray($form);
        $this->assertArrayHasKey('sections', $form);
        $this->assertArrayHasKey('steps', $form);
        $this->assertArrayHasKey('repeaters', $form);
        $this->assertCount(1, $form['sections']);
        $this->assertCount(1, $form['steps']);
        $this->assertCount(1, $form['repeaters']);
    }
}
