<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\TimeZones;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Security\ISecureRandom;

/**
 * « Proposer des créneaux »: a few free slots of the organiser's calendar put in a message;
 * the person who receives it picks one on a public page (no account needed): the meeting is
 * then made in the organiser's calendar and Exchange sends them the invitation. A proposal
 * is kept in the organiser's settings, found by its secret token, for 14 days; once a slot
 * is taken, the others are no longer offered.
 */
class BookingService {
	public const TTL_DAYS = 14;
	/** at most this many proposals kept for a user (the oldest go first) */
	public const MAX = 30;
	private const PREFIX = 'booking_';

	public function __construct(
		private AccountService $accounts,
		private IConfig $config,
		private IURLGenerator $url,
		private ISecureRandom $random,
		private IManager $notifications,
		private IUserManager $users,
	) {
	}

	/**
	 * @return array{token: string, url: string, expires: string, tz: string, slots: list<array{start: string, end: string, url: string}>}
	 * @throws MailException
	 */
	public function propose(string $userId, int $accountId, string $subject, int $minutes, int $days, int $count, string $tz, ?\DateTimeImmutable $now = null): array {
		$account = $this->accounts->find($userId, $accountId);
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$tz = TimeZones::isValidIana($tz) ? $tz : 'UTC';
		$zone = new \DateTimeZone($tz);
		$calendar = new EwsCalendar($this->accounts->getClient($account));
		$busy = self::busy($calendar->findEvents($now, $now->modify('+' . (max(1, min(30, $days)) + 1) . ' days')));
		$slots = BookingRules::slots($busy, $now, $zone, $days, $minutes, $count);
		if ($slots === []) {
			throw new MailException('Aucun créneau libre sur cette période : essayez une période plus longue ou une durée plus courte');
		}
		$secret = $this->random->generate(24, ISecureRandom::CHAR_ALPHANUMERIC);
		$token = BookingRules::token($userId, $secret);
		$subject = mb_substr(trim($subject) !== '' ? trim($subject) : 'Rendez-vous', 0, 200);
		$data = [
			'account' => $account->getId(),
			'subject' => $subject,
			'minutes' => $minutes,
			'tz' => $tz,
			'slots' => $slots,
			'created' => $now->format(\DateTimeInterface::ATOM),
			'expires' => $now->modify('+' . self::TTL_DAYS . ' days')->format(\DateTimeInterface::ATOM),
			'taken' => null,
		];
		$this->prune($userId, $now);
		$this->config->setUserValue($userId, Application::APP_ID, self::PREFIX . $secret, (string)json_encode($data));
		$page = $this->url->linkToRouteAbsolute('webmail.booking.show', ['token' => $token]);
		return [
			'token' => $token,
			'url' => $page,
			'expires' => $data['expires'],
			'tz' => $tz,
			'slots' => array_map(fn ($s, $i) => $s + ['url' => $page . '?slot=' . $i], $slots, array_keys($slots)),
		];
	}

