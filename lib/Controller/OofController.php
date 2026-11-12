<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsOof;
use OCA\Webmail\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Automatic replies (out of office) of an account.
 */
class OofController extends Controller {
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

	private function oof(int $id): EwsOof {
		$account = $this->accounts->find((string)$this->userId, $id);
		return new EwsOof($this->accounts->getClient($account), $account->getEmail());
	}

	#[NoAdminRequired]
	public function show(int $id): Response {
		return $this->handle(fn () => $this->oof($id)->get());
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 300)]
	public function update(int $id, string $state, string $audience = 'None', string $start = '', string $end = '',
		string $internal = '', string $external = ''): Response {
		return $this->handle(function () use ($id, $state, $audience, $start, $end, $internal, $external) {
			$oof = $this->oof($id);
			$oof->set(['state' => $state, 'audience' => $audience, 'start' => $start, 'end' => $end, 'internal' => $internal, 'external' => $external]);
			return $oof->get();
		});
	}
}
