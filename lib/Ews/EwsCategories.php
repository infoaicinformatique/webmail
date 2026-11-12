<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;

/**
 * The Outlook categories of a mailbox ("master category list"): their names
 * and colours, kept by Outlook as XML in the "CategoryList" configuration of
 * the calendar folder. Messages only carry the names.
 */
class EwsCategories {
	/** Outlook's 25 preset colours, by index (-1: none) */
	public const COLORS = [
		'#e7a1a2', '#f9ba89', '#f7dd8f', '#fcfa90', '#78d168', '#9fdcc9', '#c6d2b0', '#9db7e8', '#b5a1e2', '#daaec2',
		'#dad9dc', '#6b7994', '#bfbfbf', '#6f6f6f', '#4f4f4f', '#c11a25', '#e2620d', '#c79930', '#b9b300', '#368f2b',
		'#329b7a', '#778b45', '#2858a5', '#5c3fa3', '#93446b',
	];
	public const COLOR_NAMES = [
		'Rouge', 'Orange', 'Pêche', 'Jaune', 'Vert', 'Bleu-vert', 'Olive', 'Bleu', 'Violet', 'Bordeaux',
		'Acier', 'Acier foncé', 'Gris', 'Gris foncé', 'Noir', 'Rouge foncé', 'Orange foncé', 'Pêche foncé', 'Jaune foncé', 'Vert foncé',
		'Bleu-vert foncé', 'Olive foncé', 'Bleu foncé', 'Violet foncé', 'Bordeaux foncé',
	];
	private const NS = 'CategoryList.xsd';
	private const NAME = '<m:UserConfigurationName Name="CategoryList"><t:DistinguishedFolderId Id="calendar"/></m:UserConfigurationName>';

	public function __construct(
		private EwsClient $client,
	) {
	}

	/** @return list<array{name: string, color: ?string, colorIndex: int}> */
	public function list(): array {
		$xml = $this->fetch();
		return $xml === null ? [] : self::parse($xml);
	}

	/**
	 * Add a category to the list (as Outlook would). Nothing is done when
	 * one already has that name.
	 */
	public function add(string $name, int $colorIndex): void {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 255 || str_contains($name, ',') || str_contains($name, ';')) {
			throw new MailException('Nom de catégorie invalide (ni virgule ni point-virgule)');
		}
		if ($colorIndex < -1 || $colorIndex >= count(self::COLORS)) {
			throw new MailException('Couleur invalide');
		}
		$xml = $this->fetch();
		$exists = $xml !== null;
		$xml = self::withCategory($xml, $name, $colorIndex, self::guid(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
		if ($xml === null) {
			return; // already there
		}
		$config = '<m:UserConfiguration>' . str_replace('m:UserConfigurationName', 't:UserConfigurationName', self::NAME)
			. '<t:XmlData>' . base64_encode($xml) . '</t:XmlData></m:UserConfiguration>';
		$this->client->request($exists
			? '<m:UpdateUserConfiguration>' . $config . '</m:UpdateUserConfiguration>'
			: '<m:CreateUserConfiguration>' . $config . '</m:CreateUserConfiguration>');
	}

	/** The XML of the list, null when the mailbox has none yet. */
	private function fetch(): ?string {
		$xp = $this->client->request('<m:GetUserConfiguration>' . self::NAME
			. '<m:UserConfigurationProperties>XmlData</m:UserConfigurationProperties></m:GetUserConfiguration>', false);
		$msg = $xp->query('//m:ResponseMessages/*')->item(0);
		if ($msg instanceof \DOMElement && $msg->getAttribute('ResponseClass') === 'Error') {
			$code = $this->client->text($xp, 'm:ResponseCode', $msg);
			if ($code === 'ErrorItemNotFound') {
				return null;
			}
			throw new MailException(EwsClient::translateError($code, $this->client->text($xp, 'm:MessageText', $msg)));
		}
		$data = (string)$this->client->text($xp, '//m:UserConfiguration/t:XmlData');
		$xml = base64_decode($data, true);
		return $xml !== false && trim($xml) !== '' ? $xml : null;
	}

	// ------------------------------------------------------------------
	// Pure functions (tested without a server)
	// ------------------------------------------------------------------

	/** @return list<array{name: string, color: ?string, colorIndex: int}> */
	public static function parse(string $xml): array {
		$doc = new \DOMDocument();
		if (!@$doc->loadXML(self::stripBom($xml), LIBXML_NONET)) {
			return [];
		}
		$out = [];
		foreach ($doc->getElementsByTagName('category') as $c) {
			$name = $c->getAttribute('name');
			if ($name === '') {
				continue;
			}
			$i = $c->hasAttribute('color') ? (int)$c->getAttribute('color') : -1;
			$out[] = ['name' => $name, 'color' => self::COLORS[$i] ?? null, 'colorIndex' => isset(self::COLORS[$i]) ? $i : -1];
		}
		usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return $out;
	}

	/**
	 * The list with one more category; null when it is there already (any case).
	 * Unknown attributes and elements of Outlook are kept.
	 */
	public static function withCategory(?string $xml, string $name, int $colorIndex, string $guid, \DateTimeImmutable $now): ?string {
		$doc = new \DOMDocument('1.0', 'utf-8');
		if ($xml === null || !@$doc->loadXML(self::stripBom($xml), LIBXML_NONET) || $doc->documentElement?->localName !== 'categories') {
			$doc = new \DOMDocument('1.0', 'utf-8');
			$root = $doc->createElementNS(self::NS, 'categories');
			$root->setAttribute('default', '');
			$root->setAttribute('lastSavedSession', '0');
			$doc->appendChild($root);
		}
		$doc->encoding = 'utf-8'; // accented names as they are, not as character references
		$root = $doc->documentElement;
		foreach ($doc->getElementsByTagName('category') as $c) {
			if (mb_strtolower($c->getAttribute('name')) === mb_strtolower($name)) {
				return null;
			}
		}
		$time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v');
		$c = $doc->createElementNS($root->namespaceURI ?: self::NS, 'category');
		foreach ([
			'name' => $name, 'color' => (string)$colorIndex, 'keyboardShortcut' => '0', 'usageCount' => '0',
			'lastTimeUsedNotes' => '1601-01-01T00:00:00.000', 'lastTimeUsedJournal' => '1601-01-01T00:00:00.000',
			'lastTimeUsedContacts' => '1601-01-01T00:00:00.000', 'lastTimeUsedTasks' => '1601-01-01T00:00:00.000',
			'lastTimeUsedCalendar' => '1601-01-01T00:00:00.000', 'lastTimeUsedMail' => '1601-01-01T00:00:00.000',
			'lastTimeUsed' => '1601-01-01T00:00:00.000', 'lastSessionUsed' => '0', 'guid' => $guid,
		] as $k => $v) {
			$c->setAttribute($k, $v);
		}
		$root->appendChild($c);
		$root->setAttribute('lastSavedTime', $time);
		return $doc->saveXML();
	}

	private static function stripBom(string $xml): string {
		return str_starts_with($xml, "\xEF\xBB\xBF") ? substr($xml, 3) : $xml;
	}

	private static function guid(): string {
		$h = bin2hex(random_bytes(16));
		return '{' . strtoupper(substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20)) . '}';
	}
}