	/**
	 * A proposal seen from its public page.
	 * @return ?array{user: string, organizer: string, data: array<string, mixed>} null: unknown, or over
	 */
	public function find(string $token, ?\DateTimeImmutable $now = null): ?array {
		$parts = BookingRules::parse($token);
		if ($parts === null) {
			return null;
		}
		[$userId, $secret] = $parts;
		$user = $this->users->get($userId);
		if ($user === null || !$user->isEnabled()) {
			return null;
		}
		$data = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::PREFIX . $secret, ''), true);
		if (!is_array($data) || !is_array($data['slots'] ?? null)) {
			return null;
		}
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		if (new \DateTimeImmutable((string)$data['expires']) <= $now) {
			return null;
		}
		return ['user' => $userId, 'secret' => $secret, 'organizer' => $user->getDisplayName(), 'data' => $data];
	}

	/**
	 * A slot taken: the meeting made in the organiser's calendar, the guest invited by Exchange.
	 * @return array{start: string, end: string, subject: string}
	 * @throws MailException
	 */
	public function choose(string $token, int $index, string $name, string $email, ?\DateTimeImmutable $now = null): array {
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$found = $this->find($token, $now);
		if ($found === null) {
			throw new MailException('Cette proposition de rendez-vous n\'existe plus');
		}
		$data = $found['data'];
		if ($data['taken'] !== null) {
			throw new MailException('Un créneau de cette proposition a déjà été choisi');
		}
		$slot = $data['slots'][$index] ?? null;
		if ($slot === null) {
			throw new MailException('Créneau inconnu');
		}
		$name = mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', $name) ?? ''), 0, 100);
		$email = strtolower(trim($email));
		if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new MailException('Indiquez votre nom et une adresse e-mail valide');
		}
		$start = new \DateTimeImmutable($slot['start']);
		$end = new \DateTimeImmutable($slot['end']);
		if ($start->getTimestamp() <= $now->getTimestamp()) {
			throw new MailException('Ce créneau est passé : choisissez-en un autre');
		}
		$account = $this->accounts->find($found['user'], (int)$data['account']);
		$client = $this->accounts->getClient($account);
		$calendar = new EwsCalendar($client);
		// (taken meanwhile by another meeting)
		foreach (self::busy($calendar->findEvents($start, $end)) as $b) {
			if (new \DateTimeImmutable($b['start']) < $end && new \DateTimeImmutable($b['end']) > $start) {
				throw new MailException('Ce créneau vient d\'être pris : choisissez-en un autre');
			}
		}
		$zone = new \DateTimeZone((string)$data['tz']);
		$client->setTimeZone(TimeZones::toWindows((string)$data['tz']));
		$calendar->createEvent([
			'subject' => $data['subject'],
			'start' => $start->setTimezone($zone)->format('Y-m-d\TH:i:s'),
			'end' => $end->setTimezone($zone)->format('Y-m-d\TH:i:s'),
			'body' => $name . ' <' . $email . '>',
			'attendees' => [$email],
			'reminder' => 15,
		]);
		$data['taken'] = ['slot' => $index, 'name' => $name, 'email' => $email, 'at' => $now->format(\DateTimeInterface::ATOM)];
		$this->config->setUserValue($found['user'], Application::APP_ID, self::PREFIX . $found['secret'], (string)json_encode($data));
		$n = $this->notifications->createNotification();
		$n->setApp(Application::APP_ID)
			->setUser($found['user'])
			->setDateTime(\DateTime::createFromImmutable($now))
			->setObject('booking', $found['secret'])
			->setSubject('booking_taken', [
				'account' => $account->getId(),
				'subject' => $data['subject'],
				'name' => $name,
				'email' => $email,
				'start' => $slot['start'],
			]);
		$this->notifications->notify($n);
		return ['start' => $slot['start'], 'end' => $slot['end'], 'subject' => (string)$data['subject']];
	}

	/** The busy times of a calendar: not cancelled, not « free ». @return list<array{start: string, end: string}> */
	public static function busy(array $events): array {
		$out = [];
		foreach ($events as $e) {
			if (!empty($e['isCancelled']) || ($e['freeBusy'] ?? 'Busy') === 'Free' || empty($e['start'])) {
				continue;
			}
			$out[] = ['start' => (string)$e['start'], 'end' => (string)($e['end'] ?: $e['start'])];
		}
		return $out;
	}

	/** The proposals over go; the oldest go when there are too many. */
	private function prune(string $userId, \DateTimeImmutable $now): void {
		$keys = array_values(array_filter($this->config->getUserKeys($userId, Application::APP_ID), fn ($k) => str_starts_with($k, self::PREFIX)));
		$kept = [];
		foreach ($keys as $k) {
			$d = json_decode($this->config->getUserValue($userId, Application::APP_ID, $k, ''), true);
			if (!is_array($d) || new \DateTimeImmutable((string)($d['expires'] ?? '@0')) <= $now) {
				$this->config->deleteUserValue($userId, Application::APP_ID, $k);
			} else {
				$kept[$k] = (string)$d['created'];
			}
		}
		asort($kept);
		foreach (array_slice(array_keys($kept), 0, max(0, count($kept) - self::MAX + 1)) as $k) {
			$this->config->deleteUserValue($userId, Application::APP_ID, $k);
		}
	}
}
