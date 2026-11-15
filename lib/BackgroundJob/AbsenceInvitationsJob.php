<?php

declare(strict_types=1);

namespace OCA\Webmail\BackgroundJob;

use OCA\Webmail\Service\AbsenceReplyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Every quarter of an hour: the invitations to meetings arrived during an absence of Nextcloud
 * (or to come in it) declined, or answered « provisoire », as the user chose.
 */
class AbsenceInvitationsJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AbsenceReplyService $absences,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->absences->answerAll();
	}
}
