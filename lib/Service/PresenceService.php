<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCP\IUserManager;
use OCP\UserStatus\IManager as IUserStatusManager;

/**
 * Who is on line among the contacts: the Nextcloud status (« En ligne », « Absent »,
 * « Ne pas déranger ») of the Nextcloud accounts with their e-mail address.
 * The same status Nextcloud shows on their avatar to every user.
 */
class PresenceService {
	/** at most this many addresses asked at once */
	public const MAX = 200;

	public function __construct(
		private IUserManager $users,
		private IUserStatusManager $statuses,
	) {
	}

	/**
	 * @param string[] $emails
	 * @return array<string, string> address (lower case) → online | away | dnd | offline;
	 *                               the addresses of no Nextcloud account are left out
	 */
	public function forEmails(array $emails): array {
		$byUser = [];
		foreach (self::addresses($emails) as $email) {
			foreach ($this->users->getByEmail($email) as $user) {
				if ($user->isEnabled()) {
					$byUser[$user->getUID()] = $email;
					break;
				}
			}
		}
		if ($byUser === []) {
			return [];
		}
		$out = array_fill_keys(array_values($byUser), 'offline');
		foreach ($this->statuses->getUserStatuses(array_map('strval', array_keys($byUser))) as $uid => $status) {
			if (isset($byUser[$uid])) {
				$out[$byUser[$uid]] = self::status($status->getStatus());
			}
		}
		return $out;
	}

	/**
	 * The addresses asked: valid, in lower case, once each, at most MAX.
	 * @param array<mixed> $emails
	 * @return list<string>
	 */
	public static function addresses(array $emails): array {
		$out = [];
		foreach ($emails as $e) {
			$e = is_string($e) ? strtolower(trim($e)) : '';
			if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $out, true)) {
				$out[] = $e;
				if (count($out) >= self::MAX) {
					break;
				}
			}
		}
		return $out;
	}

	/** A Nextcloud status as shown: « invisible » is shown as off line. */
	public static function status(string $status): string {
		// (the values of IUserStatus; « busy »: Nextcloud 31 and later)
		return match ($status) {
			'online' => 'online',
			'away' => 'away',
			'dnd', 'busy' => 'dnd',
			default => 'offline',
		};
	}
}
