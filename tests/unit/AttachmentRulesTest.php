<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AttachmentRules;

class AttachmentRulesTest extends EwsTestCase {
	public function testRules(): void {
		$rules = AttachmentRules::add([], ' Factures@Fournisseur.fr ', 'Documents/Factures/');
		$rules = AttachmentRules::add($rules, '@client.fr', '/Clients');
		$this->assertSame([['from' => 'factures@fournisseur.fr', 'folder' => '/Documents/Factures'], ['from' => '@client.fr', 'folder' => '/Clients']], $rules);
		// one rule per sender: the new folder replaces
		$rules = AttachmentRules::add($rules, 'factures@fournisseur.fr', '/Compta');
		$this->assertSame(['@client.fr', 'factures@fournisseur.fr'], array_column($rules, 'from'));
		$this->assertSame('/Compta', AttachmentRules::folderFor($rules, 'Factures@fournisseur.fr'));
		$this->assertSame('/Clients', AttachmentRules::folderFor($rules, 'jean@client.fr'));
		$this->assertNull(AttachmentRules::folderFor($rules, 'jean@autre.fr'));
		$this->assertSame($rules, AttachmentRules::decode((string)json_encode($rules)));
		$this->assertSame([], AttachmentRules::decode('[{"from":"x","folder":"/a"}]'));
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function bad(): array {
		return [
			'no sender' => ['', '/Docs'],
			'not an address' => ['fournisseur', '/Docs'],
			'no folder' => ['a@b.fr', '/'],
			'out of the files' => ['a@b.fr', '/Docs/../../etc'],
		];
	}

	/** @dataProvider bad */
	#[\PHPUnit\Framework\Attributes\DataProvider('bad')]
	public function testBad(string $from, string $folder): void {
		$this->expectException(MailException::class);
		AttachmentRules::rule($from, $folder);
	}

	public function testMessagesWithAttachments(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="1" IncludesLastItemInRange="true"><t:Items>'
			. '<t:Message><t:ItemId Id="M1" ChangeKey="CK"/><t:Subject>Facture 42</t:Subject><t:DateTimeReceived>2026-11-14T08:00:00Z</t:DateTimeReceived>'
			. '<t:From><t:Mailbox><t:Name>Fournisseur</t:Name><t:EmailAddress>factures@fournisseur.fr</t:EmailAddress></t:Mailbox></t:From></t:Message>'
			. '</t:Items></m:RootFolder>'));
		$r = $this->client()->withAttachmentsSince('2026-11-14T07:00:00Z');
		$this->assertSame([['id' => 'M1', 'from' => ['name' => 'Fournisseur', 'email' => 'factures@fournisseur.fr'], 'subject' => 'Facture 42', 'date' => '2026-11-14T08:00:00Z']], $r);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:IsGreaterThan><t:FieldURI FieldURI="item:DateTimeReceived"/><t:FieldURIOrConstant><t:Constant Value="2026-11-14T07:00:00Z"/>', $req);
		$this->assertStringContainsString('<t:IsEqualTo><t:FieldURI FieldURI="item:HasAttachments"/>', $req);
		$this->assertStringContainsString('<t:FieldOrder Order="Ascending"><t:FieldURI FieldURI="item:DateTimeReceived"/>', $req);
	}
}
