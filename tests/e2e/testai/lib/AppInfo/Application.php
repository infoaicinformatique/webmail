<?php

declare(strict_types=1);

namespace OCA\WebmailTestAi\AppInfo;

use OCA\WebmailTestAi\Provider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public function __construct() {
		parent::__construct('webmail_testai');
	}

	public function register(IRegistrationContext $context): void {
		$context->registerTaskProcessingProvider(Provider\Summary::class);
		$context->registerTaskProcessingProvider(Provider\FreePrompt::class);
		$context->registerTaskProcessingProvider(Provider\Translate::class);
	}

	public function boot(IBootContext $context): void {
	}
}
