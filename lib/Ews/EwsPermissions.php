<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;

/**
 * Folder permissions (Outlook: right-click on a folder > Permissions):
 * share a calendar or a contacts folder without delegation.
 *
 * Calendars use CalendarPermissionSet / CalendarPermission /
 * CalendarPermissionLevel (with free/busy levels), other folders use
 * PermissionSet / Permission / PermissionLevel.
 */
class EwsPermissions {
	public const CALENDAR = 'calendar';
	public const CONTACTS = 'contacts';

	/** Levels offered in the UI; others (Owner, Custom...) are kept as they are. */
	public const CALENDAR_LEVELS = ['None', 'FreeBusyTimeOnly', 'FreeBusyTimeAndSubjectAndLocation', 'Reviewer', 'Author', 'Editor'];
	public const FOLDER_LEVELS = ['None', 'Reviewer', 'Author', 'Editor'];

	private string $setElement;
	private string $listElement;
	private string $entryElement;
	private string $levelElement;
	private string $folderElement;
	/** @var string[] */
	private array $levels;

	public function __construct(
		private EwsClient $client,
		string $kind,
	) {
		if ($kind === self::CALENDAR) {
			[$this->listElement, $this->entryElement, $this->levelElement, $this->folderElement, $this->levels]
				= ['CalendarPermissions', 'CalendarPermission', 'CalendarPermissionLevel', 'CalendarFolder', self::CALENDAR_LEVELS];
		} elseif ($kind === self::CONTACTS) {
			[$this->listElement, $this->entryElement, $this->levelElement, $this->folderElement, $this->levels]
				= ['Permissions', 'Permission', 'PermissionLevel', 'ContactsFolder', self::FOLDER_LEVELS];
		} else {
			throw new \InvalidArgumentException('Unknown folder kind ' . $kind);
		}
		$this->setElement = 'PermissionSet';
	}

	/**
	 * user is "Default" (everyone in the organisation), "Anonymous" or an e-mail address.
	 * @return array<int, array{user: string, name: string, level: string}>
	 */
	public function get(string $folderXml): array {
		$out = [];
		foreach ($this->nodes($folderXml) as [$user, $name, $level]) {
			$out[] = ['user' => $user, 'name' => $name, 'level' => $level];
		}
		return $out;
	}

	/**
	 * Replace the permissions of the folder.
	 * @param array<int, array{user: string, level: string}> $entries level "keep" = leave as it is
	 *        (for levels set in Outlook that the UI does not offer, e.g. Custom)
	 */
	public function set(string $folderXml, array $entries, string $ownEmail): void {
		$byUser = [];
		foreach ($this->nodes($folderXml) as [$user, , , $node]) {
			$byUser[strtolower($user)] = $node;
		}

		$xml = '';
		$seen = [];
		foreach ($entries as $e) {
			$user = trim((string)($e['user'] ?? ''));
			$level = (string)($e['level'] ?? '');
			$distinguished = in_array($user, ['Default', 'Anonymous'], true);
			if (!$distinguished) {
				$user = strtolower($user);
				if (preg_match('/<([^>]+)>/', $user, $m)) {
					$user = trim($m[1]);
				}
				if (!filter_var($user, FILTER_VALIDATE_EMAIL)) {
					throw new MailException('Adresse invalide : %s', [$user]);
				}
				if (strcasecmp($user, $ownEmail) === 0) {
					throw new MailException('Vous êtes déjà propriétaire de ce dossier');
				}
			}
			$key = strtolower($user);
			if (isset($seen[$key])) {
				throw new MailException('Personne en double : %s', [$user]);
			}
			$seen[$key] = true;

			if ($level === 'keep') {
				if (!isset($byUser[$key])) {
					throw new MailException('Autorisation introuvable pour %s', [$user]);
				}
				$xml .= $this->rebuild($byUser[$key]);
				continue;
			}
			if (!in_array($level, $this->levels, true)) {
				throw new MailException('Niveau de partage inconnu : %s', [$level]);
			}
			$xml .= $this->entry($distinguished
				? '<t:DistinguishedUser>' . $user . '</t:DistinguishedUser>'
				: '<t:PrimarySmtpAddress>' . $this->client->esc($user) . '</t:PrimarySmtpAddress>', $level);
		}
		// Exchange expects the Default and Anonymous entries to stay present
		foreach (['default' => 'Default', 'anonymous' => 'Anonymous'] as $key => $name) {
			if (!isset($seen[$key])) {
				$xml .= isset($byUser[$key]) ? $this->rebuild($byUser[$key])
					: $this->entry('<t:DistinguishedUser>' . $name . '</t:DistinguishedUser>', 'None');
			}
		}

		$this->client->request(
			'<m:UpdateFolder><m:FolderChanges><t:FolderChange>' . $folderXml
			. '<t:Updates><t:SetFolderField><t:FieldURI FieldURI="folder:PermissionSet"/>'
			. '<t:' . $this->folderElement . '><t:' . $this->setElement . '><t:' . $this->listElement . '>' . $xml
			. '</t:' . $this->listElement . '></t:' . $this->setElement . '></t:' . $this->folderElement . '>'
			. '</t:SetFolderField></t:Updates></t:FolderChange></m:FolderChanges></m:UpdateFolder>'
		);
	}

	private function entry(string $userXml, string $level): string {
		return '<t:' . $this->entryElement . '><t:UserId>' . $userXml . '</t:UserId>'
			. '<t:' . $this->levelElement . '>' . $level . '</t:' . $this->levelElement . '></t:' . $this->entryElement . '>';
	}

	/**
	 * @return array<int, array{0: string, 1: string, 2: string, 3: \DOMElement}>
	 */
	private function nodes(string $folderXml): array {
		$xp = $this->client->request(
			'<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="folder:PermissionSet"/></t:AdditionalProperties></m:FolderShape>'
			. '<m:FolderIds>' . $folderXml . '</m:FolderIds></m:GetFolder>'
		);
		$out = [];
		$path = '//t:' . $this->setElement . '/t:' . $this->listElement . '/t:' . $this->entryElement;
		foreach ($xp->query($path) as $node) {
			$distinguished = $this->client->text($xp, 't:UserId/t:DistinguishedUser', $node);
			$user = $distinguished ?? strtolower((string)$this->client->text($xp, 't:UserId/t:PrimarySmtpAddress', $node));
			if ($user === '') {
				continue; // e.g. an external or deleted user: kept by Exchange in UnknownEntries
			}
			$out[] = [$user, (string)$this->client->text($xp, 't:UserId/t:DisplayName', $node),
				(string)$this->client->text($xp, 't:' . $this->levelElement, $node), $node];
		}
		return $out;
	}

	/**
	 * Rebuild a permission returned by Exchange with our own prefixes and
	 * escaping (never copied verbatim into the request).
	 */
	private function rebuild(\DOMElement $el): string {
		$inner = '';
		$hasChildren = false;
		foreach ($el->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				$hasChildren = true;
				if ($child->localName === 'SID') {
					continue; // the SMTP address identifies the user
				}
				$inner .= $this->rebuild($child);
			}
		}
		$name = preg_replace('/[^A-Za-z]/', '', $el->localName);
		return '<t:' . $name . '>' . ($hasChildren ? $inner : $this->client->esc($el->textContent)) . '</t:' . $name . '>';
	}
}
