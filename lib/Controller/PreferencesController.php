<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** The user's own display choices kept by the server (all their devices): the theme. */
class PreferencesController extends Controller {
	use ErrorHandling;

	/** '' as Nextcloud (the user's theme of Nextcloud, or that of the device), light, dark */
	public const THEMES = ['', 'light', 'dark'];
	public const THEME = 'theme';

	public function __construct(
		IRequest $request,
		private IConfig $config,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function logger(): LoggerInterface {
		return $this->logger;
	}

	#[NoAdminRequired]
	public function theme(string $theme = ''): Response {
		return $this->handle(function () use ($theme) {
			if (!in_array($theme, self::THEMES, true)) {
				throw new MailException('Thème inconnu');
			}
			if ($theme === '') {
				$this->config->deleteUserValue((string)$this->userId, Application::APP_ID, self::THEME);
			} else {
				$this->config->setUserValue((string)$this->userId, Application::APP_ID, self::THEME, $theme);
			}
			return ['theme' => $theme];
		});
	}
}
