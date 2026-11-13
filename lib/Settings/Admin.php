<?php

declare(strict_types=1);

namespace OCA\Webmail\Settings;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\ServerConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private ServerConfigService $servers,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'settings/admin', [
			'servers' => $this->servers->getServers(),
			'autodiscover' => $this->servers->getAutodiscover(),
			'discovered' => $this->servers->getDiscovered(),
			'visio' => $this->servers->getVisio(),
		], '');
	}

	public function getSection(): string {
		return 'webmail';
	}

	public function getPriority(): int {
		return 10;
	}
}
