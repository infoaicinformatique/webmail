<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Exception\MailException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

trait ErrorHandling {
	abstract protected function logger(): LoggerInterface;

	/** The app's texts in the language of the user (French: the texts as they are). */
	protected function l10n(): IL10N {
		return \OCP\Server::get(IFactory::class)->get(Application::APP_ID);
	}

	/** The message of an error, in the language of the user. */
	protected function errorMessage(MailException $e): string {
		return $e->translate($this->l10n());
	}

	/**
	 * @param callable(): Response|array $fn
	 */
	protected function handle(callable $fn): Response {
		try {
			$result = $fn();
			return $result instanceof Response ? $result : new JSONResponse($result);
		} catch (MailException $e) {
			// the log keeps the French text
			$this->logger()->info('Webmail: ' . $e->getMessage(), ['app' => 'webmail', 'exception' => $e]);
			return new JSONResponse(['message' => $this->errorMessage($e)], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger()->error('Webmail: ' . $e->getMessage(), ['app' => 'webmail', 'exception' => $e]);
			return new JSONResponse(['message' => $this->l10n()->t('Erreur interne, consultez le journal Nextcloud')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
