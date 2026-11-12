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
	 * The link of a file or folder (an existing read-only link without
	 * password is reused, else a new one is made).
	 * @return array{url: string, name: string, size: int, isFolder: bool, expires: ?string}
	 */
	public function link(string $userId, string $path): array {
		if (!$this->shares->shareApiAllowLinks()) {
			throw new MailException('Les liens de partage sont désactivés sur ce Nextcloud');
		}
		try {
			$node = $this->rootFolder->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			throw new MailException('Fichier introuvable : ' . $path);
		}
		if (!$node->isShareable()) {
			throw new MailException('Ce fichier ne peut pas être partagé');
		}
		$now = new \DateTimeImmutable();
		foreach ($this->shares->getSharesBy($userId, IShare::TYPE_LINK, $node, false, 50) as $s) {
			if ($s->getPassword() === null && $s->getPermissions() === Constants::PERMISSION_READ
				&& ($s->getExpirationDate() === null || $s->getExpirationDate() > $now)) {
				return $this->describe($s, $node);
			}
		}
		if ($this->shares->shareApiLinkEnforcePassword()) {
			throw new MailException('Ce Nextcloud impose un mot de passe sur les liens : créez le lien dans Fichiers et collez-le dans le message');
		}
		$share = $this->shares->newShare();
		$share->setNode($node)
			->setShareType(IShare::TYPE_LINK)
			->setPermissions(Constants::PERMISSION_READ)
			->setSharedBy($userId);
		try {
			$share = $this->shares->createShare($share);
		} catch (\Exception $e) {
			$hint = method_exists($e, 'getHint') ? (string)$e->getHint() : '';
			throw new MailException('Lien de partage impossible : ' . ($hint !== '' ? $hint : $e->getMessage()));
		}
		return $this->describe($share, $node);
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
