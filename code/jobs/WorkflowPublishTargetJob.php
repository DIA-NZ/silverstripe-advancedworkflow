<?php
/**
 * A queued job that publishes a target after a delay.
 *
 * @package advancedworkflow
 */
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;

class WorkflowPublishTargetJob extends AbstractQueuedJob {

	public function __construct($obj = null, $type = null) {
		if ($obj) {
			$this->setObject($obj);
			$this->publishType = $type ? strtolower($type) : 'publish';
			$this->totalSteps = 1;
		}
	}

	public function getTitle() {
		return _t(
			'AdvancedWorkflowPublishJob.SCHEDULEJOBTITLE',
			"Scheduled {type} of {object}",
			"",
			array(
				'type' => $this->publishType,
				'object' => $this->getObject()->Title
			)
		);
	}

	public function process() {
        // Ensures we're retrieving the "draft" version of the object to be published 
        \Versioned::reading_stage('Stage');
		if ($target = $this->getObject()) {
			if ($this->publishType == 'publish') {
				$target->setIsPublishJobRunning(true);
				$target->PublishOnDate = '';
				$target->writeWithoutVersion();
				$target->doPublish();
			} else if ($this->publishType == 'unpublish') {
				$target->setIsPublishJobRunning(true);
				$target->UnPublishOnDate = '';
				$target->writeWithoutVersion();
				$target->doUnpublish();
			}
		}
		$this->currentStep = 1;
		$this->isComplete = true;
	}

}