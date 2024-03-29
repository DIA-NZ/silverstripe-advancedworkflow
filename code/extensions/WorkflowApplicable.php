<?php
/**
 * DataObjects that have the WorkflowApplicable extension can have a
 * workflow definition applied to them. At some point, the workflow definition is then
 * triggered.
 *
 * @author  marcus@silverstripe.com.au
 * @license BSD License (http://silverstripe.org/bsd-license/)
 * @package advancedworkflow
 */

use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Security\Permission;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridFieldConfig_Base;
use SilverStripe\Forms\GridField\GridFieldEditButton;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridField;

class WorkflowApplicable extends DataExtension {

	private static $has_one = array(
		'WorkflowDefinition' => 'WorkflowDefinition',
	);

	private static $many_many = array(
		'AdditionalWorkflowDefinitions' => 'WorkflowDefinition'
	);

	private static $dependencies = array(
		'workflowService'		=> '%$WorkflowService',
	);

	/**
	 *
	 * Used to flag to this extension if there's a WorkflowPublishTargetJob running.
	 * @var boolean
	 */
	public $isPublishJobRunning = false;

	/**
	 *
	 * @param boolean $truth
	 */
	public function setIsPublishJobRunning($truth) {
		$this->isPublishJobRunning = $truth;
	}

	/**
	 *
	 * @return boolean
	 */
	public function getIsPublishJobRunning() {
		return $this->isPublishJobRunning;
	}

	/**
	 *
	 * @see {@link $this->isPublishJobRunning}
	 * @return boolean
	 */
	public function isPublishJobRunning() {
		$propIsSet = $this->getIsPublishJobRunning() ? true : false;
		return class_exists('AbstractQueuedJob') && $propIsSet;
	}

	/**
	 * @var WorkflowService
	 */
	public $workflowService;
	
	/**
	 * 
	 * A cache var for the current workflow instance
	 *
	 * @var WorkflowInstance
	 */
	protected $currentInstance;
	
	public function updateSettingsFields(FieldList $fields) {
		$this->updateFields($fields);
	}

	public function updateCMSFields(FieldList $fields) {
		if(!$this->owner->hasMethod('getSettingsFields')) $this->updateFields($fields);

		// Instantiate a hidden form field to pass the triggered workflow definition through, allowing a dynamic form action.

		$fields->push(HiddenField::create(
			'TriggeredWorkflowID'
		));
	}

	public function updateFields(FieldList $fields) {
		if (!$this->owner->ID) {
			return $fields;
		}
		
		$tab       = $fields->fieldByName('Root') ? $fields->findOrMakeTab('Root.Workflow') : $fields;

		if(Permission::check('APPLY_WORKFLOW')) {
			$definition = new DropdownField('WorkflowDefinitionID', _t('WorkflowApplicable.DEFINITION', 'Applied Workflow'));
			$definitions = $this->workflowService->getDefinitions()->map()->toArray();
			$definition->setSource($definitions);
			$definition->setEmptyString(_t('WorkflowApplicable.INHERIT', 'Inherit from parent'));
			$tab->push($definition);

			// Allow an optional selection of additional workflow definitions.

			if($this->owner->WorkflowDefinitionID) {
				$fields->removeByName('AdditionalWorkflowDefinitions');
				unset($definitions[$this->owner->WorkflowDefinitionID]);
				$tab->push($additional = ListboxField::create(
					'AdditionalWorkflowDefinitions',
					_t('WorkflowApplicable.ADDITIONAL_WORKFLOW_DEFINITIONS', 'Additional Workflows')
				));
				$additional->setSource($definitions);
				$additional->setMultiple(true);
			}
		}

		// Display the effective workflow definition.

		if($effective = $this->getWorkflowInstance()) {
			$title = $effective->Definition()->Title;
			$tab->push(ReadonlyField::create(
				'EffectiveWorkflow',
				_t('WorkflowApplicable.EFFECTIVE_WORKFLOW', 'Effective Workflow'),
				$title
			));
		}

		if($this->owner->ID) {
			$config = new GridFieldConfig_Base();
			$config->addComponent(new GridFieldEditButton());
			$config->addComponent(new GridFieldDetailForm());
			
			$insts = $this->owner->WorkflowInstances();
			$log   = new GridField('WorkflowLog', _t('WorkflowApplicable.WORKFLOWLOG', 'Workflow Log'), $insts, $config);

			$tab->push($log);
		}
	}

