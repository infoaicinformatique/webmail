<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\UpdateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Once a day: a new version of the app told to the administrators (once per version). */
class UpdateCheckJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private UpdateService $updates,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->updates->dismissInstalled();
		$this->updates->notifyAdmins();
	}
}
