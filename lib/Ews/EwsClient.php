<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;

/**
 * Small, dependency-free Exchange Web Services (EWS) SOAP client.
 *
 * Covers what the webmail needs: folders, message list, message details,
 * attachments, read/flag state, move, delete and sending (new, reply,
 * reply-all, forward). Targets Exchange 2013 SP1+ (OVH Hosted Exchange
 * runs 2016/2019).
 */
class EwsClient {
	public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
	public const NS_M = 'http://schemas.microsoft.com/exchange/services/2006/messages';
	public const NS_T = 'http://schemas.microsoft.com/exchange/services/2006/types';

	/** Folders that can be addressed by their well-known name. */
	public const DISTINGUISHED = ['inbox', 'drafts', 'sentitems', 'deleteditems', 'junkemail', 'outbox', 'msgfolderroot', 'archivemsgfolderroot'];

	/** @var null|callable(string $url, string $body): array{0: int, 1: string} */
	private $transport;

	/** Windows time zone id sent in the TimeZoneContext header (e.g. "Romance Standard Time"). */
	private ?string $timeZoneId = null;

	public function __construct(
		private string $url,
		private string $username,
		private string $password,
		private int $timeout = 60,
		private bool $verifyPeer = true,
		?callable $transport = null,
	) {
		$this->transport = $transport;
	}

	/**
	 * Interpret times without offset (and all-day events) in this time zone.
	 */
	public function setTimeZone(?string $windowsId): void {
		$this->timeZoneId = $windowsId !== null && $windowsId !== '' ? $windowsId : null;
	}

	public function getTimeZone(): ?string {
		return $this->timeZoneId;
	}

	// ------------------------------------------------------------------
	// Folders
	// ------------------------------------------------------------------

	/**
	 * All mail folders below the mailbox root (recursive), plus the ids of
	 * the well-known folders so the UI can show them first.
	 *
	 * @return array<int, array{id: string, name: string, parentId: ?string, total: int, unread: int, role: ?string}>
	 */
	public function listFolders(): array {
		$roles = $this->distinguishedFolderIds(['inbox', 'drafts', 'sentitems', 'deleteditems', 'junkemail', 'msgfolderroot']);
		$rootId = $roles['msgfolderroot'] ?? null;
		unset($roles['msgfolderroot']);
		$roleById = array_flip($roles);

		$xp = $this->request(
			'<m:FindFolder Traversal="Deep">'
			. '<m:FolderShape><t:BaseShape>Default</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="folder:FolderClass"/><t:FieldURI FieldURI="folder:ParentFolderId"/>'
			. '</t:AdditionalProperties></m:FolderShape>'
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="msgfolderroot"/></m:ParentFolderIds>'
			. '</m:FindFolder>'
		);

		$folders = [];
		foreach ($xp->query('//t:Folders/t:Folder') as $node) {
			$class = $this->text($xp, 't:FolderClass', $node);
			if ($class !== null && $class !== '' && !str_starts_with($class, 'IPF.Note')) {
				continue; // calendars, contacts, tasks... are not mail folders
			}
			$id = $this->attr($xp, 't:FolderId', 'Id', $node);
			if ($id === null) {
				continue;
			}
			$parent = $this->attr($xp, 't:ParentFolderId', 'Id', $node);
			$folders[] = [
				'id' => $id,
				'name' => (string)$this->text($xp, 't:DisplayName', $node),
				'parentId' => ($parent === $rootId) ? null : $parent,
				'total' => (int)$this->text($xp, 't:TotalCount', $node),
				'unread' => (int)$this->text($xp, 't:UnreadCount', $node),
				'role' => $roleById[$id] ?? null,
			];
		}
		return $folders;
	}

	/**
	 * @param string[] $names
	 * @return array<string, string> distinguished name => FolderId
	 */
	public function distinguishedFolderIds(array $names): array {
		$ids = '';
		foreach ($names as $n) {
			$ids .= '<t:DistinguishedFolderId Id="' . $this->esc($n) . '"/>';
		}
		$xp = $this->request(
			'<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape></m:FolderShape>'
			. '<m:FolderIds>' . $ids . '</m:FolderIds></m:GetFolder>',
			false
		);
		$out = [];
		$messages = $xp->query('//m:ResponseMessages/m:GetFolderResponseMessage');
		foreach ($messages as $i => $msg) {
			if ($msg instanceof \DOMElement && $msg->getAttribute('ResponseClass') === 'Success') {
				$id = $this->attr($xp, './/t:FolderId', 'Id', $msg);
				if ($id !== null && isset($names[$i])) {
					$out[$names[$i]] = $id;
				}
			}
		}
		return $out;
	}

