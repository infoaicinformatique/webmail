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

	/** @var null|callable(string $url, string $body, string[] $headers): array{0: int, 1: string} */
	private $transport;

	/** Public folder mailbox the requests are routed to (Exchange 2013+), null for the user's mailbox. */
	private ?string $publicFolderMailbox = null;

	/** One HTTP connection for all the requests of this client (TLS and NTLM handshakes once). */
	private ?\CurlHandle $curl = null;

	/**
	 * Authentication: Basic is sent with the first request (no extra round
	 * trip to discover the method); NTLM is used if the server refuses it.
	 */
	private int $auth = CURLAUTH_BASIC;

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

	/**
	 * Route the following requests to the public folders: since Exchange 2013
	 * they live in "public folder mailboxes" and requests about them carry
	 * that mailbox in the X-AnchorMailbox / X-PublicFolderMailbox headers.
	 */
	public function usePublicFolderMailbox(?string $smtp): void {
		$this->publicFolderMailbox = $smtp !== null && $smtp !== '' ? $smtp : null;
	}

	/**
	 * The user's public folder mailbox, from Autodiscover (GetUserSettings,
	 * setting "PublicFolderInformation"), on the same host as EWS.
	 * @return ?string null when the server has none or does not answer
	 */
	public function discoverPublicFolderMailbox(): ?string {
		$parts = parse_url($this->url);
		if (!isset($parts['scheme'], $parts['host']) || !str_contains($this->username, '@')) {
			return null;
		}
		$url = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/autodiscover/autodiscover.svc';
		$ns = 'http://schemas.microsoft.com/exchange/2010/Autodiscover';
		$action = $ns . '/Autodiscover/GetUserSettings';
		$envelope = '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '" xmlns:a="' . $ns . '" xmlns:wsa="http://www.w3.org/2005/08/addressing">'
			. '<soap:Header><a:RequestedServerVersion>Exchange2013</a:RequestedServerVersion>'
			. '<wsa:Action>' . $action . '</wsa:Action><wsa:To>' . $this->esc($url) . '</wsa:To></soap:Header>'
			. '<soap:Body><a:GetUserSettingsRequestMessage><a:Request>'
			. '<a:Users><a:User><a:Mailbox>' . $this->esc($this->username) . '</a:Mailbox></a:User></a:Users>'
			. '<a:RequestedSettings><a:Setting>PublicFolderInformation</a:Setting></a:RequestedSettings>'
			. '</a:Request></a:GetUserSettingsRequestMessage></soap:Body></soap:Envelope>';
		try {
			$headers = ['SOAPAction: "' . $action . '"'];
			[$status, $response] = $this->transport !== null
				? ($this->transport)($url, $envelope, $headers)
				: $this->httpPost($envelope, $headers, $url);
		} catch (MailException) {
			return null;
		}
		$doc = new \DOMDocument();
		if ($status !== 200 || $response === '' || !@$doc->loadXML($response, LIBXML_NONET)) {
			return null;
		}
		$xp = new \DOMXPath($doc);
		$xp->registerNamespace('a', $ns);
		$value = trim((string)$xp->evaluate('string(//a:UserSetting[a:Name="PublicFolderInformation"]/a:Value)'));
		return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
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
	public function listFolders(?int &$usedBytes = null): array {
		$roles = $this->distinguishedFolderIds(['inbox', 'drafts', 'sentitems', 'deleteditems', 'junkemail', 'msgfolderroot']);
		$rootId = $roles['msgfolderroot'] ?? null;
		unset($roles['msgfolderroot']);
		$roleById = array_flip($roles);

		$xp = $this->request(
			'<m:FindFolder Traversal="Deep">'
			. '<m:FolderShape><t:BaseShape>Default</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="folder:FolderClass"/><t:FieldURI FieldURI="folder:ParentFolderId"/>'
			// size of each folder (PR_MESSAGE_SIZE_EXTENDED): their sum is the space used by the mailbox
			. '<t:ExtendedFieldURI PropertyTag="0x0E08" PropertyType="Long"/>'
			. '</t:AdditionalProperties></m:FolderShape>'
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="msgfolderroot"/></m:ParentFolderIds>'
			. '</m:FindFolder>'
		);
		$usedBytes = 0;
		foreach ($xp->query('//t:Folders/*/t:ExtendedProperty/t:Value') as $v) {
			$usedBytes += max(0, (int)$v->textContent);
		}

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
	/**
	 * Mailbox size limit set by the administrator (MAPI quota properties, in
	 * KB, read on the top of the mailbox): the "prohibit send" quota, else the
	 * storage limit, else the "prohibit receive" quota.
	 * @return ?int bytes, null when Exchange does not tell
	 */
	public function mailboxQuota(): ?int {
		$tags = ['0x666E', '0x3FF5', '0x666A'];
		$props = '';
		foreach ($tags as $tag) {
			$props .= '<t:ExtendedFieldURI PropertyTag="' . $tag . '" PropertyType="Integer"/>';
		}
		$xp = $this->request(
			'<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $props . '</t:AdditionalProperties></m:FolderShape>'
			. '<m:FolderIds><t:DistinguishedFolderId Id="msgfolderroot"/><t:DistinguishedFolderId Id="root"/></m:FolderIds></m:GetFolder>',
			false
		);
		foreach ($tags as $tag) {
			foreach ($xp->query('//m:Folders/*/t:ExtendedProperty') as $p) {
				$uri = $xp->query('t:ExtendedFieldURI', $p)->item(0);
				if ($uri instanceof \DOMElement && hexdec($uri->getAttribute('PropertyTag')) === hexdec($tag)) {
					$kb = (int)$this->text($xp, 't:Value', $p);
					if ($kb > 0) {
						return $kb * 1024;
					}
				}
			}
		}
		return null;
	}

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
	/**
	 * Exchange search text (AQS) for the words typed and the filters ticked:
	 * unread, with attachments, flagged, received since a date (Y-m-d).
	 * @param array{unread?: bool, attachments?: bool, flagged?: bool, since?: string} $filters
	 */
	public static function searchQuery(?string $text, array $filters): ?string {
		$parts = [];
		$text = trim((string)$text);
		if ($text !== '') {
			$parts[] = $text;
		}
		if (!empty($filters['unread'])) {
			$parts[] = 'isread:false';
		}
		if (!empty($filters['attachments'])) {
			$parts[] = 'hasattachment:true';
		}
		if (!empty($filters['flagged'])) {
			$parts[] = 'isflagged:true';
		}
		$since = (string)($filters['since'] ?? '');
		if ($since !== '') {
			$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $since);
			if ($d === false || $d->format('Y-m-d') !== $since) {
				throw new MailException('Date de recherche invalide');
			}
			$parts[] = 'received>=' . $since;
		}
		return $parts !== [] ? implode(' ', $parts) : null;
	}

	/**
	 * Search several folders at once (the whole mailbox): one FindItem over
	 * all of them, results merged newest first. Paging goes up to 200 results.
	 * @param string[] $folderIds
	 */
	public function searchFolders(array $folderIds, int $offset, int $limit, string $query): array {
		$window = min(200, max(1, $offset + $limit));
		$ids = '';
		foreach ($folderIds as $id) {
			$ids .= $this->folderIdXml($id);
		}
		$xp = $this->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $this->summaryFields(true) . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . $window . '" Offset="0" BasePoint="Beginning"/>'
			. '<m:SortOrder><t:FieldOrder Order="Descending"><t:FieldURI FieldURI="item:DateTimeReceived"/></t:FieldOrder></m:SortOrder>'
			. '<m:ParentFolderIds>' . $ids . '</m:ParentFolderIds>'
			. '<m:QueryString>' . $this->esc($query) . '</m:QueryString>'
			. '</m:FindItem>',
			false
		);
		$items = [];
		$total = 0;
		$more = false;
		foreach ($xp->query('//m:RootFolder') as $root) {
			if (!$root instanceof \DOMElement) {
				continue;
			}
			$total += (int)$root->getAttribute('TotalItemsInView');
			$more = $more || $root->getAttribute('IncludesLastItemInRange') === 'false';
			foreach ($xp->query('t:Items/*', $root) as $node) {
				$items[] = $this->summary($xp, $node);
			}
		}
		if ($items === [] && $total === 0) {
			// every folder refused: report the first error
			$err = $xp->query('//m:ResponseMessages/*[@ResponseClass="Error"]')->item(0);
			if ($err !== null) {
				throw new MailException(self::translateError($this->text($xp, 'm:ResponseCode', $err), $this->text($xp, 'm:MessageText', $err)));
			}
		}
		usort($items, fn ($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
		$page = array_slice($items, $offset, $limit);
		return [
			'items' => $page,
			'total' => $total,
			'hasMore' => $offset + $limit < 200 && ($more || count($items) > $offset + $limit),
		];
	}

	private function summaryFields(bool $withFolder = false): string {
		$props = ['item:Subject', 'message:From', 'item:DisplayTo', 'item:DateTimeReceived', 'message:IsRead',
			'item:HasAttachments', 'item:Importance', 'item:Flag', 'item:Preview'];
		if ($withFolder) {
			$props[] = 'item:ParentFolderId';
		}
		$fields = '';
		foreach ($props as $p) {
			$fields .= '<t:FieldURI FieldURI="' . $p . '"/>';
		}
		return $fields;
	}

	/** @return array<string, mixed> one message of a list */
	private function summary(\DOMXPath $xp, \DOMNode $node): array {
		$item = [
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
		$folder = $this->attr($xp, 't:ParentFolderId', 'Id', $node);
		if ($folder !== null) {
			$item['folderId'] = $folder;
		}
		return $item;
	}

	/**
	 * Unread messages received in the inbox on or after $since (for
	 * notifications), and the number of unread messages of the inbox. Without
	 * $since: the newest messages, read or not, and the date to start from.
	 * @return array{items: array<int, array<string, mixed>>, latest: string, unread: int}
	 */
	public function newMessages(?string $since, int $max = 10): array {
		$restriction = '';
		if ($since !== null) {
			$d = date_create_immutable($since);
			if ($d === false) {
				throw new MailException('Date invalide');
			}
			$since = $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
			$restriction = '<m:Restriction><t:And>'
				// "on or after": dates are to the second, a message received in the same second
				// as the last one seen must not be missed (the page ignores those already announced)
				. '<t:IsGreaterThanOrEqualTo><t:FieldURI FieldURI="item:DateTimeReceived"/><t:FieldURIOrConstant><t:Constant Value="' . $since . '"/></t:FieldURIOrConstant></t:IsGreaterThanOrEqualTo>'
				. '<t:IsEqualTo><t:FieldURI FieldURI="message:IsRead"/><t:FieldURIOrConstant><t:Constant Value="false"/></t:FieldURIOrConstant></t:IsEqualTo>'
				. '</t:And></m:Restriction>';
		}
		$xp = $this->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $this->summaryFields() . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . max(1, $max) . '" Offset="0" BasePoint="Beginning"/>'
			. $restriction
			. '<m:SortOrder><t:FieldOrder Order="Descending"><t:FieldURI FieldURI="item:DateTimeReceived"/></t:FieldOrder></m:SortOrder>'
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="inbox"/></m:ParentFolderIds>'
			. '</m:FindItem>'
		);
		$items = [];
		foreach ($xp->query('//m:RootFolder/t:Items/*') as $node) {
			$items[] = $this->summary($xp, $node);
		}
		// next time, start from the newest message seen (ISO dates in UTC compare as text)
		$dates = array_values(array_filter(array_map(fn ($i) => (string)$i['date'], $items)));
		if ($since !== null) {
			$dates[] = $since;
		}
		$latest = $dates !== [] ? max($dates) : gmdate('Y-m-d\TH:i:s\Z');
		$folder = $this->request(
			'<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties><t:FieldURI FieldURI="folder:UnreadCount"/></t:AdditionalProperties></m:FolderShape>'
			. '<m:FolderIds><t:DistinguishedFolderId Id="inbox"/></m:FolderIds></m:GetFolder>'
		);
		return [
			// without a date: the newest messages, for the page to know them (not announced)
			'items' => $items,
			'latest' => $latest,
			'unread' => (int)$this->text($folder, '//m:Folders/*/t:UnreadCount'),
		];
	}

	public function findItems(string $folderId, int $offset = 0, int $limit = 50, ?string $query = null): array {
		$fields = $this->summaryFields();
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
			$items[] = $this->summary($xp, $node);
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
		foreach (['message:ReceivedRepresenting', 'calendar:Start', 'calendar:End', 'calendar:IsAllDayEvent', 'calendar:Location',
			'calendar:MyResponseType', 'calendar:IsCancelled'] as $p) {
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
		// a copy of a colleague's invitation received as their delegate:
		// answering it answers on their behalf
		$representing = $this->mailbox($xp, 't:ReceivedRepresenting/t:Mailbox', $node);
		if ($representing !== null && strcasecmp($representing['email'], $this->username) === 0) {
			$representing = null;
		}
		return [
			'start' => $this->text($xp, 't:Start', $node),
			'end' => $this->text($xp, 't:End', $node),
			'allDay' => $this->text($xp, 't:IsAllDayEvent', $node) === 'true',
			'location' => (string)$this->text($xp, 't:Location', $node),
			'response' => $this->text($xp, 't:MyResponseType', $node) ?? 'Unknown',
			'cancelled' => $node->localName === 'MeetingCancellation' || $this->text($xp, 't:IsCancelled', $node) === 'true',
			'onBehalfOf' => $representing,
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
		if ($mode === 'new') {
			$attXml = '';
			foreach ($attachments as $a) {
				$attXml .= $this->fileAttachmentXml($a);
			}
			$item = $this->composeItemXml('new', $subject, $htmlBody, $to, $cc, $bcc, null, $importance, $attXml);
			if ($attachments === []) {
				$this->createAndSend($item);
				return;
			}
			$draft = $this->createDraft($item);
			$this->sendDraft($draft['id'], $draft['changeKey']);
			return;
		}

		$item = $this->composeItemXml($mode, $subject, $htmlBody, $to, $cc, $bcc, $referenceId, $importance);
		if ($attachments === []) {
			$this->createAndSend($item);
			return;
		}
		$draft = $this->createDraft($item);
		$changeKey = $this->addAttachments($draft['id'], $attachments);
		$this->sendDraft($draft['id'], $changeKey);
	}

	/**
	 * The item to create: a new message, or a reply / forward of
	 * $referenceId (Exchange then adds the quote and keeps the thread).
	 */
	private function composeItemXml(string $mode, string $subject, string $htmlBody, array $to, array $cc, array $bcc,
		?string $referenceId, string $importance, string $attachmentsXml = ''): string {
		$recipients = $this->recipientsXml('ToRecipients', $to)
			. $this->recipientsXml('CcRecipients', $cc)
			. $this->recipientsXml('BccRecipients', $bcc);
		$importance = in_array($importance, ['Low', 'Normal', 'High'], true) ? $importance : 'Normal';
		if ($mode === 'new') {
			return '<t:Message>'
				. '<t:Subject>' . $this->esc($subject) . '</t:Subject>'
				. '<t:Body BodyType="HTML">' . $this->esc($htmlBody) . '</t:Body>'
				. ($attachmentsXml !== '' ? '<t:Attachments>' . $attachmentsXml . '</t:Attachments>' : '')
				. '<t:Importance>' . $importance . '</t:Importance>'
				. $recipients
				. '</t:Message>';
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
		return '<t:' . $element . '>'
			. $recipients
			. '<t:ReferenceItemId Id="' . $this->esc($ref['id']) . '" ChangeKey="' . $this->esc($ref['changeKey']) . '"/>'
			. '<t:NewBodyContent BodyType="HTML">' . $this->esc($htmlBody) . '</t:NewBodyContent>'
			. '</t:' . $element . '>';
	}

	// ------------------------------------------------------------------
	// Drafts
	// ------------------------------------------------------------------

	/**
	 * Save what is being written as a draft in « Brouillons ». A reply or a
	 * forward is created again at each save (Exchange adds the quote and
	 * keeps the thread), the previous version ($replaceId) is then deleted.
	 * @return string id of the draft
	 */
	public function saveDraft(?string $replaceId, string $mode, string $subject, string $htmlBody, array $to, array $cc, array $bcc,
		?string $referenceId = null, string $importance = 'Normal'): string {
		$draft = $this->createDraft($this->composeItemXml($mode, $subject, $htmlBody, $to, $cc, $bcc, $referenceId, $importance));
		if ($replaceId !== null && $replaceId !== $draft['id']) {
			try {
				$this->deleteItems([$replaceId], true);
			} catch (MailException) {
				// already gone (sent or deleted elsewhere)
			}
		}
		return $draft['id'];
	}

	/**
	 * Change a draft opened from « Brouillons » (its quote and attachments stay).
	 */
	public function updateDraft(string $id, string $subject, string $htmlBody, array $to, array $cc, array $bcc, string $importance = 'Normal'): void {
		$importance = in_array($importance, ['Low', 'Normal', 'High'], true) ? $importance : 'Normal';
		$set = fn (string $uri, string $inner) => '<t:SetItemField><t:FieldURI FieldURI="' . $uri . '"/><t:Message>' . $inner . '</t:Message></t:SetItemField>';
		$updates = $set('item:Subject', '<t:Subject>' . $this->esc($subject) . '</t:Subject>')
			. $set('item:Body', '<t:Body BodyType="HTML">' . $this->esc($htmlBody) . '</t:Body>')
			. $set('item:Importance', '<t:Importance>' . $importance . '</t:Importance>');
		foreach (['ToRecipients' => $to, 'CcRecipients' => $cc, 'BccRecipients' => $bcc] as $field => $list) {
			$updates .= $list !== []
				? $set('message:' . $field, $this->recipientsXml($field, $list))
				: '<t:DeleteItemField><t:FieldURI FieldURI="message:' . $field . '"/></t:DeleteItemField>';
		}
		$this->request(
			'<m:UpdateItem MessageDisposition="SaveOnly" ConflictResolution="AlwaysOverwrite">'
			. '<m:ItemChanges><t:ItemChange><t:ItemId Id="' . $this->esc($id) . '"/><t:Updates>' . $updates . '</t:Updates></t:ItemChange></m:ItemChanges>'
			. '</m:UpdateItem>'
		);
	}

	/**
	 * Send a draft as it is in Exchange (after updateDraft), with extra attachments.
	 */
	public function sendSavedDraft(string $id, array $attachments = []): void {
		$ref = $this->itemRef($id);
		$changeKey = $attachments !== [] ? $this->addAttachments($ref['id'], $attachments) : $ref['changeKey'];
		$this->sendDraft($ref['id'], $changeKey);
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

		$headers = $this->publicFolderMailbox !== null
			? ['X-AnchorMailbox: ' . $this->publicFolderMailbox, 'X-PublicFolderMailbox: ' . $this->publicFolderMailbox]
			: [];
		[$status, $response] = $this->transport !== null
			? ($this->transport)($this->url, $envelope, $headers)
			: $this->httpPost($envelope, $headers);

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
			'ErrorAccessDenied' => 'Accès refusé par Exchange (élément non partagé ou droits insuffisants)',
			'ErrorQuotaExceeded' => 'Boîte aux lettres pleine',
			'ErrorMessageSizeExceeded' => 'Message trop volumineux',
			'ErrorInvalidRecipients', 'ErrorInvalidSmtpAddress' => 'Adresse de destinataire invalide',
			'ErrorCalendarEndDateIsEarlierThanStartDate' => 'La fin de l\'événement est avant son début',
			'ErrorCalendarDurationIsTooLong' => 'Événement trop long',
			'ErrorCalendarIsOrganizerForAccept', 'ErrorCalendarIsOrganizerForDecline', 'ErrorCalendarIsOrganizerForTentative' => 'Vous êtes l\'organisateur de cette réunion',
			'ErrorOccurrenceCrossingBoundary' => 'Une occurrence ne peut pas être déplacée au-delà de l\'occurrence précédente ou suivante de la série',
			'ErrorNonExistentMailbox' => 'Aucune boîte Exchange ne correspond à cette adresse',
			'ErrorDelegateNoUser', 'ErrorDelegateValidationFailed' => 'Vous n\'êtes pas délégué de cette personne dans Exchange',
			'ErrorInvalidUserInfo', 'ErrorUserNotMailbox', 'ErrorInvalidUserSid' => 'Une des personnes n\'a pas de boîte Exchange dans votre organisation',
			'ErrorDuplicateUserIdsSpecified' => 'Une personne figure deux fois dans la liste',
			'ErrorNotAllowedExternalSharingByPolicy' => 'Le partage avec des personnes extérieures est interdit par votre organisation',
			'ErrorInvalidPermissionSettings' => 'Exchange a refusé ces autorisations',
			'ErrorIrresolvableConflict' => 'L\'élément a été modifié entre-temps, rechargez la page',
			'ErrorTimeZone' => 'Fuseau horaire non reconnu par Exchange',
			default => 'Erreur Exchange' . ($code ? ' (' . $code . ')' : '') . ($text ? ' : ' . $text : ''),
		};
	}

	/**
	 * @param string[] $headers extra HTTP headers
	 * @return array{0: int, 1: string}
	 */
	private function httpPost(string $body, array $headers = [], ?string $url = null): array {
		$this->curl ??= curl_init();
		$ch = $this->curl;
		curl_setopt_array($ch, [
			CURLOPT_URL => $url ?? $this->url,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array_merge(['Content-Type: text/xml; charset=utf-8', 'Accept: text/xml'], $headers),
			// OVH Hosted Exchange accepts Basic and NTLM
			CURLOPT_HTTPAUTH => $this->auth,
			CURLOPT_USERPWD => $this->username . ':' . $this->password,
			// EWS answers are verbose XML: let the server compress them
			CURLOPT_ENCODING => '',
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
		if ($status === 401 && $this->auth === CURLAUTH_BASIC && $response !== false) {
			// Basic refused: this server wants NTLM (kept for the next requests)
			$this->auth = CURLAUTH_NTLM;
			return $this->httpPost($body, $headers, $url);
		}
		if ($response === false) {
			// do not reuse a connection in an unknown state
			curl_close($ch);
			$this->curl = null;
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
