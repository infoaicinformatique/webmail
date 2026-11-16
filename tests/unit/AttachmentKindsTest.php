<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\AttachmentKinds;
use PHPUnit\Framework\TestCase;

class AttachmentKindsTest extends TestCase {
	public function testTheKindOfAFile(): void {
		$this->assertSame('pdf', AttachmentKinds::kind('Devis 2026.PDF'));
		$this->assertSame('image', AttachmentKinds::kind('photo', 'image/jpeg'));
		$this->assertSame('sheet', AttachmentKinds::kind('budget.xlsx'));
		$this->assertSame('document', AttachmentKinds::kind('contrat.odt'));
		$this->assertSame('presentation', AttachmentKinds::kind('salon.pptx'));
		$this->assertSame('archive', AttachmentKinds::kind('plans.zip'));
		$this->assertSame('other', AttachmentKinds::kind('facture.xml', 'text/xml'));
	}

	public function testOneRowPerFileWithItsMessage(): void {
		$messages = [
			['id' => 'M1', 'subject' => 'Devis', 'from' => ['name' => 'Jean', 'email' => 'jean@x.fr'], 'date' => '2026-11-10T10:00:00Z', 'folderId' => 'IN'],
			['id' => 'M2', 'subject' => 'Photos', 'from' => null, 'date' => '2026-11-09T10:00:00Z', 'folderId' => 'IN'],
		];
		$files = [
			'M1' => [['id' => 'A1', 'name' => 'devis.pdf', 'size' => 10, 'contentType' => 'application/pdf'], ['id' => 'A2', 'name' => 'cgv.docx', 'size' => 5, 'contentType' => '']],
			'M2' => [['id' => 'A3', 'name' => 'chantier.jpg', 'size' => 7, 'contentType' => 'image/jpeg']],
		];
		$rows = AttachmentKinds::rows($messages, $files, '');
		$this->assertSame(['A1', 'A2', 'A3'], array_column($rows, 'id'));
		$this->assertSame('Devis', $rows[0]['subject']);
		$this->assertSame('M1', $rows[1]['messageId']);
		$this->assertSame(['A3'], array_column(AttachmentKinds::rows($messages, $files, 'image'), 'id'));
	}
}
