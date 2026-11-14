<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * Public read-only links to files of Nextcloud, put in a message instead of
 * attaching heavy files (Exchange refuses messages that are too big).
 */
class ShareLinkService {
	public function __construct(
		private IRootFolder $rootFolder,
		private IManager $shares,
		private IURLGenerator $url,
	) {
	}

	/**
	 * The protection asked for a new link: an expiry « 2026-12-31 » (tomorrow to a year from now) or
	 * none, a password or not. Pure: tested without Nextcloud.
	 *
	 * @return array{expires: ?string, password: bool}
	 * @throws MailException
	 */
	public static function protection(string $expires, bool $password, \DateTimeImmutable $today): array {
		$expires = trim($expires);
		if ($expires !== '') {
			$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires, new \DateTimeZone('UTC'));
			$min = $today->setTime(0, 0)->modify('+1 day');
			if ($d === false || $d->format('Y-m-d') !== $expires || $d < $min || $d > $today->modify('+1 year')) {
				throw new MailException('Date d\'expiration invalide (de demain à un an)');
			}
		}
		return ['expires' => $expires !== '' ? $expires : null, 'password' => $password];
	}

	/** A password for a link: 12 characters, nothing that is easily misread (l, 1, O, 0). */
	public static function newPassword(): string {
		$chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$out = '';
		for ($i = 0; $i < 12; $i++) {
			$out .= $chars[random_int(0, strlen($chars) - 1)];
		}
		return $out;
	}

	/**
	 * The link of a file or folder (an existing read-only link without password nor
	 * expiry is reused when none is asked, else a new one is made).
	 * @return array{url: string, name: string, size: int, isFolder: bool, expires: ?string, password?: string}
	 */
	public function link(string $userId, string $path, string $expires = '', bool $password = false): array {
		$protect = self::protection($expires, $password, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
		// a password imposed by the administrator: one is made
		if ($this->shares->shareApiLinkEnforcePassword()) {
			$protect['password'] = true;
		}
		if (!$this->shares->shareApiAllowLinks()) {
			throw new MailException('Les liens de partage sont désactivés sur ce Nextcloud');
		}
		try {
			$node = $this->rootFolder->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			throw new MailException('Fichier introuvable : %s', [$path]);
		}
		if (!$node->isShareable()) {
			throw new MailException('Ce fichier ne peut pas être partagé');
		}
		$now = new \DateTimeImmutable();
		if ($protect['expires'] === null && !$protect['password']) {
			foreach ($this->shares->getSharesBy($userId, IShare::TYPE_LINK, $node, false, 50) as $s) {
				if ($s->getPassword() === null && $s->getPermissions() === Constants::PERMISSION_READ
					&& ($s->getExpirationDate() === null || $s->getExpirationDate() > $now)) {
					return $this->describe($s, $node);
				}
			}
		}
		$share = $this->shares->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_LINK)
			->setPermissions(Constants::PERMISSION_READ)
			->setSharedBy($userId);
		if ($protect['expires'] !== null) {
			$share->setExpirationDate(new \DateTime($protect['expires'] . ' 00:00:00'));
		}
		$secret = null;
		if ($protect['password']) {
			$secret = self::newPassword();
			$share->setPassword($secret);
		}
		try {
			$share = $this->shares->createShare($share);
		} catch (\Exception $e) {
			$hint = method_exists($e, 'getHint') ? (string)$e->getHint() : '';
			throw new MailException('Lien de partage impossible : %s', [$hint !== '' ? $hint : $e->getMessage()]);
		}
		// (the password is given once, to be passed on another way: it is not put in the message)
		return $this->describe($share, $node) + ($secret !== null ? ['password' => $secret] : []);
	}

	private function describe(IShare $share, \OCP\Files\Node $node): array {
		return [
			'url' => $this->url->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', ['token' => $share->getToken()]),
			'name' => $node->getName(),
			'size' => (int)$node->getSize(),
			'isFolder' => $node instanceof Folder,
			'expires' => $share->getExpirationDate()?->format('Y-m-d'),
		];
	}
}
