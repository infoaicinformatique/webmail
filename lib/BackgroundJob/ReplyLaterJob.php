<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\ReplyLaterService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Every five minutes: « Répondre plus tard » due, and still not answered: told in the bell. */
class ReplyLaterJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ReplyLaterService $replies,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->replies->checkAll();
	}
}
