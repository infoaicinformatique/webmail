<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\MailNotificationService;
use OCA\Webmail\Service\Matrix;
use PHPUnit\Framework\TestCase;

class MatrixTest extends TestCase {
	public function testHomeserver(): void {
		$this->assertSame('https://matrix.example.org', Matrix::homeserver(' matrix.example.org '));
		$this->assertSame('https://matrix.example.org:8448', Matrix::homeserver('https://Matrix.Example.org:8448/'));
		foreach (['http://matrix.example.org', 'https://matrix.example.org/_matrix', 'https://u:p@matrix.example.org', 'https://matrix.example.org?x=1', ''] as $bad) {
			try {
				Matrix::homeserver($bad);
				$this->fail($bad);
			} catch (MailException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testRoom(): void {
		$this->assertSame(['type' => 'alias', 'value' => '#equipe:example.org'], Matrix::room(' #equipe:example.org '));
		$this->assertSame(['type' => 'id', 'value' => '!AbC123:example.org'], Matrix::room('!AbC123:example.org'));
		$this->assertSame(['type' => 'alias', 'value' => '#chantier-antibes:matrix.example.org'], Matrix::room('https://matrix.to/#/%23chantier-antibes:matrix.example.org'));
		$this->assertSame('#equipe:example.org', Matrix::room('https://matrix.to/#/#equipe:example.org?via=example.org')['value']);
		foreach (['equipe', '@alice:example.org', '#equipe', '#a b:example.org', '!x:'] as $bad) {
			try {
				Matrix::room($bad);
				$this->fail($bad);
			} catch (MailException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testMessage(): void {
		$m = Matrix::message("  ✉️ Devis <urgent>\r\nVoir https://cloud.example.org/x?a=1&b=2  ");
		$this->assertSame('m.text', $m['msgtype']);
		$this->assertSame("✉️ Devis <urgent>\nVoir https://cloud.example.org/x?a=1&b=2", $m['body']);
		$this->assertSame('org.matrix.custom.html', $m['format']);
		// nothing of the text is taken as HTML; the link is clickable
		$this->assertSame('✉️ Devis &lt;urgent&gt;<br>' . "\n" . 'Voir <a href="https://cloud.example.org/x?a=1&amp;b=2">https://cloud.example.org/x?a=1&amp;b=2</a>', $m['formatted_body']);
		$this->assertSame(Matrix::MAX_TEXT, mb_strlen(Matrix::message(str_repeat('é', Matrix::MAX_TEXT + 50))['body']));
		$this->expectException(MailException::class);
		Matrix::message("  \n ");
	}

	public function testTxnId(): void {
		$a = Matrix::txnId('alice', '!r:x', 'Bonjour', 100);
		$this->assertSame($a, Matrix::txnId('alice', '!r:x', 'Bonjour', 100));
		$this->assertNotSame($a, Matrix::txnId('alice', '!r:x', 'Bonjour', 101));
		$this->assertMatchesRegularExpression('/^webmail-[0-9a-f]{24}$/', $a);
	}

	public function testAlertsWanted(): void {
		$high = ['importance' => 'High'];
		$normal = ['importance' => 'Normal'];
		$this->assertTrue(MailNotificationService::matrixWanted('all', $normal));
		$this->assertTrue(MailNotificationService::matrixWanted('important', $high));
		$this->assertFalse(MailNotificationService::matrixWanted('important', $normal));
		$this->assertFalse(MailNotificationService::matrixWanted('important', []));
		$this->assertFalse(MailNotificationService::matrixWanted('off', $high));
	}

	public function testMeetingRoom(): void {
		$r = Matrix::meetingRoom("  Réunion   de chantier\n", 'lundi 16 novembre 2026 à 10:00');
		$this->assertSame('Réunion de chantier', $r['name']);
		$this->assertSame('lundi 16 novembre 2026 à 10:00', $r['topic']);
		// joined by its link by the accounts of the server, not listed, not open to other servers
		$this->assertSame('public_chat', $r['preset']);
		$this->assertSame('private', $r['visibility']);
		$this->assertFalse($r['creation_content']['m.federate']);
		// a video room of Element: everyone may join its call
		$this->assertSame('org.matrix.msc3417.call', $r['creation_content']['type']);
		$this->assertSame(0, $r['power_level_content_override']['events']['org.matrix.msc3401.call.member']);
		$this->assertSame('Réunion', Matrix::meetingRoom('')['name']);
		$this->assertArrayNotHasKey('topic', Matrix::meetingRoom('x'));
	}

	public function testRoomLink(): void {
		$this->assertSame('https://chat.example.org/#/room/%21abc%3Amatrix.example.org?via=matrix.example.org',
			Matrix::roomLink('https://chat.example.org/', '!abc:matrix.example.org'));
		$this->expectException(MailException::class);
		Matrix::roomLink('https://chat.example.org', '#alias:example.org');
	}

	public function testElementOfTheExternalSites(): void {
		$sites = json_encode([
			'1' => ['id' => 1, 'name' => 'Chat', 'url' => 'https://chat.example.org/'],
			'2' => ['id' => 2, 'name' => 'Intranet', 'url' => 'http://intranet.local'],
			'3' => ['id' => 3, 'name' => 'Wiki', 'url' => 'javascript:alert(1)'],
		]);
		$this->assertSame([['name' => 'Chat', 'url' => 'https://chat.example.org']], Matrix::externalSites((string)$sites));
		$this->assertSame([], Matrix::externalSites(''));
		$this->assertSame(['homeserver' => 'https://matrix.example.org', 'serverName' => 'matrix.example.org'],
			Matrix::elementHomeserver(['default_server_config' => ['m.homeserver' => ['base_url' => 'https://matrix.example.org/', 'server_name' => 'matrix.example.org']]]));
		// not an Element Web
		$this->assertNull(Matrix::elementHomeserver(['brand' => 'x']));
		$this->assertNull(Matrix::elementHomeserver(['default_server_config' => ['m.homeserver' => ['base_url' => 'http://matrix.example.org']]]));
	}
}
