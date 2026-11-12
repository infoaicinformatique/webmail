<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\MailNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Every 5 minutes (with Nextcloud's cron), a notification for each new
 * message of each account whose user has not switched them off.
 */
class NewMailJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private MailNotificationService $service,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$this->service->checkAll();
	}
}
