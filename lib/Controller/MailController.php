<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\HtmlSanitizer;
use OCA\Webmail\Service\MailNotificationService;
use OCA\Webmail\Service\SharedFolderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class MailController extends Controller {
	use ErrorHandling;

	/** Maximum total size of attachments sent in one message. */
	private const MAX_ATTACHMENTS_BYTES = 25 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		private AccountService $accounts,
		private HtmlSanitizer $sanitizer,
		private MailNotificationService $bell,
		private SharedFolderService $shared,
		private IRootFolder $rootFolder,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	/**
	 * Their notification in Nextcloud has done its job (opened, read, moved,
	 * deleted). Never a reason to fail the request.
	 * @param string[] $itemIds
	 */
	private function dismiss(array $itemIds): void {
		try {
			$this->bell->dismiss((string)$this->userId, $itemIds);
		} catch (\Throwable $e) {
			$this->logger->debug('Webmail: notification not removed', ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}

	private function client(int $id): EwsClient {
		return $this->accounts->getClient($this->accounts->find((string)$this->userId, $id));
	}

	/** @param string $parentId "" = top of the mailbox */
	#[NoAdminRequired]
	public function createFolder(int $id, string $name, string $parentId = ''): Response {
		return $this->handle(fn () => ['id' => $this->client($id)->createFolder($name, $parentId !== '' ? $parentId : null)]);
	}

	#[NoAdminRequired]
	public function renameFolder(int $id, string $folderId, string $name): Response {
		return $this->handle(function () use ($id, $folderId, $name) {
			$this->client($id)->renameFolder($folderId, $name);
			return ['status' => 'ok'];
		});
	}

	/** @param string $parentId "" = top of the mailbox */
	#[NoAdminRequired]
	public function moveFolder(int $id, string $folderId, string $parentId = ''): Response {
		return $this->handle(function () use ($id, $folderId, $parentId) {
			$this->client($id)->moveFolder($folderId, $parentId !== '' ? $parentId : null);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function deleteFolder(int $id, string $folderId): Response {
		return $this->handle(function () use ($id, $folderId) {
			$this->client($id)->deleteFolder($folderId);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function folders(int $id, string $mailbox = ''): Response {
		return $this->handle(function () use ($id, $mailbox) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$client = $this->accounts->getClient($account);
			if ($mailbox !== '') {
				$this->sharedMailbox($id, $mailbox);
				return ['folders' => $client->listFolders($unused, $mailbox), 'usage' => null];
			}
			$used = null;
			$folders = $client->listFolders($used);
			return ['folders' => $folders, 'usage' => ['used' => $used, 'limit' => $this->accounts->mailboxQuota($account, $client)]];
		});
	}

	/**
	 * Messages of a folder, or search results. Filters: unread, with
	 * attachments, flagged, received since a date (Y-m-d); scope "all"
	 * searches every mail folder.
	 */
	#[NoAdminRequired]
	public function messages(int $id, string $folder = 'inbox', int $offset = 0, int $limit = 50, string $q = '',
		bool $unread = false, bool $attachments = false, bool $flagged = false, string $since = '', string $scope = 'folder'): Response {
		$limit = max(1, min(100, $limit));
		return $this->handle(function () use ($id, $folder, $offset, $limit, $q, $unread, $attachments, $flagged, $since, $scope) {
			$client = $this->client($id);
			$query = EwsClient::searchQuery($q, ['unread' => $unread, 'attachments' => $attachments, 'flagged' => $flagged, 'since' => $since]);
			if ($scope === 'all' && $query !== null) {
				$ids = array_column($client->listFolders(), 'id');
				return $client->searchFolders($ids, max(0, $offset), $limit, $query);
			}
			return $client->findItems($folder, max(0, $offset), $limit, $query);
		});
	}

	/**
	 * The list grouped by conversation: one row per conversation of the folder.
	 */
	#[NoAdminRequired]
	public function conversations(int $id, string $folder = 'inbox', int $offset = 0, int $limit = 50): Response {
		return $this->handle(fn () => $this->client($id)->findConversations($folder, max(0, $offset), max(1, min(100, $limit))));
	}

	/**
	 * New unread messages of the inbox since a date (polled for notifications).
	 */
	#[NoAdminRequired]
	public function newMessages(int $id, string $since = ''): Response {
		return $this->handle(fn () => $this->client($id)->newMessages($since !== '' ? $since : null));
	}

	/** @return array{email: string, name: string} a shared mailbox added to this account */
	private function sharedMailbox(int $id, string $email): array {
		$entry = $this->shared->find(SharedFolderService::MAILBOXES, (string)$this->userId, $id, $email);
		if ($entry === null) {
			throw new MailException('Boîte partagée inconnue : %s', [$email]);
		}
		return $entry;
	}

	/**
	 * Shared mailboxes (contact@…, accueil@…) added to an account.
	 */
	#[NoAdminRequired]
	public function mailboxes(int $id): Response {
		return $this->handle(function () use ($id) {
			$this->accounts->find((string)$this->userId, $id);
			return ['mailboxes' => $this->mailboxList($id)];
		});
	}

	/** The shared mailboxes added to an account: full access, or delegate of some folders. */
	private function mailboxList(int $id): array {
		return array_map(fn ($m) => ['email' => $m['email'], 'name' => $m['name'], 'delegate' => ($m['mode'] ?? 'full') === 'delegate'],
			$this->shared->list(SharedFolderService::MAILBOXES, (string)$this->userId, $id));
	}

	/**
	 * Add a shared mailbox: Exchange must give this account access to it
	 * (« Accès total » set by the administrator), or the colleague made us
	 * their delegate in Outlook (some folders: often the inbox).
	 */
	#[NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 20, period: 300)]
	public function addMailbox(int $id, string $email, string $name = ''): Response {
		return $this->handle(function () use ($id, $email, $name) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$email = strtolower(trim($email));
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new MailException('Adresse invalide');
			}
			if ($email === strtolower($account->getEmail())) {
				throw new MailException('C\'est l\'adresse de ce compte');
			}
			$client = $this->accounts->getClient($account);
			$client->listFolders($unused, $email); // access checked here
			$mode = $client->mailboxAccess();
			if (trim($name) === '') {
				// its name in the directory (« Contact Société A »), else its address
				try {
					foreach ((new \OCA\Webmail\Ews\EwsContacts($client))->resolve($email) as $r) {
						if (strtolower($r['email']) === $email && trim($r['name']) !== '') {
							$name = trim($r['name']);
							break;
						}
					}
				} catch (MailException) {
				}
			}
			$name = trim($name) !== '' ? trim($name) : $email;
			$this->shared->save(SharedFolderService::MAILBOXES, (string)$this->userId, $id, ['email' => $email, 'name' => $name, 'mode' => $mode, 'canEdit' => true]);
			return ['mailboxes' => $this->mailboxList($id)];
		});
	}

	#[NoAdminRequired]
	public function removeMailbox(int $id, string $email): Response {
		return $this->handle(function () use ($id, $email) {
			$this->accounts->find((string)$this->userId, $id);
			$this->shared->remove(SharedFolderService::MAILBOXES, (string)$this->userId, $id, $email);
			return ['mailboxes' => $this->mailboxList($id)];
		});
	}

	/**
	 * The messages of a conversation, in every folder, oldest first.
	 */
	#[NoAdminRequired]
	public function conversation(int $id, string $conversationId): Response {
		return $this->handle(fn () => ['items' => $this->client($id)->conversation($conversationId)]);
	}

	#[NoAdminRequired]
	public function message(int $id, string $itemId, bool $remote = false, bool $markRead = true): Response {
		return $this->handle(function () use ($id, $itemId, $remote, $markRead) {
			$client = $this->client($id);
			$msg = $client->getItem($itemId);
			$this->dismiss([$itemId]);

			$cidMap = [];
			foreach ($msg['attachments'] as $att) {
				if (!empty($att['contentId']) && !empty($att['id'])) {
					$cidMap[trim($att['contentId'], '<>')] = $this->urlGenerator->linkToRoute('webmail.mail.attachment', [
						'id' => $id, 'attachmentId' => $att['id'], 'inline' => 1,
					]);
				}
			}

			$body = $msg['body'];
			if (strtoupper((string)$msg['bodyType']) !== 'HTML') {
				$body = '<pre style="white-space:pre-wrap;font-family:inherit">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</pre>';
			}
			$clean = $this->sanitizer->sanitize($body, $cidMap, $remote);
			$msg['body'] = $clean['html'];
			$msg['hasRemoteContent'] = $clean['hasRemoteContent'];

			// Inline images referenced from the body are not listed as attachments
			$used = [];
			foreach ($cidMap as $cid => $url) {
				if (str_contains($clean['html'], htmlspecialchars($url, ENT_QUOTES)) || str_contains($clean['html'], $url)) {
					$used[$cid] = true;
				}
			}
			$msg['attachments'] = array_values(array_filter($msg['attachments'], function ($a) use ($used) {
				return !($a['isInline'] && !empty($a['contentId']) && isset($used[trim((string)$a['contentId'], '<>')]));
			}));

			if ($markRead && !$msg['isRead']) {
				try {
					$client->setRead([$itemId], true);
					$msg['isRead'] = true;
				} catch (MailException $e) {
					$this->logger->info('Webmail: could not mark as read: ' . $e->getMessage());
				}
			}
			return $msg;
		});
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function attachment(int $id, string $attachmentId, bool $inline = false): Response {
		return $this->handle(function () use ($id, $attachmentId, $inline) {
			$att = $this->client($id)->getAttachment($attachmentId);
			$type = strtolower($att['contentType']);
			// only display safe image types inline; everything else is downloaded
			$safeInline = in_array($type, ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf'], true);
			$disposition = ($inline && $safeInline) ? 'inline' : 'attachment';
			$response = new DataDisplayResponse($att['content'], 200, [
				'Content-Type' => $safeInline ? $type : 'application/octet-stream',
				'Content-Disposition' => $disposition . '; filename="' . addcslashes(self::asciiName($att['name']), '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($att['name']),
			]);
			$response->cacheFor(3600);
			return $response;
		});
	}

	/** A message as an .eml file, to download. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function eml(int $id, string $itemId): Response {
		return $this->handle(function () use ($id, $itemId) {
			$mail = $this->client($id)->getMime($itemId);
			return new DataDownloadResponse($mail['content'], $mail['name'], 'message/rfc822');
		});
	}

	/**
	 * .eml files put into a folder of the mailbox (sent from the computer or picked in Files).
	 *
	 * @param string[] $filePaths
	 */
	#[NoAdminRequired]
	public function importEml(int $id, string $folderId, array $filePaths = []): Response {
		return $this->handle(function () use ($id, $folderId, $filePaths) {
			$client = $this->client($id);
			$files = [];
			$uploads = $this->request->getUploadedFile('files');
			if (is_array($uploads) && isset($uploads['name'])) {
				foreach ((array)$uploads['name'] as $i => $name) {
					$error = ((array)$uploads['error'])[$i] ?? UPLOAD_ERR_NO_FILE;
					$tmp = (string)(((array)$uploads['tmp_name'])[$i] ?? '');
					$files[] = ['name' => (string)$name, 'content' => $error === UPLOAD_ERR_OK && is_uploaded_file($tmp) ? (string)file_get_contents($tmp) : null];
				}
			}
			$userFolder = $this->rootFolder->getUserFolder((string)$this->userId);
			foreach ($filePaths as $path) {
				try {
					$node = $userFolder->get((string)$path);
					$files[] = ['name' => $node->getName(), 'content' => $node instanceof File && $node->isReadable() && $node->getSize() <= EwsClient::MAX_EML_BYTES ? $node->getContent() : null];
				} catch (NotFoundException) {
					$files[] = ['name' => basename((string)$path), 'content' => null];
				}
			}
			if ($files === []) {
				throw new MailException('Aucun fichier à importer');
			}
			$imported = 0;
			$failed = [];
			foreach ($files as $f) {
				try {
					if ($f['content'] === null) {
						throw new MailException('Fichier illisible ou trop lourd');
					}
					$client->importMime($f['content'], $folderId);
					$imported++;
				} catch (MailException $e) {
					$failed[] = ['name' => $f['name'], 'error' => $this->errorMessage($e)];
				}
			}
			return ['imported' => $imported, 'failed' => $failed];
		});
	}

	/** A message as an .eml file, saved in Nextcloud Files. */
	#[NoAdminRequired]
	public function saveEml(int $id, string $itemId, string $path = '/Pièces jointes'): Response {
		return $this->handle(function () use ($id, $itemId, $path) {
			$mail = $this->client($id)->getMime($itemId);
			return ['path' => $this->saveToFiles($path, $mail['name'], $mail['content'])];
		});
	}

	#[NoAdminRequired]
	public function saveAttachment(int $id, string $attachmentId, string $path = '/Pièces jointes'): Response {
		return $this->handle(function () use ($id, $attachmentId, $path) {
			$att = $this->client($id)->getAttachment($attachmentId);
			return ['path' => $this->saveToFiles($path, $att['name'], $att['content'])];
		});
	}

	/**
	 * A file in a folder of the user's Files (created if missing), under a free name.
	 *
	 * @return string its path in the user's Files
	 * @throws MailException
	 */
	private function saveToFiles(string $path, string $fileName, string $content): string {
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
		$name = self::safeFileName($fileName);
		$name = $folder->getNonExistingName($name);
		$file = $folder->newFile($name, $content);
		return (string)$userFolder->getRelativePath($file->getPath());
	}

	/**
	 * @param string[] $itemIds
	 */
	#[NoAdminRequired]
	public function flags(int $id, array $itemIds, string $flag, bool $value): Response {
		return $this->handle(function () use ($id, $itemIds, $flag, $value) {
			$client = $this->client($id);
			match ($flag) {
				'read' => $client->setRead($itemIds, $value),
				'flagged' => $client->setFlagged($itemIds, $value),
				default => throw new MailException('Indicateur inconnu'),
			};
			if ($flag === 'read' && $value) {
				$this->dismiss($itemIds);
			}
			return ['status' => 'ok'];
		});
	}

	/**
	 * @param string[] $itemIds
	 */
	#[NoAdminRequired]
	public function move(int $id, array $itemIds, string $target): Response {
		return $this->handle(function () use ($id, $itemIds, $target) {
			$this->client($id)->moveItems($itemIds, $target);
			$this->dismiss($itemIds);
			return ['status' => 'ok'];
		});
	}

	/**
	 * @param string[] $itemIds
	 */
	/**
	 * @param bool $junk true: junk (sender blocked), false: not junk (sender trusted)
	 */
	#[NoAdminRequired]
	public function junk(int $id, array $itemIds, bool $junk): Response {
		return $this->handle(function () use ($id, $itemIds, $junk) {
			$this->client($id)->markAsJunk($itemIds, $junk);
			$this->dismiss($itemIds);
			return ['status' => 'ok'];
		});
	}

	#[NoAdminRequired]
	public function delete(int $id, array $itemIds, bool $permanent = false): Response {
		return $this->handle(function () use ($id, $itemIds, $permanent) {
			$this->client($id)->deleteItems($itemIds, $permanent);
			$this->dismiss($itemIds);
			return ['status' => 'ok'];
		});
	}

	/**
	 * What the compose form sent: mode (new, reply, replyAll, forward, draft),
	 * recipients, subject, body (HTML from the editor, cleaned, or plain text).
	 * @return array{mode: string, to: string[], cc: string[], bcc: string[], subject: string, html: string, referenceId: ?string, importance: string}
	 */
	private function composed(bool $forSending): array {
		$mode = (string)$this->request->getParam('mode', 'new');
		if (!in_array($mode, ['new', 'reply', 'replyAll', 'forward', 'draft'], true)) {
			throw new MailException('Type d\'envoi inconnu');
		}
		$to = self::splitAddresses((string)$this->request->getParam('to', ''));
		$cc = self::splitAddresses((string)$this->request->getParam('cc', ''));
		$bcc = self::splitAddresses((string)$this->request->getParam('bcc', ''));
		foreach (array_merge($to, $cc, $bcc) as $addr) {
			if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
				throw new MailException('Adresse invalide : %s', [$addr]);
			}
		}
		if ($forSending && ($mode === 'new' || $mode === 'forward') && $to === [] && $cc === [] && $bcc === []) {
			throw new MailException('Indiquez au moins un destinataire');
		}
		$bodyHtml = (string)$this->request->getParam('bodyHtml', '');
		$html = $bodyHtml !== ''
			// written in the editor: only harmless formatting is kept
			? HtmlSanitizer::editorToHtml($this->sanitizer->sanitize($bodyHtml, [], true)['html'])
			: HtmlSanitizer::textToHtml((string)$this->request->getParam('body', ''));
		$referenceId = $this->request->getParam('referenceId');
		return [
			'mode' => $mode,
			'to' => $to,
			'cc' => $cc,
			'bcc' => $bcc,
			'subject' => (string)$this->request->getParam('subject', ''),
			'html' => $html,
			'referenceId' => is_string($referenceId) && $referenceId !== '' ? $referenceId : null,
			'importance' => (string)$this->request->getParam('importance', 'Normal'),
		];
	}

	/**
	 * Save the message being written in « Brouillons ».
	 */
	#[NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 120, period: 300)]
	public function saveDraft(int $id): Response {
		return $this->handle(function () use ($id) {
			$client = $this->client($id);
			$m = $this->composed(false);
			$draftId = (string)$this->request->getParam('draftId', '');
			// a draft opened from « Brouillons », or a new message already saved once:
			// changed in place, so that the files joined to it stay
			if ($m['mode'] === 'draft' || ($m['mode'] === 'new' && $draftId !== '')) {
				$client->updateDraft($draftId, $m['subject'], $m['html'], $m['to'], $m['cc'], $m['bcc'], $m['importance']);
				return ['draftId' => $draftId];
			}
			return ['draftId' => $client->saveDraft($draftId !== '' ? $draftId : null, $m['mode'], $m['subject'], $m['html'],
				$m['to'], $m['cc'], $m['bcc'], $m['referenceId'], $m['importance'])];
		});
	}

	/**
	 * Files joined to a draft (multipart: draftId, files[], filePaths[]):
	 * saved in Exchange with it.
	 */
	#[NoAdminRequired]
	#[\OCP\AppFramework\Http\Attribute\UserRateLimit(limit: 60, period: 300)]
	public function draftAttachments(int $id): Response {
		return $this->handle(function () use ($id) {
			$draftId = (string)$this->request->getParam('draftId', '');
			if ($draftId === '') {
				throw new MailException('Brouillon manquant');
			}
			$client = $this->client($id);
			$new = $this->collectAttachments();
			$size = array_sum(array_map(fn ($a) => strlen($a['content']), $new))
				+ array_sum(array_column($client->draftAttachments($draftId), 'size'));
			if ($size > self::MAX_ATTACHMENTS_BYTES) {
				throw new MailException('Pièces jointes trop lourdes (%1$s Mo, %2$s Mo au plus) : envoyez les plus grosses en lien Nextcloud',
					[round($size / 1048576, 1), self::MAX_ATTACHMENTS_BYTES / 1048576]);
			}
			return ['attachments' => $client->addDraftAttachments($draftId, $new)];
		});
	}

	#[NoAdminRequired]
	public function deleteDraftAttachment(int $id, string $draftId, string $attachmentId): Response {
		return $this->handle(function () use ($id, $draftId, $attachmentId) {
			$client = $this->client($id);
			// only a file of this draft
			if (!in_array($attachmentId, array_column($client->draftAttachments($draftId), 'id'), true)) {
				throw new MailException('Pièce jointe introuvable dans le brouillon');
			}
			$client->deleteAttachment($attachmentId);
			return ['attachments' => $client->draftAttachments($draftId)];
		});
	}

	#[NoAdminRequired]
	public function deleteDraft(int $id, string $draftId): Response {
		return $this->handle(function () use ($id, $draftId) {
			$this->client($id)->deleteItems([$draftId], true);
			return ['status' => 'ok'];
		});
	}

	/**
	 * Multipart form: mode (new, reply, replyAll, forward, or draft for a draft
	 * opened from « Brouillons »), to, cc, bcc (comma separated), subject,
	 * bodyHtml (editor) or body (plain text), referenceId, importance, draftId,
	 * files[] (uploads), filePaths[] (Nextcloud files).
	 */
	#[NoAdminRequired]
	public function send(int $id): Response {
		return $this->handle(function () use ($id) {
			$account = $this->accounts->find((string)$this->userId, $id);
			$client = $this->accounts->getClient($account);

			$m = $this->composed(true);
			$attachments = $this->collectAttachments();
			// images pasted in the text: inline attachments (cid:)
			$inline = HtmlSanitizer::extractInlineImages($m['html']);
			$m['html'] = $inline['html'];
			$size = array_sum(array_map(fn ($a) => strlen($a['content']), array_merge($attachments, $inline['images'])));
			if ($size > self::MAX_ATTACHMENTS_BYTES) {
				throw new MailException('Pièces jointes et images trop lourdes (%1$s Mo, %2$s Mo au plus) : envoyez les plus grosses en lien Nextcloud',
					[round($size / 1048576, 1), self::MAX_ATTACHMENTS_BYTES / 1048576]);
			}
			$attachments = array_merge($attachments, $inline['images']);
			$draftId = (string)$this->request->getParam('draftId', '');

			$receipt = $this->request->getParam('readReceipt', '');
			$options = [
				'readReceipt' => $receipt === true || $receipt === '1' || $receipt === 'true',
				'sendAt' => (string)$this->request->getParam('sendAt', ''),
			];
			// sent as a shared mailbox added to this account
			$from = strtolower(trim((string)$this->request->getParam('from', '')));
			if ($from !== '' && $from !== strtolower($account->getEmail())) {
				$options['from'] = $this->sharedMailbox($id, $from)['email'];
			}
			if ($m['mode'] === 'draft') {
				// a draft opened from « Brouillons »: sent as it is in Exchange, with the changes made here
				if ($m['to'] === [] && $m['cc'] === [] && $m['bcc'] === []) {
					throw new MailException('Indiquez au moins un destinataire');
				}
				$client->updateDraft($draftId, $m['subject'], $m['html'], $m['to'], $m['cc'], $m['bcc'], $m['importance']);
				$client->setSendOptions($draftId, $options);
				$client->sendSavedDraft($draftId, $attachments);
				return ['status' => 'ok'];
			}
			$client->sendMessage($m['mode'], $m['subject'], $m['html'], $m['to'], $m['cc'], $m['bcc'], $attachments,
				$m['referenceId'], $m['importance'], $options);
			if ($draftId !== '') {
				// the draft saved while writing is no longer needed
				try {
					$client->deleteItems([$draftId], true);
				} catch (MailException $e) {
					$this->logger->info('Webmail: draft not deleted after sending: ' . $e->getMessage());
				}
			}
			return ['status' => 'ok', 'sendAt' => $options['sendAt'] !== '' ? $options['sendAt'] : null];
		});
	}

	/**
	 * @return array<int, array{name: string, contentType: string, content: string}>
	 */
	private function collectAttachments(): array {
		$out = [];
		$total = 0;

		$uploads = $this->request->getUploadedFile('files');
		if (is_array($uploads) && isset($uploads['name'])) {
			$names = (array)$uploads['name'];
			$tmp = (array)$uploads['tmp_name'];
			$types = (array)$uploads['type'];
			$errors = (array)$uploads['error'];
			foreach ($names as $i => $name) {
				if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
					continue;
				}
				if (($errors[$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$tmp[$i])) {
					throw new MailException('Échec de l\'envoi de la pièce jointe « %s » (fichier trop gros ?)', [$name]);
				}
				$content = (string)file_get_contents((string)$tmp[$i]);
				$total += strlen($content);
				$out[] = ['name' => (string)$name, 'contentType' => (string)($types[$i] ?: 'application/octet-stream'), 'content' => $content];
			}
		}

		$paths = $this->request->getParam('filePaths', []);
		if (is_array($paths) && $paths !== []) {
			$userFolder = $this->rootFolder->getUserFolder((string)$this->userId);
			foreach ($paths as $path) {
				try {
					$node = $userFolder->get((string)$path);
				} catch (NotFoundException) {
					throw new MailException('Fichier introuvable : %s', [$path]);
				}
				if (!$node instanceof File || !$node->isReadable()) {
					throw new MailException('Impossible de joindre : %s', [$path]);
				}
				$total += $node->getSize();
				if ($total > self::MAX_ATTACHMENTS_BYTES) {
					break;
				}
				$out[] = ['name' => $node->getName(), 'contentType' => $node->getMimetype(), 'content' => $node->getContent()];
			}
		}

		if ($total > self::MAX_ATTACHMENTS_BYTES) {
			throw new MailException('Les pièces jointes dépassent 25 Mo');
		}
		return $out;
	}

	/** @return string[] */
	public static function splitAddresses(string $value): array {
		$out = [];
		foreach (preg_split('/[,;\n]+/', $value) ?: [] as $part) {
			$part = trim($part);
			if ($part === '') {
				continue;
			}
			// "Name <addr@x>" => addr@x
			if (preg_match('/<([^>]+)>/', $part, $m)) {
				$part = trim($m[1]);
			}
			$out[] = $part;
		}
		return array_values(array_unique($out));
	}

	public static function safeFileName(string $name): string {
		$name = str_replace(['/', '\\', "\0"], '_', $name);
		$name = trim((string)preg_replace('/[\x00-\x1f]/', '', $name), ' .');
		return $name !== '' ? $name : 'piece-jointe';
	}

	private static function asciiName(string $name): string {
		$ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
		return (string)preg_replace('/[^\x20-\x7e]/', '_', $ascii !== false ? $ascii : $name);
	}
}
