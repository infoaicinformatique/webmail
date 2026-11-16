<?php

declare(strict_types=1);

namespace OCA\WebmailTestAi\Provider;

class Translate extends Base {
	public function getId(): string {
		return 'webmail_testai-' . strtolower('Translate');
	}

	public function getTaskTypeId(): string {
		return 'core:text2text:translate';
	}

	public function getInputShapeEnumValues(): array {
		$langs = [new \OCP\TaskProcessing\ShapeEnumValue('Français', 'fr'), new \OCP\TaskProcessing\ShapeEnumValue('English', 'en')];
		return [
			'origin_language' => array_merge([new \OCP\TaskProcessing\ShapeEnumValue('Détecter', 'detect_language')], $langs),
			'target_language' => $langs,
		];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		return ['output' => '[' . ($input['target_language'] ?? '?') . '] ' . self::first($input)];
	}
}
