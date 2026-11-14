<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\ContactDuplicates;
use PHPUnit\Framework\TestCase;

class ContactDuplicatesTest extends TestCase {
	public function testSameAddressOrSameNameAreDuplicates(): void {
		$groups = ContactDuplicates::groups([
			['id' => '1', 'displayName' => 'Jean Dupont', 'emails' => ['jean.dupont@client.fr']],
			['id' => '2', 'displayName' => 'J. Dupont (Client)', 'emails' => ['JEAN.DUPONT@client.fr']],
			['id' => '3', 'displayName' => 'DUPONT Jean', 'emails' => []],
			['id' => '4', 'displayName' => 'Anne Martin', 'emails' => ['anne@client.fr']],
			['id' => '5', 'displayName' => 'Hélène Faure', 'emails' => []],
			['id' => '6', 'displayName' => 'helene faure', 'emails' => ['h.faure@exemple.fr']],
			// one word: not enough to say it is the same person
			['id' => '7', 'displayName' => 'Accueil', 'emails' => []],
			['id' => '8', 'displayName' => 'Accueil', 'emails' => []],
		]);
		$this->assertSame([['5', '6'], ['1', '2', '3']], array_map(fn ($g) => array_column($g, 'id'), $groups));
	}

	public function testTheMergedCardLosesNothing(): void {
		$keep = ['id' => '1', 'displayName' => 'Jean Dupont', 'company' => '', 'mobilePhone' => '06 12 34 56 78', 'emails' => ['jean@a.fr', 'j@b.fr'], 'notes' => 'Client depuis 2019'];
		$merged = ContactDuplicates::merge($keep, [
			['id' => '2', 'displayName' => 'J. Dupont', 'company' => 'Client SA', 'mobilePhone' => '+33 6 12 34 56 78', 'businessPhone' => '04 93 00 00 00', 'emails' => ['JEAN@a.fr', 'c@c.fr', 'd@d.fr'], 'notes' => ''],
			['id' => '3', 'mobilePhone' => '07 00 00 00 00', 'city' => 'Nice', 'emails' => [], 'notes' => 'Préfère le téléphone'],
		]);
		$this->assertSame('Jean Dupont', $merged['displayName'], 'the card kept wins');
		$this->assertSame('Client SA', $merged['company'], 'an empty field is completed');
		$this->assertSame('06 12 34 56 78', $merged['mobilePhone']);
		$this->assertSame('04 93 00 00 00', $merged['businessPhone']);
		$this->assertSame('Nice', $merged['city']);
		$this->assertSame(['jean@a.fr', 'j@b.fr', 'c@c.fr'], $merged['emails']);
		$this->assertSame("Client depuis 2019\n\nPréfère le téléphone\n\nAutres adresses : d@d.fr\n\nAutres numéros : 07 00 00 00 00", $merged['notes']);
		$this->assertSame('1', $merged['id']);
	}
}
