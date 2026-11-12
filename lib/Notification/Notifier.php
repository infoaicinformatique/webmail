<?php

declare(strict_types=1);

namespace OCA\Webmail\Notification;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\DashboardService;
use OCA\Webmail\Service\MailNotificationService;
use OCP\IConfig;
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
		private IConfig $config,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Webmail';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'event_reminder') {
			return $this->prepareReminder($notification);
		}
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

	/** "Rappel : Comité de pilotage" — "Aujourd'hui 14:00 · Salle Riviera · user@…" */
	private function prepareReminder(INotification $notification): INotification {
		$p = $notification->getSubjectParameters();
		$tz = $this->config->getUserValue($notification->getUser(), 'core', 'timezone', '') ?: date_default_timezone_get();
		try {
			$zone = new \DateTimeZone($tz);
		} catch (\Exception) {
			$zone = new \DateTimeZone('UTC');
		}
		$subject = trim((string)($p['subject'] ?? '')) !== '' ? (string)$p['subject'] : '(sans titre)';
		$parts = [DashboardService::label((string)($p['start'] ?? 'now'), (bool)($p['allDay'] ?? false), $zone)];
		if (trim((string)($p['location'] ?? '')) !== '') {
			$parts[] = trim((string)$p['location']);
		}
		$parts[] = (string)($p['email'] ?? '');
		$notification->setParsedSubject('Rappel : ' . $subject)
			->setRichSubject('Rappel : {event}', ['event' => ['type' => 'highlight', 'id' => 'event', 'name' => $subject]])
			->setParsedMessage(implode(' · ', $parts))
			->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $p['account'] ?? '', 'module' => 'calendar', 'event' => $p['eventId'] ?? '']))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'places/calendar.svg')));
		return $notification;
	}
}
