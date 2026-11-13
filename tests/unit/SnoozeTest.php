<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\SnoozeList;

class SnoozeTest extends EwsTestCase {
	private const NOW = 1794556800; // 2026-11-13T08:00:00Z

	public function testUntil(): void {
		$this->assertSame('2026-11-14T07:00:00Z', SnoozeList::until('2026-11-14T08:00:00+01:00', self::NOW));
		$this->assertSame('2026-11-14T07:00:00Z', SnoozeList::until('2026-11-14T07:00:00.000Z', self::NOW));
	}

	/** @return array<string, array{0: string}> */
	public static function badTimes(): array {
		return [
			'past' => ['2026-11-13T07:59:00Z'],
			'in 30 s' => [gmdate('Y-m-d\TH:i:s\Z', self::NOW + 30)],
			'in 2 years' => ['2028-11-13T07:00:00Z'],
			'not a date' => ['demain'],
			'empty' => [''],
		];
	}

	/** @dataProvider badTimes */
	#[\PHPUnit\Framework\Attributes\DataProvider('badTimes')]
	public function testBadTimes(string $value): void {
		$this->expectException(MailException::class);
		SnoozeList::until($value, self::NOW);
	}

	public function testListAddDueWithout(): void {
		$list = SnoozeList::add([], ['A', 'B'], '2026-11-13T08:00:00Z');
		$list = SnoozeList::add($list, ['C'], '2026-11-20T07:00:00Z');
		// put aside again: only its new time
		$list = SnoozeList::add($list, ['A'], '2026-11-21T07:00:00Z');
		$this->assertSame([
			['id' => 'B', 'until' => '2026-11-13T08:00:00Z'],
			['id' => 'C', 'until' => '2026-11-20T07:00:00Z'],
			['id' => 'A', 'until' => '2026-11-21T07:00:00Z'],
		], $list);
		[$due, $rest] = SnoozeList::due($list, strtotime('2026-11-13T08:00:00Z'));
		$this->assertSame(['B'], array_column($due, 'id'));
		$this->assertSame(['C', 'A'], array_column($rest, 'id'));
		$this->assertSame(['B', 'A'], array_column(SnoozeList::without($list, ['C']), 'id'));
	}

	public function testDecodeKeepsOnlyGoodEntries(): void {
		$this->assertSame([['id' => 'A', 'until' => '2026-11-13T08:00:00Z']],
			SnoozeList::decode('[{"id":"A","until":"2026-11-13T08:00:00Z"},{"id":""},{"until":"x"},5,"a"]'));
		$this->assertSame([], SnoozeList::decode('not json'));
	}

	public function testAtMost(): void {
		$this->expectException(MailException::class);
		SnoozeList::add([], array_map('strval', range(1, SnoozeList::MAX + 1)), '2026-11-14T07:00:00Z');
	}

	public function testMoveGivesTheNewIds(): void {
		$this->queue(self::ok('MoveItem', '<m:Items><t:Message><t:ItemId Id="NEW-A" ChangeKey="CK"/></t:Message></m:Items>')
			. '');
		$ids = $this->client()->moveItems(['A'], 'F-SNOOZE');
		$this->assertSame(['NEW-A'], $ids);
		$this->assertStringContainsString('<m:ToFolderId><t:FolderId Id="F-SNOOZE"/></m:ToFolderId>', $this->lastRequest());
	}

	public function testWhereMessagesAre(): void {
		$this->queue('<m:GetItemResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>'
			. '<m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items><t:Message><t:ItemId Id="A" ChangeKey="CK"/><t:ParentFolderId Id="F-SNOOZE" ChangeKey="x"/></t:Message></m:Items></m:GetItemResponseMessage>'
			. '<m:GetItemResponseMessage ResponseClass="Error"><m:MessageText>gone</m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode><m:DescriptiveLinkKey>0</m:DescriptiveLinkKey><m:Items/></m:GetItemResponseMessage>'
			. '</m:ResponseMessages></m:GetItemResponse>');
		$this->assertSame(['A' => 'F-SNOOZE', 'B' => null], $this->client()->parentFolders(['A', 'B']));
		$this->assertStringContainsString('<t:FieldURI FieldURI="item:ParentFolderId"/>', $this->lastRequest());
	}

	public function testTopFolderByName(): void {
		$this->queue(self::ok('FindFolder', '<m:RootFolder TotalItemsInView="2" IncludesLastItemInRange="true"><t:Folders>'
			. '<t:Folder><t:FolderId Id="F-1" ChangeKey="x"/><t:DisplayName>Factures</t:DisplayName></t:Folder>'
			. '<t:Folder><t:FolderId Id="F-2" ChangeKey="x"/><t:DisplayName>reportés</t:DisplayName></t:Folder>'
			. '</t:Folders></m:RootFolder>'));
		$this->queue(self::ok('FindFolder', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Folders/></m:RootFolder>'));
		$c = $this->client();
		$this->assertSame('F-2', $c->findTopFolder('Reportés'));
		$this->assertStringContainsString('<m:FindFolder Traversal="Shallow">', $this->lastRequest());
		$this->assertNull($c->findTopFolder('Reportés'));
	}

	public function testFolderExists(): void {
		$this->queue(self::ok('GetFolder', '<m:Folders><t:Folder><t:FolderId Id="F-2" ChangeKey="x"/></t:Folder></m:Folders>'));
		$this->queue('<m:GetFolderResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages"><m:ResponseMessages>'
			. '<m:GetFolderResponseMessage ResponseClass="Error"><m:MessageText>gone</m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode><m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:GetFolderResponseMessage>'
			. '</m:ResponseMessages></m:GetFolderResponse>');
		$c = $this->client();
		$this->assertTrue($c->folderExists('F-2'));
		$this->assertFalse($c->folderExists('F-2'));
	}
}
