<?php

declare(strict_types=1);

namespace OCA\Webmail\Notification;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\DashboardService;
use OCA\Webmail\Service\MailNotificationService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
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
		private IFactory $l10nFactory,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Webmail';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'event_reminder') {
			return $this->prepareReminder($notification, $l);
		}
		if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'followup') {
			return $this->prepareFollowUp($notification, $l);
		}
		if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'task_reminder') {
			return $this->prepareTaskReminder($notification, $l);
		}
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== 'new_message') {
			// Nextcloud 30+ expects this exception, older versions an InvalidArgumentException
			throw class_exists(UnknownNotificationException::class) ? new UnknownNotificationException() : new \InvalidArgumentException();
		}
		$p = $notification->getSubjectParameters();
		[$subject, $message] = MailNotificationService::texts($p, (string)($notification->getMessageParameters()['preview'] ?? ''), $l);
		$sender = MailNotificationService::sender($p);
		$notification->setParsedSubject($subject)
			->setRichSubject(MailNotificationService::title($sender, $l, '{sender}'),
				$sender !== '' ? ['sender' => ['type' => 'highlight', 'id' => 'sender', 'name' => $sender]] : [])
			->setParsedMessage($message)
			->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $p['account'] ?? '', 'message' => $p['itemId'] ?? '']))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}

	/** "Tâche : Relancer le cabinet" — "Échéance : 20 nov. · user@…" */
	private function prepareTaskReminder(INotification $notification, IL10N $l): INotification {
		$p = $notification->getSubjectParameters();
		$subject = trim((string)($p['subject'] ?? '')) !== '' ? (string)$p['subject'] : $l->t('(sans titre)');
		$parts = [];
		$due = (string)($p['due'] ?? '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
			$parts[] = $l->t('Échéance : %s', [(string)$l->l('date', new \DateTime($due . 'T12:00:00Z'), ['width' => 'medium'])]);
		}
		$parts[] = (string)($p['email'] ?? '');
		$notification->setParsedSubject($l->t('Tâche : %s', [$subject]))
			->setRichSubject($l->t('Tâche : %s', ['{task}']), ['task' => ['type' => 'highlight', 'id' => 'task', 'name' => $subject]])
			->setParsedMessage(implode(' · ', array_filter($parts)))
			->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $p['account'] ?? '', 'module' => 'tasks', 'task' => $p['taskId'] ?? '']))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/checkmark.svg')));
		return $notification;
	}

	/** "Pas de réponse : Devis toiture" — "Envoyé le 10 nov. à jean@… · user@…" */
	private function prepareFollowUp(INotification $notification, IL10N $l): INotification {
		$p = $notification->getSubjectParameters();
		$subject = trim((string)($p['subject'] ?? '')) !== '' ? (string)$p['subject'] : $l->t('(sans objet)');
		$since = date_create_immutable((string)($p['since'] ?? '')) ?: new \DateTimeImmutable();
		$notification->setParsedSubject($l->t('Pas de réponse : %s', [$subject]))
			->setRichSubject($l->t('Pas de réponse : %s', ['{message}']), ['message' => ['type' => 'highlight', 'id' => 'message', 'name' => $subject]])
			->setParsedMessage($l->t('Envoyé le %1$s à %2$s', [(string)$l->l('date', \DateTime::createFromImmutable($since), ['width' => 'medium']), (string)($p['to'] ?? '')])
				. ' · ' . (string)($p['email'] ?? ''))
			->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $p['account'] ?? '']))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/mail.svg')));
		return $notification;
	}

	/** "Rappel : Comité de pilotage" — "Aujourd'hui 14:00 · Salle Riviera · user@…" */
	private function prepareReminder(INotification $notification, IL10N $l): INotification {
		$p = $notification->getSubjectParameters();
		$tz = $this->config->getUserValue($notification->getUser(), 'core', 'timezone', '') ?: date_default_timezone_get();
		try {
			$zone = new \DateTimeZone($tz);
		} catch (\Exception) {
			$zone = new \DateTimeZone('UTC');
		}
		$subject = trim((string)($p['subject'] ?? '')) !== '' ? (string)$p['subject'] : $l->t('(sans titre)');
		$parts = [DashboardService::label((string)($p['start'] ?? 'now'), (bool)($p['allDay'] ?? false), $zone, null, $l)];
		if (trim((string)($p['location'] ?? '')) !== '') {
			$parts[] = trim((string)$p['location']);
		}
		$parts[] = (string)($p['email'] ?? '');
		$notification->setParsedSubject($l->t('Rappel : %s', [$subject]))
			->setRichSubject($l->t('Rappel : %s', ['{event}']), ['event' => ['type' => 'highlight', 'id' => 'event', 'name' => $subject]])
			->setParsedMessage(implode(' · ', $parts))
			->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $p['account'] ?? '', 'module' => 'calendar', 'event' => $p['eventId'] ?? '']))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'places/calendar.svg')));
		return $notification;
	}
}
