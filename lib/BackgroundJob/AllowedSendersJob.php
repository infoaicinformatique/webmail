<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\SenderListService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Every quarter of an hour: the messages of allowed senders brought back from « Courrier indésirable ». */
class AllowedSendersJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private SenderListService $senders,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->senders->rescueAll();
	}
}
