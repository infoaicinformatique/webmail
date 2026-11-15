<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\BookingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * « Proposer des créneaux »: made from the message being written; chosen on a public page
 * by the person who received it (no Nextcloud account needed).
 */
class BookingController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private BookingService $bookings,
		private IDateTimeFormatter $formatter,
		private IL10N $l,
		private IURLGenerator $url,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 30, period: 300)]
	public function propose(int $id, string $subject = '', int $minutes = 30, int $days = 7, int $count = 4, string $tz = 'UTC'): Response {
		return $this->handle(fn () => $this->bookings->propose((string)$this->userId, $id, $subject, $minutes, $days, $count, $tz));
	}

	/** The public page: the slots still offered, a form to take one. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 300)]
	public function show(string $token, int $slot = -1): TemplateResponse {
		return $this->page($token, $slot);
	}

	/** A slot taken by the guest (the form of the public page). */
	#[PublicPage]
	#[AnonRateLimit(limit: 10, period: 300)]
	public function choose(string $token, int $slot = -1, string $name = '', string $email = ''): TemplateResponse {
		try {
			$done = $this->bookings->choose($token, $slot, $name, $email);
		} catch (MailException $e) {
			return $this->page($token, $slot, $e->translate($this->l), $name, $email);
		} catch (\Throwable $e) {
			$this->logger->warning('Webmail: a slot not taken', ['app' => Application::APP_ID, 'exception' => $e]);
			return $this->page($token, $slot, $this->l->t('Le rendez-vous n\'a pas pu être pris : réessayez plus tard'), $name, $email);
		}
		$found = $this->bookings->find($token);
		return $this->template([
			'state' => 'done',
			'subject' => $done['subject'],
			'organizer' => $found['organizer'] ?? '',
			'when' => $this->when($done['start'], $done['end'], (string)($found['data']['tz'] ?? 'UTC')),
			'email' => strtolower(trim($email)),
		]);
	}

	private function page(string $token, int $slot, string $error = '', string $name = '', string $email = ''): TemplateResponse {
		$found = $this->bookings->find($token);
		if ($found === null) {
			$r = $this->template(['state' => 'gone']);
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}
		$data = $found['data'];
		if ($data['taken'] !== null) {
			return $this->template(['state' => 'taken', 'subject' => $data['subject'], 'organizer' => $found['organizer']]);
		}
		$now = time();
		$slots = [];
		foreach ($data['slots'] as $i => $s) {
			if ((new \DateTimeImmutable($s['start']))->getTimestamp() > $now) {
				$slots[] = ['index' => $i, 'label' => $this->when($s['start'], $s['end'], (string)$data['tz'])];
			}
		}
		return $this->template([
			'state' => 'open',
			'subject' => $data['subject'],
			'organizer' => $found['organizer'],
			'minutes' => (int)$data['minutes'],
			'tz' => (string)$data['tz'],
			'slots' => $slots,
			'selected' => $slot,
			'error' => $error,
			'name' => $name,
			'email' => $email,
			'action' => $this->url->linkToRoute('webmail.booking.choose', ['token' => $token]),
		]);
	}

	private function template(array $params): TemplateResponse {
		Util::addStyle(Application::APP_ID, 'booking');
		return new TemplateResponse(Application::APP_ID, 'booking', $params, TemplateResponse::RENDER_AS_GUEST);
	}

	/** « mardi 18 novembre 2026, 10:00 – 10:30 », in the organiser's time zone */
	private function when(string $start, string $end, string $tz): string {
		try {
			$zone = new \DateTimeZone($tz);
		} catch (\Exception) {
			$zone = new \DateTimeZone('UTC');
		}
		$s = (new \DateTimeImmutable($start))->getTimestamp();
		$e = (new \DateTimeImmutable($end))->getTimestamp();
		return $this->formatter->formatDateTime($s, 'full', 'short', $zone, $this->l) . ' – ' . $this->formatter->formatTime($e, 'short', $zone, $this->l);
	}
}