	public function updateCMSActions(FieldList $actions) {
		$active = $this->workflowService->getWorkflowFor($this->owner);
		$c = Controller::curr();
		if ($c && $c->hasExtension('AdvancedWorkflowExtension')) {
			if ($active) {
				if ($this->canEditWorkflow()) {
					$workflowOptions = new Tab(
						'WorkflowOptions', 
						_t('SiteTree.WorkflowOptions', 'Workflow options', 'Expands a view for workflow specific buttons')
					);

					$menu = $actions->fieldByName('ActionMenus');
					if (!$menu) {
						// create the menu for adding to any arbitrary non-sitetree object
						$menu = $this->createActionMenu();
						$actions->push($menu);
					}

					$menu->push($workflowOptions);
					
					$transitions = $active->CurrentAction()->getValidTransitions();
					
					foreach ($transitions as $transition) {
						if ($transition->canExecute($active)) {
							$action = FormAction::create('updateworkflow-' . $transition->ID, $transition->Title)
								->setAttribute('data-transitionid', $transition->ID)->setUseButtonTag(true);
							$workflowOptions->push($action);
						}
					}

//					$action = FormAction::create('updateworkflow', $active->CurrentAction() ? $active->CurrentAction()->Title : _t('WorkflowApplicable.UPDATE_WORKFLOW', 'Update Workflow'))
//						->setAttribute('data-icon', 'navigation');
//					$actions->fieldByName('MajorActions') ? $actions->fieldByName('MajorActions')->push($action) : $actions->push($action);
				}
			} else {
				// Instantiate the workflow definition initial actions.
				$definitions = $this->workflowService->getDefinitionsFor($this->owner);
				if($definitions) {
					$menu = $actions->fieldByName('ActionMenus');
					if(is_null($menu)) {

						// Instantiate a new action menu for any data objects.

						$menu = $this->createActionMenu();
						$actions->push($menu);
					}
					$tab = Tab::create(
						'AdditionalWorkflows'
					);
					$menu->insertBefore($tab, 'MoreOptions');
					$addedFirst = false;
					foreach($definitions as $definition) {
						if($definition->getInitialAction()) {
							$action = FormAction::create(
								"startworkflow-{$definition->ID}",
								$definition->InitialActionButtonText ? $definition->InitialActionButtonText : $definition->getInitialAction()->Title
							)->addExtraClass('start-workflow')->setAttribute('data-workflow', $definition->ID)->setUseButtonTag(true);

							// The first element is the main workflow definition, and will be displayed as a major action.

							if(!$addedFirst) {
								$addedFirst = true;
								$action->setAttribute('data-icon', 'navigation');
								$majorActions = $actions->fieldByName('MajorActions');
								$majorActions ? $majorActions->push($action) : $actions->push($action);
							} else {
								$tab->push($action);
							}
						}
					}
				}
				
			}
		}
	}
	
	protected function createActionMenu() {
		$rootTabSet = new TabSet('ActionMenus');
		$rootTabSet->addExtraClass('ss-ui-action-tabset action-menus');
		return $rootTabSet;
	}
	
	/**
	 * Included in CMS-generated email templates for a NotifyUsersWorkflowAction. 
	 * Returns an absolute link to the CMS UI for a Page object
	 * 
	 * @return string | null
	 */	
	public function AbsoluteEditLink() {
		$CMSEditLink = null;

		if($this->owner instanceof CMSPreviewable) {
			$CMSEditLink = $this->owner->CMSEditLink();
		} else if ($this->owner->hasMethod('WorkflowLink')) {
			$CMSEditLink = $this->owner->WorkflowLink();
		}

		if ($CMSEditLink === null) {
			return null;
		}

		return Controller::join_links(Director::absoluteBaseURL(), $CMSEditLink);
	}
	
	/**
	 * Included in CMS-generated email templates for a NotifyUsersWorkflowAction. 
	 * Allows users to select a link in an email for direct access to the transition-selection dropdown in the CMS UI.
	 * 
	 * @return string
	 */
	public function LinkToPendingItems() {
		$urlBase = Director::absoluteBaseURL();
		$urlFrag = 'admin/workflows/WorkflowDefinition/EditForm/field';
		$urlInst = $this->getWorkflowInstance();
		return Controller::join_links($urlBase, $urlFrag, 'PendingObjects', 'item', $urlInst->ID, 'edit');
	}
	
	/**
	 * After a workflow item is written, we notify the
	 * workflow so that it can take action if needbe
	 */
	public function onAfterWrite() {
		$instance = $this->getWorkflowInstance();
		if ($instance && $instance->CurrentActionID) {
			$action = $instance->CurrentAction()->BaseAction()->targetUpdated($instance);
		}
	}

	public function WorkflowInstances() {
		return WorkflowInstance::get()->filter(array(
			'TargetClass' => $this->owner->ClassName,
			'TargetID'    => $this->owner->ID
		));
	}

	/**
	 * Gets the current instance of workflow
	 *
	 * @return WorkflowInstance
	 */
	public function getWorkflowInstance() {
		if (!$this->currentInstance) {
			$this->currentInstance = $this->workflowService->getWorkflowFor($this->owner);
		}

		return $this->currentInstance;
	}


	/**
	 * Gets the history of a workflow instance
	 *
	 * @return DataObjectSet
	 */
	public function getWorkflowHistory($limit = null) {
		return $this->workflowService->getWorkflowHistoryFor($this->owner, $limit);
	}

	/**
	 * Check all recent WorkflowActionIntances and return the most recent one with a Comment
	 *
	 * @return WorkflowActionInstance
	 */
	public function RecentWorkflowComment($limit = 10){
		if($actions = $this->getWorkflowHistory($limit)){
			foreach ($actions as $action) {
				if ($action->Comment != '') {
					return $action;
				}
			}
		}
	}

	/**
	 * Content can never be directly publishable if there's a workflow applied.
	 *
	 * If there's an active instance, then it 'might' be publishable
	 */
	public function canPublish() {
		// Override any default behaviour, to allow queuedjobs to complete
		if($this->isPublishJobRunning()) {
			return true;
		}

		if ($active = $this->getWorkflowInstance()) {
			return $active->canPublishTarget($this->owner);
		}

		// otherwise, see if there's any workflows applied. If there are, then we shouldn't be able
		// to directly publish
		if ($effective = $this->workflowService->getDefinitionFor($this->owner)) {
			return false;
		}
	}

	/**
	 * Can only edit content that's NOT in another person's content changeset
	 */
	public function canEdit($member) {
		// Override any default behaviour, to allow queuedjobs to complete
		if($this->isPublishJobRunning()) {
			return true;
		}

		if ($active = $this->getWorkflowInstance()) {
			return $active->canEditTarget($this->owner);
		}
	}

	/**
	 * Can a user edit the current workflow attached to this item?
	 */
	public function canEditWorkflow() {
		$active = $this->getWorkflowInstance();
		if ($active) {
			return $active->canEdit();
		}
		return false;
	}
}
