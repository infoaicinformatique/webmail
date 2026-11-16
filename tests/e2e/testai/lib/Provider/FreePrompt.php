<?php

declare(strict_types=1);

namespace OCA\WebmailTestAi\Provider;

class FreePrompt extends Base {
	public function getId(): string {
		return 'webmail_testai-' . strtolower('FreePrompt');
	}

	public function getTaskTypeId(): string {
		return 'core:text2text';
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		return ['output' => 'Bonjour, merci pour votre message, je reviens vers vous rapidement.' . (str_contains((string)($input['input'] ?? ''), 'Consignes :') ? ' (avec consigne)' : '')];
	}
}
