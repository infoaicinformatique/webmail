<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\HtmlSanitizer;
use OCA\Webmail\Service\RecurringService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Envois récurrents »: a message sent again at regular times; made, changed, paused, deleted. */
class RecurringController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private RecurringService $recurring,
		private HtmlSanitizer $sanitizer,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(fn () => ['items' => $this->recurring->list($this->accounts->find((string)$this->userId, $id), true)]);
	}

	/** Made or changed ($rid): the text cleaned as for a message sent. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 300)]
	public function save(int $id, string $to = '', string $cc = '', string $bcc = '', string $subject = '', string $bodyHtml = '',
		string $freq = '', int $weekday = 1, int $monthday = 1, string $time = '', string $tz = 'UTC', string $rid = ''): Response {
		return $this->handle(function () use ($id, $to, $cc, $bcc, $subject, $bodyHtml, $freq, $weekday, $monthday, $time, $tz, $rid) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$lists = array_map(fn ($v) => MailController::splitAddresses($v), [$to, $cc, $bcc]);
			foreach (array_merge(...$lists) as $addr) {
				if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
					throw new MailException('Adresse invalide : %s', [$addr]);
				}
			}
			$html = HtmlSanitizer::editorToHtml($this->sanitizer->sanitize($bodyHtml, [], true)['html']);
			$entry = $this->recurring->save($account, $rid, $lists[0], $lists[1], $lists[2], $subject, $html,
				['freq' => $freq, 'weekday' => $weekday, 'monthday' => $monthday, 'time' => $time, 'tz' => $tz]);
			return ['item' => $entry, 'items' => $this->recurring->list($account, true)];
		});
	}

	#[NoAdminRequired]
	public function pause(int $id, string $rid, bool $paused = true): Response {
		return $this->handle(function () use ($id, $rid, $paused) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$this->recurring->pause($account, $rid, $paused);
			return ['items' => $this->recurring->list($account, true)];
		});
	}

	#[NoAdminRequired]
	public function delete(int $id, string $rid): Response {
		return $this->handle(function () use ($id, $rid) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$this->recurring->delete($account, $rid);
			return ['items' => $this->recurring->list($account, true)];
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 300)]
	public function sendNow(int $id, string $rid): Response {
		return $this->handle(function () use ($id, $rid) {
			$this->recurring->sendNow($this->accounts->find((string)$this->userId, $id), $rid);
			return ['ok' => true];
		});
	}
}
