<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;

/**
 * The senders whose messages show their remote images at once (« Toujours afficher les images
 * de cet expéditeur »): addresses (« news@exemple.org ») or whole domains (« @exemple.org »),
 * of the Nextcloud user, for all their accounts, kept in their preferences.
 */
class TrustedImagesService {
	public const MAX = 500;

	public function __construct(
		private IConfig $config,
	) {
	}

	/** @return list<string> */
	public function list(string $userId): array {
		return self::decode($this->config->getUserValue($userId, Application::APP_ID, 'trusted_images', '[]'));
	}

	/**
	 * @return list<string>
	 * @throws MailException
	 */
	public function add(string $userId, string $entry): array {
		$list = self::with($this->list($userId), $entry);
		$this->store($userId, $list);
		return $list;
	}

	/** @return list<string> */
	public function remove(string $userId, string $entry): array {
		$entry = strtolower(trim($entry));
		$list = array_values(array_filter($this->list($userId), fn ($e) => $e !== $entry));
		$this->store($userId, $list);
		return $list;
	}

	/** Whether this sender's images are shown at once: the address, or its domain, is in the list. */
	public function trusts(string $userId, string $email): bool {
		return self::matches($this->list($userId), $email);
	}

	/** @param list<string> $list */
	private function store(string $userId, array $list): void {
		if ($list === []) {
			$this->config->deleteUserValue($userId, Application::APP_ID, 'trusted_images');
		} else {
			$this->config->setUserValue($userId, Application::APP_ID, 'trusted_images', (string)json_encode($list));
		}
	}

	/** @return list<string> */
	public static function decode(string $json): array {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return [];
		}
		return array_values(array_filter($data, fn ($e) => is_string($e) && self::normalize($e) === $e));
	}

	/**
	 * « news@exemple.org » or « @exemple.org » (a whole domain), in lower case; null if neither.
	 */
	public static function normalize(string $entry): ?string {
		$e = strtolower(trim($entry));
		if (str_starts_with($e, '@')) {
			$domain = substr($e, 1);
			return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) ? $e : null;
		}
		return filter_var($e, FILTER_VALIDATE_EMAIL) !== false ? $e : null;
	}

	/**
	 * @param list<string> $list
	 * @return list<string>
	 * @throws MailException
	 */
	public static function with(array $list, string $entry): array {
		$e = self::normalize($entry);
		if ($e === null) {
			throw new MailException('Adresse ou domaine invalide : %s', [$entry]);
		}
		if (!in_array($e, $list, true)) {
			$list[] = $e;
		}
		if (count($list) > self::MAX) {
			throw new MailException('Trop d\'expéditeurs de confiance (%s au plus)', [(string)self::MAX]);
		}
		sort($list);
		return $list;
	}

	/** @param list<string> $list */
	public static function matches(array $list, string $email): bool {
		$email = strtolower(trim($email));
		$at = strrpos($email, '@');
		if ($at === false || $at === 0) {
			return false;
		}
		return in_array($email, $list, true) || in_array(substr($email, $at), $list, true);
	}
}
