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
			throw new MailException('Aucune boîte Exchange ne correspond à ' . $email);
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
			throw new MailException($email . ' ne partage pas de carnet d\'adresses avec vous (à faire dans Outlook : clic droit sur le carnet → Propriétés → Autorisations)');
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
			throw new MailException($parent === null || $parent === ''
				? 'Aucun dossier public n\'est accessible sur ce serveur Exchange'
				: EwsClient::translateError($code, $msg !== null ? $this->client->text($xp, 'm:MessageText', $msg) : null));
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
			throw new MailException($class === 'IPF.Appointment' ? 'Ce dossier public n\'est pas un agenda lisible' : 'Ce dossier public n\'est pas un carnet d\'adresses lisible');
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
			. '<t:FieldURI FieldURI="contacts:JobTitle"/>'
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
			'contacts:JobTitle', 'contacts:EmailAddresses', 'contacts:PhoneNumbers', 'contacts:PhysicalAddresses', 'item:Body'] as $f) {
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
		return $contact;
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
			. $el('Department', $d['department'])
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
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function normalize(array $data): array {
		$d = [];
		foreach (array_merge(array_keys(self::SIMPLE), array_keys(self::PHONES), array_keys(self::ADDRESS)) as $key) {
			$d[$key] = trim((string)($data[$key] ?? ''));
		}
		$d['notes'] = (string)($data['notes'] ?? '');

		$emails = [];
		foreach ((array)($data['emails'] ?? []) as $e) {
			$e = trim((string)$e);
			if ($e === '') {
				continue;
			}
			if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
				throw new MailException('Adresse e-mail invalide : ' . $e);
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
