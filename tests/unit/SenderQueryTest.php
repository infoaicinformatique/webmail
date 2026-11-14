<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Exception\MailException;
use PHPUnit\Framework\TestCase;

class SenderQueryTest extends TestCase {
	public function testTheSearchOfOneAddress(): void {
		$this->assertSame('from:"news@exemple.org"', EwsClient::fromQuery(' News@Exemple.org '));
	}

	/** @dataProvider invalid */
	public function testNothingElseIsSearched(string $email): void {
		$this->expectException(MailException::class);
		EwsClient::fromQuery($email);
	}

	public static function invalid(): array {
		return [[''], ['pas une adresse'], ['a@b.fr" OR subject:x'], ['/O=EXCHANGE/OU=X']];
	}
}
