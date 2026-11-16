<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\MergeRules;
use PHPUnit\Framework\TestCase;

class MergeRulesTest extends TestCase {
	public function testAListWithAHeader(): void {
		$p = MergeRules::parse("\xEF\xBB\xBFE-mail;Prénom;Nom;Société;Ville\r\nclaire@exemple.fr;Claire;Martin;Exemple SA;Nice\r\nPAS UNE ADRESSE;x\r\nclaire@exemple.fr;Doublon;;;\r\n\"paul@exemple.fr\";\"Paul\";Durand;;Antibes\r\n");
		$this->assertSame(['claire@exemple.fr', 'paul@exemple.fr'], array_column($p['rows'], 'email'));
		$this->assertSame('Claire', $p['rows'][0]['prenom']);
		$this->assertSame('Exemple SA', $p['rows'][0]['societe']);
		$this->assertSame('Antibes', $p['rows'][1]['ville']);
		$this->assertSame(['PAS UNE ADRESSE;x'], $p['refused']);
		$this->assertContains('ville', $p['columns']);
	}

	public function testAListWithoutHeaderCommasOrAddressesAlone(): void {
		$p = MergeRules::parse("jean@x.fr, Jean, Dupont\nmarie@y.fr");
		$this->assertSame(['jean@x.fr', 'marie@y.fr'], array_column($p['rows'], 'email'));
		$this->assertSame('Dupont', $p['rows'][0]['nom']);
		$this->assertSame('', $p['rows'][1]['prenom']);
	}

	public function testFill(): void {
		$row = ['email' => 'a@b.fr', 'prenom' => 'Zoé <b>', 'nom' => '', 'societe' => 'ACME'];
		$this->assertSame('Bonjour Zoé <b>, de ACME', MergeRules::fill('Bonjour {prenom}, de {Société}', $row, false));
		$this->assertSame('<p>Bonjour Zoé &lt;b&gt;</p>', MergeRules::fill('<p>Bonjour {Prénom}</p>', $row, true));
		$this->assertSame('Bonjour,', MergeRules::fill('Bonjour {nom},', $row, false), 'an empty field takes its space away');
		$this->assertSame('Votre devis', MergeRules::fill('Votre devis {inconnu}', $row, false));
	}
}
