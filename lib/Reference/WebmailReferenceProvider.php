<?php

declare(strict_types=1);

namespace OCA\Webmail\Reference;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\UnifiedSearchService;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Links of the webmail in Talk, Text, Deck, Collectives…: a message or an event shown with its
 * title and line (for the user whose account it is), and « / » → « Courrier et agenda Exchange »
 * to look for one and insert its link (the Smart Picker, through the unified search).
 */
class WebmailReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {
	public function __construct(
		private UnifiedSearchService $search,
		private IURLGenerator $url,
		private IL10N $l,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return 'webmail-exchange';
	}

	public function getTitle(): string {
		return $this->l->t('Courrier et agenda Exchange');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getSupportedSearchProviderIds(): array {
		return ['webmail-mail', 'webmail-events'];
	}

	public function matchReference(string $referenceText): bool {
		return UnifiedSearchService::parseLink($referenceText, $this->url->linkToRouteAbsolute('webmail.page.index')) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		if ($this->userId === null || !$this->matchReference($referenceText)) {
			return null;
		}
		$found = $this->search->reference($this->userId, $referenceText);
		if ($found === null) {
			return null;
		}
		$reference = new Reference($referenceText);
		$reference->setTitle($found['title']);
		$reference->setDescription($found['description']);
		$reference->setImageUrl($this->url->getAbsoluteURL($this->url->imagePath('core', $found['kind'] === 'event' ? 'places/calendar.svg' : 'actions/mail.svg')));
		$reference->setUrl($referenceText);
		return $reference;
	}

	/** Per user: the same link shows nothing to someone whose account it is not. */
	public function getCachePrefix(string $referenceId): string {
		return (string)$this->userId;
	}

	public function getCacheKey(string $referenceId): ?string {
		return $referenceId;
	}
}
