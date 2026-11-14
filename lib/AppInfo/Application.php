<?php

declare(strict_types=1);

namespace OCA\Webmail\AppInfo;

use OCA\Webmail\Dashboard\AgendaWidget;
use OCA\Webmail\Dashboard\UnreadWidget;
use OCA\Webmail\Listener\UserDeletedListener;
use OCA\Webmail\Notification\Notifier;
use OCA\Webmail\Reference\WebmailReferenceProvider;
use OCA\Webmail\Search\EventSearchProvider;
use OCA\Webmail\Search\MailSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'webmail';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerDashboardWidget(UnreadWidget::class);
		$context->registerDashboardWidget(AgendaWidget::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerSearchProvider(MailSearchProvider::class);
		$context->registerSearchProvider(EventSearchProvider::class);
		// links of the webmail previewed in Talk, Text, Deck…, and the Smart Picker (« / »)
		$context->registerReferenceProvider(WebmailReferenceProvider::class);
	}

	public function boot(IBootContext $context): void {
	}
}
