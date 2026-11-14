<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Exception\MailException;

/**
 * The address books of Nextcloud Contacts (own and shared with the user) in
 * the Contacts of the webmail, next to the Exchange ones: the same cards as
 * in the Contacts app and the phones synced with Nextcloud, read and, where
 * the user may, changed. The copy of the Exchange contacts made by the
 * webmail is left out (it is shown from Exchange).
 */
class NextcloudContactsService {
	private const READ_ONLY = '{http://owncloud.org/ns}read-only';

	public function available(): bool {
		return class_exists(\OCA\DAV\CardDAV\CardDavBackend::class);
	}

	private function backend(): \OCA\DAV\CardDAV\CardDavBackend {
		if (!$this->available()) {
			throw new MailException('L\'application « dav » de Nextcloud n\'est pas disponible');
		}
		return \OCP\Server::get(\OCA\DAV\CardDAV\CardDavBackend::class);
	}

	/** @return list<array<string, mixed>> as the webmail lists address books */
	public function books(string $userId): array {
		if (!$this->available()) {
			return [];
		}
		$out = [];
		foreach ($this->backend()->getAddressBooksForUser('principals/users/' . $userId) as $b) {
			$uri = (string)$b['uri'];
			if (str_starts_with($uri, 'webmail-exchange-') || str_starts_with($uri, 'z-server-generated--')) {
				continue;
			}
			$name = trim((string)($b['{DAV:}displayname'] ?? '')) ?: $uri;
			$out[] = [
				'key' => NcCalendarRules::key((int)$b['id']),
				'name' => $name,
				'kind' => 'nextcloud',
				'canEdit' => empty($b[self::READ_ONLY]),
				'shared' => isset($b['{http://owncloud.org/ns}owner-principal']) && $b['{http://owncloud.org/ns}owner-principal'] !== 'principals/users/' . $userId,
			];
		}
		return $out;
	}

	/**
	 * @return array<string, mixed> the book, checked to be one of the user's
	 * @throws MailException
	 */
	public function book(string $userId, string $key, bool $write = false): array {
		foreach ($this->books($userId) as $b) {
			if ($b['key'] === $key) {
				if ($write && !$b['canEdit']) {
					throw new MailException('Ce carnet Nextcloud est en lecture seule pour vous');
				}
				$b['id'] = (int)NcCalendarRules::calendarId($key);
				return $b;
			}
		}
		throw new MailException('Carnet Nextcloud inconnu');
	}

	/**
	 * The contacts of a book, by name, those matching $query (name, address, company, phone).
	 * @return array{items: list<array<string, mixed>>, total: int, hasMore: bool}
	 */
	public function list(string $userId, string $key, int $offset, int $limit, string $query = ''): array {
		$book = $this->book($userId, $key);
		$items = [];
		foreach ($this->backend()->getCards($book['id']) as $card) {
			$c = self::contact($card);
			if ($c === null) {
				continue;
			}
			if ($query !== '' && !self::matches($c, $query)) {
				continue;
			}
			$items[] = [
				'id' => $c['id'],
				'displayName' => $c['displayName'],
				'company' => $c['company'],
				'jobTitle' => $c['jobTitle'],
				'email' => $c['emails'][0] ?? '',
				'phone' => $c['mobilePhone'] ?: ($c['businessPhone'] ?: $c['homePhone']),
				'isGroup' => false,
				'hasPhoto' => $c['hasPhoto'],
				'birthday' => $c['birthday'],
			];
		}
		$collator = class_exists(\Collator::class) ? new \Collator('fr_FR') : null;
		usort($items, fn ($a, $b) => $collator ? $collator->compare($a['displayName'], $b['displayName']) : strcasecmp($a['displayName'], $b['displayName']));
		$total = count($items);
		return ['items' => array_slice($items, $offset, $limit), 'total' => $total, 'hasMore' => $offset + $limit < $total];
	}

	/** @return list<array<string, mixed>> every contact of a book, as the form has them */
	public function all(string $userId, string $key): array {
		$book = $this->book($userId, $key);
		return array_values(array_filter(array_map(fn ($card) => self::contact($card), $this->backend()->getCards($book['id']))));
	}

