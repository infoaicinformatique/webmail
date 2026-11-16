<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\DigestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Every quarter of an hour: the « Résumé du matin » of those whose hour has come. */
class DigestJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private DigestService $digests,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->digests->checkAll();
	}
}
