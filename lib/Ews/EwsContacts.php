<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;

/**
 * Exchange contacts (default "Contacts" folder) and directory lookup through EWS.
 */
class EwsContacts {
	/** Simple (non-indexed) fields: UI key => [FieldURI, element] */
	private const SIMPLE = [
		'displayName' => ['contacts:DisplayName', 'DisplayName'],
		'givenName' => ['contacts:GivenName', 'GivenName'],
		'company' => ['contacts:CompanyName', 'CompanyName'],
		'department' => ['contacts:Department', 'Department'],
		'jobTitle' => ['contacts:JobTitle', 'JobTitle'],
		'surname' => ['contacts:Surname', 'Surname'],
	];
	private const PHONES = ['mobilePhone' => 'MobilePhone', 'businessPhone' => 'BusinessPhone', 'homePhone' => 'HomePhone'];
	private const ADDRESS = ['street' => 'Street', 'city' => 'City', 'country' => 'CountryOrRegion', 'postalCode' => 'PostalCode'];

	public function __construct(
		private EwsClient $client,
	) {
	}

	// ------------------------------------------------------------------
	// Address books: the mailbox's contact folders, other people's contacts
	// ------------------------------------------------------------------

	/**
	 * Folder reference for an address book key:
	 *  - "contacts"        the default contacts folder of the mailbox
	 *  - "shared:<email>"  the default contacts folder of another mailbox
	 *  - "shared:<email>:<folder id>"  another contact folder of that mailbox
	 *  - "public:<folder id>"  a contact folder of the public folders
	 *  - anything else     a FolderId of the mailbox (another contact folder)
	 */
	public function folderXml(string $book): string {
		if ($book === '' || $book === 'contacts') {
			return '<t:DistinguishedFolderId Id="contacts"/>';
		}
		if (self::isPublic($book)) {
			$id = substr($book, 7);
			if ($id === '') {
				throw new MailException('Carnet public invalide');
			}
			return '<t:FolderId Id="' . $this->client->esc($id) . '"/>';
		}
		if (str_starts_with($book, 'shared:')) {
			[$email, $folder] = self::parseSharedKey($book);
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new MailException('Carnet partagé invalide');
			}
			if ($folder !== '') {
				return '<t:FolderId Id="' . $this->client->esc($folder) . '"/>';
			}
			return '<t:DistinguishedFolderId Id="contacts"><t:Mailbox><t:EmailAddress>' . $this->client->esc($email)
				. '</t:EmailAddress></t:Mailbox></t:DistinguishedFolderId>';
		}
		return '<t:FolderId Id="' . $this->client->esc($book) . '"/>';
	}

	/**
	 * Contact folders of the mailbox, the default one first. Only plain
	 * "IPF.Contact" folders: Exchange's internal ones (recipient cache,
	 * GAL contacts, Skype...) use derived classes and are skipped.
	 * @return array<int, array{key: string, name: string, kind: string, canEdit: bool}>
	 */
	public function listOwnBooks(): array {
		$defaultId = $this->client->distinguishedFolderIds(['contacts'])['contacts'] ?? null;
		$xp = $this->client->request(
			'<m:FindFolder Traversal="Deep">'
			. '<m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="folder:DisplayName"/><t:FieldURI FieldURI="folder:FolderClass"/>'
			. '</t:AdditionalProperties></m:FolderShape>'
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="msgfolderroot"/></m:ParentFolderIds>'
			. '</m:FindFolder>'
		);
		$default = ['key' => 'contacts', 'name' => 'Contacts', 'kind' => 'own', 'canEdit' => true];
		$others = [];
		foreach ($xp->query('//t:Folders/*') as $node) {
			if ($this->client->text($xp, 't:FolderClass', $node) !== 'IPF.Contact') {
				continue;
			}
			$id = (string)$this->client->attr($xp, 't:FolderId', 'Id', $node);
			$name = (string)$this->client->text($xp, 't:DisplayName', $node);
			if ($id === $defaultId) {
				$default['name'] = $name !== '' ? $name : $default['name'];
				continue;
			}
			$others[] = ['key' => $id, 'name' => $name, 'kind' => 'own', 'canEdit' => true];
		}
		usort($others, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return array_merge([$default], $others);
	}

	/**
	 * Address books another person shares with me (Outlook: right-click on the
	 * folder > Properties > Permissions): their default « Contacts » and their
	 * other contact folders I can read. Exchange has no "shared with me" list,
	 * so the other folders are looked for at the top of their mailbox and inside
	 * their « Contacts »; they only show up when I can see the parent folder
	 * ("Folder visible" on the mailbox, or any access to « Contacts »).
	 * @return array<int, array{folder: string, name: string, canEdit: bool}> folder "" = their default « Contacts »
	 * @throws MailException when the mailbox does not exist or shares no address book
	 */
	public function listSharedBooks(string $email): array {
		$main = $this->folderXml('shared:' . $email);
		$mailbox = '<t:Mailbox><t:EmailAddress>' . $this->client->esc($email) . '</t:EmailAddress></t:Mailbox>';
		$shape = '<t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="folder:DisplayName"/><t:FieldURI FieldURI="folder:FolderClass"/><t:FieldURI FieldURI="folder:EffectiveRights"/>'
			. '</t:AdditionalProperties>';
		$xp = $this->client->request('<m:GetFolder><m:FolderShape>' . $shape . '</m:FolderShape><m:FolderIds>' . $main . '</m:FolderIds></m:GetFolder>', false);
		$msg = $xp->query('//m:ResponseMessages/*')->item(0);
		$code = $msg !== null ? $this->client->text($xp, 'm:ResponseCode', $msg) : null;
		if ($code === 'ErrorNonExistentMailbox' || $code === 'ErrorInvalidSmtpAddress') {
			throw new MailException('Aucune boîte Exchange ne correspond à %s', [$email]);
		}
		$books = [];
		$mainId = null;
		if ($msg instanceof \DOMElement && $msg->getAttribute('ResponseClass') !== 'Error') {
			$folder = $xp->query('.//m:Folders/*', $msg)->item(0);
			$mainId = $folder !== null ? $this->client->attr($xp, 't:FolderId', 'Id', $folder) : null;
			if ($folder !== null && $this->client->text($xp, 't:EffectiveRights/t:Read', $folder) !== 'false') {
				$books[] = ['folder' => '', 'name' => 'Contacts', 'canEdit' => $this->client->text($xp, 't:EffectiveRights/t:CreateContents', $folder) === 'true'];
			}
		}

		// other contact folders: top of the mailbox and inside « Contacts » (each may be refused)
		$xp = $this->client->request(
			'<m:FindFolder Traversal="Shallow"><m:FolderShape>' . $shape . '</m:FolderShape><m:ParentFolderIds>'
			. '<t:DistinguishedFolderId Id="msgfolderroot">' . $mailbox . '</t:DistinguishedFolderId>' . $main
			. '</m:ParentFolderIds></m:FindFolder>',
			false
		);
		$others = [];
		foreach ($xp->query('//m:ResponseMessages/*[@ResponseClass!="Error"]//t:Folders/*') as $node) {
			$id = (string)$this->client->attr($xp, 't:FolderId', 'Id', $node);
			if ($id === '' || $id === $mainId || isset($others[$id])
				|| $this->client->text($xp, 't:FolderClass', $node) !== 'IPF.Contact'
				|| $this->client->text($xp, 't:EffectiveRights/t:Read', $node) === 'false') {
				continue;
			}
			$others[$id] = [
				'folder' => $id,
				'name' => (string)$this->client->text($xp, 't:DisplayName', $node),
				'canEdit' => $this->client->text($xp, 't:EffectiveRights/t:CreateContents', $node) === 'true',
			];
		}
		$others = array_values($others);
		usort($others, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		$books = array_merge($books, $others);
		if ($books === []) {
			throw new MailException('%s ne partage pas de carnet d\'adresses avec vous (à faire dans Outlook : clic droit sur le carnet → Propriétés → Autorisations)', [$email]);
		}
		return $books;
	}

	/**
	 * Key of a colleague's address book: "shared:<email>" for their default
	 * « Contacts », "shared:<email>:<folder id>" for another one.
	 * @return ?array{0: string, 1: string} [email, folder id or ""], null when not a shared book
	 */
	public static function parseSharedKey(string $book): ?array {
		if (!str_starts_with($book, 'shared:')) {
			return null;
		}
		$parts = explode(':', substr($book, 7), 2);
		return [strtolower($parts[0]), $parts[1] ?? ''];
	}

	public static function sharedKey(string $email, string $folder = ''): string {
		return 'shared:' . strtolower($email) . ($folder !== '' ? ':' . $folder : '');
	}

	public static function isPublic(string $book): bool {
		return str_starts_with($book, 'public:');
	}

	/**
	 * Public folders below $parent (null = the top of the public folders),
	 * to browse them like Outlook does. Only folders that hold contacts or
	 * other folders are returned. The client must be routed to the public
	 * folder mailbox (EwsClient::usePublicFolderMailbox).
	 * @return array<int, array{id: string, name: string, isContacts: bool, hasChildren: bool, canEdit: bool}>
	 * @throws MailException when the public folders cannot be read
	 */
	public function listPublicFolders(?string $parent = null, string $class = 'IPF.Contact'): array {
		$parentXml = $parent === null || $parent === ''
			? '<t:DistinguishedFolderId Id="publicfoldersroot"/>'
			: '<t:FolderId Id="' . $this->client->esc($parent) . '"/>';
		$xp = $this->client->request(
			'<m:FindFolder Traversal="Shallow"><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="folder:DisplayName"/><t:FieldURI FieldURI="folder:FolderClass"/>'
			. '<t:FieldURI FieldURI="folder:ChildFolderCount"/><t:FieldURI FieldURI="folder:EffectiveRights"/>'
			. '</t:AdditionalProperties></m:FolderShape>'
			. '<m:ParentFolderIds>' . $parentXml . '</m:ParentFolderIds></m:FindFolder>',
			false
		);
		$msg = $xp->query('//m:ResponseMessages/*')->item(0);
		if (!$msg instanceof \DOMElement || $msg->getAttribute('ResponseClass') === 'Error') {
			$code = $msg !== null ? $this->client->text($xp, 'm:ResponseCode', $msg) : null;
			throw $parent === null || $parent === ''
				? new MailException('Aucun dossier public n\'est accessible sur ce serveur Exchange')
				: EwsClient::exchangeError($code, $msg !== null ? $this->client->text($xp, 'm:MessageText', $msg) : null);
		}
		$out = [];
		foreach ($xp->query('.//t:Folders/*', $msg) as $node) {
			$readable = $this->client->text($xp, 't:EffectiveRights/t:Read', $node) !== 'false';
			// address books, or calendars (IPF.Appointment) for the calendar module
			$isContacts = $readable && $this->client->text($xp, 't:FolderClass', $node) === $class;
			$hasChildren = (int)$this->client->text($xp, 't:ChildFolderCount', $node) > 0;
			if (!$isContacts && !$hasChildren) {
				continue; // nothing to add or to open
			}
			$out[] = [
				'id' => (string)$this->client->attr($xp, 't:FolderId', 'Id', $node),
				'name' => (string)$this->client->text($xp, 't:DisplayName', $node),
				'isContacts' => $isContacts,
				'hasChildren' => $hasChildren,
				'canEdit' => $this->client->text($xp, 't:EffectiveRights/t:CreateContents', $node) === 'true',
			];
		}
		usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return $out;
	}

	/**
	 * One public contact folder, checked before it is added.
	 * @return array{name: string, canEdit: bool}
	 */
	public function getPublicBook(string $folderId, string $class = 'IPF.Contact'): array {
		$xp = $this->client->request(
			'<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="folder:DisplayName"/><t:FieldURI FieldURI="folder:FolderClass"/><t:FieldURI FieldURI="folder:EffectiveRights"/>'
			. '</t:AdditionalProperties></m:FolderShape>'
			. '<m:FolderIds>' . $this->folderXml('public:' . $folderId) . '</m:FolderIds></m:GetFolder>'
		);
		$node = $xp->query('//m:Folders/*')->item(0);
		if ($node === null || $this->client->text($xp, 't:FolderClass', $node) !== $class
			|| $this->client->text($xp, 't:EffectiveRights/t:Read', $node) === 'false') {
			throw $class === 'IPF.Appointment'
				? new MailException('Ce dossier public n\'est pas un agenda lisible')
				: new MailException('Ce dossier public n\'est pas un carnet d\'adresses lisible');
		}
		return [
			'name' => (string)$this->client->text($xp, 't:DisplayName', $node),
			'canEdit' => $this->client->text($xp, 't:EffectiveRights/t:CreateContents', $node) === 'true',
		];
	}

	/**
	 * Who one of my address books is shared with (folder permissions, without delegation).
	 * @return array<int, array{user: string, name: string, level: string}>
	 */
	public function getPermissions(string $book): array {
		self::assertOwn($book);
		return (new EwsPermissions($this->client, EwsPermissions::CONTACTS))->get($this->folderXml($book));
	}

	/**
	 * @param array<int, array{user: string, level: string}> $entries level "keep" = leave as it is
	 */
	public function setPermissions(string $book, array $entries, string $ownEmail): void {
		self::assertOwn($book);
		(new EwsPermissions($this->client, EwsPermissions::CONTACTS))->set($this->folderXml($book), $entries, $ownEmail);
	}

	private static function assertOwn(string $book): void {
		if (str_starts_with($book, 'shared:') || self::isPublic($book) || $book === 'directory') {
			throw new MailException('Seul le propriétaire d\'un carnet peut le partager');
		}
	}

	/**
	 * Search the company directory (Global Address List). Exchange only allows
	 * searching it, not listing it entirely, and returns at most 100 entries.
	 * @return array<int, array<string, mixed>> full contact cards
	 */
	public function searchDirectory(string $query): array {
		$query = trim($query);
		if (mb_strlen($query) < 2) {
			return [];
		}
		$xp = $this->client->request(
			'<m:ResolveNames ReturnFullContactData="true" SearchScope="ActiveDirectory">'
			. '<m:UnresolvedEntry>' . $this->client->esc($query) . '</m:UnresolvedEntry></m:ResolveNames>',
			false
		);
		$out = [];
		$seen = [];
		foreach ($xp->query('//m:ResolutionSet/t:Resolution') as $i => $res) {
			$mb = $xp->query('t:Mailbox', $res)->item(0);
			$email = $mb !== null ? (string)$this->client->text($xp, 't:EmailAddress', $mb) : '';
			$routing = $mb !== null ? (string)$this->client->text($xp, 't:RoutingType', $mb) : '';
			if ($email === '' || ($routing !== '' && $routing !== 'SMTP') || isset($seen[strtolower($email)])) {
				continue;
			}
			$seen[strtolower($email)] = true;
			$ct = $xp->query('t:Contact', $res)->item(0);
			$card = $ct !== null ? $this->parseContact($xp, $ct) : ['emails' => []];
			$card['id'] = 'directory:' . strtolower($email);
			$card['displayName'] = ($card['displayName'] ?? '') !== '' ? $card['displayName'] : (string)$this->client->text($xp, 't:Name', $mb);
			if (!in_array($email, $card['emails'], true)) {
				array_unshift($card['emails'], $email);
			}
			$card['isGroup'] = in_array((string)$this->client->text($xp, 't:MailboxType', $mb), ['PublicDL', 'PrivateDL'], true);
			$card['email'] = $email;
			$card['phone'] = ($card['mobilePhone'] ?? '') ?: ($card['businessPhone'] ?? '');
			$card['company'] = $card['company'] ?? '';
			$out[] = $card;
		}
		usort($out, fn ($a, $b) => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}

	/**
	 * @return array{items: array<int, array<string, mixed>>, total: int, hasMore: bool}
	 */
	public function listContacts(int $offset = 0, int $limit = 100, ?string $query = null, string $book = 'contacts'): array {
		$props = '<t:FieldURI FieldURI="contacts:DisplayName"/><t:FieldURI FieldURI="contacts:CompanyName"/>'
			. '<t:FieldURI FieldURI="contacts:JobTitle"/><t:FieldURI FieldURI="contacts:HasPicture"/><t:FieldURI FieldURI="contacts:Birthday"/>'
			. '<t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress1"/>'
			. '<t:IndexedFieldURI FieldURI="contacts:PhoneNumber" FieldIndex="MobilePhone"/>'
			. '<t:IndexedFieldURI FieldURI="contacts:PhoneNumber" FieldIndex="BusinessPhone"/>';
		$query = $query !== null ? trim($query) : '';
		$queryXml = '';
		$restriction = '';
		if ($query !== '' && self::isPublic($book)) {
			// public folders have no search index (no QueryString): filter on the main fields
			$contains = fn (string $field) => '<t:Contains ContainmentMode="Substring" ContainmentComparison="IgnoreCase">' . $field
				. '<t:Constant Value="' . $this->client->esc($query) . '"/></t:Contains>';
			$restriction = '<m:Restriction><t:Or>'
				. $contains('<t:FieldURI FieldURI="contacts:DisplayName"/>')
				. $contains('<t:FieldURI FieldURI="contacts:CompanyName"/>')
				. $contains('<t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress1"/>')
				. '</t:Or></m:Restriction>';
		} elseif ($query !== '') {
			$queryXml = '<m:QueryString>' . $this->client->esc($query) . '</m:QueryString>';
		}

		$xp = $this->client->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $props . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . max(1, $limit) . '" Offset="' . max(0, $offset) . '" BasePoint="Beginning"/>'
			. $restriction
			. '<m:SortOrder><t:FieldOrder Order="Ascending"><t:FieldURI FieldURI="contacts:DisplayName"/></t:FieldOrder></m:SortOrder>'
			. '<m:ParentFolderIds>' . $this->folderXml($book) . '</m:ParentFolderIds>'
			. $queryXml
			. '</m:FindItem>'
		);
		$root = $xp->query('//m:RootFolder')->item(0);
		$items = [];
		foreach ($xp->query('//m:RootFolder/t:Items/*') as $node) {
			$c = $this->client;
			$items[] = [
				'id' => $c->attr($xp, 't:ItemId', 'Id', $node),
				'displayName' => (string)$c->text($xp, 't:DisplayName', $node),
				'company' => (string)$c->text($xp, 't:CompanyName', $node),
				'jobTitle' => (string)$c->text($xp, 't:JobTitle', $node),
				'email' => (string)$c->text($xp, 't:EmailAddresses/t:Entry[@Key="EmailAddress1"]', $node),
				'phone' => (string)($c->text($xp, 't:PhoneNumbers/t:Entry[@Key="MobilePhone"]', $node)
					?? $c->text($xp, 't:PhoneNumbers/t:Entry[@Key="BusinessPhone"]', $node) ?? ''),
				'isGroup' => $node->localName === 'DistributionList',
				'hasPhoto' => $c->text($xp, 't:HasPicture', $node) === 'true',
				'birthday' => self::birthdayFromEws((string)$c->text($xp, 't:Birthday', $node)),
			];
		}
		return [
			'items' => $items,
			'total' => $root instanceof \DOMElement ? (int)$root->getAttribute('TotalItemsInView') : count($items),
			'hasMore' => $root instanceof \DOMElement && $root->getAttribute('IncludesLastItemInRange') === 'false',
		];
	}

	/** at most this many contacts in one export or import */
	public const MAX_TRANSFER = 5000;

	/**
	 * Every contact of a book with its details (groups are left out), for
	 * a vCard export.
	 * @return list<array<string, mixed>>
	 */
	public function exportContacts(string $book = 'contacts'): array {
		$ids = [];
		for ($offset = 0; $offset < self::MAX_TRANSFER; $offset += 500) {
			$xp = $this->client->request(
				'<m:FindItem Traversal="Shallow">'
				. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape>'
				. '<m:IndexedPageItemView MaxEntriesReturned="500" Offset="' . $offset . '" BasePoint="Beginning"/>'
				. '<m:SortOrder><t:FieldOrder Order="Ascending"><t:FieldURI FieldURI="contacts:DisplayName"/></t:FieldOrder></m:SortOrder>'
				. '<m:ParentFolderIds>' . $this->folderXml($book) . '</m:ParentFolderIds>'
				. '</m:FindItem>'
			);
			foreach ($xp->query('//m:RootFolder/t:Items/t:Contact/t:ItemId') as $n) {
				$ids[] = $n->getAttribute('Id');
			}
			$root = $xp->query('//m:RootFolder')->item(0);
			if (!$root instanceof \DOMElement || $root->getAttribute('IncludesLastItemInRange') !== 'false') {
				break;
			}
		}
		$contacts = [];
		foreach (array_chunk($ids, 100) as $chunk) {
			$xp = $this->client->request(
				'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:BodyType>Text</t:BodyType>'
				. '<t:AdditionalProperties>' . self::detailFields() . '</t:AdditionalProperties></m:ItemShape>'
				. '<m:ItemIds>' . implode('', array_map(fn ($id) => '<t:ItemId Id="' . $this->client->esc($id) . '"/>', $chunk)) . '</m:ItemIds></m:GetItem>',
				false
			);
			foreach ($xp->query('//m:Items/t:Contact') as $node) {
				$contacts[] = $this->parseContact($xp, $node);
			}
		}
		return $contacts;
	}

	/**
	 * The e-mail addresses already in a book (lower case), to leave out the
	 * cards of people already there when importing.
	 * @return array<string, true>
	 */
	public function knownEmails(string $book = 'contacts'): array {
		$known = [];
		for ($offset = 0; $offset < self::MAX_TRANSFER; $offset += 500) {
			$xp = $this->client->request(
				'<m:FindItem Traversal="Shallow">'
				. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
				. '<t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress1"/>'
				. '<t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress2"/>'
				. '<t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress3"/>'
				. '</t:AdditionalProperties></m:ItemShape>'
				. '<m:IndexedPageItemView MaxEntriesReturned="500" Offset="' . $offset . '" BasePoint="Beginning"/>'
				. '<m:ParentFolderIds>' . $this->folderXml($book) . '</m:ParentFolderIds>'
				. '</m:FindItem>'
			);
			foreach ($xp->query('//m:RootFolder/t:Items/t:Contact/t:EmailAddresses/t:Entry') as $n) {
				$known[mb_strtolower(preg_replace('/^smtp:/i', '', trim($n->textContent)) ?? '')] = true;
			}
			$root = $xp->query('//m:RootFolder')->item(0);
			if (!$root instanceof \DOMElement || $root->getAttribute('IncludesLastItemInRange') !== 'false') {
				break;
			}
		}
		return $known;
	}

	private static function detailFields(): string {
		$fields = '';
		foreach (['contacts:DisplayName', 'contacts:GivenName', 'contacts:Surname', 'contacts:CompanyName', 'contacts:Department',
			'contacts:JobTitle', 'contacts:EmailAddresses', 'contacts:PhoneNumbers', 'contacts:PhysicalAddresses', 'contacts:ImAddresses', 'item:Body', 'contacts:HasPicture', 'contacts:Birthday'] as $f) {
			$fields .= '<t:FieldURI FieldURI="' . $f . '"/>';
		}
		return $fields;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getContact(string $id): array {
		$fields = self::detailFields();
		$xp = $this->client->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:BodyType>Text</t:BodyType>'
			. '<t:AdditionalProperties>' . $fields . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->client->esc($id) . '"/></m:ItemIds></m:GetItem>'
		);
		$node = $xp->query('//m:Items/*')->item(0);
		if ($node === null) {
			throw new MailException('Contact introuvable');
		}
		$contact = $this->parseContact($xp, $node);
		$contact['id'] = $this->client->attr($xp, 't:ItemId', 'Id', $node);
		$contact['isGroup'] = $node->localName === 'DistributionList';
		$contact['hasPhoto'] = $this->client->text($xp, 't:HasPicture', $node) === 'true';
		if ($contact['isGroup']) {
			$contact['members'] = $this->groupMembers((string)$contact['id']);
		}
		return $contact;
	}

	// ------------------------------------------------------------------
	// Photos: a contact's own (an attachment marked IsContactPhoto, as
	// Outlook saves it), and the photo of a person of the directory
	// ------------------------------------------------------------------

	/** Largest photo accepted (the page reduces it to 256 pixels before). */
	public const MAX_PHOTO_BYTES = 1024 * 1024;

	/** @return string[] ids of the photo attachments of a contact */
	private function photoAttachmentIds(string $id): array {
		$xp = $this->client->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties><t:FieldURI FieldURI="item:Attachments"/>'
			. '</t:AdditionalProperties></m:ItemShape><m:ItemIds><t:ItemId Id="' . $this->client->esc($id) . '"/></m:ItemIds></m:GetItem>'
		);
		$ids = [];
		foreach ($xp->query('//m:Items/*/t:Attachments/t:FileAttachment[t:IsContactPhoto="true"]/t:AttachmentId') as $a) {
			$ids[] = $a instanceof \DOMElement ? $a->getAttribute('Id') : '';
		}
		return array_values(array_filter($ids));
	}

	/** @return ?array{contentType: string, content: string} the photo of a contact, null if it has none */
	public function contactPhoto(string $id): ?array {
		$ids = $this->photoAttachmentIds($id);
		if ($ids === []) {
			return null;
		}
		$att = $this->client->getAttachment($ids[0]);
		// the type comes from the image itself, never from the attachment (anyone who can
		// write the contact chooses that one: text/html would then be served by Nextcloud)
		try {
			return ['contentType' => self::imageType($att['content']), 'content' => $att['content']];
		} catch (MailException) {
			return null; // not a JPEG, PNG or GIF image: shown as no photo
		}
	}

	/** A new photo for a contact (the former one is replaced), or none ($content === ''). */
	public function setContactPhoto(string $id, string $content): void {
		if ($content !== '') {
			$type = self::imageType($content);
		}
		foreach ($this->photoAttachmentIds($id) as $old) {
			$this->client->deleteAttachment($old);
		}
		if ($content === '') {
			return;
		}
		$this->client->request(
			'<m:CreateAttachment><m:ParentItemId Id="' . $this->client->esc($id) . '"/><m:Attachments><t:FileAttachment>'
			. '<t:Name>ContactPicture.jpg</t:Name><t:ContentType>' . $type . '</t:ContentType>'
			. '<t:IsContactPhoto>true</t:IsContactPhoto><t:Content>' . base64_encode($content) . '</t:Content>'
			. '</t:FileAttachment></m:Attachments></m:CreateAttachment>'
		);
	}

	/** The type of an image given as a photo: only JPEG, PNG and GIF, as Outlook. */
	public static function imageType(string $content): string {
		if (strlen($content) > self::MAX_PHOTO_BYTES) {
			throw new MailException('Photo trop lourde (1 Mo au plus)');
		}
		$info = @getimagesizefromstring($content);
		$types = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif'];
		if ($info === false || !isset($types[$info[2]])) {
			throw new MailException('La photo doit être une image JPEG, PNG ou GIF');
		}
		return $types[$info[2]];
	}

	/**
	 * The photo of a person of the organisation (GetUserPhoto, Exchange
	 * 2013 and later), null when they have none.
	 * @return ?array{contentType: string, content: string}
	 */
	public function userPhoto(string $email): ?array {
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return null;
		}
		try {
			$xp = $this->client->request(
				'<m:GetUserPhoto><m:Email>' . $this->client->esc($email) . '</m:Email><m:SizeRequested>HR96x96</m:SizeRequested></m:GetUserPhoto>',
				false
			);
		} catch (MailException) {
			// no photo: some Exchange servers answer with an HTTP error
			return null;
		}
		$node = $xp->query('//m:GetUserPhotoResponse')->item(0);
		if (!$node instanceof \DOMElement || $node->getAttribute('ResponseClass') !== 'Success') {
			return null;
		}
		$data = base64_decode(trim((string)$this->client->text($xp, 'm:PictureData', $node)), true);
		if ($data === false || $data === '') {
			return null;
		}
		try {
			return ['contentType' => self::imageType($data), 'content' => $data];
		} catch (MailException) {
			return null;
		}
	}

	/**
	 * The members of a contact group (an Outlook distribution list).
	 * @return list<array{name: string, email: string}>
	 */
	public function groupMembers(string $id): array {
		$xp = $this->client->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
			. '<t:FieldURI FieldURI="distributionlist:Members"/></t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->client->esc($id) . '"/></m:ItemIds></m:GetItem>'
		);
		$out = [];
		foreach ($xp->query('//m:Items/t:DistributionList/t:Members/t:Member/t:Mailbox') as $mb) {
			$email = (string)$this->client->text($xp, 't:EmailAddress', $mb);
			$name = (string)$this->client->text($xp, 't:Name', $mb);
			if ($email === '' && $name === '') {
				continue;
			}
			$out[] = ['name' => $name !== '' ? $name : $email, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ''];
		}
		return $out;
	}

	/**
	 * A contact group: a name and its members (with an address each).
	 * @param array<string, mixed> $data {displayName, members: [{name, email}]}
	 */
	public function createGroup(array $data, string $book = 'contacts'): string {
		$g = self::normalizeGroup($data);
		$c = $this->client;
		$xp = $c->request(
			'<m:CreateItem><m:SavedItemFolderId>' . $this->folderXml($book) . '</m:SavedItemFolderId><m:Items>'
			. '<t:DistributionList><t:DisplayName>' . $c->esc($g['displayName']) . '</t:DisplayName>'
			. '<t:FileAs>' . $c->esc($g['displayName']) . '</t:FileAs>'
			. ($g['members'] !== [] ? $this->membersXml($g['members']) : '')
			. '</t:DistributionList></m:Items></m:CreateItem>'
		);
		return (string)$c->attr($xp, '//m:Items/t:DistributionList/t:ItemId', 'Id');
	}

	/** @param array<string, mixed> $data */
	public function updateGroup(string $id, array $data): void {
		$g = self::normalizeGroup($data);
		$c = $this->client;
		$set = fn (string $uri, string $inner) => '<t:SetItemField><t:FieldURI FieldURI="' . $uri . '"/><t:DistributionList>' . $inner . '</t:DistributionList></t:SetItemField>';
		$updates = $set('contacts:DisplayName', '<t:DisplayName>' . $c->esc($g['displayName']) . '</t:DisplayName>')
			. $set('contacts:FileAs', '<t:FileAs>' . $c->esc($g['displayName']) . '</t:FileAs>')
			. ($g['members'] !== []
				? $set('distributionlist:Members', $this->membersXml($g['members']))
				: '<t:DeleteItemField><t:FieldURI FieldURI="distributionlist:Members"/></t:DeleteItemField>');
		$c->request(
			'<m:UpdateItem ConflictResolution="AlwaysOverwrite"><m:ItemChanges><t:ItemChange><t:ItemId Id="' . $c->esc($id) . '"/>'
			. '<t:Updates>' . $updates . '</t:Updates></t:ItemChange></m:ItemChanges></m:UpdateItem>'
		);
	}

	/** @param list<array{name: string, email: string}> $members */
	private function membersXml(array $members): string {
		$c = $this->client;
		$xml = '';
		foreach ($members as $m) {
			$xml .= '<t:Member><t:Mailbox><t:Name>' . $c->esc($m['name']) . '</t:Name><t:EmailAddress>' . $c->esc($m['email']) . '</t:EmailAddress>'
				. '<t:RoutingType>SMTP</t:RoutingType><t:MailboxType>OneOff</t:MailboxType></t:Mailbox></t:Member>';
		}
		return '<t:Members>' . $xml . '</t:Members>';
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{displayName: string, members: list<array{name: string, email: string}>}
	 */
	public static function normalizeGroup(array $data): array {
		$name = trim((string)($data['displayName'] ?? ''));
		if ($name === '') {
			throw new MailException('Donnez un nom au groupe');
		}
		$members = [];
		$seen = [];
		foreach ((array)($data['members'] ?? []) as $m) {
			if (!is_array($m)) {
				continue;
			}
			$email = strtolower(trim((string)($m['email'] ?? '')));
			if ($email === '') {
				continue;
			}
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				throw new MailException('Adresse invalide dans le groupe : %s', [$email]);
			}
			if (isset($seen[$email])) {
				continue;
			}
			$seen[$email] = true;
			$mname = trim((string)($m['name'] ?? ''));
			$members[] = ['name' => $mname !== '' ? $mname : $email, 'email' => $email];
		}
		if (count($members) > 500) {
			throw new MailException('500 membres au plus par groupe');
		}
		return ['displayName' => $name, 'members' => $members];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parseContact(\DOMXPath $xp, \DOMNode $node): array {
		$c = $this->client;
		$contact = [
			'notes' => (string)$c->text($xp, 't:Body', $node),
			'emails' => [],
		];
		foreach (self::SIMPLE as $key => [, $el]) {
			$contact[$key] = (string)$c->text($xp, 't:' . $el, $node);
		}
		foreach (['EmailAddress1', 'EmailAddress2', 'EmailAddress3'] as $k) {
			$v = $c->text($xp, 't:EmailAddresses/t:Entry[@Key="' . $k . '"]', $node);
			if ($v !== null && $v !== '' && !str_starts_with($v, '/') && !in_array(preg_replace('/^smtp:/i', '', $v), $contact['emails'], true)) {
				$contact['emails'][] = preg_replace('/^smtp:/i', '', $v);
			}
		}
		foreach (self::PHONES as $key => $k) {
			$contact[$key] = (string)$c->text($xp, 't:PhoneNumbers/t:Entry[@Key="' . $k . '"]', $node);
		}
		foreach (self::ADDRESS as $key => $el) {
			$contact[$key] = (string)$c->text($xp, 't:PhysicalAddresses/t:Entry[@Key="Business"]/t:' . $el, $node);
		}
		$contact['office'] = (string)$c->text($xp, 't:OfficeLocation', $node);
		// the Matrix address (« @alice:example.org »), kept in Exchange's first IM address (also shown by Outlook)
		$contact['matrix'] = self::matrixId((string)$c->text($xp, 't:ImAddresses/t:Entry[@Key="ImAddress1"]', $node)) ?? '';
		$contact['birthday'] = self::birthdayFromEws((string)$c->text($xp, 't:Birthday', $node));
		return $contact;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function createContact(array $data, string $book = 'contacts'): string {
		$d = self::normalize($data);
		$c = $this->client;
		$el = fn (string $name, string $value) => $value !== '' ? '<t:' . $name . '>' . $c->esc($value) . '</t:' . $name . '>' : '';

		$emails = '';
		foreach ($d['emails'] as $i => $e) {
			$emails .= '<t:Entry Key="EmailAddress' . ($i + 1) . '">' . $c->esc($e) . '</t:Entry>';
		}
		$address = '';
		foreach (self::ADDRESS as $key => $name) {
			$address .= $el($name, $d[$key]);
		}
		$phones = '';
		foreach (self::PHONES as $key => $k) {
			if ($d[$key] !== '') {
				$phones .= '<t:Entry Key="' . $k . '">' . $c->esc($d[$key]) . '</t:Entry>';
			}
		}

		// element order follows the EWS schema (ItemType then ContactItemType)
		$item = '<t:Contact>'
			. ($d['notes'] !== '' ? '<t:Body BodyType="Text">' . $c->esc($d['notes']) . '</t:Body>' : '')
			. $el('FileAs', $d['displayName'])
			. $el('DisplayName', $d['displayName'])
			. $el('GivenName', $d['givenName'])
			. $el('CompanyName', $d['company'])
			. ($emails !== '' ? '<t:EmailAddresses>' . $emails . '</t:EmailAddresses>' : '')
			. ($address !== '' ? '<t:PhysicalAddresses><t:Entry Key="Business">' . $address . '</t:Entry></t:PhysicalAddresses>' : '')
			. ($phones !== '' ? '<t:PhoneNumbers>' . $phones . '</t:PhoneNumbers>' : '')
			. ($d['birthday'] !== '' ? '<t:Birthday>' . self::birthdayToEws($d['birthday']) . '</t:Birthday>' : '')
			. $el('Department', $d['department'])
			. ($d['matrix'] !== '' ? '<t:ImAddresses><t:Entry Key="ImAddress1">' . $c->esc($d['matrix']) . '</t:Entry></t:ImAddresses>' : '')
			. $el('JobTitle', $d['jobTitle'])
			. $el('Surname', $d['surname'])
			. '</t:Contact>';

		$xp = $this->client->request(
			'<m:CreateItem><m:SavedItemFolderId>' . $this->folderXml($book) . '</m:SavedItemFolderId>'
			. '<m:Items>' . $item . '</m:Items></m:CreateItem>'
		);
		return (string)$this->client->attr($xp, '//m:Items/t:Contact/t:ItemId', 'Id');
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function updateContact(string $id, array $data): void {
		$d = self::normalize($data);
		$c = $this->client;
		$updates = '';
		$set = fn (string $path, string $inner) => '<t:SetItemField>' . $path . '<t:Contact>' . $inner . '</t:Contact></t:SetItemField>';
		$del = fn (string $path) => '<t:DeleteItemField>' . $path . '</t:DeleteItemField>';

		foreach (self::SIMPLE as $key => [$uri, $el]) {
			$path = '<t:FieldURI FieldURI="' . $uri . '"/>';
			$updates .= $d[$key] !== '' ? $set($path, '<t:' . $el . '>' . $c->esc($d[$key]) . '</t:' . $el . '>') : $del($path);
		}
		if ($d['displayName'] !== '') {
			$updates .= $set('<t:FieldURI FieldURI="contacts:FileAs"/>', '<t:FileAs>' . $c->esc($d['displayName']) . '</t:FileAs>');
		}
		$updates .= $d['notes'] !== ''
			? $set('<t:FieldURI FieldURI="item:Body"/>', '<t:Body BodyType="Text">' . $c->esc($d['notes']) . '</t:Body>')
			: $del('<t:FieldURI FieldURI="item:Body"/>');

		for ($i = 1; $i <= 3; $i++) {
			$path = '<t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress' . $i . '"/>';
			$value = $d['emails'][$i - 1] ?? '';
			$updates .= $value !== ''
				? $set($path, '<t:EmailAddresses><t:Entry Key="EmailAddress' . $i . '">' . $c->esc($value) . '</t:Entry></t:EmailAddresses>')
				: $del($path);
		}
		foreach (self::PHONES as $key => $k) {
			$path = '<t:IndexedFieldURI FieldURI="contacts:PhoneNumber" FieldIndex="' . $k . '"/>';
			$updates .= $d[$key] !== ''
				? $set($path, '<t:PhoneNumbers><t:Entry Key="' . $k . '">' . $c->esc($d[$key]) . '</t:Entry></t:PhoneNumbers>')
				: $del($path);
		}
		$path = '<t:FieldURI FieldURI="contacts:Birthday"/>';
		$updates .= $d['birthday'] !== ''
			? $set($path, '<t:Birthday>' . self::birthdayToEws($d['birthday']) . '</t:Birthday>')
			: $del($path);
		$path = '<t:IndexedFieldURI FieldURI="contacts:ImAddress" FieldIndex="ImAddress1"/>';
		$updates .= $d['matrix'] !== ''
			? $set($path, '<t:ImAddresses><t:Entry Key="ImAddress1">' . $c->esc($d['matrix']) . '</t:Entry></t:ImAddresses>')
			: $del($path);
		foreach (self::ADDRESS as $key => $el) {
			$path = '<t:IndexedFieldURI FieldURI="contacts:PhysicalAddress:' . $el . '" FieldIndex="Business"/>';
			$updates .= $d[$key] !== ''
				? $set($path, '<t:PhysicalAddresses><t:Entry Key="Business"><t:' . $el . '>' . $c->esc($d[$key]) . '</t:' . $el . '></t:Entry></t:PhysicalAddresses>')
				: $del($path);
		}

		$this->client->request(
			'<m:UpdateItem ConflictResolution="AlwaysOverwrite">'
			. '<m:ItemChanges><t:ItemChange><t:ItemId Id="' . $c->esc($id) . '"/><t:Updates>' . $updates . '</t:Updates></t:ItemChange></m:ItemChanges>'
			. '</m:UpdateItem>'
		);
	}

	/**
	 * @param bool $public items of the public folders cannot go to my Deleted Items
	 */
	public function deleteContact(string $id, bool $public = false): void {
		$this->client->deleteItems([$id], $public);
	}

	/**
	 * Look up people in the personal contacts and the company directory (GAL).
	 * Used for address autocompletion.
	 *
	 * @return array<int, array{name: string, email: string, source: string}>
	 */
	public function resolve(string $query): array {
		$query = trim($query);
		if (mb_strlen($query) < 2) {
			return [];
		}
		$xp = $this->client->request(
			'<m:ResolveNames ReturnFullContactData="false" SearchScope="ContactsActiveDirectory">'
			. '<m:UnresolvedEntry>' . $this->client->esc($query) . '</m:UnresolvedEntry></m:ResolveNames>',
			false // "no results" is reported as an error by Exchange
		);
		$out = [];
		$seen = [];
		foreach ($xp->query('//m:ResolutionSet/t:Resolution/t:Mailbox') as $mb) {
			$email = (string)$this->client->text($xp, 't:EmailAddress', $mb);
			$routing = (string)$this->client->text($xp, 't:RoutingType', $mb);
			if ($email === '' || ($routing !== '' && $routing !== 'SMTP') || isset($seen[strtolower($email)])) {
				continue;
			}
			$seen[strtolower($email)] = true;
			$type = (string)$this->client->text($xp, 't:MailboxType', $mb);
			$out[] = [
				'name' => (string)$this->client->text($xp, 't:Name', $mb),
				'email' => $email,
				'source' => in_array($type, ['Contact', 'PrivateDL'], true) ? 'contacts' : 'directory',
			];
			if (count($out) >= 15) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A Matrix user id, « @alice:example.org », from what was typed or pasted: the id itself,
	 * a matrix.to link or a matrix: URI (« matrix:u/alice:example.org »); null if it is none.
	 */
	public static function matrixId(string $value): ?string {
		$v = trim($value);
		if (preg_match('~^https://matrix\.to/#/(@[^/?#\s]+)~i', $v, $m)) {
			$v = rawurldecode($m[1]);
		} elseif (preg_match('~^matrix:u/([^/?#\s]+)~i', $v, $m)) {
			$v = '@' . rawurldecode($m[1]);
		}
		// localpart: the characters allowed by the Matrix spec; server: a host name or IP, with a port
		if (strlen($v) > 255 || !preg_match('~^@[a-z0-9._=\-/+]+:(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)*|\[[0-9a-f:.]+\])(?::\d{1,5})?$~i', $v)) {
			return null;
		}
		return $v;
	}

	/** Outlook's year for a birthday without a year. */
	public const NO_YEAR = 1604;

	/** A birthday « 1980-05-12 »: a real date, from 1900 to today (or in 1604: day and month only). */
	public static function birthday(string $value, ?int $now = null): ?string {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			return null;
		}
		if (((int)$m[1] < 1900 && (int)$m[1] !== self::NO_YEAR) || $value > gmdate('Y-m-d', $now ?? time())) {
			return null;
		}
		return $value;
	}

	/**
	 * Exchange keeps a birthday as a time, midnight where the contact was saved (« 1980-05-11T22:00:00Z »
	 * from Paris): the nearest day. The year 1604 is a birthday without a year (Outlook).
	 */
	public static function birthdayFromEws(string $value): string {
		$t = $value !== '' ? strtotime($value) : false;
		if ($t === false) {
			return '';
		}
		// (noon UTC: as written here, its own day)
		return gmdate('Y-m-d', gmdate('H:i:s', $t) === '12:00:00' ? $t : $t + 12 * 3600);
	}

	/** Written at noon UTC: the same day wherever Outlook shows it (UTC-11 to UTC+11). */
	public static function birthdayToEws(string $date): string {
		return $date . 'T12:00:00Z';
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function normalize(array $data): array {
		$d = [];
		foreach (array_merge(array_keys(self::SIMPLE), array_keys(self::PHONES), array_keys(self::ADDRESS)) as $key) {
			$d[$key] = trim((string)($data[$key] ?? ''));
		}
		$d['notes'] = (string)($data['notes'] ?? '');
		$birthday = trim((string)($data['birthday'] ?? ''));
		$d['birthday'] = $birthday === '' ? '' : (self::birthday($birthday) ?? throw new MailException('Date d\'anniversaire invalide : %s', [$birthday]));
		$matrix = trim((string)($data['matrix'] ?? ''));
		$d['matrix'] = $matrix === '' ? '' : (self::matrixId($matrix) ?? throw new MailException('Adresse Matrix invalide : %s (par exemple %s)', [$matrix, '@alice:example.org']));

		$emails = [];
		foreach ((array)($data['emails'] ?? []) as $e) {
			$e = trim((string)$e);
			if ($e === '') {
				continue;
			}
			if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
				throw new MailException('Adresse e-mail invalide : %s', [$e]);
			}
			$emails[] = $e;
		}
		$d['emails'] = array_slice(array_values(array_unique($emails)), 0, 3);

		if ($d['displayName'] === '') {
			$d['displayName'] = trim($d['givenName'] . ' ' . $d['surname']);
		}
		if ($d['displayName'] === '') {
			$d['displayName'] = $d['company'] !== '' ? $d['company'] : ($d['emails'][0] ?? '');
		}
		if ($d['displayName'] === '') {
			throw new MailException('Indiquez au moins un nom, une société ou une adresse e-mail');
		}
		return $d;
	}
}
