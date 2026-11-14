<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsTasks;
use OCA\Webmail\Exception\MailException;

class EwsTasksTest extends EwsTestCase {
	private static function task(string $id, string $subject, ?string $due, string $status = 'NotStarted', string $importance = 'Normal', ?string $done = null): string {
		return '<t:Task><t:ItemId Id="' . $id . '" ChangeKey="CK"/><t:Subject>' . $subject . '</t:Subject><t:Importance>' . $importance . '</t:Importance>'
			. ($due ? '<t:DueDate>' . $due . '</t:DueDate>' : '') . ($done ? '<t:CompleteDate>' . $done . '</t:CompleteDate>' : '')
			. '<t:PercentComplete>' . ($status === 'Completed' ? '100' : '0') . '</t:PercentComplete><t:Status>' . $status . '</t:Status></t:Task>';
	}

	public function testListToDoFirstByDueDate(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="4" IncludesLastItemInRange="true"><t:Items>'
			. self::task('T1', 'Sans échéance', null)
			. self::task('T2', 'Relancer le client', '2026-11-19T23:00:00Z', 'InProgress', 'High')
			. self::task('T3', 'Faire le devis', '2026-11-16T23:00:00Z')
			. self::task('T4', 'Ancienne', '2026-11-01T23:00:00Z', 'Completed', 'Normal', '2026-11-02T10:00:00Z')
			. '</t:Items></m:RootFolder>'));
		$tasks = (new EwsTasks($this->client()))->list(true);
		$this->assertSame(['T3', 'T2', 'T1', 'T4'], array_column($tasks, 'id'));
		$this->assertSame(['2026-11-17', '2026-11-20', null, '2026-11-02'], array_column($tasks, 'due'));
		$this->assertTrue($tasks[3]['done']);
		$this->assertStringNotContainsString('<m:Restriction>', $this->lastRequest());

		// to do only: the completed ones left out here, not by a Restriction (refused by OVH)
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="2" IncludesLastItemInRange="true"><t:Items>'
			. self::task('T3', 'Faire le devis', '2026-11-16T23:00:00Z')
			. self::task('T4', 'Ancienne', '2026-11-01T23:00:00Z', 'Completed', 'Normal', '2026-11-02T10:00:00Z')
			. '</t:Items></m:RootFolder>'));
		$this->assertSame(['T3'], array_column((new EwsTasks($this->client()))->list(), 'id'));
		$this->assertStringNotContainsString('<m:Restriction>', $this->lastRequest());
	}

	public function testListAsksTheNextPages(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="501" IncludesLastItemInRange="false"><t:Items>'
			. self::task('T1', 'Une', '2026-11-16T23:00:00Z') . '</t:Items></m:RootFolder>'));
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="501" IncludesLastItemInRange="true"><t:Items>'
			. self::task('T2', 'Deux', '2026-11-17T23:00:00Z') . '</t:Items></m:RootFolder>'));
		$this->assertSame(['T1', 'T2'], array_column((new EwsTasks($this->client()))->list(), 'id'));
		$this->assertStringContainsString('Offset="500"', $this->lastRequest());
	}

	public function testCreate(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:Task><t:ItemId Id="NEW" ChangeKey="CK"/></t:Task></m:Items>'));
		$id = (new EwsTasks($this->client()))->create(['subject' => 'Appeler <Dupont>', 'notes' => "Devis\nà relancer", 'due' => '2026-11-20', 'importance' => 'High']);
		$this->assertSame('NEW', $id);
		$this->assertStringContainsString('<m:SavedItemFolderId><t:DistinguishedFolderId Id="tasks"/></m:SavedItemFolderId><m:Items><t:Task>'
			. '<t:Subject>Appeler &lt;Dupont&gt;</t:Subject><t:Body BodyType="Text">Devis' . "\n" . 'à relancer</t:Body><t:Importance>High</t:Importance>'
			. '<t:DueDate>2026-11-20T12:00:00Z</t:DueDate><t:Status>NotStarted</t:Status></t:Task>', $this->lastRequest());
	}

	public function testUpdateCompleteDelete(): void {
		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		(new EwsTasks($this->client()))->update('T1', ['subject' => 'x', 'due' => '', 'status' => 'WaitingOnOthers']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:DeleteItemField><t:FieldURI FieldURI="task:DueDate"/></t:DeleteItemField>', $req);
		$this->assertStringContainsString('<t:Status>WaitingOnOthers</t:Status>', $req);

		$this->queue(self::ok('UpdateItem', '<m:Items/>'));
		(new EwsTasks($this->client()))->complete('T1', true);
		$this->assertStringContainsString('<t:PercentComplete>100</t:PercentComplete></t:Task></t:SetItemField><t:SetItemField><t:FieldURI FieldURI="task:Status"/><t:Task><t:Status>Completed</t:Status>', $this->lastRequest());

		$this->queue(self::ok('DeleteItem'));
		(new EwsTasks($this->client()))->delete('T1');
		$this->assertStringContainsString('<m:DeleteItem DeleteType="MoveToDeletedItems" AffectedTaskOccurrences="AllOccurrences">', $this->lastRequest());
	}

	public function testDueDays(): void {
		$this->assertSame('2026-11-20', EwsTasks::day('2026-11-19T23:00:00Z')); // Paris, winter
		$this->assertSame('2026-07-20', EwsTasks::day('2026-07-19T22:00:00Z')); // Paris, summer
		$this->assertSame('2026-11-20', EwsTasks::day('2026-11-20T12:00:00Z')); // set by the webmail
		$this->assertSame('2026-11-20', EwsTasks::day('2026-11-20T05:00:00Z')); // New York
	}

	public function testValidation(): void {
		foreach ([[['subject' => ' '], 'titre'], [['subject' => 'x', 'due' => '20/11/2026'], 'Échéance'], [['subject' => 'x', 'status' => 'Done'], 'invalide']] as [$data, $msg]) {
			try {
				EwsTasks::normalize($data);
				$this->fail('accepted');
			} catch (MailException $e) {
				$this->assertStringContainsString($msg, $e->getMessage());
			}
		}
	}
}
