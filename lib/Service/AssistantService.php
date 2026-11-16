<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The Assistant of Nextcloud on a message: its summary, a reply drafted, its translation.
 * Through the text processing of Nextcloud (Nextcloud 30 and later), with the AI the
 * administrator set up there; nothing offered when there is none.
 */
class AssistantService {
	private const MANAGER = 'OCP\TaskProcessing\IManager';
	private const TASK = 'OCP\TaskProcessing\Task';
	public const TYPES = ['summary' => 'core:text2text:summary', 'reply' => 'core:text2text', 'translate' => 'core:text2text:translate'];
	/** characters of a message given to the AI, at most */
	public const TEXT_MAX = 12000;

	public function __construct(
		private ContainerInterface $container,
		private IConfig $config,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/** @return ?object the task processing of Nextcloud, when this version has one */
	private function manager(): ?object {
		if (!interface_exists(self::MANAGER)) {
			return null;
		}
		try {
			return $this->container->get(self::MANAGER);
		} catch (\Throwable) {
			return null;
		}
	}

	/** @return array<string, array<string, mixed>> the task types an AI can do now, by id */
	private function types(string $userId): array {
		$m = $this->manager();
		if ($m === null) {
			return [];
		}
		try {
			return $m->getAvailableTaskTypes(false, $userId);
		} catch (\ArgumentCountError | \Error) {
			return $m->getAvailableTaskTypes();
		} catch (\Throwable $e) {
			$this->logger->debug('Webmail: no task types: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return [];
		}
	}

	/**
	 * What the Assistant can do here: summary, reply, translate (each true or false),
	 * and the languages it translates into.
	 * @return array{summary: bool, reply: bool, translate: bool, languages: list<array{code: string, name: string}>}
	 */
	public function capabilities(string $userId): array {
		$types = $this->types($userId);
		$out = [];
		foreach (self::TYPES as $kind => $id) {
			$out[$kind] = isset($types[$id]);
		}
		$out['languages'] = $out['translate'] ? self::enum($types[self::TYPES['translate']], 'target_language') : [];
		return $out;
	}

	/** @return list<array{code: string, name: string}> */
	private static function enum(array $type, string $key): array {
		$list = [];
		foreach ((array)($type['inputShapeEnumValues'][$key] ?? []) as $v) {
			$v = is_object($v) && method_exists($v, 'jsonSerialize') ? $v->jsonSerialize() : $v;
			if (is_array($v) && isset($v['value'])) {
				$list[] = ['code' => (string)$v['value'], 'name' => (string)($v['name'] ?? $v['value'])];
			}
		}
		return $list;
	}

	/**
	 * A task begun on the text of a message; its result is asked for later (the AI may take a while).
	 * @return int the id of the task
	 */
	public function start(string $userId, string $kind, string $subject, string $text, string $instructions = '', string $language = ''): int {
		$m = $this->manager();
		$types = $this->types($userId);
		$type = self::TYPES[$kind] ?? null;
		if ($m === null || $type === null || !isset($types[$type])) {
			throw new MailException('L\'Assistant de Nextcloud n\'est pas disponible pour cela');
		}
		$text = mb_substr(trim($text), 0, self::TEXT_MAX);
		if ($text === '') {
			throw new MailException('Ce message n\'a pas de texte');
		}
		$input = match ($kind) {
			'summary' => ['input' => $text],
			'translate' => ['input' => $text, 'origin_language' => 'detect_language', 'target_language' => $this->target($types[$type], $userId, $language)],
			default => ['input' => self::replyPrompt($subject, $text, $instructions, $this->l)],
		};
		$class = self::TASK;
		$task = new $class($type, $input, Application::APP_ID, $userId, 'webmail-' . $kind);
		$m->scheduleTask($task);
		return (int)$task->getId();
	}

	private function target(array $type, string $userId, string $language): string {
		$codes = array_column(self::enum($type, 'target_language'), 'code');
		$wanted = $language !== '' ? $language : ($this->config->getUserValue($userId, 'core', 'lang', '') ?: 'fr');
		foreach ([$wanted, substr($wanted, 0, 2)] as $c) {
			if (in_array($c, $codes, true)) {
				return $c;
			}
		}
		if ($codes === []) {
			return $wanted;
		}
		throw new MailException('Langue de traduction non proposée par l\'Assistant');
	}

	/** The request for a reply, in the user's language. */
	public static function replyPrompt(string $subject, string $text, string $instructions, IL10N $l): string {
		return $l->t('Rédige une réponse courte et polie à ce message, dans la langue du message, sans objet ni signature.')
			. ($instructions !== '' ? "\n" . $l->t('Consignes : %s', [$instructions]) : '')
			. "\n\n" . $l->t('Objet : %s', [$subject]) . "\n\n" . $text;
	}

	/**
	 * Where a task stands: scheduled, running, done (with its text) or failed.
	 * @return array{status: string, output?: string, error?: string}
	 */
	public function result(string $userId, int $id): array {
		$m = $this->manager();
		if ($m === null) {
			throw new MailException('L\'Assistant de Nextcloud n\'est pas disponible pour cela');
		}
		try {
			$task = $m->getUserTask($id, $userId);
		} catch (\Throwable) {
			throw new MailException('Demande à l\'Assistant introuvable');
		}
		if ($task->getAppId() !== Application::APP_ID) {
			throw new MailException('Demande à l\'Assistant introuvable');
		}
		$class = self::TASK;
		return match ($task->getStatus()) {
			$class::STATUS_SUCCESSFUL => ['status' => 'done', 'output' => trim((string)($task->getOutput()['output'] ?? ''))],
			$class::STATUS_FAILED, $class::STATUS_CANCELLED => ['status' => 'failed', 'error' => (string)$task->getErrorMessage()],
			$class::STATUS_RUNNING => ['status' => 'running'],
			default => ['status' => 'scheduled'],
		};
	}
}
