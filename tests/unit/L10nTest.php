<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * The page's texts and their English translation (l10n/source/en.json).
 */
class L10nTest extends TestCase {
	/** The files whose texts all go through _() (the others follow, one at a time). */
	private const TRANSLATED = ['js/tasks.js', 'js/rules.js', 'js/delegates.js', 'js/sharing.js'];

	private static function root(): string {
		return dirname(__DIR__, 2);
	}

	/** @return array<string, string> */
	private static function english(): array {
		return json_decode((string)file_get_contents(self::root() . '/l10n/source/en.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/** @return string[] the texts given to _('…') in a file */
	private static function keys(string $file): array {
		preg_match_all("/(?<![\\w.])_\\('((?:[^'\\\\]|\\\\.)*)'/u", (string)file_get_contents(self::root() . '/' . $file), $m);
		return array_map(fn ($k) => stripcslashes($k), $m[1]);
	}

	public function testEveryTextHasItsTranslation(): void {
		$en = self::english();
		$missing = [];
		foreach (glob(self::root() . '/js/*.js') as $path) {
			$file = 'js/' . basename($path);
			foreach (self::keys($file) as $k) {
				if (!isset($en[$k])) {
					$missing[] = $file . ': ' . $k;
				}
			}
		}
		$this->assertSame([], array_values(array_unique($missing)), 'add them to l10n/source/en.json');
	}

	public function testSamePlaceholders(): void {
		foreach (self::english() as $fr => $en) {
			preg_match_all('/\\{\\w+\\}/', $fr, $a);
			preg_match_all('/\\{\\w+\\}/', $en, $b);
			sort($a[0]);
			sort($b[0]);
			$this->assertSame($a[0], $b[0], $fr);
			$this->assertNotSame('', trim($en), $fr);
		}
	}

	public function testNoFrenchLeftInTranslatedFiles(): void {
		foreach (self::TRANSLATED as $file) {
			$code = (string)file_get_contents(self::root() . '/' . $file);
			// texts with an accent outside _(…), comments aside
			$code = (string)preg_replace('#^\\s*//.*$|/\\*.*?\\*/#ms', '', $code);
			$code = (string)preg_replace("/_\\('(?:[^'\\\\]|\\\\.)*'/u", '', $code);
			preg_match_all("/'(?:[^'\\\\\\n]|\\\\.)*[àâçéèêëîïôûùüÀÂÇÉÈÊÎÔ](?:[^'\\\\\\n]|\\\\.)*'/u", $code, $m);
			$this->assertSame([], $m[0], $file);
		}
	}

	public function testGeneratedFilesUpToDate(): void {
		$en = self::english();
		ksort($en);
		foreach (['en', 'en_GB'] as $code) {
			$json = json_decode((string)file_get_contents(self::root() . '/l10n/' . $code . '.json'), true);
			$this->assertSame($en, $json['translations'], 'run php l10n/build.php');
			$this->assertStringContainsString(json_encode(array_key_first($en), JSON_UNESCAPED_UNICODE), (string)file_get_contents(self::root() . '/l10n/' . $code . '.js'));
		}
	}

	public function testFrenchUsersKeepTheTexts(): void {
		// without a French file, Nextcloud would load the English one for French users
		foreach (['json', 'js'] as $ext) {
			$this->assertFileExists(self::root() . '/l10n/fr.' . $ext);
		}
		$json = json_decode((string)file_get_contents(self::root() . '/l10n/fr.json'), true);
		$this->assertSame([], $json['translations']);
	}
}
