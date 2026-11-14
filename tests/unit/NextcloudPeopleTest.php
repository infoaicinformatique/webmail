<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\NextcloudPeople;
use PHPUnit\Framework\TestCase;

class NextcloudPeopleTest extends TestCase {
	public function testPeople(): void {
		$found = [
			['FN' => 'Sophie Nextcloud', 'EMAIL' => ['Sophie@NC.example'], 'isLocalSystemBook' => true],
			['FN' => 'Claire', 'EMAIL' => [['type' => 'WORK', 'value' => 'claire@client.fr'], 'claire.perso@mail.example', 'pas-une-adresse']],
			['FN' => '', 'EMAIL' => 'seul@exemple.org'],
			['FN' => 'Doublon', 'EMAIL' => ['sophie@nc.example']],
			['FN' => 'Sans adresse'],
		];
		$this->assertSame([
			['name' => 'Sophie Nextcloud', 'email' => 'sophie@nc.example', 'source' => 'nextcloud-user'],
			['name' => 'Claire', 'email' => 'claire@client.fr', 'source' => 'nextcloud'],
			['name' => 'Claire', 'email' => 'claire.perso@mail.example', 'source' => 'nextcloud'],
			['name' => 'seul@exemple.org', 'email' => 'seul@exemple.org', 'source' => 'nextcloud'],
		], NextcloudPeople::people($found));
	}

	public function testMergeExchangeFirst(): void {
		$exchange = [['name' => 'Claire Martin', 'email' => 'Claire@Client.fr', 'source' => 'contacts']];
		$nc = [['name' => 'Claire', 'email' => 'claire@client.fr', 'source' => 'nextcloud'], ['name' => 'Sophie', 'email' => 'sophie@nc.example', 'source' => 'nextcloud-user']];
		$this->assertSame(['contacts', 'nextcloud-user'], array_column(NextcloudPeople::merge($exchange, $nc), 'source'));
	}
}
