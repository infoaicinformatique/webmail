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
		// room version 12 (recent Synapse): no server in the id
		$this->assertSame(['type' => 'id', 'value' => '!Zk3xQ9_dHf-2Lp8wVb5nRt7yMc1sGa4eJo6uKi0hXqE'], Matrix::room('!Zk3xQ9_dHf-2Lp8wVb5nRt7yMc1sGa4eJo6uKi0hXqE'));
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
		// room version 12: joined by the server of the organiser's account
		$this->assertSame('https://chat.example.org/#/room/%21Zk3xQ9_dHf-2Lp8wVb5nRt7yMc1sGa4eJo6uKi0hXqE?via=matrix.example.org',
			Matrix::roomLink('https://chat.example.org', '!Zk3xQ9_dHf-2Lp8wVb5nRt7yMc1sGa4eJo6uKi0hXqE', 'matrix.example.org'));
		$this->assertSame('https://chat.example.org/#/room/%21abc%3Amatrix.example.org?via=matrix.example.org',
			Matrix::roomLink('https://chat.example.org', '!abc:matrix.example.org', 'other.example.org'), 'the server of the id first');
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
		// Element Web declared on one of its pages
		$this->assertSame([['name' => 'Chat', 'url' => 'https://chat.example.org'], ['name' => 'Element', 'url' => 'https://element.example.org/web']],
			Matrix::externalSites((string)json_encode([
				['name' => 'Chat', 'url' => 'https://chat.example.org/#/home'],
				['name' => 'Element', 'url' => 'https://element.example.org/web/?lang=fr#/welcome'],
			])));
		$this->assertSame([], Matrix::externalSites(''));
		$this->assertSame(['homeserver' => 'https://matrix.example.org', 'serverName' => 'matrix.example.org'],
			Matrix::elementHomeserver(['default_server_config' => ['m.homeserver' => ['base_url' => 'https://matrix.example.org/', 'server_name' => 'matrix.example.org']]]));
		// not an Element Web
		$this->assertNull(Matrix::elementHomeserver(['brand' => 'x']));
		$this->assertNull(Matrix::elementHomeserver(['default_server_config' => ['m.homeserver' => ['base_url' => 'http://matrix.example.org']]]));
	}

	public function testRoomOfAMeeting(): void {
		$link = 'https://chat.pbcannes.org/#/room/%21meet42%3Amatrix.pbcannes.org?via=matrix.pbcannes.org';
		$this->assertSame('!meet42:matrix.pbcannes.org', Matrix::roomIdIn('Salle B ' . $link));
		$this->assertSame('!meet42:matrix.pbcannes.org', Matrix::roomIdIn("Rejoindre la visio : " . $link . "\n"));
		$this->assertSame('!abc:fake.test', Matrix::roomIdIn('salon !abc:fake.test'));
		$this->assertSame('!Zk3xQ9_dHf-2Lp8wVb5nRt7yMc1sGa4eJo6uKi0hXqE',
			Matrix::roomIdIn('Visio : https://chat.pbcannes.org/#/room/%21Zk3xQ9_dHf-2Lp8wVb5nRt7yMc1sGa4eJo6uKi0hXqE?via=matrix.pbcannes.org'));
		$this->assertNull(Matrix::roomIdIn('https://meet.example.org/reunion-k3x9'));
		$this->assertNull(Matrix::roomIdIn('Bonjour ! à demain'));
		$this->assertNull(Matrix::roomIdIn('https://chat.example/#/room/%23general%3Aserver'));
	}

	public function testMatrixIdsOfAnAccount(): void {
		$this->assertSame(['@nicolas:matrix.pbcannes.org', '@n.martin:matrix.pbcannes.org'], Matrix::candidates('Nicolas', 'n.martin@pbcannes.org', 'matrix.pbcannes.org'));
		// the same: once
		$this->assertSame(['@p.nom:srv'], Matrix::candidates('p.nom', 'P.Nom@pbcannes.org', 'srv'));
		// an LDAP id (UUID) is a valid Matrix localpart too; what is not, is left out
		$this->assertSame(['@3f2a-11:srv', '@marie:srv'], Matrix::candidates('3F2A-11', 'marie@x.fr', 'srv'));
		$this->assertSame(['@marie:srv'], Matrix::candidates('Marie Curie', 'marie@x.fr', 'srv'));
	}
}
