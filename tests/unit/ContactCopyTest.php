<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\ContactCopy;
use PHPUnit\Framework\TestCase;

class ContactCopyTest extends TestCase {
	private const JEAN = ['id' => 'AAMk-jean', 'displayName' => 'Jean Dupont', 'givenName' => 'Jean', 'surname' => 'Dupont', 'emails' => ['jean@client.fr']];
	private const ANNE = ['id' => 'AAMk-anne', 'displayName' => 'Anne Martin', 'emails' => ['anne@client.fr']];

	public function testTheSameContactKeepsItsCard(): void {
		$this->assertSame(ContactCopy::uri('AAMk-jean'), ContactCopy::uri('AAMk-jean'));
		$this->assertNotSame(ContactCopy::uri('AAMk-jean'), ContactCopy::uri('AAMk-anne'));
		$this->assertMatchesRegularExpression('/^webmail-[0-9a-f]{32}\.vcf$/', ContactCopy::uri('AAMk-jean'));
	}

	public function testTheCardHasAUid(): void {
		$uri = ContactCopy::uri('AAMk-jean');
		$card = ContactCopy::card(self::JEAN, $uri);
		$this->assertStringStartsWith("BEGIN:VCARD\r\nVERSION:3.0\r\nUID:" . substr($uri, 0, -4) . "\r\nFN:Jean Dupont\r\n", $card);
		$this->assertStringContainsString('EMAIL;TYPE=INTERNET,PREF:jean@client.fr', $card);
		$this->assertSame(1, substr_count($card, 'UID:'));
	}

	public function testFirstCopyCreatesEveryCard(): void {
		$plan = ContactCopy::plan([self::JEAN, self::ANNE], []);
		$this->assertCount(2, $plan['create']);
		$this->assertSame([], $plan['update']);
		$this->assertSame([], $plan['delete']);
	}

	public function testOnlyWhatChangedIsWritten(): void {
		$jean = ContactCopy::uri('AAMk-jean');
		$anne = ContactCopy::uri('AAMk-anne');
		$gone = ContactCopy::uri('AAMk-gone');
		$existing = [
			$jean => str_replace("\r\n", "\n", ContactCopy::card(self::JEAN, $jean)), // as stored: same card
			$anne => ContactCopy::card(['displayName' => 'Anne Martin (ancienne)'] + self::ANNE, $anne),
			$gone => 'BEGIN:VCARD…',
			'added-by-hand.vcf' => 'BEGIN:VCARD…',
		];
		$plan = ContactCopy::plan([self::JEAN, self::ANNE], $existing);
		$this->assertSame([], $plan['create']);
		$this->assertSame([$anne], array_keys($plan['update']));
		// a contact deleted in Exchange goes; a card added in Nextcloud stays
		$this->assertSame([$gone], $plan['delete']);
	}

	public function testContactsWithoutIdOrTwiceAreLeftOut(): void {
		$plan = ContactCopy::plan([self::JEAN, self::JEAN, ['displayName' => 'Sans id']], []);
		$this->assertCount(1, $plan['create']);
	}
}
