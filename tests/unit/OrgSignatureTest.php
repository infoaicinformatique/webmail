<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\OrgSignatureService;
use PHPUnit\Framework\TestCase;

class OrgSignatureTest extends TestCase {
	public function testFieldsFilledInAndEscaped(): void {
		$html = OrgSignatureService::render('<p><b>{nom}</b><br>{fonction} — {organisation}</p>', ['nom' => 'Marie <Martin>', 'fonction' => 'Achats', 'organisation' => 'PB & Co']);
		$this->assertSame('<p><b>Marie &lt;Martin&gt;</b><br>Achats — PB &amp; Co</p>', $html);
	}

	public function testALineWithAnEmptyFieldGoesAway(): void {
		$template = '<p><b>{nom}</b><br>Tél. : {telephone}<br>{email}</p><div>Site : {site}</div><p>Mentions légales</p>';
		$this->assertSame('<p><b>Marie Martin</b><br>marie@societe-a.fr</p><p>Mentions légales</p>',
			OrgSignatureService::render($template, ['nom' => 'Marie Martin', 'email' => 'marie@societe-a.fr', 'telephone' => '', 'site' => '']));
		// lines outside a block too
		$this->assertSame('Marie<br>fin', OrgSignatureService::render('Marie<br>Tél. {telephone}<br>fin', ['telephone' => '']));
	}

	public function testUnknownBracesStay(): void {
		$this->assertSame('{x} Marie', OrgSignatureService::render('{x} {nom}', ['nom' => 'Marie']));
	}
}
