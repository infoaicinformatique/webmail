<?php

declare(strict_types=1);

namespace OCA\WebmailTestAi\Provider;

class Summary extends Base {
	public function getId(): string {
		return 'webmail_testai-' . strtolower('Summary');
	}

	public function getTaskTypeId(): string {
		return 'core:text2text:summary';
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		return ['output' => 'RÉSUMÉ : ' . self::first($input)];
	}
}
