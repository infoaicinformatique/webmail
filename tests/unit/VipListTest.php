<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\MailNotificationService;
use OCA\Webmail\Service\VipList;
use PHPUnit\Framework\TestCase;

class VipListTest extends TestCase {
	public function testTheList(): void {
		$list = VipList::set([], ' Boss@Client.fr ', true);
		$list = VipList::set($list, 'marie@x.fr', true);
		$list = VipList::set($list, 'boss@client.fr', true);
		$this->assertSame(['marie@x.fr', 'boss@client.fr'], $list, 'each once');
		$this->assertTrue(VipList::has($list, ['name' => 'Le patron', 'email' => 'BOSS@client.fr']));
		$this->assertFalse(VipList::has($list, ['email' => 'autre@client.fr']));
		$this->assertFalse(VipList::has($list, null));
		$this->assertSame(['marie@x.fr'], VipList::set($list, 'boss@client.fr', false));
		$this->assertSame(['a@b.fr'], VipList::decode('["A@b.fr", "nope", 3, "a@b.fr"]'));
		$this->expectException(\InvalidArgumentException::class);
		VipList::set($list, 'pas une adresse', true);
	}

	public function testThePriorityNotificationStandsOut(): void {
		[$subject] = MailNotificationService::texts(['from' => 'Le patron', 'subject' => 'Urgent', 'email' => 'moi@x.fr', 'vip' => true], '');
		$this->assertStringStartsWith('⭐ ', $subject);
		[$subject] = MailNotificationService::texts(['from' => 'Paul', 'subject' => 'Info', 'email' => 'moi@x.fr'], '');
		$this->assertStringStartsNotWith('⭐', $subject);
	}
}
