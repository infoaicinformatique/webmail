<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\AttachmentZip;
use PHPUnit\Framework\TestCase;

class AttachmentZipTest extends TestCase {
	/** @return array<string, string> name => content of each file of a .zip */
	private static function unzip(string $bytes): array {
		$tmp = tempnam(sys_get_temp_dir(), 'zip-test-');
		file_put_contents($tmp, $bytes);
		$zip = new \ZipArchive();
		self::assertTrue($zip->open($tmp));
		$out = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$out[$name] = (string)$zip->getFromIndex($i);
		}
		$zip->close();
		unlink($tmp);
		return $out;
	}

	public function testFilesInTheZip(): void {
		$files = self::unzip(AttachmentZip::build([
			['name' => 'devis-2027.pdf', 'content' => "%PDF-1.4\n"],
			['name' => 'photo é.jpg', 'content' => "\xFF\xD8\xFF"],
			['name' => 'Re: Commande.eml', 'content' => "Subject: x\r\n\r\nbody"],
		]));
		$this->assertSame(['devis-2027.pdf' => "%PDF-1.4\n", 'photo é.jpg' => "\xFF\xD8\xFF", 'Re Commande.eml' => "Subject: x\r\n\r\nbody"], $files);
	}

	public function testSameNamesNumbered(): void {
		$files = self::unzip(AttachmentZip::build([
			['name' => 'scan.pdf', 'content' => '1'],
			['name' => 'SCAN.pdf', 'content' => '2'],
			['name' => 'scan.pdf', 'content' => '3'],
			['name' => 'notes', 'content' => '4'],
			['name' => 'notes', 'content' => '5'],
		]));
		$this->assertSame(['scan.pdf' => '1', 'SCAN (2).pdf' => '2', 'scan (3).pdf' => '3', 'notes' => '4', 'notes (2)' => '5'], $files);
	}

	public function testNoWayOutOfTheFolder(): void {
		$files = self::unzip(AttachmentZip::build([
			['name' => '../../etc/passwd', 'content' => 'x'],
			['name' => '..\\..\\boot.ini', 'content' => 'y'],
			['name' => '...', 'content' => 'z'],
		]));
		foreach (array_keys($files) as $name) {
			$this->assertStringNotContainsString('/', $name);
			$this->assertStringNotContainsString('\\', $name);
			$this->assertNotSame('..', $name);
		}
		$this->assertSame(['etc passwd', 'boot.ini', 'piece-jointe-3'], array_keys($files));
	}

	public function testSafeName(): void {
		$this->assertSame('Devis n°42 chantier « Antibes »', AttachmentZip::safeName('Devis n°42 / chantier « Antibes »', 'x'));
		$this->assertSame('pieces-jointes', AttachmentZip::safeName(' :?* ', 'pieces-jointes'));
		$long = AttachmentZip::safeName(str_repeat('é', 200) . '.pdf', 'x');
		$this->assertSame(120, mb_strlen($long));
		$this->assertStringEndsWith('.pdf', $long);
	}

	public function testNothingToZip(): void {
		$this->expectException(MailException::class);
		AttachmentZip::build([]);
	}
}
