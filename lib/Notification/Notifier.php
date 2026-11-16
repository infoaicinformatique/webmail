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
		if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'oof_scheduled') {
			return $this->prepareAbsence($notification, $l);
		}
		if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'booking_taken') {
			return $this->prepareBooking($notification, $l);
		}
		if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'reply_later') {
			$p = $notification->getSubjectParameters();
			$subject = trim((string)($p['subject'] ?? '')) !== '' ? (string)$p['subject'] : $l->t('(sans objet)');
			$notification->setParsedSubject($l->t('À répondre : %s', [$subject]))
				->setRichSubject($l->t('À répondre : %s', ['{message}']), ['message' => ['type' => 'highlight', 'id' => 'message', 'name' => $subject]])
				->setParsedMessage(((string)($p['from'] ?? '') !== '' ? $l->t('Message de %s, toujours sans réponse', [(string)$p['from']]) . ' · ' : '') . (string)($p['email'] ?? ''))
				->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $p['account'] ?? '', 'message' => $p['itemId'] ?? '']))
				->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/mail.svg')));
			return $notification;
		}
		if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'merge_done') {
			$p = $notification->getSubjectParameters();
			$failed = (int)($p['failed'] ?? 0);
			$notification->setParsedSubject($l->t('Envoi personnalisé terminé : %s', [(string)($p['subject'] ?? '')]))
				->setParsedMessage(($failed
					? $l->t('%1$s messages envoyés, %2$s en échec', [(int)($p['sent'] ?? 0), $failed])
					: $l->t('%s messages envoyés', [(int)($p['sent'] ?? 0)])) . ' · ' . (string)($p['email'] ?? ''))
				->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $p['account'] ?? '']))
				->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/mail.svg')));
			return $notification;
		}
		if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'digest') {
			return $this->prepareDigest($notification, $l);
		}
		if ($notification->getApp() === Application::APP_ID && $notification->getSubject() === 'update_available') {
			return $this->prepareUpdate($notification, $l);
		}
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== 'new_message') {
			// Nextcloud 30+ expects this exception, older versions an InvalidArgumentException
			throw class_exists(UnknownNotificationException::class) ? new UnknownNotificationException() : new \InvalidArgumentException();
		}
		$p = $notification->getSubjectParameters();
		[$subject, $message] = MailNotificationService::texts($p, (string)($notification->getMessageParameters()['preview'] ?? ''), $l);
		$sender = MailNotificationService::sender($p);
		$notification->setParsedSubject($subject)
			->setRichSubject((!empty($p['vip']) ? '⭐ ' : '') . MailNotificationService::title($sender, $l, '{sender}'),
				$sender !== '' ? ['sender' => ['type' => 'highlight', 'id' => 'sender', 'name' => $sender]] : [])
			->setParsedMessage($message)
			->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $p['account'] ?? '', 'message' => $p['itemId'] ?? '']))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}

	/**
	 * « Résumé du matin » — « 3 réunions (la première à 09:30 : Point projet) · 12 non lus, dont 2 importants ·
	 * 1 réponse à faire · 2 tâches · 1 message à suivre »
	 */
	private function prepareDigest(INotification $notification, IL10N $l): INotification {
		$p = $notification->getSubjectParameters();
		$n = fn (string $k) => (int)($p[$k] ?? 0);
		$parts = [];
		if ($n('meetings') > 0) {
			$text = $n('meetings') > 1 ? $l->t('%s réunions', [$n('meetings')]) : $l->t('1 réunion');
			if ((string)($p['first'] ?? '') !== '' && (string)($p['firstStart'] ?? '') !== '') {
				$tz = $this->config->getUserValue($notification->getUser(), 'core', 'timezone', '') ?: date_default_timezone_get();
				try {
					$zone = new \DateTimeZone($tz);
				} catch (\Exception) {
					$zone = new \DateTimeZone('UTC');
				}
				$at = (new \DateTime((string)$p['firstStart']))->setTimezone($zone)->format('H:i');
				$text .= ' (' . $l->t('la prochaine à %1$s : %2$s', [$at, (string)$p['first']]) . ')';
			}
			$parts[] = $text;
		}
		if ($n('unread') > 0) {
			$text = $n('unread') > 1 ? $l->t('%s non lus', [$n('unread')]) : $l->t('1 non lu');
			if ($n('important') > 0) {
				$text .= ', ' . ($n('important') > 1 ? $l->t('dont %s importants', [$n('important')]) : $l->t('dont 1 important'));
			}
			$parts[] = $text;
		}
		if ($n('toReply') > 0) {
			$parts[] = $n('toReply') > 1 ? $l->t('%s réponses à faire', [$n('toReply')]) : $l->t('1 réponse à faire');
		}
		if ($n('tasks') > 0) {
			$parts[] = $n('tasks') > 1 ? $l->t('%s tâches à faire', [$n('tasks')]) : $l->t('1 tâche à faire');
		}
		if ($n('flagged') > 0) {
			$parts[] = $n('flagged') > 1 ? $l->t('%s messages à suivre', [$n('flagged')]) : $l->t('1 message à suivre');
		}
		$notification->setParsedSubject('☀️ ' . $l->t('Votre journée'))
			->setParsedMessage(implode(' · ', $parts))
			->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?view=today')
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'places/calendar.svg')));
		return $notification;
	}

	/** "Réponse automatique programmée du 22 décembre au 2 janvier" — "user@… · compta@…" */
	private function prepareAbsence(INotification $notification, IL10N $l): INotification {
		$p = $notification->getSubjectParameters();
		$tz = $this->config->getUserValue($notification->getUser(), 'core', 'timezone', '') ?: date_default_timezone_get();
		try {
			$zone = new \DateTimeZone($tz);
		} catch (\Exception) {
			$zone = new \DateTimeZone('UTC');
		}
		$day = fn ($ts) => (string)$l->l('date', (new \DateTime('@' . (int)$ts))->setTimezone($zone), ['width' => 'long']);
		$notification->setParsedSubject($l->t('Réponse automatique programmée du %1$s au %2$s', [$day($p['start'] ?? 0), $day($p['end'] ?? 0)]))
			->setParsedMessage($l->t('Pour votre absence de Nextcloud : %s', [(string)($p['accounts'] ?? '')]))
			->setLink($this->url->linkToRouteAbsolute('webmail.page.index'))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'places/calendar.svg')));
		return $notification;
	}

	/** "Rendez-vous pris : Point projet" — "Jean Dupont (jean@…) · mardi 18 nov. 10:00" */
	/** « Webmail Exchange 1.154.0 est disponible » — « Version installée : 1.153.0 » */
	private function prepareUpdate(INotification $notification, IL10N $l): INotification {
		$p = $notification->getSubjectParameters();
		$version = (string)($p['version'] ?? '');
		$notification->setParsedSubject($l->t('Webmail Exchange %s est disponible', [$version]))
			->setParsedMessage($l->t('Version installée : %s. Nouveautés et commande de mise à jour dans les paramètres d\'administration.', [(string)($p['installed'] ?? '')]))
			->setLink($this->url->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'webmail']))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/download.svg')));
		return $notification;
	}

	private function prepareBooking(INotification $notification, IL10N $l): INotification {
		$p = $notification->getSubjectParameters();
		$tz = $this->config->getUserValue($notification->getUser(), 'core', 'timezone', '') ?: date_default_timezone_get();
		try {
			$zone = new \DateTimeZone($tz);
		} catch (\Exception) {
			$zone = new \DateTimeZone('UTC');
		}
		$subject = (string)($p['subject'] ?? '');
		$when = DashboardService::label((string)($p['start'] ?? 'now'), false, $zone, null, $l);
		$notification->setParsedSubject($l->t('Rendez-vous pris : %s', [$subject]))
			->setRichSubject($l->t('Rendez-vous pris : %s', ['{event}']), ['event' => ['type' => 'highlight', 'id' => 'event', 'name' => $subject]])
			->setParsedMessage((string)($p['name'] ?? '') . ' (' . (string)($p['email'] ?? '') . ') · ' . $when)
			->setLink($this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $p['account'] ?? '', 'module' => 'calendar']))
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', 'places/calendar.svg')));
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
		// a meeting with a visio (the chat, Talk, Teams…): joined from the bell
		$join = (string)($p['join'] ?? '');
		if (preg_match('~^https://~i', $join)) {
			$action = $notification->createAction();
			$action->setLabel('join')
				->setParsedLabel($l->t('Rejoindre'))
				->setLink($join, 'WEB')
				->setPrimary(true);
			$notification->addParsedAction($action);
		}
		return $notification;
	}
}
