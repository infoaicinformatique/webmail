<?php

declare(strict_types=1);

namespace OCA\Webmail\Listener;

use OCA\Webmail\Db\AccountMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private AccountMapper $mapper,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$this->mapper->deleteAllForUser($event->getUser()->getUID());
		}
	}
}
