<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;
use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * « Note » from a message: a Markdown file in the user's Notes folder (the one Nextcloud
 * Notes uses, « Notes » by default), opened in Notes when the app is there, else in Files.
 */
class NoteService {
	public function __construct(
		private AccountService $accounts,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IAppManager $apps,
		private IURLGenerator $url,
		private IL10N $l,
	) {
	}

	/**
	 * The message as Markdown: its subject, sender, date, text and a link back to it.
	 *
	 * @return array{subject: string, markdown: string}
	 * @throws MailException
	 */
	private function markdownOf(string $userId, int $accountId, string $itemId, int $maxText = MessageNote::MAX_TEXT): array {
		$account = $this->accounts->find($userId, $accountId);
		$m = $this->accounts->getClient($account)->getItem($itemId);
		$from = is_array($m['from'] ?? null) ? trim(($m['from']['name'] !== '' && $m['from']['name'] !== $m['from']['email'] ? $m['from']['name'] . ' ' : '') . '<' . $m['from']['email'] . '>') : '';
		$date = '';
		if (!empty($m['date'])) {
			try {
				$tz = new \DateTimeZone($this->config->getUserValue($userId, 'core', 'timezone', '') ?: date_default_timezone_get());
			} catch (\Exception) {
				$tz = new \DateTimeZone('UTC');
			}
			$date = (string)$this->l->l('datetime', (new \DateTime((string)$m['date']))->setTimezone($tz), ['width' => 'medium']);
		}
		$text = ($m['bodyType'] ?? 'HTML') === 'Text' ? trim((string)$m['body']) : MessageNote::text((string)$m['body']);
		if (mb_strlen($text) > $maxText) {
			$text = rtrim(mb_substr($text, 0, $maxText)) . ' …';
		}
		$link = $this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $accountId, 'message' => $itemId]);
		return [
			'subject' => (string)$m['subject'],
			'markdown' => MessageNote::markdown((string)$m['subject'], $from, $date, $text, $link, $this->l->t('De'), $this->l->t('Date'), $this->l->t('Ouvrir dans le webmail')),
		];
	}

	/**
	 * « Carte Deck »: the title and the description (Markdown, without the title) of a card
	 * made from a message; the card itself is made by the browser, with Deck's own API.
	 *
	 * @return array{title: string, description: string}
	 * @throws MailException
	 */
	public function card(string $userId, int $accountId, string $itemId): array {
		$note = $this->markdownOf($userId, $accountId, $itemId, MessageNote::CARD_TEXT);
		return [
			'title' => MessageNote::cardTitle($note['subject']),
			'description' => (string)preg_replace('/^# [^\n]*\n\n/', '', $note['markdown']),
		];
	}

	/**
	 * @return array{path: string, url: string, app: string}
	 * @throws MailException
	 */
	public function fromMessage(string $userId, int $accountId, string $itemId): array {
		$note = $this->markdownOf($userId, $accountId, $itemId);
		$md = $note['markdown'];
		$m = ['subject' => $note['subject']];

		$userFolder = $this->rootFolder->getUserFolder($userId);
		// the folder of Nextcloud Notes (its own setting), « Notes » otherwise
		$path = '/' . trim($this->config->getUserValue($userId, 'notes', 'notesPath', '') ?: 'Notes', '/');
		try {
			$folder = $userFolder->get($path);
		} catch (NotFoundException) {
			$folder = $userFolder->newFolder($path);
		}
		if (!$folder instanceof Folder || !$folder->isCreatable()) {
			throw new MailException('Le dossier des notes n\'est pas accessible en écriture');
		}
		$file = $folder->newFile($folder->getNonExistingName(MessageNote::fileName((string)$m['subject'])), $md);
		$notes = $this->apps->isEnabledForUser('notes');
		return [
			'path' => (string)$userFolder->getRelativePath($file->getPath()),
			'url' => $notes
				? $this->url->linkToRouteAbsolute('notes.page.index') . 'note/' . $file->getId()
				: $this->url->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $file->getId()]),
			'app' => $notes ? 'notes' : 'files',
		];
	}
}
