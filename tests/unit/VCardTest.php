<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\VCard;
use PHPUnit\Framework\TestCase;

class VCardTest extends TestCase {
	private const CONTACT = [
		'displayName' => 'Jean Dupont', 'givenName' => 'Jean', 'surname' => 'Dupont', 'company' => 'Client, Exemple & Cie', 'department' => 'Achats',
		'jobTitle' => 'Acheteur', 'emails' => ['jean.dupont@client-exemple.fr', 'jd@perso.fr'], 'mobilePhone' => '+33 6 12 34 56 78',
		'businessPhone' => '04 93 00 00 00', 'homePhone' => '', 'street' => "12 rue de la Paix\nBâtiment B", 'city' => 'Nice', 'postalCode' => '06000',
		'country' => 'France', 'notes' => "Préfère le téléphone ; rappeler le matin.\nClient depuis 2019.", 'matrix' => '@jean.dupont:matrix.client-exemple.fr',
	];

	public function testRoundTrip(): void {
		$vcf = VCard::export([self::CONTACT]);
		$this->assertStringStartsWith("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Jean Dupont\r\nN:Dupont;Jean;;;\r\nORG:Client\\, Exemple & Cie;Achats\r\n", $vcf);
		$this->assertStringContainsString("EMAIL;TYPE=INTERNET,PREF:jean.dupont@client-exemple.fr\r\n", $vcf);
		$this->assertStringContainsString("TEL;TYPE=CELL:+33 6 12 34 56 78\r\n", $vcf);
		$this->assertStringContainsString("IMPP;X-SERVICE-TYPE=Matrix:matrix:u/jean.dupont:matrix.client-exemple.fr\r\n", $vcf);
		foreach (explode("\r\n", $vcf) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line), $line);
		}
		$this->assertSame([self::CONTACT], VCard::parse($vcf));
	}

	public function testOutlookVCard21(): void {
		$vcf = "BEGIN:VCARD\r\nVERSION:2.1\r\nN;LANGUAGE=fr:Martin;Marie\r\nFN:Marie Martin\r\nORG:Soci=C3=A9t=C3=A9 B\r\n"
			. "TEL;WORK;VOICE:04 92 00 00 00\r\nTEL;CELL;VOICE:06 00 00 00 00\r\n"
			. "ADR;WORK;PREF;ENCODING=QUOTED-PRINTABLE;CHARSET=utf-8:;;3 all=C3=A9e des Pins;Antibes;;06600;France\r\n"
			. "EMAIL;PREF;INTERNET:marie.martin@societe-b.fr\r\nNOTE;ENCODING=QUOTED-PRINTABLE:Ligne 1=0D=0ALigne=\r\n 2\r\nEND:VCARD\r\n";
		$vcf = str_replace('ORG:Soci', 'ORG;ENCODING=QUOTED-PRINTABLE;CHARSET=utf-8:Soci', $vcf);
		$c = VCard::parse($vcf)[0];
		$this->assertSame(['Marie Martin', 'Marie', 'Martin', 'Société B'], [$c['displayName'], $c['givenName'], $c['surname'], $c['company']]);
		$this->assertSame(['04 92 00 00 00', '06 00 00 00 00'], [$c['businessPhone'], $c['mobilePhone']]);
		$this->assertSame(['3 allée des Pins', 'Antibes', '06600', 'France'], [$c['street'], $c['city'], $c['postalCode'], $c['country']]);
		$this->assertSame(['marie.martin@societe-b.fr'], $c['emails']);
	}

	public function testSeveralCardsGroupsAndEmpty(): void {
		$vcf = "BEGIN:VCARD\nVERSION:4.0\nFN:Paul\nEMAIL:paul@x.fr\nEMAIL;PREF=1:paul.pro@x.fr\nTEL;VALUE=uri;TYPE=\"voice,home\":tel:+33-1-00\nEND:VCARD\n"
			. "BEGIN:VCARD\nVERSION:4.0\nKIND:group\nFN:Équipe\nEND:VCARD\n"
			. "BEGIN:VCARD\nVERSION:3.0\nNOTE:sans nom\nEND:VCARD\n"
			. "BEGIN:VCARD\nVERSION:3.0\nitem1.EMAIL;type=INTERNET:pas-une-adresse\nORG:Seulement une société\nEND:VCARD\n";
		$cards = VCard::parse($vcf);
		$this->assertCount(2, $cards);
		$this->assertSame(['paul.pro@x.fr', 'paul@x.fr'], $cards[0]['emails']);
		$this->assertSame('+33-1-00', $cards[0]['homePhone']);
		$this->assertSame(['Seulement une société', []], [$cards[1]['company'], $cards[1]['emails']]);
	}

	public function testMatrixFromOtherApps(): void {
		$card = fn (string $line) => VCard::parse("BEGIN:VCARD\r\nVERSION:4.0\r\nFN:A\r\n" . $line . "\r\nEND:VCARD\r\n")[0]['matrix'];
		$this->assertSame('@alice:example.org', $card('IMPP:matrix:u/alice:example.org'));
		$this->assertSame('@alice:example.org', $card('IMPP;PREF=1:https://matrix.to/#/@alice:example.org'));
		$this->assertSame('@alice:example.org', $card('X-MATRIX:@alice:example.org'));
		// another messenger: not a Matrix address
		$this->assertSame('', $card('IMPP:xmpp:alice@example.org'));
	}
}
