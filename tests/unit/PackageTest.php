<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * The tarball built by « make dist » is what gets installed: it must hold
 * everything the app needs at runtime (translations included) and nothing else.
 */
class PackageTest extends TestCase {
	public function testTarballContents(): void {
		if (trim((string)shell_exec('command -v make')) === '' || trim((string)shell_exec('command -v tar')) === '') {
			$this->markTestSkipped('make and tar are needed');
		}
		$root = dirname(__DIR__, 2);
		$build = sys_get_temp_dir() . '/webmail-package-' . bin2hex(random_bytes(4));
		try {
			exec('make -s -C ' . escapeshellarg($root) . ' dist BUILD=' . escapeshellarg($build) . ' 2>&1', $out, $code);
			$this->assertSame(0, $code, implode("\n", $out));
			$tarballs = glob($build . '/webmail-*.tar.gz');
			$this->assertCount(1, $tarballs);
			exec('tar -tzf ' . escapeshellarg($tarballs[0]), $files);
			foreach (['webmail/appinfo/info.xml', 'webmail/js/webmail.js', 'webmail/l10n/en.js', 'webmail/l10n/en.json', 'webmail/l10n/fr.js', 'webmail/l10n/fr.json', 'webmail/templates/main.php'] as $needed) {
				$this->assertContains($needed, $files, $needed . ' is in the tarball');
			}
			foreach ($files as $file) {
				$this->assertDoesNotMatchRegularExpression('#^webmail/(tests|vendor|docs|l10n/source|l10n/build\.php)#', $file, 'not shipped');
			}
		} finally {
			exec('rm -rf ' . escapeshellarg($build));
		}
	}
}
