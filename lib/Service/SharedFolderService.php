<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;

/**
 * Other people's folders (calendars, contacts) a user has added to one of
 * their accounts. Exchange has no API listing "folders shared with me", so
 * the user adds them by e-mail address; the list is kept in the user's
 * preferences, one list per account and per type.
 */
class SharedFolderService {
	public const CALENDARS = 'calendars';
	public const CONTACTS = 'contacts';

	private const MAX = 30;

	public function __construct(
		private IConfig $config,
	) {
	}

	private static function key(string $type, int $accountId): string {
		if ($type !== self::CALENDARS && $type !== self::CONTACTS) {
			throw new \InvalidArgumentException('Unknown shared folder type ' . $type);
		}
		return 'shared_' . $type . '_' . $accountId;
	}

	/**
	 * @return array<int, array{email: string, name: string, mode: string, canEdit: bool}>
	 */
	public function list(string $type, string $userId, int $accountId): array {
		$data = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::key($type, $accountId), '[]'), true);
		if (!is_array($data)) {
			return [];
		}
		$out = [];
		foreach ($data as $c) {
			if (is_array($c) && !empty($c['email']) && filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
				$out[] = [
					'email' => strtolower((string)$c['email']),
					'name' => (string)($c['name'] ?? $c['email']),
					'mode' => ($c['mode'] ?? '') === 'freebusy' ? 'freebusy' : 'full',
					'canEdit' => !empty($c['canEdit']),
				];
			}
		}
		return $out;
	}

	/**
	 * @return ?array{email: string, name: string, mode: string, canEdit: bool}
	 */
	public function find(string $type, string $userId, int $accountId, string $email): ?array {
		foreach ($this->list($type, $userId, $accountId) as $c) {
			if ($c['email'] === strtolower($email)) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Add or refresh an entry.
	 * @param array{email: string, name: string, mode: string, canEdit: bool} $entry
	 */
	public function save(string $type, string $userId, int $accountId, array $entry): void {
		$list = array_values(array_filter($this->list($type, $userId, $accountId), fn ($c) => $c['email'] !== strtolower($entry['email'])));
		if (count($list) >= self::MAX) {
			throw new MailException('Trop d\'éléments partagés (maximum ' . self::MAX . ')');
		}
		$entry['email'] = strtolower($entry['email']);
		$list[] = $entry;
		usort($list, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		$this->config->setUserValue($userId, Application::APP_ID, self::key($type, $accountId), json_encode($list));
	}

	public function remove(string $type, string $userId, int $accountId, string $email): void {
		$list = array_values(array_filter($this->list($type, $userId, $accountId), fn ($c) => $c['email'] !== strtolower($email)));
		$this->config->setUserValue($userId, Application::APP_ID, self::key($type, $accountId), json_encode($list));
	}

	/** Forget every shared folder of an account (when the account is removed). */
	public function removeAll(string $userId, int $accountId): void {
		foreach ([self::CALENDARS, self::CONTACTS] as $type) {
			$this->config->deleteUserValue($userId, Application::APP_ID, self::key($type, $accountId));
		}
	}
}
