<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\OofStatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Every hour, the status of Nextcloud after the automatic replies of Exchange (set in Outlook,
 * or dates reached).
 */
class OofStatusJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OofStatusService $service,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->service->syncAll();
	}
}
