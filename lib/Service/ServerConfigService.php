<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;

/**
 * Admin-defined mapping "e-mail domain => Exchange (EWS) server".
 *
 * Stored as JSON in the app config, e.g.
 * [{"domain":"societe-a.fr","label":"OVH ex3","url":"https://ex3.mail.ovh.net/EWS/Exchange.asmx"},
 *  {"domain":"societe-b.fr","label":"OVH ex5","url":"https://ex5.mail.ovh.net/EWS/Exchange.asmx"}]
 * The domain "*" is used as a fallback for any other domain.
 */
class ServerConfigService {
	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * @return array<int, array{domain: string, label: string, url: string}>
	 */
	public function getServers(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, 'servers', '[]');
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return [];
		}
		$out = [];
		foreach ($data as $s) {
			if (!is_array($s) || empty($s['domain']) || empty($s['url'])) {
				continue;
			}
			try {
				// also normalises values written with "occ config:app:set"
				$url = self::normalizeUrl((string)$s['url']);
			} catch (MailException) {
				continue; // never contact a non-HTTPS or malformed URL
			}
			$out[] = ['domain' => strtolower(ltrim(trim((string)$s['domain']), '@')), 'label' => (string)($s['label'] ?? ''), 'url' => $url];
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $servers
	 * @return array<int, array{domain: string, label: string, url: string}>
	 * @throws MailException
	 */
	public function setServers(array $servers): array {
		$clean = [];
		$seen = [];
		foreach ($servers as $s) {
			$domain = strtolower(trim((string)($s['domain'] ?? '')));
			$domain = ltrim($domain, '@');
			$url = trim((string)($s['url'] ?? ''));
			if ($domain === '' && $url === '') {
				continue;
			}
			if ($domain !== '*' && !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
				throw new MailException('Domaine invalide : %s', [$domain]);
			}
			if (isset($seen[$domain])) {
				throw new MailException('Domaine en double : %s', [$domain]);
			}
			$seen[$domain] = true;
			$clean[] = [
				'domain' => $domain,
				'label' => trim((string)($s['label'] ?? '')),
				'url' => self::normalizeUrl($url),
			];
		}
		$this->config->setAppValue(Application::APP_ID, 'servers', json_encode($clean, JSON_UNESCAPED_SLASHES));
		return $clean;
	}

	/**
	 * Accepts a bare host ("ex3.mail.ovh.net") or a full EWS URL.
	 * @throws MailException
	 */
	public static function normalizeUrl(string $url): string {
		$url = trim($url);
		if ($url === '') {
			throw new MailException('Adresse du serveur manquante');
		}
		if (!preg_match('#^https?://#i', $url)) {
			$url = 'https://' . $url;
		}
		$parts = parse_url($url);
		if ($parts === false || empty($parts['host'])) {
			throw new MailException('Adresse de serveur invalide : %s', [$url]);
		}
		if (strtolower($parts['scheme'] ?? '') !== 'https') {
			throw new MailException('Le serveur Exchange doit être en HTTPS : %s', [$url]);
		}
		$path = $parts['path'] ?? '';
		if ($path === '' || $path === '/') {
			$path = '/EWS/Exchange.asmx';
		}
		return 'https://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
	}

	/**
	 * @throws MailException when no server is configured for this address
	 */
	public function resolveUrl(string $email): string {
		$url = $this->findUrl($email);
		if ($url !== null) {
			return $url;
		}
		throw new MailException('Aucun serveur Exchange n\'est configuré pour le domaine « %s ». Contactez votre administrateur.', [self::domainOf($email)]);
	}

	/**
	 * The server of an address: the one set for its domain, else the one
	 * found by Autodiscover for it, else the default one (« * »).
	 */
	public function findUrl(string $email): ?string {
		$domain = self::domainOf($email);
		$fallback = null;
		foreach ($this->getServers() as $s) {
			if ($s['domain'] === $domain) {
				return $s['url'];
			}
			if ($s['domain'] === '*') {
				$fallback = $s['url'];
			}
		}
		return $this->getDiscovered()[$domain] ?? $fallback;
	}

	public static function domainOf(string $email): string {
		return strtolower(substr((string)strrchr($email, '@'), 1));
	}

	// ------------------------------------------------------------------
	// Autodiscover: the server of a domain missing from the table, found
	// the way Outlook does; only on servers whose name the administrator
	// allows (by default OVH's: mail.ovh.net)
	// ------------------------------------------------------------------

	public const DEFAULT_AUTODISCOVER_HOSTS = ['mail.ovh.net'];

	/** @return array{enabled: bool, hosts: string[]} */
	public function getAutodiscover(): array {
		$hosts = json_decode($this->config->getAppValue(Application::APP_ID, 'autodiscover_hosts', ''), true);
		return [
			'enabled' => $this->config->getAppValue(Application::APP_ID, 'autodiscover', 'no') === 'yes',
			'hosts' => is_array($hosts) ? array_values(array_filter(array_map('strval', $hosts))) : self::DEFAULT_AUTODISCOVER_HOSTS,
		];
	}

	/**
	 * @param string[] $hosts host names (or the end of them: « mail.ovh.net »)
	 * @return array{enabled: bool, hosts: string[]}
	 * @throws MailException
	 */
	public function setAutodiscover(bool $enabled, array $hosts): array {
		$clean = [];
		foreach ($hosts as $h) {
			$h = strtolower(trim(ltrim(trim((string)$h), '*.')));
			if ($h === '') {
				continue;
			}
			if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $h)) {
				throw new MailException('Nom de serveur invalide : %s', [$h]);
			}
			$clean[$h] = true;
		}
		if ($enabled && $clean === []) {
			throw new MailException('Indiquez au moins un serveur autorisé pour la découverte automatique (par exemple mail.ovh.net)');
		}
		$this->config->setAppValue(Application::APP_ID, 'autodiscover', $enabled ? 'yes' : 'no');
		$this->config->setAppValue(Application::APP_ID, 'autodiscover_hosts', json_encode(array_keys($clean)));
		return $this->getAutodiscover();
	}

	// ------------------------------------------------------------------
	// Security and limits of the whole server
	// ------------------------------------------------------------------

	/** Default size of the attachments of one message (MB): the usual limit of Exchange. */
	public const MAX_ATTACHMENTS_MB_DEFAULT = 25;
	/** Largest value an administrator may set (MB): Exchange Online stops at 150. */
	public const MAX_ATTACHMENTS_MB_LIMIT = 150;

	/** Whether the certificates of the Exchange and Matrix servers are checked (yes unless an administrator said no). */
	public function getVerifyTls(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'verify_tls', 'yes') !== 'no';
	}

	public function setVerifyTls(bool $verify): void {
		$this->config->setAppValue(Application::APP_ID, 'verify_tls', $verify ? 'yes' : 'no');
	}

	/** Most attachments of one message, in MB. */
	public function getMaxAttachmentsMb(): int {
		return self::attachmentsMb($this->config->getAppValue(Application::APP_ID, 'max_attachments_mb', ''));
	}

	/** The size kept, the default when none or a wrong one is set (by occ). */
	public static function attachmentsMb(string $raw): int {
		$mb = ctype_digit(trim($raw)) ? (int)trim($raw) : 0;
		return $mb >= 1 && $mb <= self::MAX_ATTACHMENTS_MB_LIMIT ? $mb : self::MAX_ATTACHMENTS_MB_DEFAULT;
	}

	public function getMaxAttachmentsBytes(): int {
		return $this->getMaxAttachmentsMb() * 1024 * 1024;
	}

	/** @throws MailException */
	public function setMaxAttachmentsMb(int $mb): int {
		if ($mb < 1 || $mb > self::MAX_ATTACHMENTS_MB_LIMIT) {
			throw new MailException('Taille maximale des pièces jointes : entre %1$s et %2$s Mo', [1, self::MAX_ATTACHMENTS_MB_LIMIT]);
		}
		$this->config->setAppValue(Application::APP_ID, 'max_attachments_mb', (string)$mb);
		return $mb;
	}

	// ------------------------------------------------------------------
	// Video meetings: Element Call (Matrix), or any service with a room name in its links (Jitsi…)
	// ------------------------------------------------------------------

	/** The video meetings are rooms of Element Web made for each meeting (not links of Element Call). */
	public const VISIO_ELEMENT_ROOM = 'element-room';

	/** '' (Element Call or an address with {room}) or VISIO_ELEMENT_ROOM. */
	public function getVisioMode(): string {
		return $this->config->getAppValue(Application::APP_ID, 'visio_mode', '') === self::VISIO_ELEMENT_ROOM ? self::VISIO_ELEMENT_ROOM : '';
	}

	public function setVisioMode(string $mode): string {
		$mode = $mode === self::VISIO_ELEMENT_ROOM ? $mode : '';
		$this->config->setAppValue(Application::APP_ID, 'visio_mode', $mode);
		return $mode;
	}

	/** The address of the video meetings, '' when none is set. */
	public function getVisio(): string {
		return $this->config->getAppValue(Application::APP_ID, 'visio', '');
	}

	/** @throws MailException */
	public function setVisio(string $url): string {
		$url = self::visioUrl($url);
		$this->config->setAppValue(Application::APP_ID, 'visio', $url);
		return $url;
	}

	/**
	 * '' (none), an Element Call address (« https://call.element.io »: its links are
	 * …/room/#/<name>?password=<secret>), or any https address with « {room} » where the
	 * name of the room goes (« https://meet.example.org/{room} »).
	 *
	 * @throws MailException
	 */
	public static function visioUrl(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		$check = str_replace('{room}', 'room', $url);
		$parts = parse_url($check);
		if (strlen($url) > 500 || preg_match('/[\s"<>\\\\\'`]/', $url) || !is_array($parts)
			|| strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
			throw new MailException('Adresse de visio invalide : une adresse https://, par exemple %s', ['https://call.element.io']);
		}
		// Element Call: its address only (the links are made from it)
		return str_contains($url, '{room}') ? $url : rtrim($url, '/');
	}

	/** @return array<string, string> domain => EWS URL found by Autodiscover */
	public function getDiscovered(): array {
		$data = json_decode($this->config->getAppValue(Application::APP_ID, 'discovered', '{}'), true);
		$out = [];
		foreach (is_array($data) ? $data : [] as $domain => $url) {
			try {
				$out[(string)$domain] = self::normalizeUrl((string)$url);
			} catch (MailException) {
			}
		}
		return $out;
	}

	public function rememberDiscovered(string $domain, string $url): void {
		$all = $this->getDiscovered();
		$all[$domain] = $url;
		ksort($all);
		$this->config->setAppValue(Application::APP_ID, 'discovered', json_encode($all, JSON_UNESCAPED_SLASHES));
	}

	public function forgetDiscovered(string $domain): void {
		$all = $this->getDiscovered();
		unset($all[strtolower($domain)]);
		$this->config->setAppValue(Application::APP_ID, 'discovered', json_encode($all, JSON_UNESCAPED_SLASHES));
	}

	/** A server name allowed by the administrator (« mail.ovh.net » allows ex3.mail.ovh.net). */
	public static function isAllowedHost(string $host, array $allowed): bool {
		$host = strtolower(rtrim($host, '.'));
		foreach ($allowed as $a) {
			$a = strtolower((string)$a);
			if ($a !== '' && ($host === $a || str_ends_with($host, '.' . $a))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Addresses of the Internet only: a mail domain is chosen by whoever
	 * types an address, it must not make Nextcloud call its local network
	 * (unless config.php allows it: « allow_local_remote_servers »).
	 * @param string[] $ips
	 */
	public static function arePublicIps(array $ips): bool {
		if ($ips === []) {
			return false;
		}
		foreach ($ips as $ip) {
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
				return false;
			}
		}
		return true;
	}

	public function isReachableHost(string $host): bool {
		return $this->reachableIps($host) !== null;
	}

	/**
	 * The public addresses of a host, to connect to exactly those (a second DNS answer,
	 * given to the connection, could otherwise point to the local network).
	 *
	 * @return ?string[] null: not to be reached; []: local servers allowed, no restriction
	 */
	public function reachableIps(string $host): ?array {
		if ($this->config->getSystemValueBool('allow_local_remote_servers', false)) {
			return [];
		}
		$ips = @gethostbynamel($host) ?: [];
		foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
			if (!empty($r['ipv6'])) {
				$ips[] = $r['ipv6'];
			}
		}
		return self::arePublicIps($ips) ? $ips : null;
	}

	/** @return string[] */
	public function getDomains(): array {
		return array_values(array_filter(array_map(fn ($s) => $s['domain'], $this->getServers()), fn ($d) => $d !== '*'));
	}
}
