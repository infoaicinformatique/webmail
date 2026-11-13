<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCP\IConfig;

/**
 * Reply templates: texts the user inserts in a message in one click. They belong
 * to the Nextcloud user (all their accounts), kept in their preferences.
 */
class TemplateService {
	public const MAX_TEMPLATES = 50;
	public const MAX_NAME = 80;
	public const MAX_BYTES = 262144; // 256 KB, a small image included

	public function __construct(
		private IConfig $config,
	) {
	}

	/** @return list<array{id: string, name: string, html: string}> */
	public function list(string $userId): array {
		return self::decode($this->config->getUserValue($userId, Application::APP_ID, 'templates', '[]'));
	}

	/**
	 * @return list<array{id: string, name: string, html: string}>
	 * @throws MailException
	 */
	public function save(string $userId, ?string $id, string $name, string $html): array {
		$list = self::put($this->list($userId), $id, $name, $html, bin2hex(random_bytes(8)));
		$this->store($userId, $list);
		return $list;
	}

	/** @return list<array{id: string, name: string, html: string}> */
	public function delete(string $userId, string $id): array {
		$list = self::remove($this->list($userId), $id);
		$this->store($userId, $list);
		return $list;
	}

	private function store(string $userId, array $list): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'templates', json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/** @return list<array{id: string, name: string, html: string}> what is stored, whatever its state */
	public static function decode(string $json): array {
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return [];
		}
		$list = [];
		foreach ($data as $t) {
			if (is_array($t) && is_string($t['id'] ?? null) && is_string($t['name'] ?? null) && is_string($t['html'] ?? null)) {
				$list[] = ['id' => $t['id'], 'name' => $t['name'], 'html' => $t['html']];
			}
		}
		return $list;
	}

	/**
	 * A new template ($id null: added, sorted by name) or a changed one.
	 *
	 * @param list<array{id: string, name: string, html: string}> $list
	 * @return list<array{id: string, name: string, html: string}>
	 * @throws MailException
	 */
	public static function put(array $list, ?string $id, string $name, string $html, string $newId): array {
		$name = trim((string)preg_replace('/\s+/u', ' ', $name));
		if ($name === '') {
			throw new MailException('Donnez un nom au modèle');
		}
		if (mb_strlen($name) > self::MAX_NAME) {
			throw new MailException('Nom du modèle trop long (%s caractères au plus)', [self::MAX_NAME]);
		}
		// the same cleaning as a signature: no script, images kept
		$clean = trim((new HtmlSanitizer())->sanitize(HtmlSanitizer::isHtml($html) ? $html : HtmlSanitizer::textToHtml($html), [], true)['html']);
		if (trim(strip_tags($clean, '<img>')) === '') {
			throw new MailException('Le modèle est vide');
		}
		if (strlen($clean) > self::MAX_BYTES) {
			throw new MailException('Modèle trop lourd : réduisez l\'image qu\'il contient (256 Ko au plus)');
		}
		$template = ['id' => $id ?? $newId, 'name' => $name, 'html' => $clean];
		if ($id === null) {
			if (count($list) >= self::MAX_TEMPLATES) {
				throw new MailException('%s modèles au plus : supprimez-en un d\'abord', [self::MAX_TEMPLATES]);
			}
			$list[] = $template;
		} else {
			$found = false;
			foreach ($list as $i => $t) {
				if ($t['id'] === $id) {
					$list[$i] = $template;
					$found = true;
				}
			}
			if (!$found) {
				throw new MailException('Modèle introuvable');
			}
		}
		usort($list, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));
		return array_values($list);
	}

	/**
	 * @param list<array{id: string, name: string, html: string}> $list
	 * @return list<array{id: string, name: string, html: string}>
	 */
	public static function remove(array $list, string $id): array {
		return array_values(array_filter($list, fn (array $t) => $t['id'] !== $id));
	}
}
