<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\VipList;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Expéditeurs prioritaires » of an account, and their messages of the inbox. */
class VipController extends Controller {
	use ErrorHandling;

	/** messages of each priority sender read for « ⭐ Prioritaires » */
	private const PER_SENDER = 25;
	private const SHOWN = 100;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private IConfig $config,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/** @return list<string> */
	private function list(int $id): array {
		$this->accounts->find((string)$this->userId, $id);
		return VipList::decode($this->config->getUserValue((string)$this->userId, Application::APP_ID, VipList::PREF . $id, '[]'));
	}

	#[NoAdminRequired]
	public function index(int $id): Response {
		return $this->handle(fn () => ['vip' => $this->list($id)]);
	}

	#[NoAdminRequired]
	public function set(int $id, string $email, bool $vip = true): Response {
		return $this->handle(function () use ($id, $email, $vip) {
			try {
				$list = VipList::set($this->list($id), $email, $vip);
			} catch (\InvalidArgumentException $e) {
				throw $e->getMessage() === 'too many'
					? new MailException('Trop d\'expéditeurs prioritaires (%s au plus)', [VipList::MAX])
					: new MailException('Adresse invalide : %s', [$email]);
			}
			if ($list === []) {
				$this->config->deleteUserValue((string)$this->userId, Application::APP_ID, VipList::PREF . $id);
			} else {
				$this->config->setUserValue((string)$this->userId, Application::APP_ID, VipList::PREF . $id, (string)json_encode($list));
			}
			return ['vip' => $list];
		});
	}

	/** The messages of the priority senders in the inbox, the newest first. */
	#[NoAdminRequired]
	public function messages(int $id): Response {
		return $this->handle(function () use ($id) {
			$list = $this->list($id);
			$client = $this->accounts->getClient($this->accounts->find((string)$this->userId, $id));
			$items = [];
			foreach ($list as $email) {
				foreach ($client->findItems('inbox', 0, self::PER_SENDER, 'from:"' . $email . '"')['items'] as $m) {
					// (the search of Exchange is wide: only theirs)
					if (VipList::has([$email], $m['from'] ?? null)) {
						$items[(string)$m['id']] = $m;
					}
				}
			}
			$items = array_values($items);
			usort($items, fn ($a, $b) => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
			return ['messages' => array_slice($items, 0, self::SHOWN), 'vip' => $list];
		});
	}
}
