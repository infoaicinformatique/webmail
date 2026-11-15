<?php

declare(strict_types=1);

namespace OCA\Webmail\Listener;

use OCA\Webmail\Service\AbsenceReplyService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\OutOfOfficeChangedEvent;
use OCP\User\Events\OutOfOfficeClearedEvent;
use OCP\User\Events\OutOfOfficeScheduledEvent;

/**
 * An absence saved or removed in Nextcloud (Paramètres personnels › Disponibilité › Absence):
 * the automatic replies of Exchange follow.
 *
 * @template-implements IEventListener<Event>
 */
class AbsenceListener implements IEventListener {
	public function __construct(
		private AbsenceReplyService $replies,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof OutOfOfficeScheduledEvent || $event instanceof OutOfOfficeChangedEvent) {
			$this->replies->scheduled($event->getData());
		} elseif ($event instanceof OutOfOfficeClearedEvent) {
			$this->replies->cleared($event->getData());
		}
	}
}
