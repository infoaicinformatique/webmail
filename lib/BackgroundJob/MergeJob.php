<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\MergeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Every five minutes: the personalised sendings under way go on, a few dozen messages each. */
class MergeJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private MergeService $merges,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->merges->runAll();
	}
}
