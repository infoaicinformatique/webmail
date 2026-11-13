<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * Makes an HTML e-mail body safe for display in a sandboxed iframe
 * (defence in depth: the iframe itself never gets "allow-scripts").
 *
 * - removes active content (scripts, forms, embedded objects, frames, svg)
 * - removes event handlers and javascript:/vbscript: URLs
 * - replaces cid: references with the given URLs (inline images)
 * - optionally blocks remote images (tracking pixels) until the user allows them
 */
class HtmlSanitizer {
	private const REMOVE_ELEMENTS = ['script', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
		'form', 'input', 'button', 'textarea', 'select', 'option', 'link', 'meta', 'base', 'svg', 'math', 'template',
		'audio', 'video', 'source', 'track', 'portal', 'title'];

	private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'background', 'poster', 'xlink:href', 'lowsrc', 'dynsrc', 'srcset', 'data'];

	/**
	 * @param array<string, string> $cidMap content-id (without <>) => URL
	 * @return array{html: string, hasRemoteContent: bool}
	 */
	public function sanitize(string $html, array $cidMap = [], bool $allowRemote = false): array {
		$hasRemote = false;
		if (trim($html) === '') {
			return ['html' => '', 'hasRemoteContent' => false];
		}

		$doc = new \DOMDocument();
		$prev = libxml_use_internal_errors(true);
		// Force UTF-8 interpretation
		$doc->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_PARSEHUGE);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		$xp = new \DOMXPath($doc);

		foreach (self::REMOVE_ELEMENTS as $tag) {
			foreach (iterator_to_array($xp->query('//' . $tag)) as $node) {
				$node->parentNode?->removeChild($node);
			}
		}
		// processing instruction added above
		foreach (iterator_to_array($xp->query('//processing-instruction()')) as $pi) {
			$pi->parentNode?->removeChild($pi);
		}
		// comments: libxml and browsers do not end them at the same place (« <!--> » is an
		// empty comment for a browser), so what follows could come back to life as markup
		foreach (iterator_to_array($xp->query('//comment()')) as $comment) {
			$comment->parentNode?->removeChild($comment);
		}

		/** @var \DOMElement $el */
		foreach (iterator_to_array($xp->query('//*')) as $el) {
			foreach (iterator_to_array($el->attributes) as $attr) {
				$name = strtolower($attr->nodeName);
				$value = trim($attr->nodeValue ?? '');

				if (str_starts_with($name, 'on') || $name === 'formaction' || $name === 'srcdoc') {
					$el->removeAttribute($attr->nodeName);
					continue;
				}

				if ($name === 'style') {
					$clean = $this->cleanCss($value, $allowRemote, $hasRemote);
					$el->setAttribute('style', $clean);
					continue;
				}

				if (!in_array($name, self::URL_ATTRIBUTES, true)) {
					continue;
				}

				$lower = strtolower(preg_replace('/[\s\x00-\x1f]+/', '', $value));
				if (preg_match('/^(javascript|vbscript|livescript|mocha):/', $lower)) {
					$el->removeAttribute($attr->nodeName);
					continue;
				}

				if ($name === 'href') {
					continue; // links are fine (opened in a new tab)
				}

				// several images, the browser picks one: each candidate follows the rules below
				if ($name === 'srcset') {
					$keep = true;
					foreach (self::srcsetUrls($value) as $candidate) {
						$url = strtolower((string)preg_replace('/[\x00-\x1f]+/', '', $candidate));
						if (preg_match('#^(https?:)?//#', $url)) {
							$hasRemote = true;
							$keep = $keep && $allowRemote;
						} elseif (!str_starts_with($url, 'data:image/') || str_starts_with($url, 'data:image/svg')) {
							$keep = false;
						}
					}
					if (!$keep) {
						$el->removeAttribute($attr->nodeName);
					}
					continue;
				}

				// resource attributes (src, background, ...)
				if (str_starts_with($lower, 'cid:')) {
					$cid = trim(substr($value, 4), '<> ');
					if (isset($cidMap[$cid])) {
						$el->setAttribute($attr->nodeName, $cidMap[$cid]);
					} else {
						$el->removeAttribute($attr->nodeName);
					}
					continue;
				}
				if (str_starts_with($lower, 'data:')) {
					if (!str_starts_with($lower, 'data:image/') || str_starts_with($lower, 'data:image/svg')) {
						$el->removeAttribute($attr->nodeName);
					}
					continue;
				}
				if (preg_match('#^(https?:)?//#', $lower)) {
					$hasRemote = true;
					if (!$allowRemote) {
						$el->removeAttribute($attr->nodeName);
					}
					continue;
				}
				// relative or unknown scheme: useless outside the original context
				$el->removeAttribute($attr->nodeName);
			}

			if (strtolower($el->tagName) === 'a' && $el->hasAttribute('href')) {
				$el->setAttribute('target', '_blank');
				$el->setAttribute('rel', 'noopener noreferrer');
			}
			// a long newsletter: its images load as they come into view, decoded aside (the page stays fluid)
			if (strtolower($el->tagName) === 'img') {
				$el->setAttribute('loading', 'lazy');
				$el->setAttribute('decoding', 'async');
			}
		}

		foreach (iterator_to_array($xp->query('//style')) as $style) {
			$style->textContent = $this->cleanCss($style->textContent, $allowRemote, $hasRemote);
		}

		$body = $xp->query('//body')->item(0);
		$head = $xp->query('//head')->item(0);
		$out = '';
		if ($head !== null) {
			foreach ($xp->query('.//style', $head) as $style) {
				$out .= $doc->saveHTML($style);
			}
		}
		if ($body !== null) {
			foreach ($body->childNodes as $child) {
				$out .= $doc->saveHTML($child);
			}
		} else {
			$out .= $doc->saveHTML();
		}

		return ['html' => $out, 'hasRemoteContent' => $hasRemote];
	}

	/**
	 * The addresses of a srcset, cut as a browser does (HTML standard): an address runs
	 * up to a space (a data: address keeps its commas), its descriptors up to a comma.
	 *
	 * @return string[]
	 */
	public static function srcsetUrls(string $value): array {
		$urls = [];
		$i = 0;
		$n = strlen($value);
		$space = static fn (string $c): bool => $c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f";
		while ($i < $n) {
			while ($i < $n && ($space($value[$i]) || $value[$i] === ',')) {
				$i++;
			}
			if ($i >= $n) {
				break;
			}
			$start = $i;
			while ($i < $n && !$space($value[$i])) {
				$i++;
			}
			$url = substr($value, $start, $i - $start);
			if (str_ends_with($url, ',')) {
				// no descriptors: the next address follows
				$urls[] = rtrim($url, ',');
				continue;
			}
			$urls[] = $url;
			$depth = 0;
			while ($i < $n) {
				$c = $value[$i++];
				if ($c === '(') {
					$depth++;
				} elseif ($c === ')') {
					$depth = max(0, $depth - 1);
				} elseif ($c === ',' && $depth === 0) {
					break;
				}
			}
		}
		return $urls;
	}

	private function cleanCss(string $css, bool $allowRemote, bool &$hasRemote): string {
		// CSS escapes (« u\72l( » is « url( »): decoded first, so that the rules below see them
		$css = (string)preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', fn (array $m) => mb_chr((int)hexdec($m[1])) ?: '', $css);
		$css = (string)preg_replace('/\\\\(.)/su', '$1', $css);
		// « < » has no use in CSS, and decoded it could close a <style> element (« \3c/style\3e »)
		$css = str_replace('<', '', $css);
		// legacy IE script vectors
		$css = (string)preg_replace('/expression\s*\(|behaviou?r\s*:|-moz-binding\s*:|javascript\s*:/i', 'blocked(', $css);
		$css = (string)preg_replace('/@import[^;]*;?/i', '', $css);
		// position:fixed could overlay the UI if the iframe ever grew
		$css = (string)preg_replace('/position\s*:\s*fixed/i', 'position:static', $css);
		$css = (string)preg_replace_callback('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/i', function (array $m) use ($allowRemote, &$hasRemote): string {
			$url = strtolower(trim($m[2]));
			if (str_starts_with($url, 'data:image/') && !str_starts_with($url, 'data:image/svg')) {
				return $m[0];
			}
			if (preg_match('#^(https?:)?//#', $url)) {
				$hasRemote = true;
				return $allowRemote ? $m[0] : 'none';
			}
			return 'none';
		}, $css);
		// image-set("https://…" 1x), src("…"), and any other address written as a plain string
		return (string)preg_replace_callback('/([\'"])\s*((?:https?:)?\/\/[^\'"]*)\1/i', function (array $m) use ($allowRemote, &$hasRemote): string {
			$hasRemote = true;
			return $allowRemote ? $m[0] : '""';
		}, $css);
	}

	/**
	 * Convert plain text typed by the user to simple HTML.
	 */
	/**
	 * Message written in the editor (already sanitized): web addresses typed
	 * as text become links, like in Outlook, and the usual font is applied.
	 */
	public static function editorToHtml(string $html): string {
		$doc = new \DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="UTF-8"?><div id="wm-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$xp = new \DOMXPath($doc);
		$root = $xp->query('//div[@id="wm-root"]')->item(0);
		if (!$root instanceof \DOMElement) {
			return '<div style="font-family:Calibri,Arial,sans-serif;font-size:11pt">' . $html . '</div>';
		}
		foreach (iterator_to_array($xp->query('.//text()[not(ancestor::a)]', $root)) as $text) {
			$parts = preg_split('#(https?://[^\s<>"\']+)#i', $text->nodeValue ?? '', -1, PREG_SPLIT_DELIM_CAPTURE);
			if ($parts === false || count($parts) < 2) {
				continue;
			}
			$frag = $doc->createDocumentFragment();
			foreach ($parts as $i => $part) {
				if ($i % 2 === 1) {
					$a = $doc->createElement('a');
					$a->setAttribute('href', $part);
					$a->appendChild($doc->createTextNode($part));
					$frag->appendChild($a);
				} elseif ($part !== '') {
					$frag->appendChild($doc->createTextNode($part));
				}
			}
			$text->parentNode?->replaceChild($frag, $text);
		}
		$inner = '';
		foreach ($root->childNodes as $child) {
			$inner .= $doc->saveHTML($child);
		}
		return '<div style="font-family:Calibri,Arial,sans-serif;font-size:11pt">' . $inner . '</div>';
	}

	/**
	 * Images pasted in the editor (data: URLs) become inline attachments,
	 * referenced as cid:, as Outlook sends them: every mail program shows
	 * them, and the message is not weighed down by base64 text in its HTML.
	 * The same image twice is attached once.
	 *
	 * @return array{html: string, images: list<array{name: string, contentType: string, content: string, contentId: string, isInline: bool}>}
	 */
	public static function extractInlineImages(string $html): array {
		$images = [];
		$out = preg_replace_callback(
			'#(<img\b[^>]*?\bsrc\s*=\s*)(["\'])data:image/(png|jpe?g|gif|webp|bmp);base64,([A-Za-z0-9+/=\s]+)\2#i',
			function (array $m) use (&$images): string {
				$content = base64_decode(preg_replace('/\s+/', '', $m[4]) ?? '', true);
				if ($content === false || $content === '') {
					return $m[1] . '""';
				}
				$type = strtolower($m[3]) === 'jpg' ? 'jpeg' : strtolower($m[3]);
				$hash = substr(hash('sha256', $content), 0, 16);
				$cid = 'image-' . $hash . '@webmail';
				if (!isset($images[$cid])) {
					$images[$cid] = [
						'name' => 'image-' . (count($images) + 1) . '.' . ($type === 'jpeg' ? 'jpg' : $type),
						'contentType' => 'image/' . $type,
						'content' => $content,
						'contentId' => $cid,
						'isInline' => true,
					];
				}
				return $m[1] . $m[2] . 'cid:' . $cid . $m[2];
			},
			$html
		);
		return ['html' => $out ?? $html, 'images' => array_values($images)];
	}

	/** Largest signature kept (a small logo pasted in it is part of it). */
	public const MAX_SIGNATURE_BYTES = 512 * 1024;

	/**
	 * A signature written in the editor of the account settings: only
	 * harmless formatting, links and images are kept. A signature in plain
	 * text (written before 1.44) is kept as it is.
	 * @throws \OCA\Webmail\Exception\MailException
	 */
	public static function signature(string $signature): string {
		$signature = trim($signature);
		if (!self::isHtml($signature)) {
			return $signature;
		}
		$html = trim((new self())->sanitize($signature, [], true)['html']);
		// an editor left empty (« <div><br></div> »)
		if (trim(strip_tags($html, '<img>')) === '') {
			return '';
		}
		if (strlen($html) > self::MAX_SIGNATURE_BYTES) {
			throw new \OCA\Webmail\Exception\MailException('Signature trop lourde : réduisez l\'image qu\'elle contient (512 Ko au plus)');
		}
		return $html;
	}

	/** A signature in HTML (from the editor), or in plain text. */
	public static function isHtml(string $text): bool {
		return preg_match('#<(div|p|br|b|strong|i|em|u|a|img|span|font|ul|ol|li|table|blockquote)\b[^>]*>#i', $text) === 1;
	}

	public static function textToHtml(string $text): string {
		$escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$linked = (string)preg_replace('#(https?://[^\s<>"\']+)#i', '<a href="$1">$1</a>', $escaped);
		return '<div style="font-family:Calibri,Arial,sans-serif;font-size:11pt">' . nl2br($linked, false) . '</div>';
	}
}
