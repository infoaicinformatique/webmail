<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\SenderLists;
use PHPUnit\Framework\TestCase;

class SenderListsTest extends TestCase {
	public function testEntriesAreNormalized(): void {
		$this->assertSame('jean@x.fr', SenderLists::normalize(' Jean Dupont <Jean@X.fr> '));
		$this->assertSame('@exemple-spam.com', SenderLists::normalize('exemple-spam.com'));
		$this->assertSame('@exemple-spam.com', SenderLists::normalize('*@Exemple-Spam.com'));
		$this->assertNull(SenderLists::normalize('pas une adresse'));
		$this->assertNull(SenderLists::normalize('@x'));
		$this->assertSame(['a@b.fr', '@c.fr'], SenderLists::clean(['A@b.fr', 'a@b.fr', '@C.fr', '???']));
	}

	public function testMatching(): void {
		$entries = ['boss@client.fr', '@spam.com'];
		$this->assertTrue(SenderLists::matches('Boss@Client.fr', $entries));
		$this->assertFalse(SenderLists::matches('autre@client.fr', $entries));
		$this->assertTrue(SenderLists::matches('x@spam.com', $entries));
		$this->assertTrue(SenderLists::matches('x@mail.spam.com', $entries), 'a sub-domain');
		$this->assertFalse(SenderLists::matches('x@notspam.com', $entries));
		$this->assertFalse(SenderLists::matches('', $entries));
	}
}
