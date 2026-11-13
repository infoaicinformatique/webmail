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
				throw new MailException('Domaine invalide : ' . $domain);
			}
			if (isset($seen[$domain])) {
				throw new MailException('Domaine en double : ' . $domain);
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
			throw new MailException('Adresse de serveur invalide : ' . $url);
		}
		if (strtolower($parts['scheme'] ?? '') !== 'https') {
			throw new MailException('Le serveur Exchange doit être en HTTPS : ' . $url);
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
		throw new MailException(sprintf('Aucun serveur Exchange n\'est configuré pour le domaine « %s ». Contactez votre administrateur.', self::domainOf($email)));
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
				throw new MailException('Nom de serveur invalide : ' . $h);
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
		if ($this->config->getSystemValueBool('allow_local_remote_servers', false)) {
			return true;
		}
		$ips = @gethostbynamel($host) ?: [];
		foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
			if (!empty($r['ipv6'])) {
				$ips[] = $r['ipv6'];
			}
		}
		return self::arePublicIps($ips);
	}

	/** @return string[] */
	public function getDomains(): array {
		return array_values(array_filter(array_map(fn ($s) => $s['domain'], $this->getServers()), fn ($d) => $d !== '*'));
	}
}
