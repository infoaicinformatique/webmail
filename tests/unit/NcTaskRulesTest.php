<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\NcTaskRules;
use PHPUnit\Framework\TestCase;

/** Tasks between Exchange and the Tasks app of Nextcloud: the rules without a server. */
class NcTaskRulesTest extends TestCase {
	public function testWordsOfEachSide(): void {
		$this->assertSame('High', NcTaskRules::importance(1));
		$this->assertSame('High', NcTaskRules::importance(4));
		$this->assertSame('Normal', NcTaskRules::importance(5));
		$this->assertSame('Normal', NcTaskRules::importance(0));
		$this->assertSame('Low', NcTaskRules::importance(9));
		foreach (['High', 'Normal', 'Low'] as $i) {
			$this->assertSame($i, NcTaskRules::importance(NcTaskRules::priority($i)));
		}
		$this->assertSame('Completed', NcTaskRules::status('COMPLETED'));
		$this->assertSame('InProgress', NcTaskRules::status('IN-PROCESS'));
		$this->assertSame('Deferred', NcTaskRules::status('CANCELLED'));
		$this->assertSame('NotStarted', NcTaskRules::status(''));
		$this->assertSame('InProgress', NcTaskRules::status('', 40));
		$this->assertSame('Completed', NcTaskRules::status('NEEDS-ACTION', 100));
		$this->assertSame('IN-PROCESS', NcTaskRules::vtodoStatus('WaitingOnOthers'));
		$this->assertSame('NEEDS-ACTION', NcTaskRules::vtodoStatus('NotStarted'));
		$this->assertSame('COMPLETED', NcTaskRules::vtodoStatus('Completed'));
	}

	public function testExchangeTasksForTheTasksApp(): void {
		$ics = NcTaskRules::calendar([
			['id' => 'AAMk1', 'subject' => 'Relancer le cabinet; comptable, vite', 'due' => '2026-11-20', 'status' => 'InProgress', 'percent' => 50, 'importance' => 'High', 'categories' => ['Client', 'Urgent']],
			['id' => 'AAMk2', 'subject' => 'Vœux', 'due' => null, 'status' => 'Completed', 'percent' => 100, 'importance' => 'Normal', 'completed' => '2026-11-02T10:00:00Z', 'categories' => []],
			['id' => '', 'subject' => 'sans id'],
		], 'https://cloud.example/apps/webmail/?account=1&module=tasks&task=', new \DateTimeImmutable('2026-11-15T08:00:00Z'));
		$unfolded = str_replace("\r\n ", '', $ics);
		$this->assertSame(2, substr_count($ics, 'BEGIN:VTODO'));
		$this->assertStringContainsString('UID:' . NcTaskRules::uid('AAMk1') . "\r\n", $ics);
		$this->assertStringContainsString("SUMMARY:Relancer le cabinet\; comptable\\, vite\r\n", $unfolded);
		$this->assertStringContainsString("DUE;VALUE=DATE:20261120\r\n", $ics);
		$this->assertStringContainsString("STATUS:IN-PROCESS\r\nPERCENT-COMPLETE:50\r\nPRIORITY:1\r\nCATEGORIES:Client,Urgent\r\n", $unfolded);
		$this->assertStringContainsString("STATUS:COMPLETED\r\nPERCENT-COMPLETE:100\r\nCOMPLETED:20261102T100000Z\r\n", $unfolded);
		$this->assertStringContainsString('URL:https://cloud.example/apps/webmail/?account=1&module=tasks&task=AAMk1', $unfolded);
		$this->assertStringContainsString("DTSTAMP:20261115T080000Z\r\n", $ics);
		foreach (explode("\r\n", $ics) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line));
		}
		$this->assertSame(NcTaskRules::uid('AAMk1'), NcTaskRules::uid('AAMk1'));
		$this->assertNotSame(NcTaskRules::uid('AAMk1'), NcTaskRules::uid('AAMk2'));
	}
}