	/** Cheap call used to validate credentials. */
	public function testConnection(): void {
		$this->request(
			'<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape></m:FolderShape>'
			. '<m:FolderIds><t:DistinguishedFolderId Id="inbox"/></m:FolderIds></m:GetFolder>'
		);
	}

	// ------------------------------------------------------------------
	// Messages
	// ------------------------------------------------------------------

	/**
	 * @return array{items: array<int, array<string, mixed>>, total: int, hasMore: bool}
	 */
	public function findItems(string $folderId, int $offset = 0, int $limit = 50, ?string $query = null): array {
		$props = ['item:Subject', 'message:From', 'item:DisplayTo', 'item:DateTimeReceived', 'message:IsRead',
			'item:HasAttachments', 'item:Importance', 'item:Flag', 'item:Preview'];
		$fields = '';
		foreach ($props as $p) {
			$fields .= '<t:FieldURI FieldURI="' . $p . '"/>';
		}
		$queryXml = '';
		if ($query !== null && trim($query) !== '') {
			$queryXml = '<m:QueryString>' . $this->esc(trim($query)) . '</m:QueryString>';
		}

		$xp = $this->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $fields . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . max(1, $limit) . '" Offset="' . max(0, $offset) . '" BasePoint="Beginning"/>'
			. '<m:SortOrder><t:FieldOrder Order="Descending"><t:FieldURI FieldURI="item:DateTimeReceived"/></t:FieldOrder></m:SortOrder>'
			. '<m:ParentFolderIds>' . $this->folderIdXml($folderId) . '</m:ParentFolderIds>'
			. $queryXml
			. '</m:FindItem>'
		);

		$root = $xp->query('//m:RootFolder')->item(0);
		$total = $root instanceof \DOMElement ? (int)$root->getAttribute('TotalItemsInView') : 0;
		$last = $root instanceof \DOMElement ? $root->getAttribute('IncludesLastItemInRange') === 'true' : true;

		$items = [];
		foreach ($xp->query('//m:RootFolder/t:Items/*') as $node) {
			$items[] = [
				'id' => $this->attr($xp, 't:ItemId', 'Id', $node),
				'changeKey' => $this->attr($xp, 't:ItemId', 'ChangeKey', $node),
				'subject' => (string)$this->text($xp, 't:Subject', $node),
				'from' => $this->mailbox($xp, 't:From/t:Mailbox', $node),
				'displayTo' => (string)$this->text($xp, 't:DisplayTo', $node),
				'date' => $this->text($xp, 't:DateTimeReceived', $node),
				'isRead' => $this->text($xp, 't:IsRead', $node) !== 'false',
				'hasAttachments' => $this->text($xp, 't:HasAttachments', $node) === 'true',
				'importance' => $this->text($xp, 't:Importance', $node) ?? 'Normal',
				'flagged' => $this->text($xp, 't:Flag/t:FlagStatus', $node) === 'Flagged',
				'preview' => mb_substr((string)$this->text($xp, 't:Preview', $node), 0, 200),
				'isMeeting' => $node->localName !== 'Message',
			];
		}
		return ['items' => $items, 'total' => $total, 'hasMore' => !$last];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getItem(string $itemId): array {
		$props = ['item:Subject', 'item:Body', 'message:From', 'message:Sender', 'message:ToRecipients', 'message:CcRecipients',
			'message:BccRecipients', 'message:ReplyTo', 'item:DateTimeReceived', 'item:DateTimeSent', 'message:IsRead',
			'item:Attachments', 'item:HasAttachments', 'item:Importance', 'item:Flag', 'message:InternetMessageId', 'item:ParentFolderId'];
		$fields = '';
		foreach ($props as $p) {
			$fields .= '<t:FieldURI FieldURI="' . $p . '"/>';
		}
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:BodyType>HTML</t:BodyType>'
			. '<t:AdditionalProperties>' . $fields . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->esc($itemId) . '"/></m:ItemIds></m:GetItem>'
		);
		$node = $xp->query('//m:Items/*')->item(0);
		if ($node === null) {
			throw new MailException('Message introuvable');
		}

