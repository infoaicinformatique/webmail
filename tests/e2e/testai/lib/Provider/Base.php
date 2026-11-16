<?php

declare(strict_types=1);

namespace OCA\WebmailTestAi\Provider;

use OCP\TaskProcessing\ISynchronousProvider;

/** A fake AI: answers known in advance, from the text given (end-to-end tests only). */
abstract class Base implements ISynchronousProvider {
	public function getName(): string {
		return 'Webmail test AI';
	}

	public function getExpectedRuntime(): int {
		return 1;
	}

	public function getOptionalInputShape(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	/** the first line of the text given, at most 60 characters */
	protected static function first(array $input): string {
		$lines = array_values(array_filter(array_map('trim', explode("\n", (string)($input['input'] ?? '')))));
		return mb_substr($lines[0] ?? '', 0, 60);
	}
}
