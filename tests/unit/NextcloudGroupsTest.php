<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\NextcloudGroups;
use PHPUnit\Framework\TestCase;

class NextcloudGroupsTest extends TestCase {
	public function testTheMembersWithAnAddressOnceEachWithoutTheUser(): void {
		$r = NextcloudGroups::addresses([
			['uid' => 'paul', 'name' => 'Paul Durand', 'email' => 'Paul.Durand@societe-a.fr'],
			['uid' => 'me', 'name' => 'Moi', 'email' => 'moi@societe-a.fr'],
			['uid' => 'anne', 'name' => 'Anne Martin', 'email' => 'anne@societe-a.fr'],
			['uid' => 'jo', 'name' => 'Jo', 'email' => ''],
			['uid' => 'bad', 'name' => 'Bad', 'email' => 'pas-une-adresse'],
			['uid' => 'paul2', 'name' => 'Paul (2e compte)', 'email' => 'paul.durand@societe-a.fr'],
			['uid' => 'zed', 'name' => '', 'email' => 'zed@societe-a.fr'],
		], 'me');
		$this->assertSame([
			['name' => 'Anne Martin', 'email' => 'anne@societe-a.fr'],
			['name' => 'Paul Durand', 'email' => 'paul.durand@societe-a.fr'],
			['name' => 'zed@societe-a.fr', 'email' => 'zed@societe-a.fr'],
		], $r['addresses']);
		$this->assertSame(2, $r['without']);
	}

	public function testAGroupFoundByItsIdOrItsName(): void {
		$this->assertTrue(NextcloudGroups::matches('compta', 'Comptabilité', 'COMPTA'));
		$this->assertTrue(NextcloudGroups::matches('g-42', 'Équipe chantier', 'chantier'));
		$this->assertFalse(NextcloudGroups::matches('g-42', 'Équipe chantier', 'direction'));
	}
}
