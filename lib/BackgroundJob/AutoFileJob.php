<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\AutoFileService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Every 10 minutes, the attachments of the senders chosen put into their folder of Files.
 */
class AutoFileJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AutoFileService $service,
	) {
		parent::__construct($time);
		$this->setInterval(600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->service->runAll();
	}
}
