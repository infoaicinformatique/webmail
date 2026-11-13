<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\Unsubscribe;

class UnsubscribeTest extends EwsTestCase {
	public function testOneClickFirst(): void {
		$this->assertSame(['method' => 'oneclick', 'url' => 'https://liste.exemple.org/u/42?t=abc'],
			Unsubscribe::parse('<mailto:desinscription@exemple.org?subject=stop>, <https://liste.exemple.org/u/42?t=abc>', 'List-Unsubscribe=One-Click'));
		// (header folded over two lines)
		$this->assertSame('oneclick', Unsubscribe::parse("<https://liste.exemple.org/u/\r\n 42>", ' list-unsubscribe=one-click ')['method']);
	}

	public function testMailto(): void {
		$this->assertSame(['method' => 'mailto', 'to' => 'desinscription@exemple.org', 'subject' => 'Désinscription 42', 'body' => 'stop'],
			Unsubscribe::parse('<mailto:desinscription@exemple.org?Subject=D%C3%A9sinscription%2042&body=stop>, <https://liste.exemple.org/u/42>'));
		$this->assertSame(['method' => 'mailto', 'to' => 'leave@lists.exemple.org', 'subject' => 'unsubscribe', 'body' => ''],
			Unsubscribe::parse('<mailto:leave@lists.exemple.org>'));
		// a subject on one line only
		$this->assertSame('a b', Unsubscribe::parse('<mailto:x@exemple.org?subject=a%0D%0Ab>')['subject']);
	}

	public function testLink(): void {
		// https without one-click: a page to open
		$this->assertSame(['method' => 'link', 'url' => 'https://liste.exemple.org/u/42'], Unsubscribe::parse('<https://liste.exemple.org/u/42>'));
		$this->assertSame(['method' => 'link', 'url' => 'http://liste.exemple.org/u'], Unsubscribe::parse('<http://liste.exemple.org/u>', 'List-Unsubscribe=One-Click'));
	}

	/** @return array<string, array{0: string}> */
	public static function nothing(): array {
		return [
			'empty' => [''],
			'no brackets' => ['https://liste.exemple.org/u'],
			'javascript' => ['<javascript:alert(1)>'],
			'credentials' => ['<https://user:pass@liste.exemple.org/u>'],
			'two addresses' => ['<mailto:a@exemple.org,b@exemple.org>'],
			'bad address' => ['<mailto:pas-une-adresse>'],
			'ftp' => ['<ftp://liste.exemple.org/u>'],
		];
	}

	/** @dataProvider nothing */
	#[\PHPUnit\Framework\Attributes\DataProvider('nothing')]
	public function testNothing(string $header): void {
		$this->assertNull(Unsubscribe::parse($header, 'List-Unsubscribe=One-Click'));
	}

	public function testReadWithTheMessage(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M1" ChangeKey="CK"/><t:Subject>Lettre</t:Subject>'
			. '<t:ExtendedProperty><t:ExtendedFieldURI DistinguishedPropertySetId="InternetHeaders" PropertyName="list-unsubscribe" PropertyType="String"/>'
			. '<t:Value>&lt;https://liste.exemple.org/u/42&gt;</t:Value></t:ExtendedProperty>'
			. '<t:ExtendedProperty><t:ExtendedFieldURI DistinguishedPropertySetId="InternetHeaders" PropertyName="List-Unsubscribe-Post" PropertyType="String"/>'
			. '<t:Value>List-Unsubscribe=One-Click</t:Value></t:ExtendedProperty>'
			. '</t:Message></m:Items>'));
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M2" ChangeKey="CK"/><t:Subject>Bonjour</t:Subject></t:Message></m:Items>'));
		$c = $this->client();
		$this->assertSame(['method' => 'oneclick', 'url' => 'https://liste.exemple.org/u/42'], $c->getItem('M1')['unsubscribe']);
		$this->assertStringContainsString('<t:ExtendedFieldURI DistinguishedPropertySetId="InternetHeaders" PropertyName="List-Unsubscribe" PropertyType="String"/>', $this->lastRequest());
		$this->assertNull($c->getItem('M2')['unsubscribe']);
	}

	public function testReadAgainBeforeUnsubscribing(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:Message><t:ItemId Id="M1" ChangeKey="CK"/>'
			. '<t:ExtendedProperty><t:ExtendedFieldURI DistinguishedPropertySetId="InternetHeaders" PropertyName="List-Unsubscribe" PropertyType="String"/>'
			. '<t:Value>&lt;mailto:stop@exemple.org&gt;</t:Value></t:ExtendedProperty></t:Message></m:Items>'));
		$this->assertSame('stop@exemple.org', $this->client()->unsubscribeInfo('M1')['to']);
		$this->assertStringContainsString('<t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties><t:ExtendedFieldURI DistinguishedPropertySetId="InternetHeaders"', $this->lastRequest());
	}
}
