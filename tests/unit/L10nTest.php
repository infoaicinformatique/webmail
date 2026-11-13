<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\EwsCategories;
use PHPUnit\Framework\TestCase;

/**
 * The page's texts and their English translation (l10n/source/en.json).
 */
class L10nTest extends TestCase {
	/** The files whose texts all go through _() (the others follow, one at a time). */
	private const TRANSLATED = ['js/tasks.js', 'js/rules.js', 'js/delegates.js', 'js/sharing.js', 'js/contacts.js', 'js/calendar.js', 'js/webmail.js', 'js/admin.js'];

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

	/**
	 * The texts of the server: error messages (MailException) and the texts
	 * given to IL10N::t() or L10n::t(), in lib/ and templates/.
	 * @return array<string, string[]> text => files
	 */
	private static function phpKeys(): array {
		$q = "'((?:[^'\\\\]|\\\\.)*)'";
		$patterns = [
			'/new MailException\\(\\s*' . $q . '/u',
			'/->t\\(\\s*' . $q . '/u',
			'/\\bL10n::t\\(\\s*\\$\\w+\\s*,\\s*' . $q . '/u',
		];
		$files = array_merge(
			glob(self::root() . '/lib/*/*.php') ?: [],
			glob(self::root() . '/lib/*/*/*.php') ?: [],
			glob(self::root() . '/templates/*.php') ?: [],
			glob(self::root() . '/templates/*/*.php') ?: [],
		);
		$keys = [];
		foreach ($files as $path) {
			$code = (string)file_get_contents($path);
			foreach ($patterns as $p) {
				preg_match_all($p, $code, $m);
				foreach ($m[1] as $k) {
					// a PHP single-quoted string: only \' and \\ are escapes
					$keys[(string)preg_replace('/\\\\([\\\\\'])/', '$1', $k)][] = substr($path, strlen(self::root()) + 1);
				}
			}
		}
		// texts translated through a variable
		foreach ([...EwsCategories::COLOR_NAMES, ...array_values(EwsCalendar::FREE_BUSY_LABELS), ...EwsCalendar::AVAILABILITY_ERRORS] as $k) {
			$keys[$k][] = 'lib/Ews';
		}
		return $keys;
	}

	public function testEveryServerTextHasItsTranslation(): void {
		$keys = self::phpKeys();
		$this->assertGreaterThan(200, count($keys), 'the extraction finds the texts');
		$this->assertArrayHasKey('Dossier introuvable', $keys);
		$this->assertArrayHasKey('Erreur interne, consultez le journal Nextcloud', $keys);
		$en = self::english();
		$missing = [];
		foreach ($keys as $k => $files) {
			if (!isset($en[$k])) {
				$missing[] = $files[0] . ': ' . $k;
			}
		}
		$this->assertSame([], $missing, 'add them to l10n/source/en.json');
	}

	public function testServerTextsAreLiterals(): void {
		// a concatenated message cannot be translated: %s and its parameters instead
		foreach (array_merge(glob(self::root() . '/lib/*/*.php') ?: [], glob(self::root() . '/lib/*.php') ?: []) as $path) {
			$code = (string)file_get_contents($path);
			preg_match_all("/new MailException\\((?!\\s*'(?:[^'\\\\]|\\\\.)*'\\s*[,)])[^\\n]*/u", $code, $m);
			$this->assertSame([], $m[0], basename($path));
		}
	}

	public function testSamePlaceholders(): void {
		foreach (self::english() as $fr => $en) {
			preg_match_all('/\\{\\w+\\}/', $fr, $a);
			preg_match_all('/\\{\\w+\\}/', $en, $b);
			sort($a[0]);
			sort($b[0]);
			$this->assertSame($a[0], $b[0], $fr);
			$this->assertNotSame('', trim($en), $fr);
			// PHP (vsprintf) placeholders: %s, or %1$s, %2$s… when the order may change
			preg_match_all('/%(?:\\d+\\$)?s/', $fr, $c);
			preg_match_all('/%(?:\\d+\\$)?s/', $en, $d);
			sort($c[0]);
			sort($d[0]);
			$this->assertSame($c[0], $d[0], $fr);
			$this->assertFalse($a[0] !== [] && $c[0] !== [], 'one style of placeholders per text: ' . $fr);
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
