<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsTasks;
use OCA\Webmail\Exception\MailException;

class TaskReminderTest extends EwsTestCase {
	public function testReadWrittenRemoved(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="2" IncludesLastItemInRange="true"><t:Items>'
			. '<t:Task><t:ItemId Id="T1" ChangeKey="CK"/><t:Subject>Relancer</t:Subject><t:ReminderDueBy>2026-11-20T09:00:00+01:00</t:ReminderDueBy><t:ReminderIsSet>true</t:ReminderIsSet><t:Status>NotStarted</t:Status></t:Task>'
			. '<t:Task><t:ItemId Id="T2" ChangeKey="CK"/><t:Subject>Sans</t:Subject><t:ReminderDueBy>2026-11-20T09:00:00Z</t:ReminderDueBy><t:ReminderIsSet>false</t:ReminderIsSet><t:Status>NotStarted</t:Status></t:Task>'
			. '</t:Items></m:RootFolder>'));
		$this->queue(self::ok('CreateItem', '<m:Items><t:Task><t:ItemId Id="T3" ChangeKey="CK"/></t:Task></m:Items>'));
		$this->queue(self::ok('UpdateItem', '<m:Items><t:Task><t:ItemId Id="T1" ChangeKey="CK"/></t:Task></m:Items>'));
		$this->queue(self::ok('UpdateItem', '<m:Items><t:Task><t:ItemId Id="T1" ChangeKey="CK"/></t:Task></m:Items>'));
		$t = new EwsTasks($this->client());
		$list = $t->list();
		$this->assertSame(['T1' => '2026-11-20T08:00:00Z', 'T2' => null], array_column($list, 'reminder', 'id'));
		$this->assertStringContainsString('<t:FieldURI FieldURI="item:ReminderIsSet"/><t:FieldURI FieldURI="item:ReminderDueBy"/>', $this->lastRequest());

		// schema order: after Importance, before DueDate
		$t->create(['subject' => 'Devis', 'due' => '2026-11-20', 'reminder' => '2026-11-19T09:00:00+01:00']);
		$this->assertStringContainsString('</t:Importance><t:ReminderDueBy>2026-11-19T08:00:00Z</t:ReminderDueBy><t:ReminderIsSet>true</t:ReminderIsSet><t:DueDate>', $this->lastRequest());
		$t->update('T1', ['subject' => 'Relancer', 'reminder' => '2026-11-21T07:30:00.000Z']);
		$this->assertStringContainsString('<t:SetItemField><t:FieldURI FieldURI="item:ReminderDueBy"/><t:Task><t:ReminderDueBy>2026-11-21T07:30:00Z</t:ReminderDueBy></t:Task></t:SetItemField>'
			. '<t:SetItemField><t:FieldURI FieldURI="item:ReminderIsSet"/><t:Task><t:ReminderIsSet>true</t:ReminderIsSet></t:Task></t:SetItemField>', $this->lastRequest());
		$t->update('T1', ['subject' => 'Relancer', 'reminder' => '']);
		$this->assertStringContainsString('<t:SetItemField><t:FieldURI FieldURI="item:ReminderIsSet"/><t:Task><t:ReminderIsSet>false</t:ReminderIsSet></t:Task></t:SetItemField>', $this->lastRequest());
		$this->assertStringNotContainsString('ReminderDueBy', $this->lastRequest());
	}

	public function testBadTime(): void {
		$this->expectException(MailException::class);
		EwsTasks::normalize(['subject' => 'X', 'reminder' => 'demain 9h']);
	}

	public function testDue(): void {
		$now = new \DateTimeImmutable('2026-11-20T08:00:00Z');
		$tasks = [
			['id' => 'past', 'reminder' => '2026-11-20T07:59:00Z', 'done' => false],
			['id' => 'now', 'reminder' => '2026-11-20T08:00:00Z', 'done' => false],
			['id' => 'later', 'reminder' => '2026-11-20T08:01:00Z', 'done' => false],
			['id' => 'done', 'reminder' => '2026-11-20T07:00:00Z', 'done' => true],
			['id' => 'none', 'reminder' => null, 'done' => false],
			['id' => 'too old', 'reminder' => '2026-11-10T08:00:00Z', 'done' => false],
		];
		$this->assertSame(['past', 'now'], array_column(EwsTasks::dueReminders($tasks, $now), 'id'));
	}
}
