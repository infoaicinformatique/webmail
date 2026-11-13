<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\ServerConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private ServerConfigService $servers,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IAppManager $apps,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		// modules first: they register themselves in window.WebmailModules
		// (Util::addScript also loads the translations of the user's language, l10n/<language>.js)
		Util::addScript(Application::APP_ID, 'sharing');
		Util::addScript(Application::APP_ID, 'calendar');
		Util::addScript(Application::APP_ID, 'contacts');
		Util::addScript(Application::APP_ID, 'delegates');
		Util::addScript(Application::APP_ID, 'rules');
		Util::addScript(Application::APP_ID, 'tasks');
		Util::addScript(Application::APP_ID, 'webmail');
		Util::addStyle(Application::APP_ID, 'webmail');

		$user = $this->userSession->getUser();
		$response = new TemplateResponse(Application::APP_ID, 'main', [
			'domains' => $this->servers->getDomains(),
			'autodiscover' => $this->servers->getAutodiscover()['enabled'],
			'isAdmin' => $user !== null && $this->groupManager->isAdmin($user->getUID()),
			// Nextcloud Talk: video meetings in the invitations
			'talk' => $user !== null && $this->apps->isEnabledForUser('spreed', $user),
			// video meetings with Element Call (Matrix) or another service, set by the administrator
			'visio' => $this->servers->getVisio(),
		]);

		// The message body is rendered in a sandboxed srcdoc iframe which
		// inherits this policy: remote images are only shown when the user
		// explicitly asks for them (the sanitizer strips them otherwise).
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedImageDomain('https:');
		$csp->addAllowedImageDomain('data:');
		$csp->addAllowedFrameDomain('\'self\'');
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
