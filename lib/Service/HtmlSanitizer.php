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

	private function cleanCss(string $css, bool $allowRemote, bool &$hasRemote): string {
		// legacy IE script vectors
		$css = (string)preg_replace('/expression\s*\(|behaviou?r\s*:|-moz-binding\s*:|javascript\s*:/i', 'blocked(', $css);
		$css = (string)preg_replace('/@import[^;]*;?/i', '', $css);
		// position:fixed could overlay the UI if the iframe ever grew
		$css = (string)preg_replace('/position\s*:\s*fixed/i', 'position:static', $css);
		return (string)preg_replace_callback('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/i', function (array $m) use ($allowRemote, &$hasRemote): string {
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
	}

	/**
	 * Convert plain text typed by the user to simple HTML.
	 */
	public static function textToHtml(string $text): string {
		$escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$linked = (string)preg_replace('#(https?://[^\s<>"\']+)#i', '<a href="$1">$1</a>', $escaped);
		return '<div style="font-family:Calibri,Arial,sans-serif;font-size:11pt">' . nl2br($linked, false) . '</div>';
	}
}
