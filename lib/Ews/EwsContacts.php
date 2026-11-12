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

	/**
	 * @return array{items: array<int, array<string, mixed>>, total: int, hasMore: bool}
	 */
	public function listContacts(int $offset = 0, int $limit = 100, ?string $query = null): array {
		$props = '<t:FieldURI FieldURI="contacts:DisplayName"/><t:FieldURI FieldURI="contacts:CompanyName"/>'
			. '<t:FieldURI FieldURI="contacts:JobTitle"/>'
			. '<t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress1"/>'
			. '<t:IndexedFieldURI FieldURI="contacts:PhoneNumber" FieldIndex="MobilePhone"/>'
			. '<t:IndexedFieldURI FieldURI="contacts:PhoneNumber" FieldIndex="BusinessPhone"/>';
		$queryXml = ($query !== null && trim($query) !== '') ? '<m:QueryString>' . $this->client->esc(trim($query)) . '</m:QueryString>' : '';

		$xp = $this->client->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . $props . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="' . max(1, $limit) . '" Offset="' . max(0, $offset) . '" BasePoint="Beginning"/>'
			. '<m:SortOrder><t:FieldOrder Order="Ascending"><t:FieldURI FieldURI="contacts:DisplayName"/></t:FieldOrder></m:SortOrder>'
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="contacts"/></m:ParentFolderIds>'
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

	/**
	 * @return array<string, mixed>
	 */
	public function getContact(string $id): array {
		$fields = '';
		foreach (['contacts:DisplayName', 'contacts:GivenName', 'contacts:Surname', 'contacts:CompanyName', 'contacts:Department',
			'contacts:JobTitle', 'contacts:EmailAddresses', 'contacts:PhoneNumbers', 'contacts:PhysicalAddresses', 'item:Body'] as $f) {
			$fields .= '<t:FieldURI FieldURI="' . $f . '"/>';
		}
		$xp = $this->client->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:BodyType>Text</t:BodyType>'
			. '<t:AdditionalProperties>' . $fields . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->client->esc($id) . '"/></m:ItemIds></m:GetItem>'
		);
		$node = $xp->query('//m:Items/*')->item(0);
		if ($node === null) {
			throw new MailException('Contact introuvable');
		}
		$c = $this->client;
		$contact = [
			'id' => $c->attr($xp, 't:ItemId', 'Id', $node),
			'isGroup' => $node->localName === 'DistributionList',
			'notes' => (string)$c->text($xp, 't:Body', $node),
			'emails' => [],
		];
		foreach (self::SIMPLE as $key => [, $el]) {
			$contact[$key] = (string)$c->text($xp, 't:' . $el, $node);
		}
		foreach (['EmailAddress1', 'EmailAddress2', 'EmailAddress3'] as $k) {
			$v = $c->text($xp, 't:EmailAddresses/t:Entry[@Key="' . $k . '"]', $node);
			if ($v !== null && $v !== '') {
				$contact['emails'][] = $v;
			}
		}
		foreach (self::PHONES as $key => $k) {
			$contact[$key] = (string)$c->text($xp, 't:PhoneNumbers/t:Entry[@Key="' . $k . '"]', $node);
		}
		foreach (self::ADDRESS as $key => $el) {
			$contact[$key] = (string)$c->text($xp, 't:PhysicalAddresses/t:Entry[@Key="Business"]/t:' . $el, $node);
		}
		return $contact;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function createContact(array $data): string {
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
			'<m:CreateItem><m:SavedItemFolderId><t:DistinguishedFolderId Id="contacts"/></m:SavedItemFolderId>'
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

	public function deleteContact(string $id): void {
		$this->client->deleteItems([$id]);
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
