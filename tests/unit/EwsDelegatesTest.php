<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsDelegates;
use OCA\Webmail\Exception\MailException;

class EwsDelegatesTest extends EwsTestCase {
	private const M = 'xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"';

	private function delegates(): EwsDelegates {
		return new EwsDelegates($this->client(), 'user@example.fr');
	}

	private static function response(string $op, string $users = '', string $deliver = 'DelegatesAndMe', string $class = 'Success', string $code = 'NoError'): string {
		return '<m:' . $op . ' ResponseClass="' . $class . '" ' . self::M . '><m:ResponseCode>' . $code . '</m:ResponseCode>'
			. ($users !== '' ? '<m:ResponseMessages>' . $users . '</m:ResponseMessages>' : '')
			. ($op === 'GetDelegateResponse' ? '<m:DeliverMeetingRequests>' . $deliver . '</m:DeliverMeetingRequests>' : '')
			. '</m:' . $op . '>';
	}

	private static function user(string $email, string $name, string $cal, string $inbox, bool $copies): string {
		return '<m:DelegateUserResponseMessageType ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:DelegateUser>'
			. '<t:UserId><t:SID>S-1</t:SID><t:PrimarySmtpAddress>' . $email . '</t:PrimarySmtpAddress><t:DisplayName>' . $name . '</t:DisplayName></t:UserId>'
			. '<t:DelegatePermissions><t:CalendarFolderPermissionLevel>' . $cal . '</t:CalendarFolderPermissionLevel>'
			. '<t:TasksFolderPermissionLevel>None</t:TasksFolderPermissionLevel><t:InboxFolderPermissionLevel>' . $inbox . '</t:InboxFolderPermissionLevel>'
			. '<t:ContactsFolderPermissionLevel>Reviewer</t:ContactsFolderPermissionLevel></t:DelegatePermissions>'
			. '<t:ReceiveCopiesOfMeetingMessages>' . ($copies ? 'true' : 'false') . '</t:ReceiveCopiesOfMeetingMessages><t:ViewPrivateItems>false</t:ViewPrivateItems>'
			. '</m:DelegateUser></m:DelegateUserResponseMessageType>';
	}

	public function testList(): void {
		$this->queue(self::response('GetDelegateResponse',
			self::user('Paul@x.fr', 'Paul Durand', 'Reviewer', 'None', false) . self::user('marie@x.fr', 'Marie Martin', 'Editor', 'Editor', true),
			'DelegatesOnly'));
		$res = $this->delegates()->list();
		$this->assertSame('DelegatesOnly', $res['deliverMeetingRequests']);
		$this->assertSame(['Marie Martin', 'Paul Durand'], array_column($res['delegates'], 'name'));
		$this->assertSame(['calendar' => 'Editor', 'tasks' => 'None', 'inbox' => 'Editor', 'contacts' => 'Reviewer'], $res['delegates'][0]['permissions']);
		$this->assertTrue($res['delegates'][0]['receiveCopies']);
		$this->assertSame('paul@x.fr', $res['delegates'][1]['email']);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:GetDelegate IncludePermissions="true"><m:Mailbox><t:EmailAddress>user@example.fr</t:EmailAddress></m:Mailbox></m:GetDelegate>', $req);
	}

	public function testListWhenNeverConfigured(): void {
		$this->queue(self::response('GetDelegateResponse', '', 'DelegatesAndMe', 'Error', 'ErrorDelegateMissingConfiguration'));
		$this->assertSame(['delegates' => [], 'deliverMeetingRequests' => 'DelegatesAndMe'], $this->delegates()->list());
	}

	public function testAdd(): void {
		$this->queue(self::response('AddDelegateResponse', self::user('marie@x.fr', 'Marie Martin', 'Editor', 'None', true)));
		$this->delegates()->add(['email' => 'Marie Martin <Marie@x.fr>', 'permissions' => ['calendar' => 'Editor', 'contacts' => 'Reviewer'],
			'receiveCopies' => true, 'viewPrivate' => false]);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:DelegateUser><t:UserId><t:PrimarySmtpAddress>marie@x.fr</t:PrimarySmtpAddress></t:UserId>'
			. '<t:DelegatePermissions><t:CalendarFolderPermissionLevel>Editor</t:CalendarFolderPermissionLevel><t:TasksFolderPermissionLevel>None</t:TasksFolderPermissionLevel>'
			. '<t:InboxFolderPermissionLevel>None</t:InboxFolderPermissionLevel><t:ContactsFolderPermissionLevel>Reviewer</t:ContactsFolderPermissionLevel></t:DelegatePermissions>'
			. '<t:ReceiveCopiesOfMeetingMessages>true</t:ReceiveCopiesOfMeetingMessages><t:ViewPrivateItems>false</t:ViewPrivateItems></t:DelegateUser>', $req);
	}

	public function testAddUnknownUser(): void {
		$this->queue(self::response('AddDelegateResponse', '<m:DelegateUserResponseMessageType ResponseClass="Error"><m:MessageText>The user could not be found.</m:MessageText>'
			. '<m:ResponseCode>ErrorDelegateNoUser</m:ResponseCode></m:DelegateUserResponseMessageType>'));
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('Aucune boîte Exchange ne correspond');
		$this->delegates()->add(['email' => 'inconnu@x.fr', 'permissions' => ['calendar' => 'Reviewer']]);
	}

	public function testMeetingCopiesNeedCalendarEditor(): void {
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('Éditeur');
		$this->delegates()->add(['email' => 'paul@x.fr', 'permissions' => ['calendar' => 'Reviewer'], 'receiveCopies' => true]);
	}

	public function testCannotDelegateToSelf(): void {
		$this->expectException(MailException::class);
		$this->delegates()->add(['email' => 'USER@example.fr', 'permissions' => []]);
	}

	public function testInvalidLevel(): void {
		$this->expectException(MailException::class);
		$this->delegates()->add(['email' => 'paul@x.fr', 'permissions' => ['calendar' => 'Owner']]);
	}

	public function testUpdateRemoveAndDelivery(): void {
		$this->queue(self::response('UpdateDelegateResponse', self::user('paul@x.fr', 'Paul', 'Author', 'None', false)));
		$this->queue(self::response('RemoveDelegateResponse', '<m:DelegateUserResponseMessageType ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode></m:DelegateUserResponseMessageType>'));
		$this->queue(self::response('UpdateDelegateResponse'));
		$d = $this->delegates();
		$d->update(['email' => 'paul@x.fr', 'permissions' => ['calendar' => 'Author']]);
		$this->assertStringContainsString('<m:UpdateDelegate><m:Mailbox>', $this->lastRequest());
		$d->remove('paul@x.fr');
		$this->assertStringContainsString('<m:UserIds><t:UserId><t:PrimarySmtpAddress>paul@x.fr</t:PrimarySmtpAddress></t:UserId></m:UserIds>', $this->lastRequest());
		$d->setDeliverMeetingRequests('DelegatesAndSendInformationToMe');
		$this->assertStringContainsString('<m:DeliverMeetingRequests>DelegatesAndSendInformationToMe</m:DeliverMeetingRequests></m:UpdateDelegate>', $this->lastRequest());
	}

	public function testUnknownDeliveryMode(): void {
		$this->expectException(MailException::class);
		$this->delegates()->setDeliverMeetingRequests('Everyone');
	}
}
