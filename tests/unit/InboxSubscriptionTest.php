<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\MailNotificationService;

class InboxSubscriptionTest extends EwsTestCase {
	public function testSubscribe(): void {
		$this->queue(self::ok('Subscribe', '<m:SubscriptionId>SUB1</m:SubscriptionId><m:Watermark>W1</m:Watermark>'));
		$this->assertSame(['id' => 'SUB1', 'watermark' => 'W1'], $this->client()->subscribeInbox(60));
		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:PullSubscriptionRequest><t:FolderIds><t:DistinguishedFolderId Id="inbox"/></t:FolderIds>'
			. '<t:EventTypes><t:EventType>NewMailEvent</t:EventType></t:EventTypes><t:Timeout>60</t:Timeout>', $req);
	}

	public function testNewMailSinceTheLastCheck(): void {
		$event = fn ($w, $id) => '<t:NewMailEvent><t:Watermark>' . $w . '</t:Watermark><t:TimeStamp>2026-11-13T08:00:00Z</t:TimeStamp>'
			. '<t:ItemId Id="' . $id . '" ChangeKey="K"/><t:ParentFolderId Id="INBOX" ChangeKey="K"/></t:NewMailEvent>';
		// two pages (MoreEvents), and a status event when nothing happened
		$this->queue(self::ok('GetEvents', '<m:Notification><t:SubscriptionId>SUB1</t:SubscriptionId><t:PreviousWatermark>W1</t:PreviousWatermark>'
			. '<t:MoreEvents>true</t:MoreEvents>' . $event('W2', 'M1') . $event('W3', 'M2') . '</m:Notification>'));
		$this->queue(self::ok('GetEvents', '<m:Notification><t:SubscriptionId>SUB1</t:SubscriptionId><t:PreviousWatermark>W3</t:PreviousWatermark>'
			. '<t:MoreEvents>false</t:MoreEvents>' . $event('W4', 'M3') . $event('W5', 'M2') . '</m:Notification>'));
		$r = $this->client()->newMailEvents('SUB1', 'W1');
		$this->assertSame(['itemIds' => ['M1', 'M2', 'M3'], 'watermark' => 'W5'], $r);
		$this->assertStringContainsString('<m:GetEvents><m:SubscriptionId>SUB1</m:SubscriptionId><m:Watermark>W3</m:Watermark></m:GetEvents>', $this->lastRequest());
	}

	public function testNothingNew(): void {
		$this->queue(self::ok('GetEvents', '<m:Notification><t:SubscriptionId>SUB1</t:SubscriptionId><t:PreviousWatermark>W1</t:PreviousWatermark>'
			. '<t:MoreEvents>false</t:MoreEvents><t:StatusEvent><t:Watermark>W2</t:Watermark></t:StatusEvent></m:Notification>'));
		$this->assertSame(['itemIds' => [], 'watermark' => 'W2'], $this->client()->newMailEvents('SUB1', 'W1'));
	}

	public function testExpiredSubscription(): void {
		$this->queue('<m:GetEventsResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages"><m:ResponseMessages>'
			. '<m:GetEventsResponseMessage ResponseClass="Error"><m:MessageText>x</m:MessageText><m:ResponseCode>ErrorSubscriptionNotFound</m:ResponseCode>'
			. '<m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:GetEventsResponseMessage></m:ResponseMessages></m:GetEventsResponse>');
		$this->assertNull($this->client()->newMailEvents('SUB1', 'W1'));
	}

	public function testSummariesOfTheNewMessages(): void {
		$this->queue('<m:GetItemResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>'
			. '<m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items><t:Message><t:ItemId Id="M1" ChangeKey="K"/>'
			. '<t:Subject>Devis</t:Subject><t:DateTimeReceived>2026-11-13T08:00:00Z</t:DateTimeReceived><t:IsRead>false</t:IsRead></t:Message></m:Items></m:GetItemResponseMessage>'
			. '<m:GetItemResponseMessage ResponseClass="Error"><m:MessageText>gone</m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode><m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:GetItemResponseMessage>'
			. '</m:ResponseMessages></m:GetItemResponse>');
		$items = $this->client()->summaries(['M1', 'M2']);
		$this->assertCount(1, $items);
		$this->assertSame('Devis', $items[0]['subject']);
		$this->assertStringContainsString('<t:FieldURI FieldURI="item:ParentFolderId"/>', $this->lastRequest());
	}

	public function testAnnouncedOnce(): void {
		$items = [
			['id' => 'A', 'date' => '2026-11-13T08:00:00Z', 'isRead' => false],
			['id' => 'B', 'date' => '2026-11-13T09:00:00Z', 'isRead' => false],
			['id' => 'C', 'date' => '2026-11-13T09:30:00Z', 'isRead' => true],
		];
		$this->assertSame(['B', 'A'], array_column(MailNotificationService::unreadNewestFirst($items), 'id'));
		$this->assertSame(['B', 'A', 'X'], MailNotificationService::seenIds(['B', 'A'], ['A', 'X']));
		$this->assertCount(50, MailNotificationService::seenIds(range(1, 60), []));
	}
}
