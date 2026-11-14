<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * « Écrire à un groupe Nextcloud »: the groups offered by the completion of
 * addresses, and the addresses of their members. The groups the user can see
 * are those Nextcloud lets them share with: their own groups when sharing is
 * limited to them, else every group.
 */
class NextcloudGroups {
	public const MAX = 5;
	/** a group larger than this is not written to at once (a mistake is likely) */
	public const MAX_MEMBERS = 300;

	public function __construct(
		private IGroupManager $groups,
		private IUserManager $users,
		private IConfig $config,
	) {
	}

	private function ownGroupsOnly(): bool {
		return $this->config->getAppValue('core', 'shareapi_only_share_with_group_members', 'no') === 'yes';
	}

	/** @return list<array{name: string, email: string, source: string, gid: string, count: int}> */
	public function search(string $userId, string $query): array {
		$query = trim($query);
		$user = $this->users->get($userId);
		if (mb_strlen($query) < 2 || $user === null) {
			return [];
		}
		$found = $this->ownGroupsOnly()
			? array_filter($this->groups->getUserGroups($user), fn ($g) => self::matches($g->getGID(), $g->getDisplayName(), $query))
			: $this->groups->search($query, self::MAX * 2);
		$out = [];
		foreach ($found as $g) {
			$count = $g->count();
			if ($count === 0 || $g->getGID() === 'admin') {
				continue;
			}
			$out[] = ['name' => $g->getDisplayName(), 'email' => '', 'source' => 'nextcloud-group', 'gid' => $g->getGID(), 'count' => $count];
			if (count($out) >= self::MAX) {
				break;
			}
		}
		return $out;
	}

	public static function matches(string $gid, string $name, string $query): bool {
		$q = mb_strtolower($query);
		return str_contains(mb_strtolower($gid), $q) || str_contains(mb_strtolower($name), $q);
	}

	/**
	 * The addresses of a group's members (the user left out).
	 * @return array{name: string, addresses: list<array{name: string, email: string}>, without: int}
	 */
	public function members(string $userId, string $gid): array {
		$user = $this->users->get($userId);
		$group = $this->groups->get($gid);
		if ($user === null || $group === null || $gid === 'admin'
			|| ($this->ownGroupsOnly() && !$group->inGroup($user))) {
			throw new MailException('Groupe introuvable : %s', [$gid]);
		}
		if ($group->count() > self::MAX_MEMBERS) {
			throw new MailException('Le groupe « %s » a plus de %s membres : écrivez plutôt à une liste de diffusion', [$group->getDisplayName(), (string)self::MAX_MEMBERS]);
		}
		$members = [];
		foreach ($group->getUsers() as $u) {
			if (!$u->isEnabled()) {
				continue;
			}
			$members[] = ['uid' => $u->getUID(), 'name' => $u->getDisplayName(), 'email' => (string)$u->getEMailAddress()];
		}
		return ['name' => $group->getDisplayName()] + self::addresses($members, $userId);
	}

	/**
	 * @param list<array{uid: string, name: string, email: string}> $members
	 * @return array{addresses: list<array{name: string, email: string}>, without: int}
	 */
	public static function addresses(array $members, string $self): array {
		$out = [];
		$seen = [];
		$without = 0;
		foreach ($members as $m) {
			if ($m['uid'] === $self) {
				continue;
			}
			$email = mb_strtolower(trim($m['email']));
			if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				$without++;
				continue;
			}
			if (isset($seen[$email])) {
				continue;
			}
			$seen[$email] = true;
			$name = trim($m['name']);
			$out[] = ['name' => $name !== '' ? $name : $email, 'email' => $email];
		}
		usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return ['addresses' => $out, 'without' => $without];
	}
}
