<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\Matrix;
use OCA\Webmail\Service\MatrixService;
use OCA\Webmail\Service\ServerConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The user's Matrix account (Element…): « Envoyer sur Matrix ».
 */
class MatrixController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private MatrixService $matrix,
		private ServerConfigService $servers,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/** The account (never its token). */
	#[NoAdminRequired]
	public function index(): Response {
		return $this->handle(fn () => $this->matrix->settings((string)$this->userId));
	}

	#[NoAdminRequired]
	public function save(string $homeserver, string $token = '', string $room = ''): Response {
		return $this->handle(fn () => $this->matrix->save((string)$this->userId, $homeserver, $token, $room));
	}

	#[NoAdminRequired]
	public function alerts(string $alerts): Response {
		return $this->handle(fn () => $this->matrix->setAlerts((string)$this->userId, $alerts));
	}

	#[NoAdminRequired]
	public function forget(): Response {
		return $this->handle(function () {
			$this->matrix->forget((string)$this->userId);
			return $this->matrix->settings((string)$this->userId);
		});
	}

	#[NoAdminRequired]
	public function rooms(): Response {
		return $this->handle(fn () => ['rooms' => $this->matrix->rooms((string)$this->userId)]);
	}

	#[NoAdminRequired]
	public function send(string $text, string $room = ''): Response {
		return $this->handle(fn () => $this->matrix->send((string)$this->userId, $room, $text));
	}

	/**
	 * « Visio Element » with an Element Web without Element Call: a video room made for the
	 * meeting with the user's Matrix account, and its link in Element Web.
	 */
	#[NoAdminRequired]
	public function meeting(string $subject = '', string $when = ''): Response {
		return $this->handle(function () use ($subject, $when) {
			$visio = $this->matrix->effectiveVisio();
			if ($visio['mode'] !== ServerConfigService::VISIO_ELEMENT_ROOM || $visio['visio'] === '') {
				throw new MailException('La visio Element n\'est pas configurée par l\'administrateur');
			}
			$room = $this->matrix->createMeetingRoom((string)$this->userId, $subject, $when);
			return ['roomId' => $room['id'], 'url' => Matrix::roomLink($visio['visio'], $room['id'], $room['via'])];
		});
	}

	/**
	 * The participants with an account of the chat invited in the room of the meeting
	 * (found in its place or description).
	 * @param string[] $emails
	 */
	#[NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 30, period: 300)]
	public function invite(string $text = '', array $emails = []): Response {
		return $this->handle(function () use ($text, $emails) {
			$roomId = Matrix::roomIdIn($text) ?? throw new MailException('Aucun salon visio du chat dans cet événement');
			return $this->matrix->inviteToRoom((string)$this->userId, $roomId, array_slice($emails, 0, 100));
		});
	}

	/**
	 * « Se connecter avec Nextcloud »: to the Matrix server, which signs the user in through
	 * Nextcloud (the same account) and sends them back to ssoCallback.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ssoStart(): Response {
		try {
			return new RedirectResponse($this->matrix->ssoStart((string)$this->userId, $this->urls->linkToRouteAbsolute('webmail.matrix.ssoCallback')));
		} catch (MailException $e) {
			return $this->back('error', $this->errorMessage($e));
		}
	}

	/** The return of the Matrix server, with its login token. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ssoCallback(string $state = '', string $loginToken = ''): Response {
		try {
			$this->matrix->ssoFinish((string)$this->userId, $state, $loginToken);
			return $this->back('connected');
		} catch (MailException $e) {
			return $this->back('error', $this->errorMessage($e));
		}
	}

	private function back(string $result, string $message = ''): RedirectResponse {
		$query = ['matrix' => $result] + ($message !== '' ? ['matrixMessage' => $message] : []);
		return new RedirectResponse($this->urls->linkToRoute('webmail.page.index') . '?' . http_build_query($query));
	}
}
