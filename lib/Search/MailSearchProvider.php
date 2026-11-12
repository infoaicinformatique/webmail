<?php

declare(strict_types=1);

namespace OCA\Webmail\Search;

use OCA\Webmail\Service\UnifiedSearchService;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * "Courrier Exchange" in Nextcloud's unified search.
 */
class MailSearchProvider implements IProvider {
	public function __construct(
		private UnifiedSearchService $search,
	) {
	}

	public function getId(): string {
		return 'webmail-mail';
	}

	public function getName(): string {
		return 'Courrier Exchange';
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		// first inside the webmail, among the others elsewhere
		return str_starts_with($route, 'webmail.') ? -5 : 40;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$r = $this->search->messages($user->getUID(), $query->getTerm(), (int)$query->getCursor(), $query->getLimit());
		$entries = array_map(fn ($e) => new SearchResultEntry('', $e['title'], $e['subline'], $e['url'], 'icon-mail'), $r['entries']);
		return $r['next'] !== null
			? SearchResult::paginated($this->getName(), $entries, $r['next'])
			: SearchResult::complete($this->getName(), $entries);
	}
}
