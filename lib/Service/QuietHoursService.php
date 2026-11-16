<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCP\IConfig;

/** « Heures calmes » of each user (all their accounts), in their own time zone. */
class QuietHoursService {
	public function __construct(
		private IConfig $config,
	) {
	}

	/** @return array{on: bool, from: int, to: int, weekend: bool, vip: bool, reminders: bool} */
	public function get(string $userId): array {
		return QuietHours::decode($this->config->getUserValue($userId, Application::APP_ID, QuietHours::PREF, ''));
	}

	public function set(string $userId, array $settings): array {
		$s = QuietHours::normalize($settings);
		if ($s === QuietHours::DEFAULTS) {
			$this->config->deleteUserValue($userId, Application::APP_ID, QuietHours::PREF);
		} else {
			$this->config->setUserValue($userId, Application::APP_ID, QuietHours::PREF, (string)json_encode($s));
		}
		return $s;
	}

	/** @param string $kind message | vip | reminder */
	public function silences(string $userId, string $kind, ?\DateTimeImmutable $now = null): bool {
		$s = $this->get($userId);
		if (!$s['on']) {
			return false;
		}
		try {
			$zone = new \DateTimeZone($this->config->getUserValue($userId, 'core', 'timezone', '') ?: date_default_timezone_get());
		} catch (\Exception) {
			$zone = new \DateTimeZone('UTC');
		}
		return QuietHours::silences($s, ($now ?? new \DateTimeImmutable())->setTimezone($zone), $kind);
	}
}
