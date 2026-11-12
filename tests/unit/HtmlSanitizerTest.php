<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase {
	private function clean(string $html, array $cid = [], bool $remote = false): array {
		return (new HtmlSanitizer())->sanitize($html, $cid, $remote);
	}

	public function testRemovesScriptsAndHandlers(): void {
		$r = $this->clean('<html><head><script>alert(1)</script></head><body><p onclick="x()" onmouseover="y()">Hi</p>'
			. '<a href="javascript:alert(1)">bad</a><a href=" JaVa&#x09;script:alert(1)">bad2</a>'
			. '<iframe src="https://evil"></iframe><form action="https://evil"><input name="p"></form><svg onload="x()"></svg>'
			. '<object data="x.swf"></object><embed src="x"><meta http-equiv="refresh" content="0;url=https://evil"></body></html>');
		$html = $r['html'];
		$this->assertStringContainsString('<p>Hi</p>', $html);
		foreach (['script', 'onclick', 'onmouseover', 'javascript', 'iframe', '<form', '<input', '<svg', '<object', '<embed', 'refresh'] as $bad) {
			$this->assertStringNotContainsStringIgnoringCase($bad, $html);
		}
	}

	public function testLinksOpenInNewTab(): void {
		$r = $this->clean('<a href="https://nextcloud.com">ok</a>');
		$this->assertStringContainsString('href="https://nextcloud.com"', $r['html']);
		$this->assertStringContainsString('target="_blank"', $r['html']);
		$this->assertStringContainsString('rel="noopener noreferrer"', $r['html']);
	}

	public function testBlocksRemoteImagesUnlessAllowed(): void {
		$html = '<img src="https://tracker.example/pixel.gif"><div style="background:url(\'https://x/y.png\')">t</div>';
		$blocked = $this->clean($html);
		$this->assertTrue($blocked['hasRemoteContent']);
		$this->assertStringNotContainsString('src="https://tracker', $blocked['html']);
		$this->assertStringNotContainsString('url(\'https://x', $blocked['html']);

		$allowed = $this->clean($html, [], true);
		$this->assertTrue($allowed['hasRemoteContent']);
		$this->assertStringContainsString('src="https://tracker.example/pixel.gif"', $allowed['html']);
	}

	public function testRewritesCidImages(): void {
		$r = $this->clean('<img src="cid:logo@x"><img src="cid:unknown">', ['logo@x' => '/apps/webmail/api/accounts/1/attachment?attachmentId=A2&inline=1']);
		$this->assertStringContainsString('src="/apps/webmail/api/accounts/1/attachment?attachmentId=A2&amp;inline=1"', $r['html']);
		$this->assertSame(1, substr_count($r['html'], 'src='));
		$this->assertFalse($r['hasRemoteContent']);
	}

	public function testDataUris(): void {
		$r = $this->clean('<img src="data:image/png;base64,AAAA"><img src="data:image/svg+xml;base64,AAAA"><a href="data:text/html,x">l</a>');
		$this->assertStringContainsString('data:image/png', $r['html']);
		$this->assertStringNotContainsString('svg', $r['html']);
	}

	public function testCssExpressionsAndImports(): void {
		$r = $this->clean('<style>@import url(https://x/a.css); p{color:red;width:expression(alert(1))} .x{position:fixed}</style><p style="behavior:url(x.htc)">a</p>');
		$this->assertStringNotContainsString('@import', $r['html']);
		$this->assertStringNotContainsString('expression(', $r['html']);
		$this->assertStringNotContainsString('position:fixed', $r['html']);
		$this->assertStringContainsString('color:red', $r['html']);
	}

	public function testKeepsUtf8(): void {
		$r = $this->clean('<p>Été — « café » 😀</p>');
		$this->assertStringContainsString('Été — « café » 😀', $r['html']);
	}

	public function testTextToHtml(): void {
		$html = HtmlSanitizer::textToHtml("Bonjour <b>\nvoir https://ex.fr/a?b=1&c=2");
		$this->assertStringContainsString('Bonjour &lt;b&gt;<br>', $html);
		$this->assertStringContainsString('<a href="https://ex.fr/a?b=1&amp;c=2">', $html);
	}
}
