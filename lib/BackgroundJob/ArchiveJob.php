<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\ArchiveService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Once a day: the old read messages of the inbox to « Archives », for those who asked. */
class ArchiveJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ArchiveService $archives,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->archives->archiveAll();
	}
}
