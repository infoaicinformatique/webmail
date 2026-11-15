<?php

declare(strict_types=1);

namespace OCA\Webmail\Settings;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\MatrixService;
use OCA\Webmail\Service\ServerConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private ServerConfigService $servers,
		private MatrixService $matrix,
		private \OCA\Webmail\Service\AbsenceReplyService $absences,
		private \OCP\IL10N $l,
		private \OCA\Webmail\Service\OrgSignatureService $orgSignature,
		private \OCP\IConfig $config,
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
			'visioMode' => $this->servers->getVisioMode(),
			// nothing set yet: the Element of the organisation, from the « Sites externes » of Nextcloud
			'element' => $this->servers->getVisio() === '' ? $this->matrix->detectElement() : null,
			'verifyTls' => $this->servers->getVerifyTls(),
			'maxAttachmentsMb' => $this->servers->getMaxAttachmentsMb(),
			'maxAttachmentsLimit' => ServerConfigService::MAX_ATTACHMENTS_MB_LIMIT,
			'absenceTemplate' => $this->absences->adminTemplate(),
			'orgSignature' => $this->orgSignature->template(),
			'orgSignatureMode' => $this->config->getAppValue(Application::APP_ID, 'org_signature_mode', 'off'),
			'absenceDefault' => \OCA\Webmail\Service\AbsenceReplyService::defaultTemplate($this->l),
		], '');
	}

	public function getSection(): string {
		return 'webmail';
	}

	public function getPriority(): int {
		return 10;
	}
}
