<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\ContactCopyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Every 6 hours, the copies of Exchange contacts in Nextcloud Contacts brought up to date. */
class ContactCopyJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ContactCopyService $service,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 3600);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->service->runAll();
	}
}
