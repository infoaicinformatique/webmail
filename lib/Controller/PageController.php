<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\AccountService;
use OCA\Webmail\Service\MailNotificationService;
use OCA\Webmail\Service\MatrixService;
use OCA\Webmail\Service\MeetingReminderService;
use OCA\Webmail\Service\ServerConfigService;
use OCA\Webmail\Service\TrustedImagesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private ServerConfigService $servers,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IAppManager $apps,
		private AccountService $accounts,
		private MailNotificationService $bell,
		private MeetingReminderService $reminders,
		private IInitialState $initialState,
		private TrustedImagesService $trustedImages,
		private MatrixService $matrix,
		private LoggerInterface $logger,
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
		// the accounts with the page: the webmail asks Exchange for the folders and the messages at once,
		// without a first request for them (the page asks for them itself if this fails)
		if ($user !== null) {
			try {
				$uid = $user->getUID();
				$this->initialState->provideInitialState('accounts', array_map(
					fn ($a) => AccountController::describe($a, $this->bell, $this->reminders, $uid), $this->accounts->findAll($uid)));
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: accounts not given with the page', ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
		try {
			$visio = $this->matrix->effectiveVisio();
		} catch (\Throwable $e) {
			$visio = ['visio' => $this->servers->getVisio(), 'mode' => $this->servers->getVisioMode()];
		}
		$response = new TemplateResponse(Application::APP_ID, 'main', [
			'domains' => $this->servers->getDomains(),
			'autodiscover' => $this->servers->getAutodiscover()['enabled'],
			'isAdmin' => $user !== null && $this->groupManager->isAdmin($user->getUID()),
			// Nextcloud Talk: video meetings in the invitations
			'talk' => $user !== null && $this->apps->isEnabledForUser('spreed', $user),
			// video meetings with Element Call (Matrix) or another service, set by the administrator
			// (else, the chat of the organisation found among the « Sites externes »: before Talk)
			'visio' => $visio['visio'],
			'visioMode' => $visio['mode'],
			// most attachments of one message (bytes), set by the administrator
			'maxAttachments' => $this->servers->getMaxAttachmentsBytes(),
			// folders whose messages show their remote images at once (the user's choice)
			'imageFolders' => $user !== null ? $this->trustedImages->folders($user->getUID()) : null,
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
