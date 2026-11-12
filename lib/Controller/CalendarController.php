<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\TimeZones;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Exchange calendar. "tz" is the browser's IANA time zone (e.g. Europe/Paris):
 * wall-clock times sent by the UI are interpreted in it.
 */
class CalendarController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	private function calendar(int $id, string $tz): EwsCalendar {
		$client = $this->accounts->getClient($this->accounts->find((string)$this->userId, $id));
		$client->setTimeZone(TimeZones::toWindows($tz));
		return new EwsCalendar($client);
	}

	private static function date(string $value): \DateTimeImmutable {
		try {
			return new \DateTimeImmutable($value);
		} catch (\Exception) {
			throw new MailException('Date invalide : ' . $value);
		}
	}

	#[NoAdminRequired]
	public function events(int $id, string $start, string $end, string $tz = 'UTC'): Response {
		return $this->handle(fn () => ['events' => $this->calendar($id, $tz)->findEvents(self::date($start), self::date($end))]);
	}

	#[NoAdminRequired]
	public function event(int $id, string $eventId, string $tz = 'UTC'): Response {
		return $this->handle(fn () => $this->calendar($id, $tz)->getEvent($eventId));
	}

	/**
	 * @param array<string, mixed> $event
	 */
	#[NoAdminRequired]
	public function create(int $id, array $event, string $tz = 'UTC'): Response {
		return $this->handle(fn () => ['id' => $this->calendar($id, $tz)->createEvent($event)]);
	}

	/**
	 * The recurring series of an occurrence, with its recurrence rule.
	 */
	#[NoAdminRequired]
	public function series(int $id, string $eventId, string $tz = 'UTC'): Response {
		return $this->handle(fn () => $this->calendar($id, $tz)->getSeries($eventId));
	}

	/**
	 * @param array<string, mixed> $event
	 * @param bool $series true: $eventId is a series master, the recurrence rule is updated too
	 */
	#[NoAdminRequired]
	public function update(int $id, string $eventId, array $event, string $tz = 'UTC', bool $series = false): Response {
		return $this->handle(function () use ($id, $eventId, $event, $tz, $series) {
			$this->calendar($id, $tz)->updateEvent($eventId, $event, $series);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function delete(int $id, string $eventId, bool $series = false): Response {
		return $this->handle(function () use ($id, $eventId, $series) {
			$this->calendar($id, 'UTC')->deleteEvent($eventId, $series);
			return ['status' => 'ok'];
		});
	}

	/**
	 * @param string $eventId calendar item or meeting request (e-mail) id
	 */
	#[NoAdminRequired]
	public function respond(int $id, string $eventId, string $response, string $message = ''): Response {
		return $this->handle(function () use ($id, $eventId, $response, $message) {
			$this->calendar($id, 'UTC')->respond($eventId, $response, $message);
			return ['status' => 'ok'];
		});
	}
}
