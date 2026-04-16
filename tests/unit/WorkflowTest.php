<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

final class WorkflowTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['dcb_post_meta'] = array();
        $GLOBALS['dcb_options'] = array('dcb_workflow_default_status' => 'submitted');
    }

    public function testWorkflowTransitionSubmittedToInReview(): void {
        $postId = 101;
        \update_post_meta($postId, '_dcb_workflow_status', 'submitted');

        $ok = \DCB_Workflow::set_status($postId, 'in_review', 'Initial review');
        $this->assertTrue($ok);
        $this->assertSame('in_review', \DCB_Workflow::get_status($postId));
    }

    public function testInvalidTransitionRejectedToFinalizedFails(): void {
        $postId = 102;
        \update_post_meta($postId, '_dcb_workflow_status', 'rejected');

        $ok = \DCB_Workflow::set_status($postId, 'finalized', 'Should fail');
        $this->assertFalse($ok);
        $this->assertSame('rejected', \DCB_Workflow::get_status($postId));
    }
}
