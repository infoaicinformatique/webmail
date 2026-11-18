<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\RecurringService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Every five minutes: the « Envois récurrents » whose time has come. */
class RecurringJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private RecurringService $recurring,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->recurring->sendDue();
	}
}
