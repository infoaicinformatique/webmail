<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;

class FlagDueTest extends EwsTestCase {
	public function testFlagWithADueDate(): void {
		$this->queue(self::ok('UpdateItem'));
		$due = gmdate('Y-m-d', time() + 3 * 86400);
		$this->client()->setFlagged(['M1'], true, $due);
		$this->assertStringContainsString('<t:Flag><t:FlagStatus>Flagged</t:FlagStatus><t:StartDate>' . gmdate('Y-m-d') . 'T00:00:00Z</t:StartDate>'
			. '<t:DueDate>' . $due . 'T00:00:00Z</t:DueDate></t:Flag>', $this->lastRequest());
	}

	public function testOverdueStartsOnItsDueDate(): void {
		$this->queue(self::ok('UpdateItem'));
		$this->client()->setFlagged(['M1'], true, '2026-01-05');
		$this->assertStringContainsString('<t:StartDate>2026-01-05T00:00:00Z</t:StartDate><t:DueDate>2026-01-05T00:00:00Z</t:DueDate>', $this->lastRequest());
	}

	public function testWithoutADateAndCleared(): void {
		$this->queue(self::ok('UpdateItem'));
		$this->queue(self::ok('UpdateItem'));
		$c = $this->client();
		$c->setFlagged(['M1'], true, '');
		$this->assertStringContainsString('<t:Flag><t:FlagStatus>Flagged</t:FlagStatus></t:Flag>', $this->lastRequest());
		// a date is no use without the flag
		$c->setFlagged(['M1'], false, '2026-12-01');
		$this->assertStringContainsString('<t:Flag><t:FlagStatus>NotFlagged</t:FlagStatus></t:Flag>', $this->lastRequest());
	}

	public function testBadDate(): void {
		$this->expectException(MailException::class);
		$this->client()->setFlagged(['M1'], true, '2026-02-30');
	}

	public function testDueDateRead(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder IndexedPagingOffset="2" TotalItemsInView="2" IncludesLastItemInRange="true"><t:Items>'
			. '<t:Message><t:ItemId Id="M1" ChangeKey="CK1"/><t:Subject>A</t:Subject><t:Flag><t:FlagStatus>Flagged</t:FlagStatus>'
			. '<t:StartDate>2026-11-13T00:00:00Z</t:StartDate><t:DueDate>2026-11-14T00:00:00Z</t:DueDate></t:Flag></t:Message>'
			. '<t:Message><t:ItemId Id="M2" ChangeKey="CK2"/><t:Subject>B</t:Subject><t:Flag><t:FlagStatus>Complete</t:FlagStatus>'
			. '<t:StartDate>2026-11-13T00:00:00Z</t:StartDate><t:DueDate>2026-11-14T00:00:00Z</t:DueDate></t:Flag></t:Message>'
			. '</t:Items></m:RootFolder>'));
		$items = $this->client()->findItems('inbox', 0, 50)['items'];
		$this->assertSame('2026-11-14', $items[0]['flagDue']);
		$this->assertTrue($items[0]['flagged']);
		$this->assertNull($items[1]['flagDue']);
	}
}
