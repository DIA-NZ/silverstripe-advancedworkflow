<?php
/**
 * This DataObject replaces the SilverStripe cache as the repository for
 * imported WorkflowDefinitions.
 *
 * @author  russell@silverstripe.com
 * @license BSD License (http://silverstripe.org/bsd-license/)
 * @package advancedworkflow
 */
use SilverStripe\ORM\DataObject;

class ImportedWorkflowTemplate extends DataObject {

	/**
	 *
	 * @var array
	 */
	private static $db = array(
		"Name" => "Varchar(255)",
		"Filename" => "Varchar(255)",
		"Content" => "Text"
	);

	/**
	 *
	 * @var array
	 */
	private static $has_one = array(
		'Definition' => 'WorkflowDefinition'
	);

}
