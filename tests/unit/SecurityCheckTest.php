<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\HtmlSanitizer;
use OCA\Webmail\Service\SecurityCheck;
use PHPUnit\Framework\TestCase;

class SecurityCheckTest extends TestCase {
	private const OWN = ['societe-a.fr', 'societe-b.fr'];

	public function testExternalSenders(): void {
		$this->assertFalse(SecurityCheck::isExternal('paul.durand@societe-a.fr', self::OWN));
		$this->assertFalse(SecurityCheck::isExternal('Paul@MAIL.Societe-A.fr', self::OWN), 'a subdomain of the organisation');
		$this->assertTrue(SecurityCheck::isExternal('paul.durand@gmail.com', self::OWN));
		$this->assertTrue(SecurityCheck::isExternal('x@societe-a.fr.evil.com', self::OWN), 'not a subdomain');
		$this->assertTrue(SecurityCheck::isExternal('x@notsociete-a.fr', self::OWN));
		$this->assertFalse(SecurityCheck::isExternal('paul@gmail.com', []), 'no domain known: nothing said');
	}

	public function testNamesAsPeopleReadThem(): void {
		$this->assertSame('helene durand', SecurityCheck::normalizeName(' "Hélène  DURAND" '));
		$this->assertSame(SecurityCheck::normalizeName('Hélène Durand'), SecurityCheck::normalizeName('helene durand (Direction)'));
	}

	public function testAnAddressOfTheOrganisationInTheName(): void {
		$this->assertSame('paul.durand@societe-a.fr', SecurityCheck::addressInName('paul.durand@societe-a.fr', 'urgent.pay@gmail.com', self::OWN));
		$this->assertNull(SecurityCheck::addressInName('Paul Durand', 'urgent.pay@gmail.com', self::OWN));
		$this->assertNull(SecurityCheck::addressInName('paul.durand@societe-a.fr', 'paul.durand@societe-a.fr', self::OWN), 'itself');
	}

	public function testDeceptiveLinks(): void {
		$this->assertSame('evil.example.net', SecurityCheck::deceptive('www.ma-banque.fr', 'https://evil.example.net/login'));
		$this->assertSame('evil.example.net', SecurityCheck::deceptive('https://www.ma-banque.fr/compte', 'http://evil.example.net/'));
		// the same site, or one of its subdomains: fine
		$this->assertNull(SecurityCheck::deceptive('www.ma-banque.fr', 'https://ma-banque.fr/x'));
		$this->assertNull(SecurityCheck::deceptive('ma-banque.fr', 'https://clients.ma-banque.fr/x'));
		// a text that is no address: fine (« Cliquez ici »)
		$this->assertNull(SecurityCheck::deceptive('Cliquez ici', 'https://evil.example.net/'));
		$this->assertNull(SecurityCheck::deceptive('Voir le devis', 'https://evil.example.net/'));
		// mailto: not a web link
		$this->assertNull(SecurityCheck::deceptive('paul@societe-a.fr', 'mailto:paul@societe-a.fr'));
	}

	public function testTheSanitizerMarksThem(): void {
		$r = (new HtmlSanitizer())->sanitize('<p>Votre compte : <a href="https://evil.example.net/x">www.ma-banque.fr</a> ou <a href="https://ma-banque.fr">ma-banque.fr</a></p>');
		$this->assertSame([['shown' => 'www.ma-banque.fr', 'real' => 'evil.example.net']], $r['deceptiveLinks']);
		$this->assertStringContainsString('data-wm-deceptive="evil.example.net"', $r['html']);
	}
}
