<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
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
		private ServerConfigService $servers,
		private ICacheFactory $cacheFactory,
		private ISecureRandom $random,
		private IUserManager $users,
	) {
	}

	/** how long a connection by Nextcloud (SSO) may take, from the click to the return */
	private const SSO_TTL = 600;

	/**
	 * The video meetings: those chosen by the administrator; else, when the organisation has
	 * its chat (an Element Web among the « Sites externes » of Nextcloud), a room of that chat
	 * made for each meeting — before Nextcloud Talk.
	 *
	 * @return array{visio: string, mode: string, auto: bool}
	 */
	public function effectiveVisio(): array {
		if ($this->servers->isVisioSet()) {
			return ['visio' => $this->servers->getVisio(), 'mode' => $this->servers->getVisioMode(), 'auto' => false];
		}
		$element = $this->element();
		return $element !== null
			? ['visio' => rtrim($element['url'], '/'), 'mode' => ServerConfigService::VISIO_ELEMENT_ROOM, 'auto' => true]
			: ['visio' => '', 'mode' => '', 'auto' => false];
	}

	/**
	 * The chat of the organisation (detectElement()), remembered an hour: asked for each page.
	 * @return ?array{name: string, url: string, homeserver: string, serverName: string}
	 */
	public function element(): ?array {
		$sites = $this->config->getAppValue('external', 'sites', '');
		if ($sites === '') {
			return null;
		}
		$cache = $this->cacheFactory->createDistributed('webmail-element');
		$key = md5($sites);
		$known = $cache->get($key);
		if (is_array($known)) {
			return $known['element'] ?? null;
		}
		$element = $this->detectElement();
		$cache->set($key, ['element' => $element], $element !== null ? 3600 : 600);
		return $element;
	}

	/**
	 * « Se connecter avec Nextcloud »: the address where the Matrix server signs the user in
	 * (by Nextcloud, its identity provider), then sends them back to $callback with a login token.
	 *
	 * @throws MailException
	 */
	public function ssoStart(string $userId, string $callback): string {
		$hs = $this->ssoHomeserver($userId);
		$state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->config->setUserValue($userId, Application::APP_ID, 'matrix_sso', json_encode(['state' => $state, 'hs' => $hs, 'at' => time()]));
		$back = $callback . (str_contains($callback, '?') ? '&' : '?') . 'state=' . $state;
		return $hs . self::API . '/login/sso/redirect?redirectUrl=' . rawurlencode($back);
	}

	/**
	 * The return of the Matrix server: its login token exchanged for an access token, kept as
	 * for an account entered by hand.
	 *
	 * @return array{homeserver: string, hasToken: bool, room: string, alerts: string, user: string}
	 * @throws MailException
	 */
	public function ssoFinish(string $userId, string $state, string $loginToken): array {
		$pending = json_decode($this->config->getUserValue($userId, Application::APP_ID, 'matrix_sso', ''), true);
		$this->config->deleteUserValue($userId, Application::APP_ID, 'matrix_sso');
		if (!is_array($pending) || !is_string($pending['state'] ?? null) || !hash_equals($pending['state'], $state)
			|| time() - (int)($pending['at'] ?? 0) > self::SSO_TTL || trim($loginToken) === '') {
			throw new MailException('La connexion au chat a expiré : recommencez');
		}
		$hs = (string)$pending['hs'];
		$r = $this->call('POST', $hs, '/login', '', [
			'type' => 'm.login.token',
			'token' => trim($loginToken),
			'initial_device_display_name' => 'Webmail Nextcloud',
		]);
		$token = (string)($r['access_token'] ?? '');
		if ($token === '') {
			throw new MailException('Le serveur Matrix n\'a pas ouvert la session');
		}
		return $this->save($userId, $hs, $token, $this->config->getUserValue($userId, Application::APP_ID, 'matrix_room', ''));
	}

	/** The Matrix server to sign in to: the user's, else the one of the chat of the organisation. */
	public function ssoHomeserver(string $userId): string {
		$saved = $this->config->getUserValue($userId, Application::APP_ID, 'matrix_homeserver', '');
		if ($saved !== '') {
			return $saved;
		}
		$element = $this->element();
		if ($element === null) {
			throw new MailException('Aucun chat Matrix (Element) n\'est déclaré dans les « Sites externes » de Nextcloud : indiquez le serveur et le jeton');
		}
		return Matrix::homeserver($element['homeserver']);
	}

	/** @return array{homeserver: string, hasToken: bool, room: string, alerts: string} */
	public function settings(string $userId): array {
		return [
			'homeserver' => $this->config->getUserValue($userId, Application::APP_ID, 'matrix_homeserver', ''),
			'hasToken' => $this->config->getUserValue($userId, Application::APP_ID, 'matrix_token', '') !== '',
			'room' => $this->config->getUserValue($userId, Application::APP_ID, 'matrix_room', ''),
			'alerts' => $this->config->getUserValue($userId, Application::APP_ID, 'matrix_alerts', 'off'),
			// the session is no longer known to the server: to open again
			'expired' => $this->config->getUserValue($userId, Application::APP_ID, 'matrix_expired', '') === '1',
			// the chat of the organisation: connection by Nextcloud offered
			'chat' => ($e = $this->element()) !== null ? ['name' => $e['name'], 'homeserver' => $e['homeserver']] : null,
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
		$this->config->deleteUserValue($userId, Application::APP_ID, 'matrix_expired');
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
		foreach (['matrix_homeserver', 'matrix_token', 'matrix_room', 'matrix_alerts', 'matrix_alerts_seen', 'matrix_sso', 'matrix_expired'] as $k) {
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
		$ids = $this->userCall($userId, 'GET', $hs, '/joined_rooms', $token)['joined_rooms'] ?? [];
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
			: (string)($this->userCall($userId, 'GET', $hs, '/directory/room/' . rawurlencode($ref['value']), $token)['room_id'] ?? '');
		if ($roomId === '') {
			throw new MailException('Salon Matrix introuvable : %s', [$ref['value']]);
		}
		$message = Matrix::message($text);
		$txn = Matrix::txnId($userId, $roomId, $message['body'], intdiv(time(), 60));
		$r = $this->userCall($userId, 'PUT', $hs, '/rooms/' . rawurlencode($roomId) . '/send/m.room.message/' . $txn, $token, $message);
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
		$r = $this->userCall($userId, 'POST', $hs, '/createRoom', $token, Matrix::meetingRoom($subject, $when));
		$id = (string)($r['room_id'] ?? '');
		if ($id === '') {
			throw new MailException('Le serveur Matrix n\'a pas créé le salon');
		}
		return $id;
	}

	/**
	 * The participants of a meeting invited in its room of the chat: those with a Nextcloud
	 * account (so an account of the chat, by its single sign-on). Their Matrix id is checked
	 * on the server first; the others (outside guests) keep the link of the invitation.
	 *
	 * @param string[] $emails
	 * @return array{invited: list<string>, notFound: list<string>}
	 * @throws MailException
	 */
	public function inviteToRoom(string $userId, string $roomId, array $emails): array {
		[$hs, $token] = $this->account($userId);
		if (!preg_match('~^![^\s:/]+:[a-z0-9.\-\[\]:]+$~i', $roomId)) {
			throw new MailException('Salon Matrix introuvable : %s', [$roomId]);
		}
		$me = (string)($this->userCall($userId, 'GET', $hs, '/account/whoami', $token)['user_id'] ?? '');
		$serverName = substr($me, (int)strpos($me, ':') + 1);
		$invited = $notFound = [];
		foreach (PresenceService::addresses($emails) as $email) {
			$matrixId = null;
			foreach ($this->users->getByEmail($email) as $user) {
				if (!$user->isEnabled() || $user->getUID() === $userId) {
					continue 2; // (oneself: already in the room)
				}
				foreach (Matrix::candidates($user->getUID(), $email, $serverName) as $candidate) {
					try {
						// (the session was checked just above: an answer « unknown » is the account's)
						$this->call('GET', $hs, '/profile/' . rawurlencode($candidate), $token);
						$matrixId = $candidate;
						break 2;
					} catch (MatrixTokenException) {
						throw new MailException('Votre session du chat a expiré : reconnectez-vous avec « Se connecter avec Nextcloud » (Comptes et signature)');
					} catch (MailException) {
						// no such account on the chat: the next one
					}
				}
			}
			if ($matrixId === null) {
				$notFound[] = $email;
				continue;
			}
			try {
				$this->userCall($userId, 'POST', $hs, '/rooms/' . rawurlencode($roomId) . '/invite', $token, ['user_id' => $matrixId]);
				$invited[] = $email;
			} catch (MailException $e) {
				// already invited or in the room: as good as invited
				if (str_contains($e->getMessage(), 'already') || str_contains($e->getMessage(), 'déjà')) {
					$invited[] = $email;
				} else {
					$notFound[] = $email;
				}
			}
		}
		return ['invited' => $invited, 'notFound' => $notFound];
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

	/**
	 * A request with the user's session: when the server no longer knows it (closed in
	 * Element, ended with the Nextcloud session…), it is marked so, to be opened again.
	 *
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 */
	private function userCall(string $userId, string $method, string $hs, string $path, string $token, ?array $body = null): array {
		try {
			return $this->call($method, $hs, $path, $token, $body);
		} catch (MatrixTokenException) {
			$this->config->setUserValue($userId, Application::APP_ID, 'matrix_expired', '1');
			if ($this->element() !== null) {
				throw new MailException('Votre session du chat a expiré : reconnectez-vous avec « Se connecter avec Nextcloud » (Comptes et signature)');
			}
			throw new MailException('Votre session Matrix a expiré : indiquez un nouveau jeton (Comptes et signature)');
		}
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
			'headers' => $token !== '' ? ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'] : ['Accept' => 'application/json'],
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
			throw new MatrixTokenException('Le serveur Matrix n\'a pas reconnu ce jeton');
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
