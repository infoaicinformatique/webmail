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
		$domain = strtolower(substr((string)strrchr($email, '@'), 1));
		$fallback = null;
		foreach ($this->getServers() as $s) {
			if ($s['domain'] === $domain) {
				return $s['url'];
			}
			if ($s['domain'] === '*') {
				$fallback = $s['url'];
			}
		}
		if ($fallback !== null) {
			return $fallback;
		}
		throw new MailException(sprintf('Aucun serveur Exchange n\'est configuré pour le domaine « %s ». Contactez votre administrateur.', $domain));
	}

	/** @return string[] */
	public function getDomains(): array {
		return array_values(array_filter(array_map(fn ($s) => $s['domain'], $this->getServers()), fn ($d) => $d !== '*'));
	}
}
