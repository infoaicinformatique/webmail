<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * « Ouvrir dans Nextcloud »: an attachment copied in Files (« Pièces jointes ») and opened there,
 * with the editor or viewer of this Nextcloud (Office, Text, PDF, images…).
 */
class OpenController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private IRootFolder $rootFolder,
		private IURLGenerator $url,
		private IL10N $l,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 300)]
	public function attachment(int $id, string $attachmentId): Response {
		return $this->handle(function () use ($id, $attachmentId) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$att = $this->accounts->getClient($account)->getAttachment($attachmentId);
			$userFolder = $this->rootFolder->getUserFolder((string)$this->userId);
			$path = '/' . $this->l->t('Pièces jointes');
			try {
				$folder = $userFolder->get($path);
			} catch (NotFoundException) {
				$folder = $userFolder->newFolder($path);
			}
			if (!$folder instanceof Folder || !$folder->isCreatable()) {
				throw new MailException('Vous n\'avez pas le droit d\'écrire dans ce dossier');
			}
			$file = $folder->newFile($folder->getNonExistingName(MailController::safeFileName($att['name'])), $att['content']);
			return [
				'path' => (string)$userFolder->getRelativePath($file->getPath()),
				// Files opens it with what this Nextcloud has for its type
				'url' => $this->url->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $file->getId()]) . '?openfile=true',
			];
		});
	}
}
