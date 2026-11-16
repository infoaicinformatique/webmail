<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\FolderExport;
use PHPUnit\Framework\TestCase;

class FolderExportTest extends TestCase {
	public function testNames(): void {
		$paris = new \DateTimeZone('Europe/Paris');
		$this->assertSame('2026-07-01 0930 RE Devis.eml', FolderExport::fileName('RE: Devis', '2026-07-01T07:30:00Z', $paris), 'summer time; no « : » in a file name');
		$this->assertSame('Message.eml', FolderExport::fileName('', null, $paris));
		$this->assertSame('Sans date.eml', FolderExport::fileName('Sans date', 'pas une date', $paris));
		$this->assertSame('Clients B (2026-11-16).zip', FolderExport::zipName('Clients/B', new \DateTimeImmutable('2026-11-16')));
		$this->assertSame('Dossier (2026-11-16).zip', FolderExport::zipName('', new \DateTimeImmutable('2026-11-16')));
	}

	public function testUnique(): void {
		$used = [];
		$this->assertSame('a.eml', FolderExport::unique('a.eml', $used));
		$this->assertSame('A (2).eml', FolderExport::unique('A.eml', $used), 'whatever the case');
		$this->assertSame('a (3).eml', FolderExport::unique('a.eml', $used));
	}
}
