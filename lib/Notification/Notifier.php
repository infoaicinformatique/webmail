<?php

declare(strict_types=1);

namespace OCA\Webmail\Notification;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\MailNotificationService;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Text and link of the new message notifications (MailNotificationService).
 */
class Notifier implements INotifier {
	public function __construct(
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Webmail';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== 'new_message') {
			// Nextcloud 30+ expects this exception, older versions an InvalidArgumentException
			throw class_exists(UnknownNotificationException::class) ? new UnknownNotificationException() : new \InvalidArgumentException();
		}
		$p = $notification->getSubjectParameters();
		[$subject, $message] = MailNotificationService::texts($p, (string)($notification->getMessageParameters()['preview'] ?? ''));
		$notification->setParsedSubject($subject)
			->setRichSubject(MailNotificationService::sender($p) !== '' ? 'Nouveau message ' . MailNotificationService::fromWord(MailNotificationService::sender($p)) . '{sender}' : 'Nouveau message',
				MailNotificationService::sender($p) !== '' ? ['sender' => ['type' => 'highlight', 'id' => 'sender', 'name' => MailNotificationService::sender($p)]] : [])
			->setParsedMessage($message)
			->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $p['account'] ?? '', 'message' => $p['itemId'] ?? '']))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}
