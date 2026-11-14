<?php

declare(strict_types=1);

namespace OCA\Webmail\Listener;

use OCA\Webmail\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * In the Files app: the « Envoyer par e-mail » action, which opens a new
 * message of the webmail with the files chosen attached.
 *
 * @template-implements IEventListener<Event>
 */
class FilesScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		Util::addTranslations(Application::APP_ID);
		// before the Files app reads its actions
		if (method_exists(Util::class, 'addInitScript')) {
			Util::addInitScript(Application::APP_ID, 'files-action');
		} else {
			Util::addScript(Application::APP_ID, 'files-action');
		}
	}
}
