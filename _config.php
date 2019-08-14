<?php
/**
 * @license BSD License (http://silverstripe.org/bsd-license/)
 * @package advancedworkflow
 */
use SilverStripe\Admin\LeftAndMain;

define('ADVANCED_WORKFLOW_DIR', basename(dirname(__FILE__)));

if(ADVANCED_WORKFLOW_DIR != 'silverstripe-advancedworkflow') {
	throw new Exception(
		"The advanced workflow module must be in a directory named 'silverstripe-advancedworkflow', not " . ADVANCED_WORKFLOW_DIR
	);
}

// LeftAndMain::require_css(ADVANCED_WORKFLOW_DIR . '/css/AdvancedWorkflowAdmin.css');
