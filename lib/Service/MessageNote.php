<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

/**
 * A message as a note of Nextcloud Notes: a Markdown file in the user's Notes folder
 * (read by the Notes app, Text and Files alike). Pure functions.
 */
class MessageNote {
	/** At most this many characters of the message's text. */
	public const MAX_TEXT = 20000;

	/** The text of an HTML body: paragraphs and line breaks kept, nothing of the markup. */
	public static function text(string $html): string {
		if (trim($html) === '') {
			return '';
		}
		$doc = new \DOMDocument();
		libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		$xp = new \DOMXPath($doc);
		foreach (iterator_to_array($xp->query('//script|//style|//head|//title')) as $n) {
			$n->parentNode?->removeChild($n);
		}
		foreach (iterator_to_array($xp->query('//br')) as $n) {
			$n->parentNode?->replaceChild($doc->createTextNode("\n"), $n);
		}
		foreach (iterator_to_array($xp->query('//p|//div|//li|//tr|//h1|//h2|//h3|//h4|//h5|//h6|//blockquote|//pre|//table')) as $n) {
			$n->appendChild($doc->createTextNode("\n"));
		}
		$text = html_entity_decode((string)$doc->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = (string)preg_replace(["/[ \t\x{00a0}]+/u", "/ ?\n ?/", "/\n{3,}/"], [' ', "\n", "\n\n"], $text);
		$text = trim($text);
		return mb_strlen($text) > self::MAX_TEXT ? mb_substr($text, 0, self::MAX_TEXT) . '…' : $text;
	}

	/** At most this many characters of the message's text in a card of Deck. */
	public const CARD_TEXT = 4000;

	/** The title of a card of Deck (255 characters at most there), from the subject. */
	public static function cardTitle(string $subject): string {
		$title = trim((string)preg_replace('/[\x00-\x1f\s]+/u', ' ', $subject));
		return mb_substr($title !== '' ? $title : 'Message', 0, 255);
	}

	/** A file name from the subject (Notes shows it as the title). */
	public static function fileName(string $subject): string {
		$name = trim((string)preg_replace(['~[\\\\/:*?"<>|\x00-\x1f]+~', '~\s+~u'], ' ', $subject), ' .');
		$name = mb_substr($name !== '' ? $name : 'Message', 0, 100);
		return $name . '.md';
	}

	/**
	 * The note: the subject as title, who and when, the text, the link back to the message.
	 * (Markdown signs of the text are escaped at the start of lines: a « # » in a message is no title.)
	 */
	public static function markdown(string $subject, string $from, string $date, string $text, string $link, string $fromLabel = 'De', string $dateLabel = 'Date', string $openLabel = 'Ouvrir dans le webmail'): string {
		$escape = fn (string $s) => (string)preg_replace('/^([#>\-+*]|\d+\.)/m', '\\\\$1', $s);
		$md = '# ' . str_replace("\n", ' ', trim($subject) !== '' ? $subject : 'Message') . "\n\n";
		if ($from !== '') {
			$md .= '**' . $fromLabel . ' :** ' . $escape($from) . "  \n";
		}
		if ($date !== '') {
			$md .= '**' . $dateLabel . ' :** ' . $date . "\n";
		}
		$md .= "\n" . $escape($text) . "\n";
		if ($link !== '') {
			$md .= "\n[" . $openLabel . '](' . $link . ")\n";
		}
		return $md;
	}
}
