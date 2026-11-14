<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * « Envoyer sur Matrix »: a message or an event posted in a Matrix room (Element…), with the
 * user's own Matrix account. Its access token is kept encrypted in the user's preferences.
 * Nextcloud's HTTP client refuses the local network (unless allow_local_remote_servers).
 */
class MatrixService {
	private const API = '/_matrix/client/v3';

	public function __construct(
		private IConfig $config,
		private ICrypto $crypto,
		private IClientService $http,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{homeserver: string, hasToken: bool, room: string, alerts: string} */
	public function settings(string $userId): array {
		return [
			'homeserver' => $this->config->getUserValue($userId, Application::APP_ID, 'matrix_homeserver', ''),
			'hasToken' => $this->config->getUserValue($userId, Application::APP_ID, 'matrix_token', '') !== '',
			'room' => $this->config->getUserValue($userId, Application::APP_ID, 'matrix_room', ''),
			'alerts' => $this->config->getUserValue($userId, Application::APP_ID, 'matrix_alerts', 'off'),
		];
	}

	/**
	 * The Matrix account: homeserver, access token ('' keeps the one saved), default room.
	 * Checked with the server before it is kept.
	 *
	 * @return array{homeserver: string, hasToken: bool, room: string, alerts: string, user: string}
	 * @throws MailException
	 */
	public function save(string $userId, string $homeserver, string $token, string $room): array {
		$homeserver = Matrix::homeserver($homeserver);
		$token = trim($token);
		if ($token === '') {
			$token = $this->token($userId);
		}
		if ($token === '' || preg_match('/\s/', $token)) {
			throw new MailException('Indiquez le jeton d\'accès Matrix');
		}
		$room = trim($room);
		if ($room !== '') {
			$room = Matrix::room($room)['value'];
		}
		$user = $this->whoami($homeserver, $token);
		$this->config->setUserValue($userId, Application::APP_ID, 'matrix_homeserver', $homeserver);
		$this->config->setUserValue($userId, Application::APP_ID, 'matrix_token', $this->crypto->encrypt($token));
		$this->config->setUserValue($userId, Application::APP_ID, 'matrix_room', $room);
		return $this->settings($userId) + ['user' => $user];
	}

	/**
	 * Alerts of new messages in the default room: « off », « important », « all ».
	 *
	 * @throws MailException
	 */
	public function setAlerts(string $userId, string $mode): array {
		if (!in_array($mode, ['off', 'important', 'all'], true)) {
			throw new MailException('Choix invalide');
		}
		if ($mode !== 'off' && ($this->token($userId) === '' || $this->config->getUserValue($userId, Application::APP_ID, 'matrix_room', '') === '')) {
			throw new MailException('Indiquez d\'abord votre compte Matrix et un salon par défaut');
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'matrix_alerts', $mode);
		return $this->settings($userId);
	}

	public function forget(string $userId): void {
		foreach (['matrix_homeserver', 'matrix_token', 'matrix_room', 'matrix_alerts', 'matrix_alerts_seen'] as $k) {
			$this->config->deleteUserValue($userId, Application::APP_ID, $k);
		}
	}

	/**
	 * The rooms joined by the account, with their names (the first 50).
	 *
	 * @return list<array{id: string, name: string}>
	 * @throws MailException
	 */
	public function rooms(string $userId): array {
		[$hs, $token] = $this->account($userId);
		$ids = $this->call('GET', $hs, '/joined_rooms', $token)['joined_rooms'] ?? [];
		$rooms = [];
		foreach (array_slice(is_array($ids) ? $ids : [], 0, 50) as $id) {
			if (!is_string($id)) {
				continue;
			}
			$name = '';
			try {
				$name = (string)($this->call('GET', $hs, '/rooms/' . rawurlencode($id) . '/state/m.room.name', $token)['name'] ?? '');
			} catch (MailException) {
				// a room without a name (a direct conversation): its id
			}
			$rooms[] = ['id' => $id, 'name' => $name !== '' ? $name : $id];
		}
		usort($rooms, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return $rooms;
	}

	/**
	 * A text posted in a room (the default one when $room is '').
	 *
	 * @return array{room: string, eventId: string}
	 * @throws MailException
	 */
	public function send(string $userId, string $room, string $text): array {
		[$hs, $token] = $this->account($userId);
		$room = trim($room) !== '' ? trim($room) : $this->config->getUserValue($userId, Application::APP_ID, 'matrix_room', '');
		if ($room === '') {
			throw new MailException('Choisissez un salon Matrix');
		}
		$ref = Matrix::room($room);
		$roomId = $ref['type'] === 'id' ? $ref['value']
			: (string)($this->call('GET', $hs, '/directory/room/' . rawurlencode($ref['value']), $token)['room_id'] ?? '');
		if ($roomId === '') {
			throw new MailException('Salon Matrix introuvable : %s', [$ref['value']]);
		}
		$message = Matrix::message($text);
		$txn = Matrix::txnId($userId, $roomId, $message['body'], intdiv(time(), 60));
		$r = $this->call('PUT', $hs, '/rooms/' . rawurlencode($roomId) . '/send/m.room.message/' . $txn, $token, $message);
		return ['room' => $roomId, 'eventId' => (string)($r['event_id'] ?? '')];
	}

	/**
	 * A video room for a meeting, made with the user's Matrix account.
	 *
	 * @return string its id (« !abc:server »)
	 * @throws MailException
	 */
	public function createMeetingRoom(string $userId, string $subject, string $when): string {
		[$hs, $token] = $this->account($userId);
		$r = $this->call('POST', $hs, '/createRoom', $token, Matrix::meetingRoom($subject, $when));
		$id = (string)($r['room_id'] ?? '');
		if ($id === '') {
			throw new MailException('Le serveur Matrix n\'a pas créé le salon');
		}
		return $id;
	}

	/**
	 * The Element Web of the organisation among the « Sites externes » of Nextcloud:
	 * the first one whose config.json names a homeserver.
	 *
	 * @return ?array{name: string, url: string, homeserver: string, serverName: string}
	 */
	public function detectElement(): ?array {
		foreach (array_slice(Matrix::externalSites($this->config->getAppValue('external', 'sites', '')), 0, 5) as $site) {
			try {
				$response = $this->http->newClient()->get($site['url'] . '/config.json', [
					'timeout' => 5,
					'http_errors' => false,
					'verify' => $this->config->getAppValue(Application::APP_ID, 'verify_tls', 'yes') !== 'no',
				]);
				$config = json_decode((string)$response->getBody(), true);
			} catch (\Throwable $e) {
				continue;
			}
			$hs = is_array($config) && $response->getStatusCode() === 200 ? Matrix::elementHomeserver($config) : null;
			if ($hs !== null) {
				return $site + $hs;
			}
		}
		return null;
	}

	/** @return array{0: string, 1: string} homeserver, token */
	private function account(string $userId): array {
		$hs = $this->config->getUserValue($userId, Application::APP_ID, 'matrix_homeserver', '');
		$token = $this->token($userId);
		if ($hs === '' || $token === '') {
			throw new MailException('Connectez d\'abord votre compte Matrix (Comptes et signature)');
		}
		return [$hs, $token];
	}

	private function token(string $userId): string {
		$saved = $this->config->getUserValue($userId, Application::APP_ID, 'matrix_token', '');
		if ($saved === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($saved);
		} catch (\Throwable) {
			return '';
		}
	}

	/** The Matrix user of a token (« @alice:example.org »): checks the server and the token. */
	private function whoami(string $hs, string $token): string {
		$user = (string)($this->call('GET', $hs, '/account/whoami', $token)['user_id'] ?? '');
		if ($user === '') {
			throw new MailException('Le serveur Matrix n\'a pas reconnu ce jeton');
		}
		return $user;
	}

	/**
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 * @throws MailException
	 */
	private function call(string $method, string $hs, string $path, string $token, ?array $body = null): array {
		$options = [
			'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
			'timeout' => 15,
			'http_errors' => false,
			// (the same choice as for Exchange: an internal server with its own certificate)
			'verify' => $this->config->getAppValue(Application::APP_ID, 'verify_tls', 'yes') !== 'no',
		];
		if ($body !== null) {
			$options['body'] = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$options['headers']['Content-Type'] = 'application/json';
		}
		try {
			$client = $this->http->newClient();
			$response = match ($method) {
				'PUT' => $client->put($hs . self::API . $path, $options),
				'POST' => $client->post($hs . self::API . $path, $options),
				default => $client->get($hs . self::API . $path, $options),
			};
			$status = $response->getStatusCode();
			$data = json_decode((string)$response->getBody(), true);
		} catch (\Throwable $e) {
			$this->logger->info('Webmail: Matrix server ' . $hs . ' unreachable: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			throw new MailException('Le serveur Matrix ne répond pas');
		}
		$data = is_array($data) ? $data : [];
		if ($status === 401 || ($status === 403 && ($data['errcode'] ?? '') === 'M_UNKNOWN_TOKEN')) {
			throw new MailException('Le serveur Matrix n\'a pas reconnu ce jeton');
		}
		if ($status === 403) {
			throw new MailException('Matrix refuse : %s', [(string)($data['error'] ?? 'M_FORBIDDEN')]);
		}
		if ($status === 404) {
			throw new MailException('Introuvable sur le serveur Matrix');
		}
		if ($status < 200 || $status >= 300) {
			throw new MailException('Erreur du serveur Matrix (%s)', [(string)$status]);
		}
		return $data;
	}
}
