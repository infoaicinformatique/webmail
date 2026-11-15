<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * The room of the chat made for a meeting (« Visio Element ») follows the meeting:
 * a new time is said in it (and becomes its topic), a cancelled meeting closes it
 * (« Annulée : … », nobody new joins by its link), and the participants are told
 * when the meeting begins. Only for the organiser's own meetings, with their account
 * of the chat; never blocking: the calendar is changed whatever the chat answers.
 */
class MeetingRoomService {
	/** « La réunion commence » is said from its start until this many minutes after */
	public const START_WINDOW_MIN = 10;

	public function __construct(
		private MatrixService $matrix,
		private IConfig $config,
		private IFactory $l10nFactory,
		private IDateTimeFormatter $formatter,
		private LoggerInterface $logger,
	) {
	}

	/** The room of the chat of an event (its link in the place or the description), for its organiser. */
	public static function roomOf(array $event): ?string {
		if (!($event['isOrganizer'] ?? false)) {
			return null;
		}
		return \OCA\Webmail\Service\Matrix::roomIdIn(($event['location'] ?? '') . ' ' . ($event['body'] ?? '') . ' ' . ($event['join'] ?? ''));
	}

	/** Did the meeting move? (both ends compared as instants: the same time written otherwise is no change) */
	public static function moved(array $before, string $start, string $end): bool {
		$same = function (?string $a, ?string $b): bool {
			try {
				return $a !== null && $b !== null && (new \DateTimeImmutable($a))->getTimestamp() === (new \DateTimeImmutable($b))->getTimestamp();
			} catch (\Exception) {
				return $a === $b;
			}
		};
		return !$same($before['start'] ?? null, $start) || !$same($before['end'] ?? null, $end);
	}

	/** The meeting moved: its new time said in the room, and as its topic. */
	public function rescheduled(string $userId, array $event): void {
		$room = self::roomOf($event);
		if ($room === null) {
			return;
		}
		$l = $this->l10n($userId);
		$when = $this->when($userId, $event, $l);
		$this->best($userId, $room, 'rescheduled', function () use ($userId, $room, $l, $when) {
			$this->matrix->roomState($userId, $room, 'm.room.topic', ['topic' => $when]);
			$this->matrix->roomNotice($userId, $room, $l->t('Nouvel horaire de la réunion : %s', [$when]));
		});
	}

	/** The meeting is cancelled (deleted by its organiser): said in the room, which is closed. */
	public function cancelled(string $userId, array $event): void {
		$room = self::roomOf($event);
		if ($room === null) {
			return;
		}
		$l = $this->l10n($userId);
		$subject = trim((string)($event['subject'] ?? '')) !== '' ? trim((string)$event['subject']) : $l->t('Réunion');
		$this->best($userId, $room, 'cancelled', function () use ($userId, $room, $l, $subject) {
			$this->matrix->roomNotice($userId, $room, $l->t('La réunion « %s » est annulée.', [$subject]));
			$this->matrix->roomState($userId, $room, 'm.room.name', ['name' => mb_substr($l->t('Annulée : %s', [$subject]), 0, 200)]);
			// (its link no longer lets anyone in)
			$this->matrix->roomState($userId, $room, 'm.room.join_rules', ['join_rule' => 'invite']);
		});
	}

	/** The meeting begins: the participants told in the room. */
	public function started(string $userId, array $event): void {
		$room = self::roomOf($event);
		if ($room === null) {
			return;
		}
		$l = $this->l10n($userId);
		$subject = trim((string)($event['subject'] ?? '')) !== '' ? trim((string)$event['subject']) : $l->t('Réunion');
		$this->best($userId, $room, 'started', fn () => $this->matrix->roomNotice($userId, $room,
			$l->t('La réunion « %s » commence : rejoignez l\'appel de ce salon.', [$subject])));
	}

	/**
	 * The rooms of the meetings beginning now, told once (the reminder job, every few minutes).
	 * @param array<int, array<string, mixed>> $events the coming events, with their « join » link
	 * @param array<string, string> $told object id => end of the meeting, kept by the caller
	 * @return array<string, string> $told, with the meetings told now and without those over
	 */
	public function tellStarts(string $userId, array $events, \DateTimeImmutable $now, array $told): array {
		$t = $now->getTimestamp();
		foreach ($told as $key => $end) {
			if ($end === '' || (new \DateTimeImmutable($end))->getTimestamp() <= $t) {
				unset($told[$key]);
			}
		}
		foreach ($events as $ev) {
			if (!empty($ev['allDay']) || !empty($ev['isCancelled']) || empty($ev['start']) || self::roomOf($ev) === null) {
				continue;
			}
			$start = (new \DateTimeImmutable((string)$ev['start']))->getTimestamp();
			$key = hash('sha256', $ev['id'] . '|' . $ev['start']);
			if ($t < $start || $t >= $start + self::START_WINDOW_MIN * 60 || isset($told[$key])) {
				continue;
			}
			$this->started($userId, $ev);
			$told[$key] = (string)($ev['end'] ?? '');
		}
		return $told;
	}

	private function best(string $userId, string $room, string $what, callable $do): void {
		try {
			$do();
		} catch (\Throwable $e) {
			// (no account of the chat, a room left, the chat out of reach: the calendar goes on)
			$this->logger->info('Webmail: room ' . $room . ' of a meeting not ' . $what . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	private function l10n(string $userId): IL10N {
		$lang = $this->config->getUserValue($userId, 'core', 'lang', '');
		return $this->l10nFactory->get(Application::APP_ID, $lang !== '' ? $lang : null);
	}

	/** « mardi 18 novembre 2026, 14:00 – 15:00 », in the organiser's time zone and language */
	private function when(string $userId, array $event, IL10N $l): string {
		$tz = $this->config->getUserValue($userId, 'core', 'timezone', '') ?: date_default_timezone_get();
		try {
			$zone = new \DateTimeZone($tz);
		} catch (\Exception) {
			$zone = new \DateTimeZone('UTC');
		}
		$start = new \DateTimeImmutable((string)$event['start']);
		$end = new \DateTimeImmutable((string)($event['end'] ?: $event['start']));
		if (!empty($event['allDay'])) {
			return $this->formatter->formatDate($start->getTimestamp(), 'full', $zone, $l);
		}
		return $this->formatter->formatDateTime($start->getTimestamp(), 'full', 'short', $zone, $l)
			. ' – ' . $this->formatter->formatTime($end->getTimestamp(), 'short', $zone, $l);
	}
}
