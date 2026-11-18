<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\MailHeaders;
use OCA\Webmail\Service\Unsubscribe;

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
	/** Where new messages start from when the inbox is empty (newMessages). */
	public const BEFORE_ANY_MESSAGE = '1970-01-01T00:00:00Z';

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
	/** the server refuses Basic: NTLM, done by Ntlm (some libcurl are built without it) */
	private bool $ntlm = false;
	/** the open connection is authenticated by NTLM (it stays so until closed) */
	private bool $ntlmReady = false;
	/** @var ?callable(): void told once this server wants NTLM (remembered for the next clients) */
	private $onNtlm = null;
	/** @var string[] headers of the last answer */
	private array $lastHeaders = [];

	/** Windows time zone id sent in the TimeZoneContext header (e.g. "Romance Standard Time"). */
	private ?string $timeZoneId = null;

	/** Network settings of Nextcloud (see setNetwork) as curl options. */
	private array $network = [];

	private string $userAgent = 'Nextcloud-Webmail';

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
	 * Use the network settings of Nextcloud, as its own HTTP client does:
	 * the proxy of config.php ('proxy', 'proxyuserpwd', 'proxyexclude') and
	 * the certificate bundle (with the certificates imported by the
	 * administrator, « occ security:certificates:import »).
	 *
	 * @param string[] $noProxy hosts that are reached without the proxy
	 */
	/**
	 * NTLM at once, for a server known to refuse Basic (OVH Hosted Exchange): no refused
	 * request first. $onNtlm is called when this client finds out a server wants NTLM.
	 */
	public function setAuth(bool $ntlm, ?callable $onNtlm = null): void {
		$this->ntlm = $this->ntlm || $ntlm;
		$this->onNtlm = $onNtlm;
	}

	public function usesNtlm(): bool {
		return $this->ntlm;
	}

	public function setNetwork(?string $proxy, ?string $proxyAuth = null, array $noProxy = [], ?string $caBundle = null, ?string $userAgent = null): void {
		$this->network = self::networkOptions($proxy, $proxyAuth, $noProxy, $caBundle);
		if ($userAgent !== null && $userAgent !== '') {
			$this->userAgent = $userAgent;
		}
	}

	/**
	 * Connect to these addresses only for $host (those checked before), whatever the
	 * DNS answers at connection time. Nothing changes when $ips is empty.
	 *
	 * @param string[] $ips
	 */
	public function pinHost(string $host, array $ips): void {
		$this->network += self::pinOptions($host, $ips);
	}

	/**
	 * @param string[] $ips
	 * @return array<int, mixed> curl options
	 */
	public static function pinOptions(string $host, array $ips): array {
		if ($host === '' || $ips === []) {
			return [];
		}
		$list = implode(',', array_map(fn (string $ip) => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $ips));
		return [CURLOPT_RESOLVE => [$host . ':443:' . $list, $host . ':80:' . $list]];
	}

	/**
	 * @param string[] $noProxy
	 * @return array<int, mixed> curl options
	 */
	public static function networkOptions(?string $proxy, ?string $proxyAuth, array $noProxy, ?string $caBundle): array {
		$opts = [];
		if ($proxy !== null && trim($proxy) !== '') {
			$opts[CURLOPT_PROXY] = trim($proxy);
			if ($proxyAuth !== null && $proxyAuth !== '') {
				$opts[CURLOPT_PROXYUSERPWD] = $proxyAuth;
			}
			$hosts = array_values(array_filter(array_map('trim', $noProxy), fn ($h) => $h !== ''));
			if ($hosts !== []) {
				$opts[CURLOPT_NOPROXY] = implode(',', $hosts);
			}
		}
		if ($caBundle !== null && $caBundle !== '' && is_readable($caBundle)) {
			$opts[CURLOPT_CAINFO] = $caBundle;
		}
		return $opts;
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
		$value = (string)($this->userSettings($url, ['PublicFolderInformation'])['PublicFolderInformation'] ?? '');
		return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
	}

	/** The Autodiscover addresses tried for a mail domain, in Outlook's order. */
	public static function autodiscoverUrls(string $domain): array {
		return ['https://autodiscover.' . $domain . '/autodiscover/autodiscover.svc', 'https://' . $domain . '/autodiscover/autodiscover.svc'];
	}

	/**
	 * The EWS address of this account given by an Autodiscover server
	 * (GetUserSettings: ExternalEwsUrl, else InternalEwsUrl).
	 */
	public function discoverEwsUrl(string $autodiscoverUrl): ?string {
		$s = $this->userSettings($autodiscoverUrl, ['ExternalEwsUrl', 'InternalEwsUrl']);
		$url = trim((string)($s['ExternalEwsUrl'] ?? $s['InternalEwsUrl'] ?? ''));
		return $url !== '' ? $url : null;
	}

	/**
	 * SOAP Autodiscover (GetUserSettings) for the account.
	 * @param string[] $names
	 * @return array<string, string> the settings the server gave
	 */
	private function userSettings(string $url, array $names): array {
		$ns = 'http://schemas.microsoft.com/exchange/2010/Autodiscover';
		$action = $ns . '/Autodiscover/GetUserSettings';
		$envelope = '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '" xmlns:a="' . $ns . '" xmlns:wsa="http://www.w3.org/2005/08/addressing">'
			. '<soap:Header><a:RequestedServerVersion>Exchange2013</a:RequestedServerVersion>'
			. '<wsa:Action>' . $action . '</wsa:Action><wsa:To>' . $this->esc($url) . '</wsa:To></soap:Header>'
			. '<soap:Body><a:GetUserSettingsRequestMessage><a:Request>'
			. '<a:Users><a:User><a:Mailbox>' . $this->esc($this->username) . '</a:Mailbox></a:User></a:Users>'
			. '<a:RequestedSettings>' . implode('', array_map(fn ($n) => '<a:Setting>' . $this->esc($n) . '</a:Setting>', $names)) . '</a:RequestedSettings>'
			. '</a:Request></a:GetUserSettingsRequestMessage></soap:Body></soap:Envelope>';
		try {
			$headers = ['SOAPAction: "' . $action . '"'];
			[$status, $response] = $this->transport !== null
				? ($this->transport)($url, $envelope, $headers)
				: $this->httpPost($envelope, $headers, $url);
		} catch (MailException) {
			return [];
		}
		$doc = new \DOMDocument();
		if ($status !== 200 || $response === '' || !@$doc->loadXML($response, LIBXML_NONET)) {
			return [];
		}
		$xp = new \DOMXPath($doc);
		$xp->registerNamespace('a', $ns);
		$out = [];
		foreach ($xp->query('//a:UserSetting') as $setting) {
			$name = trim((string)$xp->evaluate('string(a:Name)', $setting));
			$value = trim((string)$xp->evaluate('string(a:Value)', $setting));
			if ($name !== '' && $value !== '') {
				$out[$name] = $value;
			}
		}
		return $out;
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
	public function listFolders(?int &$usedBytes = null, ?string $mailbox = null): array {
		$roles = $this->distinguishedFolderIds(['inbox', 'drafts', 'sentitems', 'deleteditems', 'junkemail', 'outbox', 'msgfolderroot'], $mailbox);
		if ($mailbox !== null && !isset($roles['msgfolderroot'])) {
			if ($roles === []) {
				throw new MailException('Vous n\'avez pas accès à la boîte %s : demandez à son propriétaire de vous en faire délégué dans Outlook (au moins la boîte de réception), ou à l\'administrateur Exchange le droit « Accès total »', [$mailbox]);
			}
			// a delegate (Outlook « Accès délégué »): only the folders shared with us
			$usedBytes = 0;
			$this->mailboxAccess = 'delegate';
			return $this->delegatedFolders($roles);
		}
		$this->mailboxAccess = 'full';
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
			. '<m:ParentFolderIds>' . $this->distinguishedXml('msgfolderroot', $mailbox) . '</m:ParentFolderIds>'
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
				// bytes (« Libérer de l'espace »)
				'size' => max(0, (int)$this->text($xp, 't:ExtendedProperty/t:Value', $node)),
			];
		}
		return $folders;
	}

	/** Access to the last mailbox listed by listFolders(): 'full', or 'delegate' (some folders only). */
	private string $mailboxAccess = 'full';

	public function mailboxAccess(): string {
		return $this->mailboxAccess;
	}

	/**
	 * The folders of a colleague who made us their delegate: the well-known
	 * folders we may open (often the inbox only), and their sub-folders.
	 * @param array<string, string> $roles distinguished name => FolderId
	 * @return array<int, array<string, mixed>>
	 */
	private function delegatedFolders(array $roles): array {
		$ids = '';
		foreach ($roles as $id) {
			$ids .= '<t:FolderId Id="' . $this->esc($id) . '"/>';
		}
		$xp = $this->request('<m:GetFolder><m:FolderShape><t:BaseShape>Default</t:BaseShape></m:FolderShape><m:FolderIds>' . $ids . '</m:FolderIds></m:GetFolder>', false);
		$roleById = array_flip($roles);
		$folders = [];
		foreach ($xp->query('//m:GetFolderResponseMessage[@ResponseClass="Success"]/m:Folders/*') as $node) {
			$id = $this->attr($xp, 't:FolderId', 'Id', $node);
			if ($id === null) {
				continue;
			}
			$folders[] = [
				'id' => $id,
				'name' => (string)$this->text($xp, 't:DisplayName', $node),
				'parentId' => null,
				'total' => (int)$this->text($xp, 't:TotalCount', $node),
				'unread' => (int)$this->text($xp, 't:UnreadCount', $node),
				'role' => $roleById[$id] ?? null,
			];
		}
		// their sub-folders, when the delegation reaches them
		foreach ($folders as $top) {
			$sub = $this->request(
				'<m:FindFolder Traversal="Deep"><m:FolderShape><t:BaseShape>Default</t:BaseShape><t:AdditionalProperties>'
				. '<t:FieldURI FieldURI="folder:FolderClass"/><t:FieldURI FieldURI="folder:ParentFolderId"/></t:AdditionalProperties></m:FolderShape>'
				. '<m:ParentFolderIds><t:FolderId Id="' . $this->esc($top['id']) . '"/></m:ParentFolderIds></m:FindFolder>',
				false
			);
			foreach ($sub->query('//m:FindFolderResponseMessage[@ResponseClass="Success"]//t:Folders/t:Folder') as $node) {
				$class = $this->text($sub, 't:FolderClass', $node);
				$id = $this->attr($sub, 't:FolderId', 'Id', $node);
				if ($id === null || ($class !== null && $class !== '' && !str_starts_with($class, 'IPF.Note'))) {
					continue;
				}
				$folders[] = [
					'id' => $id,
					'name' => (string)$this->text($sub, 't:DisplayName', $node),
					'parentId' => $this->attr($sub, 't:ParentFolderId', 'Id', $node),
					'total' => (int)$this->text($sub, 't:TotalCount', $node),
					'unread' => (int)$this->text($sub, 't:UnreadCount', $node),
					'role' => null,
				];
			}
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

	// ------------------------------------------------------------------
	// Managing mail folders
	// ------------------------------------------------------------------

	/** Folders Exchange needs: they cannot be renamed, moved or deleted. */
	private const PROTECTED_ROLES = ['inbox', 'drafts', 'sentitems', 'deleteditems', 'junkemail', 'outbox', 'msgfolderroot'];

	private static function folderName(string $name): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1f]/', $name)) {
			throw new MailException('Nom de dossier invalide');
		}
		return $name;
	}

	private function assertEditable(string $folderId): void {
		if (in_array($folderId, self::DISTINGUISHED, true)
			|| in_array($folderId, $this->distinguishedFolderIds(self::PROTECTED_ROLES), true)) {
			throw new MailException('Ce dossier est indispensable à Exchange : il ne peut pas être renommé, déplacé ni supprimé');
		}
	}

	/**
	 * New mail folder, at the top of the mailbox or inside $parentId.
	 * @return string id of the folder
	 */
	public function createFolder(string $name, ?string $parentId = null): string {
		$xp = $this->request(
			'<m:CreateFolder><m:ParentFolderId>' . $this->folderIdXml($parentId ?? 'msgfolderroot') . '</m:ParentFolderId>'
			. '<m:Folders><t:Folder><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>' . $this->esc(self::folderName($name)) . '</t:DisplayName></t:Folder></m:Folders>'
			. '</m:CreateFolder>'
		);
		return (string)$this->attr($xp, '//m:Folders/*/t:FolderId', 'Id');
	}

	public function renameFolder(string $folderId, string $name): void {
		$this->assertEditable($folderId);
		$this->request(
			'<m:UpdateFolder><m:FolderChanges><t:FolderChange>' . $this->folderIdXml($folderId)
			. '<t:Updates><t:SetFolderField><t:FieldURI FieldURI="folder:DisplayName"/><t:Folder><t:DisplayName>'
			. $this->esc(self::folderName($name)) . '</t:DisplayName></t:Folder></t:SetFolderField></t:Updates>'
			. '</t:FolderChange></m:FolderChanges></m:UpdateFolder>'
		);
	}

	/** Move a folder (and what it contains) inside $parentId, or to the top of the mailbox. */
	public function moveFolder(string $folderId, ?string $parentId): void {
		$this->assertEditable($folderId);
		if ($parentId === $folderId) {
			throw new MailException('Un dossier ne peut pas être déplacé dans lui-même');
		}
		$this->request(
			'<m:MoveFolder><m:ToFolderId>' . $this->folderIdXml($parentId ?? 'msgfolderroot') . '</m:ToFolderId>'
			. '<m:FolderIds>' . $this->folderIdXml($folderId) . '</m:FolderIds></m:MoveFolder>'
		);
	}

	/** Delete a folder and what it contains: to « Éléments supprimés », like Outlook. */
	/** @param bool $permanent for good (a folder already in « Éléments supprimés »), else moved there */
	public function deleteFolder(string $folderId, bool $permanent = false): void {
		$this->assertEditable($folderId);
		$this->request(
			'<m:DeleteFolder DeleteType="' . ($permanent ? 'HardDelete' : 'MoveToDeletedItems') . '"><m:FolderIds>' . $this->folderIdXml($folderId) . '</m:FolderIds></m:DeleteFolder>'
		);
	}

	public function distinguishedFolderIds(array $names, ?string $mailbox = null): array {
		$names = array_values($names);
		// the ids of the well-known folders never change: asked once, then remembered (one request less each time)
		$key = 'dist_' . sha1(strtolower($this->username) . '|' . $this->url . '|' . strtolower($mailbox ?? ''));
		$known = $this->idCacheGet !== null ? ($this->idCacheGet)($key) : null;
		$known = is_array($known) ? array_filter($known, 'is_string') : [];
		$missing = array_values(array_diff($names, array_keys($known)));
		if ($missing !== []) {
			$ids = '';
			foreach ($missing as $n) {
				$ids .= $this->distinguishedXml($n, $mailbox);
			}
			$xp = $this->request(
				'<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape></m:FolderShape>'
				. '<m:FolderIds>' . $ids . '</m:FolderIds></m:GetFolder>',
				false
			);
			$found = [];
			$messages = $xp->query('//m:ResponseMessages/m:GetFolderResponseMessage');
			foreach ($messages as $i => $msg) {
				if ($msg instanceof \DOMElement && $msg->getAttribute('ResponseClass') === 'Success') {
					$id = $this->attr($xp, './/t:FolderId', 'Id', $msg);
					if ($id !== null && isset($missing[$i])) {
						$found[$missing[$i]] = $id;
					}
				}
			}
			// (a folder not found, or not shared with us, is asked again next time)
			if ($found !== []) {
				$known += $found;
				if ($this->idCacheSet !== null) {
					($this->idCacheSet)($key, $known, self::ID_CACHE_TTL);
				}
			}
		}
		$out = [];
		foreach ($names as $n) {
			if (isset($known[$n])) {
				$out[$n] = $known[$n];
			}
		}
		return $out;
	}

	/** How long the ids of the well-known folders are remembered (seconds). */
	public const ID_CACHE_TTL = 86400;

	private ?\Closure $idCacheGet = null;
	private ?\Closure $idCacheSet = null;

	/**
	 * Where the ids of the well-known folders are remembered between requests (Nextcloud's cache).
	 *
	 * @param \Closure(string): mixed $get
	 * @param \Closure(string, array<string, string>, int): void $set
	 */
	public function setIdCache(\Closure $get, \Closure $set): void {
		$this->idCacheGet = $get;
		$this->idCacheSet = $set;
	}

	/** A well-known folder (inbox…) of the account's mailbox, or of another one (a shared mailbox). */
	public function distinguishedXml(string $name, ?string $mailbox = null): string {
		return $mailbox === null
			? '<t:DistinguishedFolderId Id="' . $this->esc($name) . '"/>'
			: '<t:DistinguishedFolderId Id="' . $this->esc($name) . '"><t:Mailbox><t:EmailAddress>' . $this->esc($mailbox) . '</t:EmailAddress></t:Mailbox></t:DistinguishedFolderId>';
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
	 * Most items one FindItem may return over all its folders: Exchange refuses
	 * or cuts a larger answer (EWSFindCountLimit, 1000 by default).
	 */
	public const FIND_COUNT_LIMIT = 1000;

	/**
	 * Search several folders at once (the whole mailbox), results merged in the
	 * order asked (newest first by default). Paging goes up to 200 results.
	 *
	 * Exchange gives the first N matches of each folder: asking 200 of each of
	 * hundreds of folders makes an answer of tens of thousands of messages
	 * (seconds, and over the limit of Exchange). So each folder gives first a
	 * few, as many as fit in one answer; only the folders whose few may hide
	 * results of the page (their last one comes before the last of the page)
	 * are asked again, for the whole window.
	 * @param string[] $folderIds
	 */
	public function searchFolders(array $folderIds, int $offset, int $limit, string $query, string $sort = 'date', bool $desc = true): array {
		$window = min(200, max(1, $offset + $limit));
		$folderIds = array_values(array_unique($folderIds));
		$per = min($window, max(5, intdiv(self::FIND_COUNT_LIMIT, max(1, count($folderIds)))));
		$found = $this->searchChunks($folderIds, $per, $query, $sort, $desc);
		$cmp = self::searchOrder($sort, $desc);
		if ($per < $window) {
			// asked for the whole window, these folders give an exact answer
			$found = array_replace($found, $this->searchChunks(self::foldersToWiden($found, $window, $cmp), $window, $query, $sort, $desc));
		}

		$items = [];
		$total = 0;
		$more = false;
		$error = null;
		foreach ($found as $r) {
			if (isset($r['error'])) {
				$error ??= $r['error'];
				continue;
			}
			$total += $r['total'];
			$more = $more || $r['more'];
			array_push($items, ...$r['items']);
		}
		if ($items === [] && $total === 0 && $error !== null) {
			// every folder refused: report the first error
			throw $error;
		}
		usort($items, $cmp);
		$page = array_slice($items, $offset, $limit);
		return [
			'items' => $page,
			'total' => $total,
			'hasMore' => $offset + $limit < 200 && ($more || count($items) > $offset + $limit),
		];
	}

	/**
	 * searchWindows for any number of folders: as many per request as fit in one answer.
	 * @param string[] $folderIds
	 */
	private function searchChunks(array $folderIds, int $count, string $query, string $sort, bool $desc): array {
		$found = [];
		foreach (array_chunk($folderIds, max(1, intdiv(self::FIND_COUNT_LIMIT, $count))) as $chunk) {
			$found += $this->searchWindows($chunk, $count, $query, $sort, $desc);
		}
		return $found;
	}

	/**
	 * The first `$count` matches of each folder (one FindItem for all of them).
	 * @param string[] $folderIds
	 * @return array<string, array{items: array, total: int, more: bool}|array{error: MailException}>
	 */
	private function searchWindows(array $folderIds, int $count, string $query, string $sort, bool $desc): array {
		$ids = '';
		foreach ($folderIds as $id) {
			$ids .= $this->folderIdXml($id);
		}
		$xp = $this->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $this->summaryFields(true) . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . $count . '" Offset="0" BasePoint="Beginning"/>'
			. self::sortOrderXml($sort, $desc)
			. '<m:ParentFolderIds>' . $ids . '</m:ParentFolderIds>'
			. '<m:QueryString>' . $this->esc($query) . '</m:QueryString>'
			. '</m:FindItem>',
			false
		);
		$out = [];
		// one answer per folder, in the order asked
		foreach ($xp->query('//m:ResponseMessages/*') as $i => $msg) {
			if (!$msg instanceof \DOMElement || !isset($folderIds[$i])) {
				continue;
			}
			$root = $xp->query('m:RootFolder', $msg)->item(0);
			if ($msg->getAttribute('ResponseClass') === 'Error' || !$root instanceof \DOMElement) {
				$out[$folderIds[$i]] = ['error' => self::exchangeError($this->text($xp, 'm:ResponseCode', $msg), $this->text($xp, 'm:MessageText', $msg))];
				continue;
			}
			$items = [];
			foreach ($xp->query('t:Items/*', $root) as $node) {
				$items[] = $this->summary($xp, $node);
			}
			$out[$folderIds[$i]] = [
				'items' => $items,
				'total' => (int)$root->getAttribute('TotalItemsInView'),
				'more' => $root->getAttribute('IncludesLastItemInRange') === 'false',
			];
		}
		return $out;
	}

	/**
	 * The folders that gave only part of their matches and whose last one comes
	 * before the last result of the window: the rest of them may be in the page.
	 * @param array<string, array> $found as searchWindows gives
	 * @return string[]
	 */
	public static function foldersToWiden(array $found, int $window, callable $cmp): array {
		$all = [];
		foreach ($found as $r) {
			if (!isset($r['error'])) {
				array_push($all, ...$r['items']);
			}
		}
		usort($all, $cmp);
		$edge = $all[$window - 1] ?? null;
		$again = [];
		foreach ($found as $id => $r) {
			if (isset($r['error']) || $r['items'] === [] || count($r['items']) >= $r['total']) {
				continue; // nothing more in this folder
			}
			$last = $r['items'][count($r['items']) - 1];
			if ($edge === null || $cmp($last, $edge) < 0) {
				$again[] = (string)$id;
			}
		}
		return $again;
	}

	/** How results of several folders are merged, as Exchange sorts each one. */
	public static function searchOrder(string $sort, bool $desc): callable {
		$key = match ($sort) {
			'from' => fn (array $m) => mb_strtolower((string)(($m['from']['name'] ?? '') !== '' ? $m['from']['name'] : ($m['from']['email'] ?? ''))),
			'subject' => fn (array $m) => mb_strtolower((string)$m['subject']),
			'size' => fn (array $m) => (int)($m['size'] ?? 0),
			default => fn (array $m) => (string)$m['date'],
		};
		return function (array $a, array $b) use ($key, $desc, $sort): int {
			$c = $key($a) <=> $key($b);
			if ($c !== 0) {
				return $desc ? -$c : $c;
			}
			// then the newest first, as Exchange is asked
			return $sort === 'date' ? 0 : strcmp((string)$b['date'], (string)$a['date']);
		};
	}

	private function summaryFields(bool $withFolder = false): string {
		$props = ['item:Subject', 'message:From', 'item:DisplayTo', 'item:DateTimeReceived', 'message:IsRead',
			'item:HasAttachments', 'item:Importance', 'item:Flag', 'item:Preview', 'item:Categories', 'item:Size'];
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
			'flagDue' => $this->flagDue($xp, $node),
			'categories' => $this->strings($xp, 't:Categories/t:String', $node),
			'preview' => mb_substr((string)$this->text($xp, 't:Preview', $node), 0, 200),
			'size' => (int)$this->text($xp, 't:Size', $node),
			'isMeeting' => $node->localName !== 'Message',
		];
		$folder = $this->attr($xp, 't:ParentFolderId', 'Id', $node);
		if ($folder !== null) {
			$item['folderId'] = $folder;
		}
		return $item;
	}

	/**
	 * The messages with attachments received in the inbox since $since (UTC), oldest first:
	 * for « classer les pièces jointes dans Fichiers ».
	 *
	 * @return list<array{id: string, from: ?array{name: string, email: string}, subject: string, date: ?string}>
	 * @throws MailException
	 */
	public function withAttachmentsSince(string $since, int $max = 50): array {
		$xp = $this->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties><t:FieldURI FieldURI="item:Subject"/>'
			. '<t:FieldURI FieldURI="message:From"/><t:FieldURI FieldURI="item:DateTimeReceived"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . max(1, $max) . '" Offset="0" BasePoint="Beginning"/>'
			. '<m:Restriction><t:And>'
			. '<t:IsGreaterThan><t:FieldURI FieldURI="item:DateTimeReceived"/><t:FieldURIOrConstant><t:Constant Value="' . $this->esc($since) . '"/></t:FieldURIOrConstant></t:IsGreaterThan>'
			. '<t:IsEqualTo><t:FieldURI FieldURI="item:HasAttachments"/><t:FieldURIOrConstant><t:Constant Value="true"/></t:FieldURIOrConstant></t:IsEqualTo>'
			. '</t:And></m:Restriction>'
			. '<m:SortOrder><t:FieldOrder Order="Ascending"><t:FieldURI FieldURI="item:DateTimeReceived"/></t:FieldOrder></m:SortOrder>'
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="inbox"/></m:ParentFolderIds>'
			. '</m:FindItem>'
		);
		$out = [];
		foreach ($xp->query('//m:RootFolder/t:Items/*') as $node) {
			$out[] = [
				'id' => (string)$this->attr($xp, 't:ItemId', 'Id', $node),
				'from' => $this->mailbox($xp, 't:From/t:Mailbox', $node),
				'subject' => (string)$this->text($xp, 't:Subject', $node),
				'date' => $this->text($xp, 't:DateTimeReceived', $node),
			];
		}
		return $out;
	}

	/**
	 * The messages received in the inbox since $since (UTC), whose subject contains $subject
	 * (case ignored; '' for any): their sender and subject, newest first. For « pas de réponse ».
	 *
	 * @return list<array{from: ?array{name: string, email: string}, subject: string}>
	 * @throws MailException
	 */
	public function receivedSince(string $since, string $subject = '', int $max = 200): array {
		$date = '<t:IsGreaterThanOrEqualTo><t:FieldURI FieldURI="item:DateTimeReceived"/>'
			. '<t:FieldURIOrConstant><t:Constant Value="' . $this->esc($since) . '"/></t:FieldURIOrConstant></t:IsGreaterThanOrEqualTo>';
		$restriction = $subject === '' ? $date
			: '<t:And>' . $date . '<t:Contains ContainmentMode="Substring" ContainmentComparison="IgnoreCase"><t:FieldURI FieldURI="item:Subject"/>'
				. '<t:Constant Value="' . $this->esc($subject) . '"/></t:Contains></t:And>';
		$xp = $this->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties><t:FieldURI FieldURI="item:Subject"/><t:FieldURI FieldURI="message:From"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . max(1, $max) . '" Offset="0" BasePoint="Beginning"/>'
			. '<m:Restriction>' . $restriction . '</m:Restriction>'
			. self::sortOrderXml()
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="inbox"/></m:ParentFolderIds>'
			. '</m:FindItem>'
		);
		$out = [];
		foreach ($xp->query('//m:RootFolder/t:Items/*') as $node) {
			$out[] = ['from' => $this->mailbox($xp, 't:From/t:Mailbox', $node), 'subject' => (string)$this->text($xp, 't:Subject', $node)];
		}
		return $out;
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
		// empty inbox: whatever arrives is new. Not Nextcloud's clock, which can be ahead
		// of Exchange's: the messages received meanwhile would be dated before it, never announced
		$latest = $dates !== [] ? max($dates) : self::BEFORE_ANY_MESSAGE;
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

	// ------------------------------------------------------------------
	// Pull subscription to the inbox: Exchange keeps the new messages for
	// us between two checks; a check then costs one small request
	// ------------------------------------------------------------------

	/**
	 * Subscribe to the new messages of the inbox.
	 * @param int $timeout minutes without a check after which Exchange drops the subscription
	 * @return array{id: string, watermark: string}
	 */
	public function subscribeInbox(int $timeout = 30): array {
		$xp = $this->request(
			'<m:Subscribe><m:PullSubscriptionRequest><t:FolderIds><t:DistinguishedFolderId Id="inbox"/></t:FolderIds>'
			. '<t:EventTypes><t:EventType>NewMailEvent</t:EventType></t:EventTypes>'
			. '<t:Timeout>' . max(1, min(1440, $timeout)) . '</t:Timeout></m:PullSubscriptionRequest></m:Subscribe>'
		);
		$id = (string)$this->text($xp, '//m:SubscriptionId');
		$watermark = (string)$this->text($xp, '//m:Watermark');
		if ($id === '' || $watermark === '') {
			throw new MailException('Exchange n\'a pas accepté l\'abonnement aux nouveaux messages');
		}
		return ['id' => $id, 'watermark' => $watermark];
	}

	/**
	 * The messages arrived since the last check of a subscription.
	 * @return ?array{itemIds: string[], watermark: string} null when Exchange
	 *         no longer knows the subscription (expired): subscribe again
	 */
	public function newMailEvents(string $subscriptionId, string $watermark): ?array {
		$ids = [];
		for ($i = 0; $i < 10; $i++) {
			$xp = $this->request(
				'<m:GetEvents><m:SubscriptionId>' . $this->esc($subscriptionId) . '</m:SubscriptionId>'
				. '<m:Watermark>' . $this->esc($watermark) . '</m:Watermark></m:GetEvents>',
				false
			);
			$msg = $xp->query('//m:GetEventsResponseMessage')->item(0);
			if (!$msg instanceof \DOMElement || $msg->getAttribute('ResponseClass') !== 'Success') {
				return null;
			}
			foreach ($xp->query('m:Notification/*', $msg) as $event) {
				$w = $this->text($xp, 't:Watermark', $event);
				if ($w !== null && $w !== '') {
					$watermark = $w;
				}
				if ($event->localName === 'NewMailEvent') {
					$id = $this->attr($xp, 't:ItemId', 'Id', $event);
					if ($id !== null) {
						$ids[] = $id;
					}
				}
			}
			if ($this->text($xp, 'm:Notification/t:MoreEvents', $msg) !== 'true') {
				break;
			}
		}
		return ['itemIds' => array_values(array_unique($ids)), 'watermark' => $watermark];
	}

	/**
	 * Summaries of some messages (those gone meanwhile are left out).
	 * @param string[] $ids
	 * @return list<array<string, mixed>>
	 */
	public function summaries(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $this->summaryFields(true) . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds>' . $this->itemIdsXml($ids) . '</m:ItemIds></m:GetItem>',
			false
		);
		$out = [];
		foreach ($xp->query('//m:GetItemResponseMessage[@ResponseClass="Success"]/m:Items/*') as $node) {
			$out[] = $this->summary($xp, $node);
		}
		return $out;
	}

	/** At most this many messages of one sender in one go. */
	public const SENDER_MAX = 1000;

	/**
	 * The ids of a folder's messages from one sender (« Tous ses messages »),
	 * newest first, at most SENDER_MAX.
	 * @return list<string>
	 * @throws MailException
	 */
	public function idsFromSender(string $folderId, string $email): array {
		$ids = [];
		for ($offset = 0; $offset < self::SENDER_MAX; $offset += 200) {
			$xp = $this->request(
				'<m:FindItem Traversal="Shallow">'
				. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape>'
				. '<m:IndexedPageItemView MaxEntriesReturned="200" Offset="' . $offset . '" BasePoint="Beginning"/>'
				. self::sortOrderXml('date', true)
				. '<m:ParentFolderIds>' . $this->folderIdXml($folderId) . '</m:ParentFolderIds>'
				. '<m:QueryString>' . $this->esc(self::fromQuery($email)) . '</m:QueryString>'
				. '</m:FindItem>'
			);
			foreach ($xp->query('//m:RootFolder/t:Items/*/t:ItemId') as $n) {
				$ids[] = $n->getAttribute('Id');
			}
			$root = $xp->query('//m:RootFolder')->item(0);
			if (!$root instanceof \DOMElement || $root->getAttribute('IncludesLastItemInRange') !== 'false') {
				break;
			}
		}
		return array_slice(array_values(array_unique($ids)), 0, self::SENDER_MAX);
	}

	/**
	 * The search (AQS) for the messages of one address.
	 * @throws MailException
	 */
	public static function fromQuery(string $email): string {
		$email = trim($email);
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_contains($email, '"')) {
			throw new MailException('Adresse invalide : %s', [$email]);
		}
		return 'from:"' . mb_strtolower($email) . '"';
	}

	/** The orders of the message list: field => what Exchange sorts on. */
	private const SORTS = [
		'date' => '<t:FieldURI FieldURI="item:DateTimeReceived"/>',
		// PR_SENT_REPRESENTING_NAME: the name shown as the sender
		'from' => '<t:ExtendedFieldURI PropertyTag="0x0042" PropertyType="String"/>',
		'subject' => '<t:FieldURI FieldURI="item:Subject"/>',
		'size' => '<t:FieldURI FieldURI="item:Size"/>',
	];

	/**
	 * « Trier par » of the message list; then by date, newest first, for equal values.
	 * Unknown fields: by date. $desc: Z→A, largest, newest first.
	 */
	public static function sortOrderXml(string $field = 'date', bool $desc = true): string {
		$field = isset(self::SORTS[$field]) ? $field : 'date';
		$xml = '<t:FieldOrder Order="' . ($desc ? 'Descending' : 'Ascending') . '">' . self::SORTS[$field] . '</t:FieldOrder>';
		if ($field !== 'date') {
			$xml .= '<t:FieldOrder Order="Descending">' . self::SORTS['date'] . '</t:FieldOrder>';
		}
		return '<m:SortOrder>' . $xml . '</m:SortOrder>';
	}

	public function findItems(string $folderId, int $offset = 0, int $limit = 50, ?string $query = null, string $sort = 'date', bool $desc = true): array {
		$fields = $this->summaryFields();
		$queryXml = '';
		if ($query !== null && trim($query) !== '') {
			$queryXml = '<m:QueryString>' . $this->esc(trim($query)) . '</m:QueryString>';
		}

		$xp = $this->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $fields . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . max(1, $limit) . '" Offset="' . max(0, $offset) . '" BasePoint="Beginning"/>'
			. self::sortOrderXml($sort, $desc)
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
	 * The conversations of a folder, newest first (FindConversation, like
	 * Outlook's « Afficher en tant que conversations »): one row each, with
	 * its senders, its number of messages and unread ones. The row opens the
	 * conversation's newest message of this folder.
	 * @return array{items: list<array<string, mixed>>, total: int, hasMore: bool}
	 */
	public function findConversations(string $folderId, int $offset = 0, int $limit = 50): array {
		$xp = $this->request(
			'<m:FindConversation>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . max(1, $limit) . '" Offset="' . max(0, $offset) . '" BasePoint="Beginning"/>'
			. '<m:ParentFolderId>' . $this->folderIdXml($folderId) . '</m:ParentFolderId>'
			. '</m:FindConversation>',
			false
		);
		$root = $xp->query('//m:FindConversationResponse')->item(0);
		if (!$root instanceof \DOMElement || $root->getAttribute('ResponseClass') !== 'Success') {
			$code = $root instanceof \DOMElement ? (string)$this->text($xp, 'm:ResponseCode', $root) : '';
			throw $code !== ''
				? new MailException('Exchange ne donne pas les conversations de ce dossier (%s)', [$code])
				: new MailException('Exchange ne donne pas les conversations de ce dossier');
		}
		$items = [];
		foreach ($xp->query('m:Conversations/t:Conversation', $root) as $c) {
			$ids = $xp->query('t:ItemIds/t:ItemId', $c);
			$first = $ids->item(0);
			if (!$first instanceof \DOMElement) {
				continue;
			}
			$senders = $this->strings($xp, 't:UniqueSenders/t:String', $c);
			$unread = (int)$this->text($xp, 't:UnreadCount', $c);
			$items[] = [
				'id' => $first->getAttribute('Id'),
				'changeKey' => $first->getAttribute('ChangeKey'),
				'conversationId' => $this->attr($xp, 't:ConversationId', 'Id', $c),
				'subject' => (string)$this->text($xp, 't:ConversationTopic', $c),
				'from' => ['name' => implode(', ', $senders), 'email' => ''],
				'displayTo' => implode(', ', $this->strings($xp, 't:UniqueRecipients/t:String', $c)),
				'date' => $this->text($xp, 't:LastDeliveryTime', $c),
				'isRead' => $unread === 0,
				'hasAttachments' => $this->text($xp, 't:HasAttachments', $c) === 'true',
				'importance' => $this->text($xp, 't:Importance', $c) ?? 'Normal',
				'flagged' => $this->text($xp, 't:FlagStatus', $c) === 'Flagged',
				'categories' => $this->strings($xp, 't:Categories/t:String', $c),
				'preview' => mb_substr((string)$this->text($xp, 't:Preview', $c), 0, 200),
				'isMeeting' => false,
				'count' => (int)$this->text($xp, 't:MessageCount', $c),
				'unreadCount' => $unread,
				'ids' => array_values(array_filter(array_map(fn ($n) => $n instanceof \DOMElement ? $n->getAttribute('Id') : '', iterator_to_array($ids)))),
			];
		}
		$total = (int)$this->text($xp, 'm:TotalConversationsInView', $root);
		return ['items' => $items, 'total' => $total, 'hasMore' => max(0, $offset) + count($items) < $total];
	}

	/**
	 * « Ignorer la conversation » (as in Outlook): its messages go to « Éléments supprimés »,
	 * and so will those that come later. $ignore false: no longer ignored, and the messages
	 * put in « Éléments supprimés » go back to the inbox.
	 *
	 * @throws MailException
	 */
	public function ignoreConversation(string $conversationId, bool $ignore): void {
		$id = '<t:ConversationId Id="' . $this->esc($conversationId) . '"/>';
		$actions = '<t:ConversationAction><t:Action>AlwaysDelete</t:Action>' . $id
			. '<t:ProcessRightAway>' . ($ignore ? 'true' : 'false') . '</t:ProcessRightAway>'
			. '<t:EnableAlwaysDelete>' . ($ignore ? 'true' : 'false') . '</t:EnableAlwaysDelete></t:ConversationAction>';
		if (!$ignore) {
			$actions .= '<t:ConversationAction><t:Action>Move</t:Action>' . $id
				. '<t:ContextFolderId><t:DistinguishedFolderId Id="deleteditems"/></t:ContextFolderId>'
				. '<t:DestinationFolderId><t:DistinguishedFolderId Id="inbox"/></t:DestinationFolderId></t:ConversationAction>';
		}
		$this->request('<m:ApplyConversationAction><m:ConversationActions>' . $actions . '</m:ConversationActions></m:ApplyConversationAction>');
	}

	/**
	 * Every message of a conversation, in every folder (the replies sent are
	 * in « Éléments envoyés »), oldest first. Deleted items and drafts are
	 * left out.
	 * @return list<array<string, mixed>>
	 */
	public function conversation(string $conversationId): array {
		$xp = $this->request(
			'<m:GetConversationItems>'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $this->summaryFields(true) . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:FoldersToIgnore><t:DistinguishedFolderId Id="deleteditems"/><t:DistinguishedFolderId Id="drafts"/></m:FoldersToIgnore>'
			. '<m:MaxItemsToReturn>100</m:MaxItemsToReturn>'
			. '<m:SortOrder>DateOrderAscending</m:SortOrder>'
			. '<m:Conversations><t:Conversation><t:ConversationId Id="' . $this->esc($conversationId) . '"/></t:Conversation></m:Conversations>'
			. '</m:GetConversationItems>'
		);
		$items = [];
		$seen = [];
		foreach ($xp->query('//m:Conversation/t:ConversationNodes/t:ConversationNode/t:Items/*') as $node) {
			$item = $this->summary($xp, $node);
			if ($item['id'] === null || isset($seen[$item['id']])) {
				continue;
			}
			$seen[$item['id']] = true;
			$items[] = $item;
		}
		usort($items, fn ($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
		return $items;
	}

	/**
	 * The newest messages sent (« Sans réponse »), with their conversation.
	 * @return list<array<string, mixed>>
	 * @throws MailException
	 */
	public function recentlySent(int $max = 200): array {
		$xp = $this->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $this->summaryFields()
			. '<t:FieldURI FieldURI="item:ConversationId"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . max(1, $max) . '" Offset="0" BasePoint="Beginning"/>'
			. self::sortOrderXml('date', true)
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="sentitems"/></m:ParentFolderIds>'
			. '</m:FindItem>'
		);
		$items = [];
		foreach ($xp->query('//m:RootFolder/t:Items/*') as $node) {
			$items[] = $this->summary($xp, $node) + ['conversationId' => (string)$this->attr($xp, 't:ConversationId', 'Id', $node)];
		}
		return $items;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getItem(string $itemId): array {
		$props = ['item:Subject', 'item:Body', 'message:From', 'message:Sender', 'message:ToRecipients', 'message:CcRecipients',
			'message:BccRecipients', 'message:ReplyTo', 'item:DateTimeReceived', 'item:DateTimeSent', 'message:IsRead',
			'item:Attachments', 'item:HasAttachments', 'item:Importance', 'item:Flag', 'message:InternetMessageId', 'item:ParentFolderId',
			'item:Categories', 'item:ConversationId'];
		$fields = '';
		foreach ($props as $p) {
			$fields .= '<t:FieldURI FieldURI="' . $p . '"/>';
		}
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:BodyType>HTML</t:BodyType>'
			. '<t:AdditionalProperties>' . $fields . self::LIST_HEADERS . '</t:AdditionalProperties></m:ItemShape>'
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
			'flagDue' => $this->flagDue($xp, $node),
			'categories' => $this->strings($xp, 't:Categories/t:String', $node),
			'conversationId' => $this->attr($xp, 't:ConversationId', 'Id', $node),
			'messageId' => $this->text($xp, 't:InternetMessageId', $node),
			'unsubscribe' => $this->unsubscribeOf($xp, $node),
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

	/**
	 * A whole message as an .eml file (its MIME content, as Exchange keeps it):
	 * opened by Outlook, Thunderbird, Apple Mail…
	 *
	 * @return array{name: string, contentType: string, content: string}
	 * @throws MailException
	 */
	public function getMime(string $itemId): array {
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:IncludeMimeContent>true</t:IncludeMimeContent>'
			. '<t:AdditionalProperties><t:FieldURI FieldURI="item:Subject"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->esc($itemId) . '"/></m:ItemIds></m:GetItem>'
		);
		$item = $xp->query('//m:Items/*')->item(0);
		$mime = $item !== null ? $this->text($xp, 't:MimeContent', $item) : null;
		if ($item === null || $mime === null || $mime === '') {
			throw new MailException('Message introuvable');
		}
		$subject = trim((string)$this->text($xp, 't:Subject', $item));
		// a file name of the subject: no path, no control or reserved character, not too long
		$name = trim((string)preg_replace('~[\\\\/:*?"<>|\x00-\x1f]+~u', ' ', $subject), ' .');
		$name = mb_substr((string)preg_replace('/\s+/u', ' ', $name), 0, 80);
		return [
			'name' => ($name !== '' ? $name : 'message') . '.eml',
			'contentType' => 'message/rfc822',
			'content' => (string)base64_decode($mime),
		];
	}

	/**
	 * The ids of a folder's messages, newest first, at most $max (« Exporter le dossier »).
	 * @return list<string>
	 * @throws MailException
	 */
	public function folderItemIds(string $folderId, int $max): array {
		$ids = [];
		for ($offset = 0; $offset < $max; $offset += 200) {
			$xp = $this->request(
				'<m:FindItem Traversal="Shallow">'
				. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape>'
				. '<m:IndexedPageItemView MaxEntriesReturned="' . min(200, $max - $offset) . '" Offset="' . $offset . '" BasePoint="Beginning"/>'
				. self::sortOrderXml('date', true)
				. '<m:ParentFolderIds>' . $this->folderIdXml($folderId) . '</m:ParentFolderIds>'
				. '</m:FindItem>'
			);
			foreach ($xp->query('//m:RootFolder/t:Items/*/t:ItemId') as $n) {
				$ids[] = $n->getAttribute('Id');
			}
			$root = $xp->query('//m:RootFolder')->item(0);
			if (!$root instanceof \DOMElement || $root->getAttribute('IncludesLastItemInRange') !== 'false') {
				break;
			}
		}
		return array_slice(array_values(array_unique($ids)), 0, $max);
	}

	/**
	 * Several messages as .eml in one request; those gone are left out.
	 * @param list<string> $ids
	 * @return list<array{id: string, subject: string, date: ?string, content: string}>
	 * @throws MailException
	 */
	public function mimes(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:IncludeMimeContent>true</t:IncludeMimeContent>'
			. '<t:AdditionalProperties><t:FieldURI FieldURI="item:Subject"/><t:FieldURI FieldURI="item:DateTimeReceived"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds>' . $this->itemIdsXml($ids) . '</m:ItemIds></m:GetItem>',
			false
		);
		$out = [];
		foreach ($xp->query('//m:GetItemResponseMessage[@ResponseClass="Success"]/m:Items/*') as $item) {
			$mime = (string)$this->text($xp, 't:MimeContent', $item);
			if ($mime === '') {
				continue;
			}
			$out[] = [
				'id' => (string)$this->attr($xp, 't:ItemId', 'Id', $item),
				'subject' => trim((string)$this->text($xp, 't:Subject', $item)),
				'date' => $this->text($xp, 't:DateTimeReceived', $item),
				'content' => (string)base64_decode($mime),
			];
		}
		return $out;
	}

	/**
	 * The Internet headers of a message (PR_TRANSPORT_MESSAGE_HEADERS, 0x007D): who sent it through
	 * which servers, and the SPF / DKIM / DMARC checks. None for a message written in the mailbox.
	 *
	 * @return array{raw: string, headers: list<array{name: string, value: string}>,
	 *               auth: array<string, string>, hops: list<array{from: string, by: string, date: ?string}>}
	 * @throws MailException
	 */
	public function getHeaders(string $itemId): array {
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape>'
			. '<t:AdditionalProperties><t:ExtendedFieldURI PropertyTag="0x007D" PropertyType="String"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->esc($itemId) . '"/></m:ItemIds></m:GetItem>'
		);
		$item = $xp->query('//m:Items/*')->item(0);
		if ($item === null) {
			throw new MailException('Message introuvable');
		}
		return MailHeaders::parse((string)$this->text($xp, 't:ExtendedProperty/t:Value', $item));
	}

	/** The List-Unsubscribe headers of a message (Exchange keeps each Internet header as a property). */
	private const LIST_HEADERS = '<t:ExtendedFieldURI DistinguishedPropertySetId="InternetHeaders" PropertyName="List-Unsubscribe" PropertyType="String"/>'
		. '<t:ExtendedFieldURI DistinguishedPropertySetId="InternetHeaders" PropertyName="List-Unsubscribe-Post" PropertyType="String"/>';

	/** @return ?array{method: string, url?: string, to?: string, subject?: string, body?: string} */
	private function unsubscribeOf(\DOMXPath $xp, \DOMNode $item): ?array {
		$values = ['list-unsubscribe' => '', 'list-unsubscribe-post' => ''];
		foreach ($xp->query('t:ExtendedProperty', $item) as $prop) {
			// (Exchange may give the header name in another case)
			$name = strtolower((string)$this->attr($xp, 't:ExtendedFieldURI', 'PropertyName', $prop));
			if (array_key_exists($name, $values)) {
				$values[$name] = (string)$this->text($xp, 't:Value', $prop);
			}
		}
		return Unsubscribe::parse($values['list-unsubscribe'], $values['list-unsubscribe-post']);
	}

	/**
	 * How to leave the mailing list a message comes from (« Se désabonner »), read again
	 * from Exchange: what the browser says is not trusted.
	 *
	 * @return ?array{method: string, url?: string, to?: string, subject?: string, body?: string}
	 * @throws MailException
	 */
	public function unsubscribeInfo(string $itemId): ?array {
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape>'
			. '<t:AdditionalProperties>' . self::LIST_HEADERS . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->esc($itemId) . '"/></m:ItemIds></m:GetItem>'
		);
		$item = $xp->query('//m:Items/*')->item(0);
		if ($item === null) {
			throw new MailException('Message introuvable');
		}
		return $this->unsubscribeOf($xp, $item);
	}

	/** « Lettres d'information »: at most this many of the newest messages looked at. */
	public const LISTS_SCAN = 2000;

	/**
	 * The messages of a folder that come from a mailing list (a List-Unsubscribe header),
	 * newest first, among its LISTS_SCAN newest: who sent them, read or not, how heavy.
	 *
	 * @return array{messages: list<array{id: string, email: string, name: string, isRead: bool, size: int, date: ?string, method: string}>, scanned: int}
	 * @throws MailException
	 */
	public function mailingListMessages(string $folderId): array {
		$out = [];
		$scanned = 0;
		for ($offset = 0; $offset < self::LISTS_SCAN; $offset += 200) {
			$xp = $this->request(
				'<m:FindItem Traversal="Shallow">'
				. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
				. '<t:FieldURI FieldURI="message:From"/><t:FieldURI FieldURI="message:IsRead"/>'
				. '<t:FieldURI FieldURI="item:Size"/><t:FieldURI FieldURI="item:DateTimeReceived"/>'
				. self::LIST_HEADERS . '</t:AdditionalProperties></m:ItemShape>'
				. '<m:IndexedPageItemView MaxEntriesReturned="200" Offset="' . $offset . '" BasePoint="Beginning"/>'
				. self::sortOrderXml('date', true)
				. '<m:ParentFolderIds>' . $this->folderIdXml($folderId) . '</m:ParentFolderIds>'
				. '</m:FindItem>'
			);
			foreach ($xp->query('//m:RootFolder/t:Items/*') as $node) {
				$scanned++;
				$how = $this->unsubscribeOf($xp, $node);
				$from = $this->mailbox($xp, 't:From/t:Mailbox', $node);
				if ($how === null || !is_array($from) || (string)($from['email'] ?? '') === '') {
					continue;
				}
				$out[] = [
					'id' => (string)$this->attr($xp, 't:ItemId', 'Id', $node),
					'email' => mb_strtolower((string)$from['email']),
					'name' => (string)($from['name'] ?? ''),
					'isRead' => $this->text($xp, 't:IsRead', $node) !== 'false',
					'size' => (int)$this->text($xp, 't:Size', $node),
					'date' => $this->text($xp, 't:DateTimeReceived', $node),
					'method' => (string)$how['method'],
				];
			}
			$root = $xp->query('//m:RootFolder')->item(0);
			if (!$root instanceof \DOMElement || $root->getAttribute('IncludesLastItemInRange') !== 'false') {
				break;
			}
		}
		return ['messages' => $out, 'scanned' => $scanned];
	}

	/** Largest .eml imported (Exchange refuses bigger messages anyway). */
	public const MAX_EML_BYTES = 26214400;

	/**
	 * An .eml file (from Outlook, Thunderbird, another mailbox…) put into a folder,
	 * as a received and read message (not a draft: PR_MESSAGE_FLAGS = MSGFLAG_READ).
	 *
	 * @return string the id of the new message
	 * @throws MailException
	 */
	public function importMime(string $mime, string $folderId): string {
		if (strlen($mime) > self::MAX_EML_BYTES) {
			throw new MailException('Message trop lourd (25 Mo au plus)');
		}
		// a message starts with its headers (« From: … », « Subject: … »)
		if (!preg_match('/^(?:[!-9;-~]+:[^\r\n]*\r?\n(?:[ \t][^\r\n]*\r?\n)*)+\r?\n/', ltrim($mime, "\xEF\xBB\xBF\r\n"))
			|| !preg_match('/^(from|subject|date|message-id):/im', $mime)) {
			throw new MailException('Ce fichier n\'est pas un message (.eml)');
		}
		$xp = $this->request(
			'<m:CreateItem MessageDisposition="SaveOnly"><m:SavedItemFolderId>' . $this->folderIdXml($folderId) . '</m:SavedItemFolderId>'
			. '<m:Items><t:Message><t:MimeContent CharacterSet="UTF-8">' . base64_encode($mime) . '</t:MimeContent>'
			. '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0x0E07" PropertyType="Integer"/><t:Value>1</t:Value></t:ExtendedProperty>'
			. '</t:Message></m:Items></m:CreateItem>'
		);
		$id = $xp->query('//m:Items/t:Message/t:ItemId')->item(0);
		return $id instanceof \DOMElement ? $id->getAttribute('Id') : '';
	}

	/**
	 * Every message of a folder read (or unread) in one request, however many there
	 * are; no read receipt is sent for them.
	 *
	 * @throws MailException
	 */
	public function markFolderRead(string $folderId, bool $read = true): void {
		$this->request(
			'<m:MarkAllItemsAsRead><m:ReadFlag>' . ($read ? 'true' : 'false') . '</m:ReadFlag>'
			. '<m:SuppressReadReceipts>true</m:SuppressReadReceipts>'
			. '<m:FolderIds>' . $this->folderIdXml($folderId) . '</m:FolderIds></m:MarkAllItemsAsRead>'
		);
	}

	/** @param string[] $itemIds */
	public function setRead(array $itemIds, bool $read): void {
		$this->updateItems($itemIds, 'message:IsRead',
			'<t:Message><t:IsRead>' . ($read ? 'true' : 'false') . '</t:IsRead></t:Message>');
	}

	/**
	 * Follow-up flag of messages, with a due date (as Outlook's « Today », « Tomorrow »… ;
	 * the message then shows in Outlook's To-Do list) or without.
	 *
	 * @param string[] $itemIds
	 * @param ?string $due Y-m-d, or null / '' for a flag without a date
	 * @throws MailException
	 */
	public function setFlagged(array $itemIds, bool $flagged, ?string $due = null): void {
		$flag = '<t:FlagStatus>' . ($flagged ? 'Flagged' : 'NotFlagged') . '</t:FlagStatus>';
		if ($flagged && $due !== null && $due !== '') {
			if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $d) || !checkdate((int)$d[2], (int)$d[3], (int)$d[1])) {
				throw new MailException('Date invalide');
			}
			// Exchange wants a start with the due date, not after it; dates at midnight UTC, as Outlook writes them
			$start = min(gmdate('Y-m-d'), $due);
			$flag .= '<t:StartDate>' . $start . 'T00:00:00Z</t:StartDate><t:DueDate>' . $due . 'T00:00:00Z</t:DueDate>';
		}
		$this->updateItems($itemIds, 'item:Flag', '<t:Message><t:Flag>' . $flag . '</t:Flag></t:Message>');
	}

	/**
	 * Outlook categories of messages: these replace the ones they had.
	 * @param string[] $itemIds
	 * @param string[] $categories
	 */
	public function setCategories(array $itemIds, array $categories): void {
		$categories = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string)$c), $categories), fn ($c) => $c !== '')));
		if ($itemIds === []) {
			return;
		}
		if ($categories === []) {
			$changes = '';
			foreach ($itemIds as $id) {
				$changes .= '<t:ItemChange><t:ItemId Id="' . $this->esc($id) . '"/><t:Updates><t:DeleteItemField>'
					. '<t:FieldURI FieldURI="item:Categories"/></t:DeleteItemField></t:Updates></t:ItemChange>';
			}
			$this->request(
				'<m:UpdateItem MessageDisposition="SaveOnly" ConflictResolution="AlwaysOverwrite" SuppressReadReceipts="true">'
				. '<m:ItemChanges>' . $changes . '</m:ItemChanges></m:UpdateItem>'
			);
			return;
		}
		$this->updateItems($itemIds, 'item:Categories', '<t:Message><t:Categories>'
			. implode('', array_map(fn ($c) => '<t:String>' . $this->esc($c) . '</t:String>', $categories))
			. '</t:Categories></t:Message>');
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
	/**
	 * @param string[] $itemIds
	 * @return list<?string> the id of each message in its new folder (null when Exchange does not say it)
	 */
	public function moveItems(array $itemIds, string $targetFolderId): array {
		if ($itemIds === []) {
			return [];
		}
		$xp = $this->request(
			'<m:MoveItem><m:ToFolderId>' . $this->folderIdXml($targetFolderId) . '</m:ToFolderId>'
			. '<m:ItemIds>' . $this->itemIdsXml($itemIds) . '</m:ItemIds></m:MoveItem>'
		);
		$ids = [];
		foreach ($xp->query('//m:MoveItemResponseMessage') as $msg) {
			$ids[] = $this->attr($xp, 'm:Items/*/t:ItemId', 'Id', $msg);
		}
		return $ids;
	}

	/**
	 * The folder each message is in (null: the message is gone).
	 *
	 * @param string[] $itemIds
	 * @return array<string, ?string>
	 */
	public function parentFolders(array $itemIds): array {
		if ($itemIds === []) {
			return [];
		}
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties><t:FieldURI FieldURI="item:ParentFolderId"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds>' . $this->itemIdsXml($itemIds) . '</m:ItemIds></m:GetItem>',
			false
		);
		$out = [];
		// one answer per message, in the order asked
		foreach ($xp->query('//m:GetItemResponseMessage') as $i => $msg) {
			if (!isset($itemIds[$i])) {
				break;
			}
			$out[$itemIds[$i]] = $msg instanceof \DOMElement && $msg->getAttribute('ResponseClass') === 'Success'
				? $this->attr($xp, 'm:Items/*/t:ParentFolderId', 'Id', $msg)
				: null;
		}
		return $out;
	}

	/** Whether this folder is still there. */
	public function folderExists(string $folderId): bool {
		$xp = $this->request(
			'<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape></m:FolderShape>'
			. '<m:FolderIds>' . $this->folderIdXml($folderId) . '</m:FolderIds></m:GetFolder>',
			false
		);
		return $xp->query('//m:GetFolderResponseMessage[@ResponseClass="Success"]')->length > 0;
	}

	/** The mail folder with this name at the top of the mailbox, or null. */
	public function findTopFolder(string $name): ?string {
		$xp = $this->request(
			'<m:FindFolder Traversal="Shallow"><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="folder:DisplayName"/></t:AdditionalProperties></m:FolderShape>'
			. '<m:ParentFolderIds>' . $this->folderIdXml('msgfolderroot') . '</m:ParentFolderIds></m:FindFolder>'
		);
		foreach ($xp->query('//t:Folders/*') as $node) {
			if (mb_strtolower(trim((string)$this->text($xp, 't:DisplayName', $node))) === mb_strtolower(trim($name))) {
				return $this->attr($xp, 't:FolderId', 'Id', $node);
			}
		}
		return null;
	}

	/**
	 * @param string[] $itemIds
	 * @param bool $permanent true = HardDelete, false = move to "Deleted Items"
	 */
	/**
	 * Junk e-mail, like Outlook: $junk = true blocks the senders and moves the
	 * messages to « Courrier indésirable »; false trusts them (safe senders)
	 * and moves the messages back to the inbox.
	 * @param string[] $itemIds
	 */
	public function markAsJunk(array $itemIds, bool $junk): void {
		if ($itemIds === []) {
			return;
		}
		$this->request(
			'<m:MarkAsJunk IsJunk="' . ($junk ? 'true' : 'false') . '" MoveItem="true">'
			. '<m:ItemIds>' . $this->itemIdsXml($itemIds) . '</m:ItemIds></m:MarkAsJunk>'
		);
	}

	/**
	 * Empty a folder (« Vider »): its messages to the deleted items, or
	 * deleted for good when it is the deleted items itself. Sub-folders kept.
	 */
	public function emptyFolder(string $folderId, bool $permanent, ?bool $subFolders = null): void {
		// emptied for good: its sub-folders too (the folders deleted earlier land there)
		$subFolders ??= $permanent;
		$this->request(
			'<m:EmptyFolder DeleteType="' . ($permanent ? 'HardDelete' : 'MoveToDeletedItems') . '" DeleteSubFolders="' . ($subFolders ? 'true' : 'false') . '">'
			. '<m:FolderIds>' . $this->folderIdXml($folderId) . '</m:FolderIds></m:EmptyFolder>'
		);
	}

	/**
	 * « Éléments supprimés » emptied for good, its folders too. Some Exchange servers keep
	 * the folders (or refuse DeleteSubFolders): those left are then deleted one by one.
	 * @return list<string> the names of the folders that could not be deleted
	 */
	public function emptyDeletedItems(): array {
		try {
			$this->emptyFolder('deleteditems', true, true);
		} catch (MailException $e) {
			// (the messages at least)
			$this->emptyFolder('deleteditems', true, false);
		}
		$folders = $this->listFolders();
		$trash = null;
		foreach ($folders as $f) {
			if (($f['role'] ?? null) === 'deleteditems') {
				$trash = $f['id'];
			}
		}
		$left = [];
		foreach ($folders as $f) {
			if ($trash === null || $f['parentId'] !== $trash) {
				continue;
			}
			try {
				// (its own sub-folders go with it)
				$this->deleteFolder($f['id'], true);
			} catch (MailException $e) {
				$left[] = $f['name'];
			}
		}
		return $left;
	}

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
		array $attachments = [], ?string $referenceId = null, string $importance = 'Normal', array $options = []): void {
		$this->sendFrom = !empty($options['from']) ? (string)$options['from'] : null;
		try {
			$this->send($mode, $subject, $htmlBody, $to, $cc, $bcc, $attachments, $referenceId, $importance, $options);
		} finally {
			$this->sendFrom = null;
		}
	}

	/** Sender of the message being composed, when it is not the account (a shared mailbox). */
	private ?string $sendFrom = null;

	private function send(string $mode, string $subject, string $htmlBody, array $to, array $cc, array $bcc,
		array $attachments, ?string $referenceId, string $importance, array $options): void {
		if (!empty($options['readReceipt']) || !empty($options['sendAt'])) {
			// options are set on a draft, then the draft is sent (also for replies and forwards)
			$draft = $this->createDraft($this->composeItemXml($mode, $subject, $htmlBody, $to, $cc, $bcc, $referenceId, $importance));
			$this->setSendOptions($draft['id'], $options);
			$this->sendSavedDraft($draft['id'], $attachments);
			return;
		}
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
			. $this->recipientsXml('BccRecipients', $bcc)
			// sent as a shared mailbox (« De : contact@… »): needs the right « Envoyer en tant que »
			. ($this->sendFrom !== null ? '<t:From><t:Mailbox><t:EmailAddress>' . $this->esc($this->sendFrom) . '</t:EmailAddress></t:Mailbox></t:From>' : '');
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
	 * Send options of a draft: read receipt, and delivery at a later time
	 * (PR_DEFERRED_SEND_TIME: Exchange keeps the message in the Outbox until then).
	 * @param array{readReceipt?: bool, sendAt?: ?string} $options sendAt: ISO 8601
	 */
	public function setSendOptions(string $draftId, array $options): void {
		$updates = '';
		if (!empty($options['sendAt'])) {
			$at = date_create_immutable((string)$options['sendAt']);
			if ($at === false) {
				throw new MailException('Date d\'envoi invalide');
			}
			if ($at <= new \DateTimeImmutable('+1 minute')) {
				throw new MailException('L\'heure d\'envoi doit être dans le futur');
			}
			$updates .= '<t:SetItemField><t:ExtendedFieldURI PropertyTag="0x3FEF" PropertyType="SystemTime"/><t:Message>'
				. '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0x3FEF" PropertyType="SystemTime"/><t:Value>'
				. $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') . '</t:Value></t:ExtendedProperty></t:Message></t:SetItemField>';
		}
		if (!empty($options['readReceipt'])) {
			$updates .= '<t:SetItemField><t:FieldURI FieldURI="message:IsReadReceiptRequested"/><t:Message><t:IsReadReceiptRequested>true</t:IsReadReceiptRequested></t:Message></t:SetItemField>';
		}
		if (!empty($options['from'])) {
			$updates .= '<t:SetItemField><t:FieldURI FieldURI="message:From"/><t:Message><t:From><t:Mailbox><t:EmailAddress>'
				. $this->esc((string)$options['from']) . '</t:EmailAddress></t:Mailbox></t:From></t:Message></t:SetItemField>';
		}
		if ($updates === '') {
			return;
		}
		$this->request(
			'<m:UpdateItem MessageDisposition="SaveOnly" ConflictResolution="AlwaysOverwrite">'
			. '<m:ItemChanges><t:ItemChange><t:ItemId Id="' . $this->esc($draftId) . '"/><t:Updates>' . $updates . '</t:Updates></t:ItemChange></m:ItemChanges>'
			. '</m:UpdateItem>'
		);
	}

	/**
	 * Files joined to a draft while it is written: kept in Exchange with the
	 * draft, so nothing is lost when the window is closed.
	 * @param array<int, array{name: string, contentType: string, content: string}> $attachments
	 * @return list<array{id: string, name: string, size: int}> the files of the draft now
	 */
	public function addDraftAttachments(string $draftId, array $attachments): array {
		if ($attachments !== []) {
			$this->addAttachments($draftId, $attachments);
		}
		return $this->draftAttachments($draftId);
	}

	/** @return list<array{id: string, name: string, size: int}> the files of a draft (not the images in its text) */
	public function draftAttachments(string $draftId): array {
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="item:Attachments"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->esc($draftId) . '"/></m:ItemIds></m:GetItem>'
		);
		$out = [];
		foreach ($xp->query('//m:Items/*/t:Attachments/t:FileAttachment') as $att) {
			if ($this->text($xp, 't:IsInline', $att) === 'true') {
				continue;
			}
			$out[] = [
				'id' => (string)$this->attr($xp, 't:AttachmentId', 'Id', $att),
				'name' => (string)($this->text($xp, 't:Name', $att) ?? 'piece-jointe'),
				'size' => (int)$this->text($xp, 't:Size', $att),
			];
		}
		return $out;
	}

	/**
	 * The files attached to messages (not the images in their text), in one request.
	 * @param string[] $itemIds
	 * @return array<string, list<array{id: string, name: string, size: int, contentType: string}>> item id => its files
	 */
	public function attachmentsOf(array $itemIds): array {
		if ($itemIds === []) {
			return [];
		}
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="item:Attachments"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds>' . $this->itemIdsXml(array_values($itemIds)) . '</m:ItemIds></m:GetItem>'
		);
		$out = [];
		foreach ($xp->query('//m:Items/*') as $item) {
			$id = (string)$this->attr($xp, 't:ItemId', 'Id', $item);
			if ($id === '') {
				continue;
			}
			$out[$id] = [];
			foreach ($xp->query('t:Attachments/t:FileAttachment', $item) as $att) {
				if ($this->text($xp, 't:IsInline', $att) === 'true') {
					continue;
				}
				$out[$id][] = [
					'id' => (string)$this->attr($xp, 't:AttachmentId', 'Id', $att),
					'name' => (string)($this->text($xp, 't:Name', $att) ?? 'piece-jointe'),
					'size' => (int)$this->text($xp, 't:Size', $att),
					'contentType' => (string)($this->text($xp, 't:ContentType', $att) ?? ''),
				];
			}
		}
		return $out;
	}

	/** PR_LAST_VERB_EXECUTED: 102 replied, 103 replied to all */
	private const REPLIED_VERBS = [102, 103];

	/**
	 * Whether each message was answered (« Répondre » or « Répondre à tous », from any mail
	 * program: Exchange keeps it on the message); a message gone is left out.
	 * @param string[] $itemIds
	 * @return array<string, bool>
	 */
	public function repliedTo(array $itemIds): array {
		if ($itemIds === []) {
			return [];
		}
		// (not strict: a message deleted since is one error among the answers)
		$xp = $this->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:ExtendedFieldURI PropertyTag="0x1081" PropertyType="Integer"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds>' . $this->itemIdsXml(array_values($itemIds)) . '</m:ItemIds></m:GetItem>',
			false
		);
		$out = [];
		foreach ($xp->query('//m:GetItemResponseMessage[@ResponseClass="Success"]/m:Items/*') as $item) {
			$id = (string)$this->attr($xp, 't:ItemId', 'Id', $item);
			if ($id !== '') {
				$verb = (int)$this->text($xp, 't:ExtendedProperty/t:Value', $item);
				$out[$id] = in_array($verb, self::REPLIED_VERBS, true);
			}
		}
		return $out;
	}

	public function deleteAttachment(string $attachmentId): void {
		$this->request('<m:DeleteAttachment><m:AttachmentIds><t:AttachmentId Id="' . $this->esc($attachmentId) . '"/></m:AttachmentIds></m:DeleteAttachment>');
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
	 * @param array{name: string, contentType: string, content: string, contentId?: string, isInline?: bool} $a
	 *        contentId / isInline: an image shown in the text (cid:)
	 */
	private function fileAttachmentXml(array $a): string {
		return '<t:FileAttachment>'
			. '<t:Name>' . $this->esc($a['name']) . '</t:Name>'
			. '<t:ContentType>' . $this->esc($a['contentType'] ?: 'application/octet-stream') . '</t:ContentType>'
			. (!empty($a['contentId']) ? '<t:ContentId>' . $this->esc($a['contentId']) . '</t:ContentId>' : '')
			. (!empty($a['isInline']) ? '<t:IsInline>true</t:IsInline>' : '')
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
			throw new MailException('Réponse invalide du serveur Exchange (HTTP %s)', [$status]);
		}
		$xp = new \DOMXPath($doc);
		$xp->registerNamespace('s', self::NS_SOAP);
		$xp->registerNamespace('m', self::NS_M);
		$xp->registerNamespace('t', self::NS_T);

		$fault = $xp->query('//s:Fault/faultstring')->item(0);
		if ($fault !== null) {
			throw new MailException('Erreur Exchange : %s', [trim($fault->textContent)]);
		}
		if ($status >= 400) {
			throw new MailException('Erreur du serveur Exchange (HTTP %s)', [$status]);
		}

		if ($strict) {
			foreach ($xp->query('//m:ResponseMessages/*[@ResponseClass="Error"]') as $err) {
				$code = $this->text($xp, 'm:ResponseCode', $err);
				$text = $this->text($xp, 'm:MessageText', $err);
				throw self::exchangeError($code, $text);
			}
		}
		return $xp;
	}

	/** The error of an Exchange response code, in words for the user. */
	public static function exchangeError(?string $code, ?string $text): MailException {
		return match ($code) {
			'ErrorItemNotFound' => new MailException('Ce message n\'existe plus (déplacé ou supprimé)'),
			'ErrorFolderNotFound' => new MailException('Dossier introuvable'),
			'ErrorAccessDenied' => new MailException('Accès refusé par Exchange (élément non partagé ou droits insuffisants)'),
			'ErrorSendAsDenied' => new MailException('Vous n\'avez pas le droit d\'envoyer au nom de cette boîte : il faut le droit « Envoyer en tant que » (administrateur Exchange) ou être délégué de cette personne dans Outlook (le message part alors « de la part de »)'),
			'ErrorQuotaExceeded' => new MailException('Boîte aux lettres pleine'),
			'ErrorMessageSizeExceeded' => new MailException('Message trop volumineux'),
			'ErrorInvalidRecipients', 'ErrorInvalidSmtpAddress' => new MailException('Adresse de destinataire invalide'),
			'ErrorCalendarEndDateIsEarlierThanStartDate' => new MailException('La fin de l\'événement est avant son début'),
			'ErrorCalendarDurationIsTooLong' => new MailException('Événement trop long'),
			'ErrorCalendarIsOrganizerForAccept', 'ErrorCalendarIsOrganizerForDecline', 'ErrorCalendarIsOrganizerForTentative' => new MailException('Vous êtes l\'organisateur de cette réunion'),
			'ErrorOccurrenceCrossingBoundary' => new MailException('Une occurrence ne peut pas être déplacée au-delà de l\'occurrence précédente ou suivante de la série'),
			'ErrorNonExistentMailbox' => new MailException('Aucune boîte Exchange ne correspond à cette adresse'),
			'ErrorDelegateNoUser', 'ErrorDelegateValidationFailed' => new MailException('Vous n\'êtes pas délégué de cette personne dans Exchange'),
			'ErrorInvalidUserInfo', 'ErrorUserNotMailbox', 'ErrorInvalidUserSid' => new MailException('Une des personnes n\'a pas de boîte Exchange dans votre organisation'),
			'ErrorDuplicateUserIdsSpecified' => new MailException('Une personne figure deux fois dans la liste'),
			'ErrorNotAllowedExternalSharingByPolicy' => new MailException('Le partage avec des personnes extérieures est interdit par votre organisation'),
			'ErrorInvalidPermissionSettings' => new MailException('Exchange a refusé ces autorisations'),
			'ErrorIrresolvableConflict' => new MailException('L\'élément a été modifié entre-temps, rechargez la page'),
			'ErrorTimeZone' => new MailException('Fuseau horaire non reconnu par Exchange'),
			'ErrorFolderExists' => new MailException('Un dossier porte déjà ce nom à cet endroit'),
			'ErrorDeleteDistinguishedFolder' => new MailException('Ce dossier est indispensable à Exchange et ne peut pas être supprimé'),
			'ErrorMoveDistinguishedFolder' => new MailException('Ce dossier est indispensable à Exchange et ne peut pas être déplacé'),
			'ErrorMoveCopyFailed' => new MailException('Exchange n\'a pas pu déplacer le dossier (pas dans un de ses sous-dossiers)'),
			default => match (true) {
				$code && $text => new MailException('Erreur Exchange (%1$s) : %2$s', [$code, $text]),
				(bool)$code => new MailException('Erreur Exchange (%s)', [$code]),
				(bool)$text => new MailException('Erreur Exchange : %s', [$text]),
				default => new MailException('Erreur Exchange'),
			},
		};
	}

	/**
	 * @param string[] $headers extra HTTP headers
	 * @return array{0: int, 1: string}
	 */
	private function httpPost(string $body, array $headers = [], ?string $url = null): array {
		if ($this->ntlm) {
			return $this->ntlmPost($body, $headers, $url);
		}
		[$status, $response] = $this->postOnce($body, $headers, $url, $this->username . ':' . $this->password);
		if ($status === 401 && $this->offersNtlm()) {
			// Basic refused: this server wants NTLM (OVH Hosted Exchange), kept for the next requests
			$this->ntlm = true;
			$this->curl = null;
			[$status, $response] = $this->ntlmPost($body, $headers, $url);
			if ($status !== 401 && $this->onNtlm !== null) {
				($this->onNtlm)();
			}
		}
		return [$status, $response];
	}

	/**
	 * A request in NTLM: on a connection already authenticated, as it is;
	 * otherwise (or when the server closed it) NEGOTIATE, CHALLENGE, then the
	 * request with AUTHENTICATE, all on one HTTP/1.1 connection.
	 * @return array{0: int, 1: string}
	 */
	private function ntlmPost(string $body, array $headers, ?string $url): array {
		if ($this->ntlmReady) {
			[$status, $response] = $this->postOnce($body, $headers, $url);
			if ($status !== 401) {
				return [$status, $response];
			}
			$this->ntlmReady = false;
		}
		[$status, $response] = $this->postOnce('', array_merge($headers, ['Authorization: NTLM ' . Ntlm::negotiate()]), $url);
		$challenge = $status === 401 ? $this->ntlmChallenge() : null;
		if ($challenge === null) {
			return [$status, $response];
		}
		$type3 = Ntlm::authenticate($this->username, $this->password, Ntlm::parseChallenge($challenge));
		[$status, $response] = $this->postOnce($body, array_merge($headers, ['Authorization: NTLM ' . $type3]), $url);
		$this->ntlmReady = $status !== 401;
		return [$status, $response];
	}

	/** The last answer asks for NTLM (WWW-Authenticate: NTLM). */
	private function offersNtlm(): bool {
		foreach ($this->lastHeaders as $h) {
			if (preg_match('/^WWW-Authenticate:\s*NTLM\b/i', $h)) {
				return true;
			}
		}
		return false;
	}

	/** The CHALLENGE of the last answer (WWW-Authenticate: NTLM <base64>), null without. */
	private function ntlmChallenge(): ?string {
		foreach ($this->lastHeaders as $h) {
			if (preg_match('/^WWW-Authenticate:\s*NTLM\s+([A-Za-z0-9+\/=]+)\s*$/i', $h, $m)) {
				return $m[1];
			}
		}
		return null;
	}

	/**
	 * One POST on the kept connection: Basic with $userPwd, otherwise with the
	 * headers given (NTLM). NTLM authenticates a connection, which HTTP/2 does
	 * not keep: HTTP/1.1 then.
	 * @return array{0: int, 1: string}
	 */
	private function postOnce(string $body, array $headers, ?string $url, ?string $userPwd = null): array {
		$this->curl ??= curl_init();
		$ch = $this->curl;
		$this->lastHeaders = [];
		curl_setopt_array($ch, $this->network + [
			CURLOPT_URL => $url ?? $this->url,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => array_merge(['Content-Type: text/xml; charset=utf-8', 'Accept: text/xml'], $headers),
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
			CURLOPT_USERPWD => $userPwd,
			CURLOPT_HTTP_VERSION => $this->ntlm ? CURL_HTTP_VERSION_1_1 : CURL_HTTP_VERSION_NONE,
			CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
				$this->lastHeaders[] = rtrim($line, "\r\n");
				return strlen($line);
			},
			// EWS answers are verbose XML: let the server compress them
			CURLOPT_ENCODING => '',
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
			CURLOPT_SSL_VERIFYHOST => $this->verifyPeer ? 2 : 0,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_USERAGENT => $this->userAgent,
		]);
		$response = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error = curl_error($ch);
		if ($response === false) {
			// do not reuse a connection in an unknown state
			$this->curl = null;
			$this->ntlmReady = false;
			throw new MailException('Impossible de joindre le serveur Exchange : %s', [$error]);
		}
		return [$status, (string)$response];
	}

	public function text(\DOMXPath $xp, string $query, ?\DOMNode $ctx = null): ?string {
		$n = $xp->query($query, $ctx)->item(0);
		return $n?->textContent;
	}

	/** The due date (Y-m-d) of a flagged item, or null. */
	private function flagDue(\DOMXPath $xp, \DOMNode $node): ?string {
		if ($this->text($xp, 't:Flag/t:FlagStatus', $node) !== 'Flagged') {
			return null;
		}
		$due = (string)$this->text($xp, 't:Flag/t:DueDate', $node);
		return preg_match('/^\d{4}-\d{2}-\d{2}/', $due) ? substr($due, 0, 10) : null;
	}

	/** @return string[] the texts of every node found */
	public function strings(\DOMXPath $xp, string $query, ?\DOMNode $ctx = null): array {
		$out = [];
		foreach ($xp->query($query, $ctx) as $n) {
			$out[] = $n->textContent;
		}
		return $out;
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
