<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\SignatureSet;
use PHPUnit\Framework\TestCase;

class SignatureSetTest extends TestCase {
	public function testNamedSignaturesAndDefaults(): void {
		$set = SignatureSet::normalize([
			['name' => '  Courte ', 'html' => 'JD'],
			['name' => '', 'html' => '<br>'],
			['name' => 'Anglais', 'html' => 'Best regards'],
		], 'courte', 'Disparue', fn ($h) => $h);
		$this->assertSame([['name' => 'Courte', 'html' => 'JD'], ['name' => 'Anglais', 'html' => 'Best regards']], $set['list']);
		$this->assertSame('Courte', $set['new']);
		$this->assertSame('', $set['reply'], 'a signature gone: the main one');
		$this->assertSame('-', SignatureSet::normalize([], '-', '', fn ($h) => $h)['new']);
	}

	/** @dataProvider wrong */
	public function testRefused(array $list): void {
		$this->expectException(MailException::class);
		SignatureSet::normalize($list, '', '', fn ($h) => $h);
	}

	public static function wrong(): array {
		return [
			[[['name' => '', 'html' => 'Sans nom']]],
			[[['name' => 'A', 'html' => 'x'], ['name' => 'a', 'html' => 'y']]],
			[[['name' => str_repeat('x', 41), 'html' => 'x']]],
			[array_map(fn ($i) => ['name' => 'S' . $i, 'html' => 'x'], range(1, 11))],
		];
	}

	public function testDecodeIsForgiving(): void {
		$this->assertSame(['list' => [], 'new' => '', 'reply' => ''], SignatureSet::decode('oops'));
		$this->assertSame('Courte', SignatureSet::decode('{"list":[{"name":"Courte","html":"JD"}],"new":"Courte"}')['new']);
	}
}
