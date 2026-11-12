<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		['name' => 'account#index', 'url' => '/api/accounts', 'verb' => 'GET'],
		['name' => 'account#create', 'url' => '/api/accounts', 'verb' => 'POST'],
		['name' => 'account#update', 'url' => '/api/accounts/{id}', 'verb' => 'PUT'],
		['name' => 'account#destroy', 'url' => '/api/accounts/{id}', 'verb' => 'DELETE'],

		['name' => 'mail#folders', 'url' => '/api/accounts/{id}/folders', 'verb' => 'GET'],
		['name' => 'mail#messages', 'url' => '/api/accounts/{id}/messages', 'verb' => 'GET'],
		['name' => 'mail#message', 'url' => '/api/accounts/{id}/message', 'verb' => 'GET'],
		['name' => 'mail#attachment', 'url' => '/api/accounts/{id}/attachment', 'verb' => 'GET'],
		['name' => 'mail#saveAttachment', 'url' => '/api/accounts/{id}/attachment/save', 'verb' => 'POST'],
		['name' => 'mail#flags', 'url' => '/api/accounts/{id}/flags', 'verb' => 'POST'],
		['name' => 'mail#move', 'url' => '/api/accounts/{id}/move', 'verb' => 'POST'],
		['name' => 'mail#delete', 'url' => '/api/accounts/{id}/delete', 'verb' => 'POST'],
		['name' => 'mail#send', 'url' => '/api/accounts/{id}/send', 'verb' => 'POST'],

		['name' => 'admin#get', 'url' => '/api/admin/servers', 'verb' => 'GET'],
		['name' => 'admin#save', 'url' => '/api/admin/servers', 'verb' => 'PUT'],
	],
];
