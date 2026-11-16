<?php

declare(strict_types=1);

namespace OCA\WebmailTestDeck\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\CORS;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * The routes of Deck's REST API (v1.0) the webmail uses, with the same attributes as Deck's
 * own (CORS: the browser's session is kept only with the request token). Two boards, one
 * read only; the cards made are kept in the app's config.
 */
class ApiController extends \OCP\AppFramework\ApiController {
	private const BOARDS = [
		['id' => 1, 'title' => 'Projets', 'archived' => false, 'deletedAt' => 0,
			'permissions' => ['PERMISSION_READ' => true, 'PERMISSION_EDIT' => true, 'PERMISSION_MANAGE' => true, 'PERMISSION_SHARE' => true]],
		['id' => 2, 'title' => 'Lecture seule', 'archived' => false, 'deletedAt' => 0,
			'permissions' => ['PERMISSION_READ' => true, 'PERMISSION_EDIT' => false, 'PERMISSION_MANAGE' => false, 'PERMISSION_SHARE' => false]],
		['id' => 3, 'title' => 'Ancien', 'archived' => true, 'deletedAt' => 0,
			'permissions' => ['PERMISSION_READ' => true, 'PERMISSION_EDIT' => true, 'PERMISSION_MANAGE' => true, 'PERMISSION_SHARE' => true]],
	];
	private const STACKS = [
		1 => [['id' => 11, 'title' => 'À faire', 'boardId' => 1, 'order' => 0], ['id' => 12, 'title' => 'En cours', 'boardId' => 1, 'order' => 1]],
		2 => [['id' => 21, 'title' => 'Notes', 'boardId' => 2, 'order' => 0]],
		3 => [['id' => 31, 'title' => 'Vieux', 'boardId' => 3, 'order' => 0]],
	];

	public function __construct(IRequest $request, private IConfig $config, private ?string $userId) {
		parent::__construct('deck', $request);
	}

	#[NoAdminRequired]
	#[CORS]
	#[NoCSRFRequired]
	public function boards(): DataResponse {
		return new DataResponse(self::BOARDS);
	}

	#[NoAdminRequired]
	#[CORS]
	#[NoCSRFRequired]
	public function stacks(int $boardId): DataResponse {
		return new DataResponse(self::STACKS[$boardId] ?? [], isset(self::STACKS[$boardId]) ? Http::STATUS_OK : Http::STATUS_NOT_FOUND);
	}

	#[NoAdminRequired]
	#[CORS]
	#[NoCSRFRequired]
	public function createCard(int $boardId, int $stackId, string $title, string $type = 'plain', int $order = 999, string $description = '', ?string $duedate = null): DataResponse {
		$stack = array_values(array_filter(self::STACKS[$boardId] ?? [], fn ($s) => $s['id'] === $stackId));
		$board = array_values(array_filter(self::BOARDS, fn ($b) => $b['id'] === $boardId));
		if ($stack === [] || $board === []) {
			return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		if (!$board[0]['permissions']['PERMISSION_EDIT']) {
			return new DataResponse(['message' => 'Permission denied'], Http::STATUS_FORBIDDEN);
		}
		if (trim($title) === '' || mb_strlen($title) > 255) {
			return new DataResponse(['message' => 'title must be provided'], Http::STATUS_BAD_REQUEST);
		}
		$cards = json_decode($this->config->getAppValue('deck', 'test_cards', '[]'), true) ?: [];
		$card = ['id' => 100 + count($cards), 'title' => $title, 'stackId' => $stackId, 'boardId' => $boardId, 'type' => $type, 'order' => $order,
			'description' => $description, 'duedate' => $duedate, 'owner' => $this->userId];
		$cards[] = $card;
		$this->config->setAppValue('deck', 'test_cards', (string)json_encode($cards));
		return new DataResponse($card);
	}

	#[NoAdminRequired]
	#[CORS]
	#[NoCSRFRequired]
	public function cards(): DataResponse {
		return new DataResponse(json_decode($this->config->getAppValue('deck', 'test_cards', '[]'), true) ?: []);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function card(int $boardId, int $cardId): DataDisplayResponse {
		return new DataDisplayResponse('Carte ' . $cardId . ' du tableau ' . $boardId, Http::STATUS_OK, ['Content-Type' => 'text/plain']);
	}
}
