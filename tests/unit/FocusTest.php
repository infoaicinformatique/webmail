<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\Focus;
use PHPUnit\Framework\TestCase;

class FocusTest extends TestCase {
	public function testAutomated(): void {
		foreach (['noreply@x.fr', 'no-reply@x.fr', 'NoReply+abc@x.fr', 'donotreply@x.fr', 'ne-pas-repondre@x.fr', 'notifications@github.com', 'newsletter@shop.fr', 'MAILER-DAEMON@x.fr', 'bounce-123@x.fr', 'marketing@x.fr'] as $e) {
			$this->assertTrue(Focus::automated($e), $e);
		}
		foreach (['alice@x.fr', 'info@x.fr', 'noreplyjohn@x.fr', 'jean.newsletter@x.fr', '', null] as $e) {
			$this->assertFalse(Focus::automated($e), (string)$e);
		}
	}

	public function testDecode(): void {
		$this->assertSame(['priority' => [], 'other' => []], Focus::decode('nope'));
		$o = Focus::decode('{"priority":["A@x.fr","bad"],"other":["a@x.fr","n@x.fr"]}');
		$this->assertSame(['priority' => ['a@x.fr'], 'other' => ['n@x.fr']], $o);
	}

	public function testSet(): void {
		$o = Focus::set(['priority' => [], 'other' => []], 'N@x.fr', 'other');
		$this->assertSame(['n@x.fr'], $o['other']);
		$o = Focus::set($o, 'n@x.fr', 'priority');
		$this->assertSame(['priority' => ['n@x.fr'], 'other' => []], $o);
		$o = Focus::set($o, 'n@x.fr', '');
		$this->assertSame(['priority' => [], 'other' => []], $o);
	}

	public function testSetRejects(): void {
		$this->expectException(\InvalidArgumentException::class);
		Focus::set(['priority' => [], 'other' => []], 'nope', 'other');
	}

	public function testSetRejectsTab(): void {
		$this->expectException(\InvalidArgumentException::class);
		Focus::set(['priority' => [], 'other' => []], 'a@x.fr', 'spam');
	}

	public function testIsOther(): void {
		$none = ['priority' => [], 'other' => []];
		$person = ['from' => ['email' => 'alice@x.fr']];
		$list = ['from' => ['email' => 'shop@x.fr'], 'isList' => true];
		$robot = ['from' => ['email' => 'noreply@x.fr']];
		$this->assertFalse(Focus::isOther($person, [], $none));
		$this->assertTrue(Focus::isOther($list, [], $none));
		$this->assertTrue(Focus::isOther($robot, [], $none));
		// a priority sender stays in « Prioritaires »
		$this->assertFalse(Focus::isOther($list, ['shop@x.fr'], $none));
		// the user's choice comes first
		$this->assertFalse(Focus::isOther($robot, [], ['priority' => ['noreply@x.fr'], 'other' => []]));
		$this->assertTrue(Focus::isOther($person, ['alice@x.fr'], ['priority' => [], 'other' => ['alice@x.fr']]));
		$this->assertFalse(Focus::isOther([], [], $none));
	}
}
