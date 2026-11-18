<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\NoReply;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * « Sans réponse »: the messages sent some days ago that nobody answered (looked for in their
 * conversation, every folder), to send a reminder or to let go.
 */
class NoReplyController extends Controller {
	use ErrorHandling;

	/** conversations looked at in one go (the newest) */
	private const CHECKED = 40;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private IConfig $config,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/** @return list<string> */
	private function ignored(int $id): array {
		return NoReply::decodeIgnored($this->config->getUserValue((string)$this->userId, Application::APP_ID, NoReply::PREF . $id, '[]'));
	}

	#[NoAdminRequired]
	public function index(int $id, int $days = NoReply::DEFAULT_DAYS): Response {
		return $this->handle(function () use ($id, $days) {
			$days = NoReply::days($days);
			$account = $this->accounts->find((string)$this->userId, $id);
			$client = $this->accounts->getClient($account);
			$me = $account->getEmail();
			$now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-noreply');
			$out = [];
			foreach (array_slice(NoReply::candidates($client->recentlySent(), $now, $days, $this->ignored($id), $me), 0, self::CHECKED) as $m) {
				$key = $id . '|' . $m['id'];
				$answered = $cache->get($key);
				if ($answered === null) {
					$answered = $m['conversationId'] !== '' && NoReply::answered($client->conversation((string)$m['conversationId']), (string)$m['date'], $me);
					// an answer stays one; no answer yet is looked at again soon
					$cache->set($key, $answered, $answered ? 86400 : 600);
				}
				if (!$answered) {
					$out[] = ['id' => $m['id'], 'subject' => $m['subject'], 'displayTo' => $m['displayTo'], 'date' => $m['date'],
						'age' => NoReply::age((string)$m['date'], $now)];
				}
			}
			return ['messages' => $out, 'days' => $days, 'choices' => NoReply::DAYS];
		});
	}

	/** « Ignorer »: no longer listed. */
	#[NoAdminRequired]
	public function ignore(int $id, string $itemId): Response {
		return $this->handle(function () use ($id, $itemId) {
			$this->accounts->find((string)$this->userId, $id);
			$this->config->setUserValue((string)$this->userId, Application::APP_ID, NoReply::PREF . $id, (string)json_encode(NoReply::ignore($this->ignored($id), $itemId)));
			return ['ok' => true];
		});
	}
}
