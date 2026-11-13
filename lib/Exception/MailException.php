<?php

declare(strict_types=1);

namespace OCA\Webmail\Exception;

use OCP\IL10N;

/**
 * Error whose message can be shown to the user (in French).
 *
 * The message is a translation key (French text, Nextcloud style placeholders
 * %s or %1$s, %2$s…) and its parameters: getMessage() gives the French text,
 * translate() the text in the user's language.
 */
class MailException extends \Exception {
	private string $key;
	/** @var array<int, string|int|float> */
	private array $params;

	/**
	 * @param string $message the French text, with %s placeholders when $params is given
	 * @param array<int, string|int|float> $params
	 */
	public function __construct(string $message = '', array $params = [], int $code = 0, ?\Throwable $previous = null) {
		$this->key = $message;
		$this->params = array_values($params);
		parent::__construct($params === [] ? $message : vsprintf($message, $this->params), $code, $previous);
	}

	/** The untranslated text (French, with its placeholders). */
	public function getKey(): string {
		return $this->key;
	}

	/** @return array<int, string|int|float> */
	public function getParams(): array {
		return $this->params;
	}

	/** The message in the language of the IL10N (the French text without one). */
	public function translate(?IL10N $l): string {
		if ($l === null) {
			return $this->getMessage();
		}
		return $l->t($this->key, $this->params);
	}
}
