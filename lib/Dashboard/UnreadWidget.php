<?php

declare(strict_types=1);

namespace OCA\Webmail\Dashboard;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Service\DashboardService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IURLGenerator;

/**
 * Dashboard widget: the newest unread messages of all the Exchange accounts.
 */
class UnreadWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IReloadableWidget {
	public function __construct(
		private DashboardService $dashboard,
		private IURLGenerator $url,
	) {
	}

	public function getId(): string {
		return 'webmail-unread';
	}

	public function getTitle(): string {
		return 'Courrier non lu';
	}

	public function getOrder(): int {
		return 4;
	}

	public function getIconClass(): string {
		return 'icon-mail';
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute('webmail.page.index');
	}

	public function load(): void {
		// rendered by the dashboard from getItemsV2()
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$avatar = fn (string $name) => $this->url->getAbsoluteURL($this->url->linkToRoute('core.GuestAvatar.getAvatar', ['guestName' => $name !== '' ? $name : '?', 'size' => 44]));
		$messages = $this->dashboard->unread($userId, $limit);
		$several = count(array_unique(array_column($messages, 'account'))) > 1;
		$items = array_map(fn ($m) => new WidgetItem(
			$m['subject'],
			$m['from'] . ($several ? ' · ' . $m['account'] : ''),
			$this->url->linkToRouteAbsolute('webmail.page.index') . '?' . http_build_query(['account' => $m['accountId'], 'message' => $m['id']]),
			$avatar($m['from']),
			$m['date'],
		), $messages);
		return new WidgetItems($items, 'Aucun message non lu');
	}

	public function getWidgetButtons(string $userId): array {
		return [new WidgetButton(WidgetButton::TYPE_MORE, $this->url->linkToRouteAbsolute('webmail.page.index'), 'Ouvrir le courrier')];
	}

	public function getReloadInterval(): int {
		return 120;
	}
}
