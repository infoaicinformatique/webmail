<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * « Se désabonner »: leave the mailing list a message comes from, the way the list asks
 * (List-Unsubscribe): a POST in one click, or a message sent from the mailbox.
 */
class UnsubscribeService {
	public function __construct(
		private IClientService $http,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{method: string, url?: string, to?: string} what was done; « link »: the page to open
	 * @throws MailException
	 */
	public function unsubscribe(EwsClient $client, string $itemId): array {
		$how = $client->unsubscribeInfo($itemId);
		if ($how === null) {
			throw new MailException('Ce message ne permet pas de se désabonner');
		}
		if ($how['method'] === 'oneclick') {
			$this->oneClick((string)$how['url']);
			return ['method' => 'oneclick'];
		}
		if ($how['method'] === 'mailto') {
			$client->sendMessage('new', (string)$how['subject'], '<p>' . nl2br(htmlspecialchars((string)$how['body'], ENT_QUOTES)) . '</p>',
				[(string)$how['to']], [], []);
			return ['method' => 'mailto', 'to' => (string)$how['to']];
		}
		return ['method' => 'link', 'url' => (string)$how['url']];
	}

	/**
	 * RFC 8058: a POST with « List-Unsubscribe=One-Click », no cookie, no redirection followed.
	 * Nextcloud's client refuses addresses of the local network (unless allow_local_remote_servers).
	 */
	private function oneClick(string $url): void {
		try {
			$response = $this->http->newClient()->post($url, [
				'body' => 'List-Unsubscribe=One-Click',
				'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
				'timeout' => 20,
				'allow_redirects' => false,
				'http_errors' => false,
			]);
			$status = $response->getStatusCode();
		} catch (\Throwable $e) {
			$this->logger->info('Webmail: one-click unsubscribe failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			throw new MailException('Le site de la liste de diffusion ne répond pas. Réessayez plus tard.');
		}
		if ($status < 200 || $status >= 400) {
			throw new MailException('Le site de la liste de diffusion a refusé le désabonnement (erreur %s)', [(string)$status]);
		}
	}
}
