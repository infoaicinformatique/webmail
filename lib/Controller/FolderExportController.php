<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\FolderExport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** « Exporter le dossier »: its messages as .eml files in a .zip, downloaded or put into Files. */
class FolderExportController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	private function zone(): \DateTimeZone {
		try {
			return new \DateTimeZone($this->config->getUserValue((string)$this->userId, 'core', 'timezone', '') ?: date_default_timezone_get());
		} catch (\Exception) {
			return new \DateTimeZone('UTC');
		}
	}

	/** @return array{path: string, count: int, total: int, truncated: bool} */
	private function build(int $id, string $folderId): array {
		$client = $this->accounts->getClient($this->accounts->find((string)$this->userId, $id));
		return FolderExport::build($client, $folderId, $this->zone());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 10, period: 600)]
	public function download(int $id, string $folderId, string $name = ''): Response {
		return $this->handle(function () use ($id, $folderId, $name) {
			$zip = $this->build($id, $folderId);
			$handle = fopen($zip['path'], 'rb');
			// (removed now: the open file stays readable until sent)
			@unlink($zip['path']);
			if ($handle === false) {
				throw new MailException('Impossible de préparer le fichier .zip');
			}
			$file = FolderExport::zipName($name, new \DateTimeImmutable('now', $this->zone()));
			$response = new StreamResponse($handle);
			$response->addHeader('Content-Type', 'application/zip');
			$response->addHeader('Content-Disposition', 'attachment; filename="' . rawurlencode($file) . '"; filename*=UTF-8\'\'' . rawurlencode($file));
			$response->addHeader('X-Webmail-Count', (string)$zip['count']);
			$response->addHeader('X-Webmail-Truncated', $zip['truncated'] ? '1' : '0');
			return $response;
		});
	}

	/** Into a folder of Files (made if missing). */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 600)]
	public function save(int $id, string $folderId, string $path = '/Courriers', string $name = ''): Response {
		return $this->handle(function () use ($id, $folderId, $path, $name) {
			$userFolder = $this->rootFolder->getUserFolder((string)$this->userId);
			$path = '/' . trim($path, '/');
			try {
				$folder = $userFolder->get($path);
			} catch (NotFoundException) {
				$folder = $userFolder->newFolder($path);
			}
			if (!$folder instanceof Folder) {
				throw new MailException('Le chemin de destination n\'est pas un dossier');
			}
			if (!$folder->isCreatable()) {
				throw new MailException('Vous n\'avez pas le droit d\'écrire dans ce dossier');
			}
			$zip = $this->build($id, $folderId);
			try {
				$handle = fopen($zip['path'], 'rb');
				if ($handle === false) {
					throw new MailException('Impossible de préparer le fichier .zip');
				}
				$file = $folder->newFile($folder->getNonExistingName(FolderExport::zipName($name, new \DateTimeImmutable('now', $this->zone()))), $handle);
				if (is_resource($handle)) {
					fclose($handle);
				}
			} finally {
				@unlink($zip['path']);
			}
			return ['path' => (string)$userFolder->getRelativePath($file->getPath()), 'count' => $zip['count'], 'truncated' => $zip['truncated'],
				'max' => FolderExport::MAX_MESSAGES];
		});
	}
}
