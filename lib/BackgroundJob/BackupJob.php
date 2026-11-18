<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\BackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Every half hour, the new messages of the folders chosen saved in Files (« Sauvegarde dans Fichiers »).
 */
class BackupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private BackupService $service,
	) {
		parent::__construct($time);
		$this->setInterval(1800);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->service->runAll();
	}
}
