<?php

declare(strict_types=1);

namespace OCA\Webmail\Controller;

use OCA\Webmail\Exception\MailException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use Psr\Log\LoggerInterface;

trait ErrorHandling {
	abstract protected function logger(): LoggerInterface;

	/**
	 * @param callable(): Response|array $fn
	 */
	protected function handle(callable $fn): Response {
		try {
			$result = $fn();
			return $result instanceof Response ? $result : new JSONResponse($result);
		} catch (MailException $e) {
			$this->logger()->info('Webmail: ' . $e->getMessage(), ['app' => 'webmail', 'exception' => $e]);
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger()->error('Webmail: ' . $e->getMessage(), ['app' => 'webmail', 'exception' => $e]);
			return new JSONResponse(['message' => 'Erreur interne, consultez le journal Nextcloud'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
