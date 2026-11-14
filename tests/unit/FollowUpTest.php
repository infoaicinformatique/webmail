<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\FollowUpList;

class FollowUpTest extends EwsTestCase {
	private const NOW = 1794556800; // 2026-11-13T08:00:00Z

	public function testSubjectAndAddresses(): void {
		$this->assertSame('Devis toiture', FollowUpList::subject('RE: TR : Fwd: Devis toiture'));
		$this->assertSame('Devis toiture', FollowUpList::subject('  AW[2]: Devis toiture '));
		$this->assertSame(['jean@client.fr', 'paul@x.fr'], FollowUpList::addresses('Jean Dupont <Jean@Client.fr>, paul@x.fr; jean@client.fr, pas-une-adresse'));
	}

	public function testAddDue(): void {
		$list = FollowUpList::add([], 'RE: Devis', 'Jean <jean@client.fr>', 3, self::NOW);
		$this->assertSame([['subject' => 'Devis', 'to' => ['jean@client.fr'], 'since' => '2026-11-13T07:59:00Z', 'until' => '2026-11-16T08:00:00Z']], $list);
		[$due, $rest] = FollowUpList::due($list, self::NOW + 2 * 86400);
		$this->assertSame([[], $list], [$due, $rest]);
		[$due] = FollowUpList::due($list, self::NOW + 3 * 86400);
		$this->assertCount(1, $due);
		$this->assertSame($list, FollowUpList::decode((string)json_encode($list)));
		$this->assertSame([], FollowUpList::decode('[{"subject":"x"},5,"a"]'));
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function badAdds(): array {
		return ['nobody' => ['pas-une-adresse', 3], 'no days' => ['a@b.fr', 0], 'too long' => ['a@b.fr', 61]];
	}

	/** @dataProvider badAdds */
	#[\PHPUnit\Framework\Attributes\DataProvider('badAdds')]
	public function testBadAdds(string $to, int $days): void {
		$this->expectException(MailException::class);
		FollowUpList::add([], 'Devis', $to, $days, self::NOW);
	}

	public function testAnswered(): void {
		$to = ['jean@client.fr'];
		$reply = ['from' => ['name' => 'Jean', 'email' => 'Jean@client.fr'], 'subject' => 'RE: Devis toiture'];
		$other = ['from' => ['name' => 'Paul', 'email' => 'paul@x.fr'], 'subject' => 'RE: Devis toiture'];
		$this->assertTrue(FollowUpList::answered([$other, $reply], 'Devis toiture', $to));
		$this->assertFalse(FollowUpList::answered([$other], 'Devis toiture', $to));
		$this->assertFalse(FollowUpList::answered([['from' => ['email' => 'jean@client.fr'], 'subject' => 'Autre chose']], 'Devis toiture', $to));
		$this->assertTrue(FollowUpList::answered([['from' => ['email' => 'jean@client.fr'], 'subject' => 'Autre chose']], '', $to));
	}

	public function testReceivedSince(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="1" IncludesLastItemInRange="true"><t:Items>'
			. '<t:Message><t:ItemId Id="M1" ChangeKey="CK"/><t:Subject>RE: Devis toiture</t:Subject><t:From><t:Mailbox><t:Name>Jean</t:Name><t:EmailAddress>jean@client.fr</t:EmailAddress></t:Mailbox></t:From></t:Message>'
			. '</t:Items></m:RootFolder>'));
		$r = $this->client()->receivedSince('2026-11-13T07:59:00Z', 'Devis "toiture"');
		$this->assertSame('jean@client.fr', $r[0]['from']['email']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:Constant Value="2026-11-13T07:59:00Z"/>', $req);
		$this->assertStringContainsString('<t:Contains ContainmentMode="Substring" ContainmentComparison="IgnoreCase"><t:FieldURI FieldURI="item:Subject"/><t:Constant Value="Devis &quot;toiture&quot;"/></t:Contains>', $req);
		$this->assertStringContainsString('<t:DistinguishedFolderId Id="inbox"/>', $req);
	}
}
