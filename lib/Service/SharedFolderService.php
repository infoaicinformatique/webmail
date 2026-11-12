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
	/** contact folders of the public folders: identified by folder id only (no owner address) */
	public const PUBLIC_CONTACTS = 'public_contacts';
	/** calendars of the public folders: identified by folder id only */
	public const PUBLIC_CALENDARS = 'public_calendars';
	/** shared mailboxes (contact@…) the user reads and sends as */
	public const MAILBOXES = 'mailboxes';

	private const MAX = 30;

	public function __construct(
		private IConfig $config,
	) {
	}

	private static function key(string $type, int $accountId): string {
		if (!in_array($type, [self::CALENDARS, self::CONTACTS, self::PUBLIC_CONTACTS, self::PUBLIC_CALENDARS, self::MAILBOXES], true)) {
			throw new \InvalidArgumentException('Unknown shared folder type ' . $type);
		}
		return 'shared_' . $type . '_' . $accountId;
	}

	/**
	 * Entries are identified by the owner's address and, for an address book
	 * other than their default one, the folder id ("" otherwise).
	 * @return array<int, array{email: string, name: string, mode: string, canEdit: bool, folder: string, folderName: string}>
	 */
	public function list(string $type, string $userId, int $accountId): array {
		$data = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::key($type, $accountId), '[]'), true);
		if (!is_array($data)) {
			return [];
		}
		$out = [];
		foreach ($data as $c) {
			$valid = $type === self::PUBLIC_CONTACTS || $type === self::PUBLIC_CALENDARS
				? is_array($c) && !empty($c['folder']) && is_string($c['folder'])
				: is_array($c) && !empty($c['email']) && filter_var($c['email'], FILTER_VALIDATE_EMAIL);
			if ($valid) {
				$out[] = [
					'email' => strtolower((string)($c['email'] ?? '')),
					'name' => (string)($c['name'] ?? $c['email'] ?? ''),
					'mode' => ($c['mode'] ?? '') === 'freebusy' ? 'freebusy' : 'full',
					'canEdit' => !empty($c['canEdit']),
					'folder' => (string)($c['folder'] ?? ''),
					'folderName' => (string)($c['folderName'] ?? ''),
				];
			}
		}
		return $out;
	}

	/**
	 * @return ?array{email: string, name: string, mode: string, canEdit: bool, folder: string, folderName: string}
	 */
	public function find(string $type, string $userId, int $accountId, string $email, string $folder = ''): ?array {
		foreach ($this->list($type, $userId, $accountId) as $c) {
			if (self::same($c, $email, $folder)) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Add or refresh an entry.
	 * @param array{email: string, name: string, mode: string, canEdit: bool, folder?: string, folderName?: string} $entry
	 */
	public function save(string $type, string $userId, int $accountId, array $entry): void {
		$entry += ['folder' => '', 'folderName' => ''];
		$list = array_values(array_filter($this->list($type, $userId, $accountId), fn ($c) => !self::same($c, $entry['email'], $entry['folder'])));
		if (count($list) >= self::MAX) {
			throw new MailException('Trop d\'éléments partagés (maximum ' . self::MAX . ')');
		}
		$entry['email'] = strtolower($entry['email']);
		$list[] = $entry;
		// a person's default folder first, then their other ones
		usort($list, fn ($a, $b) => strcasecmp($a['name'], $b['name']) ?: strcmp($a['email'], $b['email'])
			?: (($a['folder'] !== '') <=> ($b['folder'] !== '')) ?: strcasecmp($a['folderName'], $b['folderName']));
		$this->config->setUserValue($userId, Application::APP_ID, self::key($type, $accountId), json_encode($list));
	}

	public function remove(string $type, string $userId, int $accountId, string $email, string $folder = ''): void {
		$list = array_values(array_filter($this->list($type, $userId, $accountId), fn ($c) => !self::same($c, $email, $folder)));
		$this->config->setUserValue($userId, Application::APP_ID, self::key($type, $accountId), json_encode($list));
	}

	/** @param array{email: string, folder: string} $entry */
	private static function same(array $entry, string $email, string $folder): bool {
		return $entry['email'] === strtolower($email) && $entry['folder'] === $folder;
	}

	/** Forget every shared folder of an account (when the account is removed). */
	public function removeAll(string $userId, int $accountId): void {
		foreach ([self::CALENDARS, self::CONTACTS, self::PUBLIC_CONTACTS, self::PUBLIC_CALENDARS, self::MAILBOXES] as $type) {
			$this->config->deleteUserValue($userId, Application::APP_ID, self::key($type, $accountId));
		}
	}
}