	/** @return array<string, mixed> */
	public function get(string $userId, string $key, string $id): array {
		$book = $this->book($userId, $key);
		$c = self::contact($this->card($book['id'], $id));
		if ($c === null) {
			throw new MailException('Contact introuvable');
		}
		return $c;
	}

	/** @param array<string, mixed> $data the form */
	public function create(string $userId, string $key, array $data): string {
		$book = $this->book($userId, $key, true);
		$d = EwsContacts::normalize($data);
		$uid = self::uuid();
		$uri = $uid . '.vcf';
		$this->backend()->createCard($book['id'], $uri, VCard::merge(null, $d, $uid));
		return $uri;
	}

	/** @param array<string, mixed> $data the form */
	public function update(string $userId, string $key, string $id, array $data): void {
		$book = $this->book($userId, $key, true);
		$card = $this->card($book['id'], $id);
		$d = EwsContacts::normalize($data);
		$this->backend()->updateCard($book['id'], $id, VCard::merge(self::data($card), $d));
	}

	public function delete(string $userId, string $key, string $id): void {
		$book = $this->book($userId, $key, true);
		$this->card($book['id'], $id);
		$this->backend()->deleteCard($book['id'], $id);
	}

	/** @return ?array{mime: string, data: string} */
	public function photo(string $userId, string $key, string $id): ?array {
		$book = $this->book($userId, $key);
		return VCard::photo(self::data($this->card($book['id'], $id)));
	}

	/** @param string $image the bytes of the image, '' to remove it */
	public function setPhoto(string $userId, string $key, string $id, string $image): void {
		$book = $this->book($userId, $key, true);
		$card = $this->card($book['id'], $id);
		$this->backend()->updateCard($book['id'], $id, VCard::withPhoto(self::data($card), $image));
	}

	// ------------------------------------------------------------------

	/** @return array<string, mixed> */
	private function card(int $bookId, string $id): array {
		if ($id === '' || str_contains($id, '/')) {
			throw new MailException('Contact introuvable');
		}
		$card = $this->backend()->getCard($bookId, $id);
		if (!is_array($card)) {
			throw new MailException('Contact introuvable');
		}
		return $card;
	}

	/** @param array<string, mixed> $card */
	private static function data(array $card): string {
		$data = $card['carddata'] ?? '';
		return is_resource($data) ? (string)stream_get_contents($data) : (string)$data;
	}

	/**
	 * @param array<string, mixed> $card a row of the backend
	 * @return ?array<string, mixed> the contact as the form has it; null for a group or an empty card
	 */
	private static function contact(array $card): ?array {
		$vcf = self::data($card);
		$c = VCard::parse($vcf)[0] ?? null;
		if ($c === null) {
			return null;
		}
		if ($c['displayName'] === '') {
			$c['displayName'] = trim($c['givenName'] . ' ' . $c['surname']) ?: ($c['company'] ?: ($c['emails'][0] ?? ''));
		}
		return ['id' => (string)$card['uri'], 'isGroup' => false, 'members' => [], 'office' => '', 'hasPhoto' => VCard::hasPhoto($vcf)] + $c;
	}

	/** @param array<string, mixed> $c */
	private static function matches(array $c, string $query): bool {
		$fold = function (string $s): string {
			$s = mb_strtolower($s, 'UTF-8');
			$t = class_exists(\Normalizer::class) ? \Normalizer::normalize($s, \Normalizer::FORM_D) : $s;
			return (string)preg_replace('/\p{Mn}+/u', '', (string)$t);
		};
		$hay = $fold(implode(' ', [$c['displayName'], $c['givenName'], $c['surname'], $c['company'], implode(' ', $c['emails'])]));
		$digits = preg_replace('/\D+/', '', implode(' ', [$c['mobilePhone'], $c['businessPhone'], $c['homePhone']]));
		foreach (preg_split('/\s+/u', $fold(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
			$num = preg_replace('/\D+/', '', $word);
			if (!str_contains($hay, $word) && !($num !== '' && strlen($num) >= 3 && str_contains((string)$digits, $num))) {
				return false;
			}
		}
		return true;
	}

	private static function uuid(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}
}
