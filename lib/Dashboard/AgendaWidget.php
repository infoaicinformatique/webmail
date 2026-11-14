<?php

declare(strict_types=1);

namespace OCA\Webmail\Dashboard;

use OCA\Webmail\Service\DashboardService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Dashboard widget: the next events of the Exchange calendars (one week).
 */
class AgendaWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IReloadableWidget {
	public function __construct(
		private DashboardService $dashboard,
		private IURLGenerator $url,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'webmail-agenda';
	}

	public function getTitle(): string {
		return $this->l->t('Agenda Exchange');
	}

	public function getOrder(): int {
		return 5;
	}

	public function getIconClass(): string {
		return 'icon-calendar-dark';
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath('core', 'places/calendar.svg'));
	}

	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute('webmail.page.index') . '?module=calendar';
	}

	public function load(): void {
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$events = $this->dashboard->upcoming($userId, $limit);
		$several = count(array_unique(array_column($events, 'account'))) > 1;
		$items = array_map(fn ($e) => new WidgetItem(
			$e['subject'],
			$this->dashboard->when($userId, $e['start'], $e['allDay']) . ($e['location'] !== '' ? ' · ' . $e['location'] : '') . ($several ? ' · ' . $e['account'] : ''),
			$this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $e['accountId'], 'module' => 'calendar', 'event' => $e['id']]),
			$this->getIconUrl(),
			$e['start'],
		), $events);
		return new WidgetItems($items, $this->l->t('Aucun rendez-vous dans les 7 prochains jours'));
	}

	public function getWidgetButtons(string $userId): array {
		return [new WidgetButton(WidgetButton::TYPE_MORE, $this->getUrl() ?? '', $this->l->t('Ouvrir l\'agenda'))];
	}

	public function getReloadInterval(): int {
		return 300;
	}
}
