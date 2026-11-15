<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\HtmlSanitizer;
use OCA\Webmail\Service\SignatureSet;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** The other signatures of an account, and the one each kind of message starts with. */
class SignatureController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private IConfig $config,
		private LoggerInterface $logger,
		private \OCA\Webmail\Service\OrgSignatureService $org,
		private \OCP\IUserSession $session,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(function () use ($id) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$set = SignatureSet::decode($this->config->getUserValue((string)$this->userId, Application::APP_ID, 'signatures_' . $id, ''));
			// the signature of the organisation, filled in with the profile of the user
			$mode = $this->org->mode();
			$user = $this->session->getUser();
			$set['org'] = $mode !== 'off' && $user !== null ? ['mode' => $mode, 'html' => $this->org->forUser($user, $account->getEmail())] : null;
			return new JSONResponse($set);
		});
	}

	/** @param array<int, mixed> $list */
	#[NoAdminRequired]
	public function save(int $id, array $list = [], string $new = '', string $reply = ''): Response {
		return $this->handle(function () use ($id, $list, $new, $reply) {
			$this->accounts->find((string)$this->userId, $id);
			$set = SignatureSet::normalize($list, $new, $reply, fn (string $h) => HtmlSanitizer::signature($h));
			if ($set['list'] === [] && $set['new'] === SignatureSet::MAIN && $set['reply'] === SignatureSet::MAIN) {
				$this->config->deleteUserValue((string)$this->userId, Application::APP_ID, 'signatures_' . $id);
			} else {
				$this->config->setUserValue((string)$this->userId, Application::APP_ID, 'signatures_' . $id, json_encode($set));
			}
			return new JSONResponse($set);
		});
	}

	/** The signature of the organisation: its model and how it is used (administrators only). */
	public function saveOrg(string $template = '', string $mode = 'off'): Response {
		return $this->handle(function () use ($template, $mode) {
			$this->org->save($template, $mode);
			$user = $this->session->getUser();
			return new JSONResponse([
				'template' => $this->org->template(),
				'mode' => $this->org->mode(),
				'preview' => $user !== null ? $this->org->forUser($user, (string)$user->getEMailAddress()) : '',
			]);
		});
	}
}
