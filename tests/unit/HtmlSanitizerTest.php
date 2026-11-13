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

	public function testEditorToHtmlLinksAddressesAndKeepsFormatting(): void {
		$out = HtmlSanitizer::editorToHtml('<div>Voir https://nextcloud.com/a?b=1 et <a href="https://x.fr">le site</a></div><ul><li><b>1 200 €</b> é</li></ul>');
		$this->assertStringStartsWith('<div style="font-family:Calibri,Arial,sans-serif;font-size:11pt">', $out);
		$this->assertStringContainsString('<a href="https://nextcloud.com/a?b=1">https://nextcloud.com/a?b=1</a>', $out);
		$this->assertStringContainsString('<a href="https://x.fr">le site</a>', $out, 'existing links untouched');
		$this->assertStringContainsString('<ul><li><b>1 200 €</b> é</li></ul>', $out);
	}

	public function testCommentsCannotHideMarkup(): void {
		// « <!--> » is an empty comment for a browser, the start of a long one for libxml
		foreach (['<p>a</p><!--><img src=https://t/x onerror=alert(1)>-->', '<p>a<!--->b<img src=x onerror=alert(1)>--></p>', '<p>a</p><!-- ok -->'] as $html) {
			$out = $this->clean($html)['html'];
			$this->assertStringNotContainsString('<!--', $out, $html);
			$this->assertStringNotContainsString('onerror', $out, $html);
		}
	}

	public function testRemoteImagesOfASrcset(): void {
		$r = $this->clean('<img srcset="data:image/png;base64,AA 1w, https://t/x 9999w" sizes="9999px">');
		$this->assertStringNotContainsString('https://t/x', $r['html']);
		$this->assertTrue($r['hasRemoteContent']);
		// without a space after the comma: still two images
		$this->assertStringNotContainsString('https://', $this->clean('<img srcset="data:image/png;base64,AA 1w,https://t/x 9w">')['html']);
		// an inline image keeps its srcset; remote ones are kept once allowed
		$this->assertStringContainsString('srcset="data:image/png;base64,AA 1x"', $this->clean('<img srcset="data:image/png;base64,AA 1x">')['html']);
		$this->assertStringContainsString('srcset="https://t/a 1x, https://t/b 2x"', $this->clean('<img srcset="https://t/a 1x, https://t/b 2x">', [], true)['html']);
		// as a browser: an address runs up to a space, so « a.png,b(1).png » is one address
		$this->assertSame(['data:image/png;base64,AA', 'https://t/x', 'a.png,b(1).png', 'c.png'], HtmlSanitizer::srcsetUrls('data:image/png;base64,AA 1w,https://t/x 9w, a.png,b(1).png 2x, c.png'));
	}

	public function testRemoteImagesHiddenInCss(): void {
		foreach (['background:u\\72l(https://t/z)', 'background:image-set("https://t/y" 1x)', 'background:-webkit-image-set(\'//t/w\' 1x)'] as $css) {
			$r = $this->clean('<p style="' . htmlspecialchars($css) . '">x</p>');
			$this->assertDoesNotMatchRegularExpression('#//t/#', $r['html'], $css);
			$this->assertTrue($r['hasRemoteContent'], $css);
		}
		// the same addresses are kept once remote content is allowed
		$this->assertStringContainsString('https://t/y', $this->clean('<p style="background:image-set(&quot;https://t/y&quot; 1x)">x</p>', [], true)['html']);
	}

	public function testCssEscapesCannotCloseTheStyle(): void {
		$out = $this->clean('<style>p{color:red}\\3c/style\\3e\\3cscript\\3e alert(1)\\3c/script\\3e</style><p>x</p>')['html'];
		$this->assertStringNotContainsString('<script', $out);
		$this->assertSame(1, substr_count($out, '</style>'));
		// ordinary escapes still work
		$this->assertStringContainsString('content:"“"', $this->clean('<p style="content:&quot;\\201C&quot;">x</p>')['html']);
	}
}