		$attachments = [];
		foreach ($xp->query('t:Attachments/*', $node) as $att) {
			$attachments[] = [
				'id' => $this->attr($xp, 't:AttachmentId', 'Id', $att),
				'name' => (string)($this->text($xp, 't:Name', $att) ?? 'piece-jointe'),
				'contentType' => (string)($this->text($xp, 't:ContentType', $att) ?? 'application/octet-stream'),
				'size' => (int)$this->text($xp, 't:Size', $att),
				'contentId' => $this->text($xp, 't:ContentId', $att),
				'isInline' => $this->text($xp, 't:IsInline', $att) === 'true',
				'isItem' => $att->localName === 'ItemAttachment',
			];
		}

		return [
			'id' => $this->attr($xp, 't:ItemId', 'Id', $node),
			'changeKey' => $this->attr($xp, 't:ItemId', 'ChangeKey', $node),
			'folderId' => $this->attr($xp, 't:ParentFolderId', 'Id', $node),
			'subject' => (string)$this->text($xp, 't:Subject', $node),
			'body' => (string)$this->text($xp, 't:Body', $node),
			'bodyType' => $this->attr($xp, 't:Body', 'BodyType', $node) ?? 'HTML',
			'from' => $this->mailbox($xp, 't:From/t:Mailbox', $node) ?? $this->mailbox($xp, 't:Sender/t:Mailbox', $node),
			'to' => $this->mailboxes($xp, 't:ToRecipients/t:Mailbox', $node),
			'cc' => $this->mailboxes($xp, 't:CcRecipients/t:Mailbox', $node),
			'bcc' => $this->mailboxes($xp, 't:BccRecipients/t:Mailbox', $node),
			'replyTo' => $this->mailboxes($xp, 't:ReplyTo/t:Mailbox', $node),
			'date' => $this->text($xp, 't:DateTimeReceived', $node) ?? $this->text($xp, 't:DateTimeSent', $node),
			'isRead' => $this->text($xp, 't:IsRead', $node) !== 'false',
			'importance' => $this->text($xp, 't:Importance', $node) ?? 'Normal',
			'flagged' => $this->text($xp, 't:Flag/t:FlagStatus', $node) === 'Flagged',
			'messageId' => $this->text($xp, 't:InternetMessageId', $node),
			'attachments' => $attachments,
			'isMeeting' => $node->localName !== 'Message',
			'itemType' => $node->localName,
			'meeting' => in_array($node->localName, ['MeetingRequest', 'MeetingCancellation'], true) ? $this->meetingInfo($itemId) : null,
		];
	}

	/**
	 * Date, place and current answer of a meeting invitation received by e-mail.
	 * @return ?array<string, mixed>
	 */
	private function meetingInfo(string $itemId): ?array {
		$fields = '';
		foreach (['calendar:Start', 'calendar:End', 'calendar:IsAllDayEvent', 'calendar:Location', 'calendar:MyResponseType', 'calendar:IsCancelled'] as $p) {
			$fields .= '<t:FieldURI FieldURI="' . $p . '"/>';
		}
		try {
			$xp = $this->request(
				'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $fields . '</t:AdditionalProperties></m:ItemShape>'
				. '<m:ItemIds><t:ItemId Id="' . $this->esc($itemId) . '"/></m:ItemIds></m:GetItem>'
			);
		} catch (MailException) {
			return null;
		}
		$node = $xp->query('//m:Items/*')->item(0);
		if ($node === null) {
			return null;
		}
		return [
			'start' => $this->text($xp, 't:Start', $node),
			'end' => $this->text($xp, 't:End', $node),
			'allDay' => $this->text($xp, 't:IsAllDayEvent', $node) === 'true',
			'location' => (string)$this->text($xp, 't:Location', $node),
			'response' => $this->text($xp, 't:MyResponseType', $node) ?? 'Unknown',
			'cancelled' => $node->localName === 'MeetingCancellation' || $this->text($xp, 't:IsCancelled', $node) === 'true',
		];
	}

	/**
	 * @return array{name: string, contentType: string, content: string}
	 */
	public function getAttachment(string $attachmentId): array {
		$xp = $this->request(
			'<m:GetAttachment><m:AttachmentShape><t:IncludeMimeContent>true</t:IncludeMimeContent></m:AttachmentShape>'
			. '<m:AttachmentIds><t:AttachmentId Id="' . $this->esc($attachmentId) . '"/></m:AttachmentIds></m:GetAttachment>'
		);
		$file = $xp->query('//m:Attachments/t:FileAttachment')->item(0);
		if ($file !== null) {
			return [
				'name' => (string)($this->text($xp, 't:Name', $file) ?? 'piece-jointe'),
				'contentType' => (string)($this->text($xp, 't:ContentType', $file) ?? 'application/octet-stream'),
				'content' => (string)base64_decode((string)$this->text($xp, 't:Content', $file)),
			];
		}
		// Attached e-mail (ItemAttachment): return it as an .eml file
		$item = $xp->query('//m:Attachments/t:ItemAttachment')->item(0);
		if ($item !== null) {
			$name = (string)($this->text($xp, 't:Name', $item) ?? 'message');
			return [
				'name' => preg_match('/\.eml$/i', $name) ? $name : $name . '.eml',
				'contentType' => 'message/rfc822',
				'content' => (string)base64_decode((string)$this->text($xp, './/t:MimeContent', $item)),
			];
		}
		throw new MailException('Pièce jointe introuvable');
	}

	/** @param string[] $itemIds */
	public function setRead(array $itemIds, bool $read): void {
		$this->updateItems($itemIds, 'message:IsRead',
			'<t:Message><t:IsRead>' . ($read ? 'true' : 'false') . '</t:IsRead></t:Message>');
	}

	/** @param string[] $itemIds */
	public function setFlagged(array $itemIds, bool $flagged): void {
		$this->updateItems($itemIds, 'item:Flag',
			'<t:Message><t:Flag><t:FlagStatus>' . ($flagged ? 'Flagged' : 'NotFlagged') . '</t:FlagStatus></t:Flag></t:Message>');
	}

	/** @param string[] $itemIds */
	private function updateItems(array $itemIds, string $field, string $valueXml): void {
		if ($itemIds === []) {
			return;
		}
		$changes = '';
		foreach ($itemIds as $id) {
			$changes .= '<t:ItemChange><t:ItemId Id="' . $this->esc($id) . '"/><t:Updates><t:SetItemField>'
				. '<t:FieldURI FieldURI="' . $field . '"/>' . $valueXml
				. '</t:SetItemField></t:Updates></t:ItemChange>';
		}
		$this->request(
			'<m:UpdateItem MessageDisposition="SaveOnly" ConflictResolution="AlwaysOverwrite" SuppressReadReceipts="true">'
			. '<m:ItemChanges>' . $changes . '</m:ItemChanges></m:UpdateItem>'
		);
	}

	/** @param string[] $itemIds */
	public function moveItems(array $itemIds, string $targetFolderId): void {
		if ($itemIds === []) {
			return;
		}
		$this->request(
			'<m:MoveItem><m:ToFolderId>' . $this->folderIdXml($targetFolderId) . '</m:ToFolderId>'
			. '<m:ItemIds>' . $this->itemIdsXml($itemIds) . '</m:ItemIds></m:MoveItem>'
		);
	}

	/**
	 * @param string[] $itemIds
	 * @param bool $permanent true = HardDelete, false = move to "Deleted Items"
	 */
	public function deleteItems(array $itemIds, bool $permanent = false): void {
		if ($itemIds === []) {
			return;
		}
		$this->request(
			'<m:DeleteItem DeleteType="' . ($permanent ? 'HardDelete' : 'MoveToDeletedItems') . '">'
			. '<m:ItemIds>' . $this->itemIdsXml($itemIds) . '</m:ItemIds></m:DeleteItem>'
		);
	}

	// ------------------------------------------------------------------
	// Sending
	// ------------------------------------------------------------------

	/**
	 * Send a message.
	 *
	 * @param string $mode new | reply | replyAll | forward
	 * @param string[] $to
	 * @param string[] $cc
	 * @param string[] $bcc
	 * @param array<int, array{name: string, contentType: string, content: string}> $attachments
	 * @param ?string $referenceId item replied to / forwarded (required unless mode = new)
	 */
	public function sendMessage(string $mode, string $subject, string $htmlBody, array $to, array $cc, array $bcc,
		array $attachments = [], ?string $referenceId = null, string $importance = 'Normal'): void {
		$recipients = $this->recipientsXml('ToRecipients', $to)
			. $this->recipientsXml('CcRecipients', $cc)
			. $this->recipientsXml('BccRecipients', $bcc);
		$importance = in_array($importance, ['Low', 'Normal', 'High'], true) ? $importance : 'Normal';

		if ($mode === 'new') {
			$attXml = '';
			foreach ($attachments as $a) {
				$attXml .= $this->fileAttachmentXml($a);
			}
			$item = '<t:Message>'
				. '<t:Subject>' . $this->esc($subject) . '</t:Subject>'
				. '<t:Body BodyType="HTML">' . $this->esc($htmlBody) . '</t:Body>'
				. ($attXml !== '' ? '<t:Attachments>' . $attXml . '</t:Attachments>' : '')
				. '<t:Importance>' . $importance . '</t:Importance>'
				. $recipients
				. '</t:Message>';
			if ($attachments === []) {
				$this->createAndSend($item);
				return;
			}
			$draft = $this->createDraft($item);
			$this->sendDraft($draft['id'], $draft['changeKey']);
			return;
		}

		$element = match ($mode) {
			'reply' => 'ReplyToItem',
			'replyAll' => 'ReplyAllToItem',
			'forward' => 'ForwardItem',
			default => throw new MailException('Type d\'envoi inconnu'),
		};
		if ($referenceId === null) {
			throw new MailException('Message d\'origine manquant');
		}
		$ref = $this->itemRef($referenceId);
		$item = '<t:' . $element . '>'
			. $recipients
			. '<t:ReferenceItemId Id="' . $this->esc($ref['id']) . '" ChangeKey="' . $this->esc($ref['changeKey']) . '"/>'
			. '<t:NewBodyContent BodyType="HTML">' . $this->esc($htmlBody) . '</t:NewBodyContent>'
			. '</t:' . $element . '>';

		if ($attachments === []) {
			$this->createAndSend($item);
			return;
		}
		$draft = $this->createDraft($item);
		$changeKey = $this->addAttachments($draft['id'], $attachments);
		$this->sendDraft($draft['id'], $changeKey);
	}

	private function createAndSend(string $itemXml): void {
		$this->request(
			'<m:CreateItem MessageDisposition="SendAndSaveCopy">'
			. '<m:SavedItemFolderId><t:DistinguishedFolderId Id="sentitems"/></m:SavedItemFolderId>'
			. '<m:Items>' . $itemXml . '</m:Items></m:CreateItem>'
		);
	}

	/**
	 * @return array{id: string, changeKey: string}
	 */
	private function createDraft(string $itemXml): array {
		$xp = $this->request(
			'<m:CreateItem MessageDisposition="SaveOnly">'
			. '<m:SavedItemFolderId><t:DistinguishedFolderId Id="drafts"/></m:SavedItemFolderId>'
			. '<m:Items>' . $itemXml . '</m:Items></m:CreateItem>'
		);
		$id = $this->attr($xp, '//m:Items/*/t:ItemId', 'Id');
		if ($id === null) {
			throw new MailException('Exchange n\'a pas créé le brouillon');
		}
		return ['id' => $id, 'changeKey' => (string)$this->attr($xp, '//m:Items/*/t:ItemId', 'ChangeKey')];
	}

	/**
	 * @param array<int, array{name: string, contentType: string, content: string}> $attachments
	 * @return string new ChangeKey of the parent item
	 */
	private function addAttachments(string $itemId, array $attachments): string {
		$attXml = '';
		foreach ($attachments as $a) {
			$attXml .= $this->fileAttachmentXml($a);
		}
		$xp = $this->request(
			'<m:CreateAttachment><m:ParentItemId Id="' . $this->esc($itemId) . '"/>'
			. '<m:Attachments>' . $attXml . '</m:Attachments></m:CreateAttachment>'
		);
		$changeKeys = $xp->query('//t:AttachmentId/@RootItemChangeKey');
		$last = $changeKeys->length > 0 ? $changeKeys->item($changeKeys->length - 1)->nodeValue : null;
		return $last ?? $this->itemRef($itemId)['changeKey'];
	}

	private function sendDraft(string $itemId, string $changeKey): void {
		$this->request(
			'<m:SendItem SaveItemToFolder="true">'
			. '<m:ItemIds><t:ItemId Id="' . $this->esc($itemId) . '" ChangeKey="' . $this->esc($changeKey) . '"/></m:ItemIds>'
			. '<m:SavedItemFolderId><t:DistinguishedFolderId Id="sentitems"/></m:SavedItemFolderId>'
			. '</m:SendItem>'
		);
	}

	/**
	 * @return array{id: string, changeKey: string}
	 */
	public function itemRef(string $itemId): array {
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->esc($itemId) . '"/></m:ItemIds></m:GetItem>'
		);
		return [
			'id' => (string)($this->attr($xp, '//m:Items/*/t:ItemId', 'Id') ?? $itemId),
			'changeKey' => (string)$this->attr($xp, '//m:Items/*/t:ItemId', 'ChangeKey'),
		];
	}

	/**
	 * @param array{name: string, contentType: string, content: string} $a
	 */
	private function fileAttachmentXml(array $a): string {
		return '<t:FileAttachment>'
			. '<t:Name>' . $this->esc($a['name']) . '</t:Name>'
			. '<t:ContentType>' . $this->esc($a['contentType'] ?: 'application/octet-stream') . '</t:ContentType>'
			. '<t:Content>' . base64_encode($a['content']) . '</t:Content>'
			. '</t:FileAttachment>';
	}

	/** @param string[] $addresses */
	public function recipientsXml(string $element, array $addresses): string {
		$xml = '';
		foreach ($addresses as $addr) {
			$addr = trim($addr);
			if ($addr === '') {
				continue;
			}
			$xml .= '<t:Mailbox><t:EmailAddress>' . $this->esc($addr) . '</t:EmailAddress></t:Mailbox>';
		}
		return $xml === '' ? '' : '<t:' . $element . '>' . $xml . '</t:' . $element . '>';
	}

	// ------------------------------------------------------------------
	// Plumbing
	// ------------------------------------------------------------------

	public function folderIdXml(string $folderId): string {
		if (in_array($folderId, self::DISTINGUISHED, true)) {
			return '<t:DistinguishedFolderId Id="' . $folderId . '"/>';
		}
		return '<t:FolderId Id="' . $this->esc($folderId) . '"/>';
	}

	/** @param string[] $ids */
	public function itemIdsXml(array $ids): string {
		$xml = '';
		foreach ($ids as $id) {
			$xml .= '<t:ItemId Id="' . $this->esc((string)$id) . '"/>';
		}
		return $xml;
	}

	public function esc(string $s): string {
		// strip characters that are not allowed in XML 1.0
		$s = (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
		return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Send a SOAP request and return an XPath over the response.
	 *
	 * @param bool $strict throw when any ResponseMessage is an error
	 */
	public function request(string $bodyXml, bool $strict = true): \DOMXPath {
		$envelope = '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '" xmlns:m="' . self::NS_M . '" xmlns:t="' . self::NS_T . '">'
			. '<soap:Header><t:RequestServerVersion Version="Exchange2013_SP1"/>'
			. ($this->timeZoneId !== null ? '<t:TimeZoneContext><t:TimeZoneDefinition Id="' . $this->esc($this->timeZoneId) . '"/></t:TimeZoneContext>' : '')
			. '</soap:Header>'
			. '<soap:Body>' . $bodyXml . '</soap:Body></soap:Envelope>';

		[$status, $response] = $this->transport !== null
			? ($this->transport)($this->url, $envelope)
			: $this->httpPost($envelope);

		if ($status === 401) {
			throw new MailException('Identifiant ou mot de passe Exchange incorrect');
		}
		if ($status === 403) {
			throw new MailException('Accès EWS refusé par le serveur Exchange (vérifiez que EWS est autorisé pour ce compte)');
		}

		$doc = new \DOMDocument();
		$ok = $response !== '' && @$doc->loadXML($response, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
		if (!$ok) {
			throw new MailException(sprintf('Réponse invalide du serveur Exchange (HTTP %d)', $status));
		}
		$xp = new \DOMXPath($doc);
		$xp->registerNamespace('s', self::NS_SOAP);
		$xp->registerNamespace('m', self::NS_M);
		$xp->registerNamespace('t', self::NS_T);

		$fault = $xp->query('//s:Fault/faultstring')->item(0);
		if ($fault !== null) {
			throw new MailException('Erreur Exchange : ' . trim($fault->textContent));
		}
		if ($status >= 400) {
			throw new MailException(sprintf('Erreur du serveur Exchange (HTTP %d)', $status));
		}

		if ($strict) {
			foreach ($xp->query('//m:ResponseMessages/*[@ResponseClass="Error"]') as $err) {
				$code = $this->text($xp, 'm:ResponseCode', $err);
				$text = $this->text($xp, 'm:MessageText', $err);
				throw new MailException(self::translateError($code, $text));
			}
		}
		return $xp;
	}

	public static function translateError(?string $code, ?string $text): string {
		return match ($code) {
			'ErrorItemNotFound' => 'Ce message n\'existe plus (déplacé ou supprimé)',
			'ErrorFolderNotFound' => 'Dossier introuvable',
			'ErrorAccessDenied' => 'Accès refusé par Exchange',
			'ErrorQuotaExceeded' => 'Boîte aux lettres pleine',
			'ErrorMessageSizeExceeded' => 'Message trop volumineux',
			'ErrorInvalidRecipients', 'ErrorInvalidSmtpAddress' => 'Adresse de destinataire invalide',
			'ErrorCalendarEndDateIsEarlierThanStartDate' => 'La fin de l\'événement est avant son début',
			'ErrorCalendarDurationIsTooLong' => 'Événement trop long',
			'ErrorCalendarIsOrganizerForAccept', 'ErrorCalendarIsOrganizerForDecline', 'ErrorCalendarIsOrganizerForTentative' => 'Vous êtes l\'organisateur de cette réunion',
			'ErrorOccurrenceCrossingBoundary' => 'Une occurrence ne peut pas être déplacée au-delà de l\'occurrence précédente ou suivante de la série',
			'ErrorIrresolvableConflict' => 'L\'élément a été modifié entre-temps, rechargez la page',
			'ErrorTimeZone' => 'Fuseau horaire non reconnu par Exchange',
			default => 'Erreur Exchange' . ($code ? ' (' . $code . ')' : '') . ($text ? ' : ' . $text : ''),
		};
	}

	/**
	 * @return array{0: int, 1: string}
	 */
	private function httpPost(string $body): array {
		$ch = curl_init($this->url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'Accept: text/xml'],
			// OVH Hosted Exchange accepts Basic and NTLM; let curl negotiate.
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC | CURLAUTH_NTLM,
			CURLOPT_USERPWD => $this->username . ':' . $this->password,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
			CURLOPT_SSL_VERIFYHOST => $this->verifyPeer ? 2 : 0,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_USERAGENT => 'Nextcloud-Webmail/1.0',
		]);
		$response = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		if ($response === false) {
			throw new MailException('Impossible de joindre le serveur Exchange : ' . $error);
		}
		return [$status, (string)$response];
	}

	public function text(\DOMXPath $xp, string $query, ?\DOMNode $ctx = null): ?string {
		$n = $xp->query($query, $ctx)->item(0);
		return $n?->textContent;
	}

	public function attr(\DOMXPath $xp, string $query, string $attr, ?\DOMNode $ctx = null): ?string {
		$n = $xp->query($query, $ctx)->item(0);
		return $n instanceof \DOMElement && $n->hasAttribute($attr) ? $n->getAttribute($attr) : null;
	}

	/**
	 * @return ?array{name: string, email: string}
	 */
	public function mailbox(\DOMXPath $xp, string $query, \DOMNode $ctx): ?array {
		$n = $xp->query($query, $ctx)->item(0);
		if ($n === null) {
			return null;
		}
		return [
			'name' => (string)$this->text($xp, 't:Name', $n),
			'email' => (string)$this->text($xp, 't:EmailAddress', $n),
		];
	}

	/**
	 * @return array<int, array{name: string, email: string}>
	 */
	public function mailboxes(\DOMXPath $xp, string $query, \DOMNode $ctx): array {
		$out = [];
		foreach ($xp->query($query, $ctx) as $n) {
			$out[] = ['name' => (string)$this->text($xp, 't:Name', $n), 'email' => (string)$this->text($xp, 't:EmailAddress', $n)];
		}
		return $out;
	}
}
