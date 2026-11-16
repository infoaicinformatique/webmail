<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'api#boards', 'url' => '/api/v1.0/boards', 'verb' => 'GET'],
		['name' => 'api#stacks', 'url' => '/api/v1.0/boards/{boardId}/stacks', 'verb' => 'GET'],
		['name' => 'api#createCard', 'url' => '/api/v1.0/boards/{boardId}/stacks/{stackId}/cards', 'verb' => 'POST'],
		// for the test: the cards made
		['name' => 'api#cards', 'url' => '/api/v1.0/test/cards', 'verb' => 'GET'],
		['name' => 'api#card', 'url' => '/board/{boardId}/card/{cardId}', 'verb' => 'GET'],
	],
];
