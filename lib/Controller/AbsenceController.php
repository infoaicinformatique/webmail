<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AbsenceReplyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/** The automatic replies during the absences of Nextcloud: switched on or off, the model. */
class AbsenceController extends Controller {
	use ErrorHandling;

	public function __construct(
		IRequest $request,
		private AbsenceReplyService $absences,
		private IL10N $l,
		private IURLGenerator $url,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	private function settings(): array {
		$user = (string)$this->userId;
		$admin = $this->absences->adminTemplate();
		return [
			'enabled' => $this->absences->isEnabled($user),
			'invites' => $this->absences->invitesMode($user),
			'template' => $this->absences->userTemplate($user),
			// the model of the organisation, used when the user has none ('' : the message of the absence as it is)
			'defaultTemplate' => $admin,
			'example' => AbsenceReplyService::defaultTemplate($this->l),
			'available' => interface_exists(\OCP\User\IAvailabilityCoordinator::class),
			'settingsUrl' => $this->url->linkToRoute('settings.PersonalSettings.index', ['section' => 'availability']),
		];
	}

	#[NoAdminRequired]
	public function show(): Response {
		return $this->handle(fn () => $this->settings());
	}

	#[NoAdminRequired]
	public function update(bool $enabled = true, string $template = '', string $invites = ''): Response {
		return $this->handle(function () use ($enabled, $template, $invites) {
			$this->absences->setEnabled((string)$this->userId, $enabled);
			if ($invites !== '') {
				$this->absences->setInvitesMode((string)$this->userId, $invites);
			}
			$this->absences->setUserTemplate((string)$this->userId, $template);
			return $this->settings();
		});
	}

	/** The model of the organisation (administrators only). */
	public function adminTemplate(string $template = ''): Response {
		return $this->handle(function () use ($template) {
			$this->absences->setAdminTemplate($template);
			$admin = $this->absences->adminTemplate();
			return ['template' => $admin, 'defaultTemplate' => AbsenceReplyService::defaultTemplate($this->l)];
		});
	}
}
