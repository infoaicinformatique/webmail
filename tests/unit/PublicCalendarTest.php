<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsCalendar;
use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Exception\MailException;

class PublicCalendarTest extends EwsTestCase {
	public function testEventsOfAPublicCalendar(): void {
		$this->queue(self::ok('FindItem', '<m:RootFolder TotalItemsInView="0" IncludesLastItemInRange="true"><t:Items/></m:RootFolder>'));
		(new EwsCalendar($this->client()))->findEvents(new \DateTimeImmutable('2026-11-16T00:00:00Z'), new \DateTimeImmutable('2026-11-23T00:00:00Z'), 'public:PF-AGENDA');
		$this->assertStringContainsString('<m:ParentFolderIds><t:FolderId Id="PF-AGENDA"/></m:ParentFolderIds>', $this->lastRequest());
	}

	public function testDeletedForGoodInThePublicFolders(): void {
		$this->queue(self::ok('DeleteItem'));
		(new EwsCalendar($this->client()))->deleteEvent('E1', false, true);
		$this->assertStringContainsString('<m:DeleteItem DeleteType="HardDelete" SendMeetingCancellations="SendToAllAndSaveCopy">', $this->lastRequest());
		$this->queue(self::ok('DeleteItem'));
		(new EwsCalendar($this->client()))->deleteEvent('E1');
		$this->assertStringContainsString('DeleteType="MoveToDeletedItems"', $this->lastRequest());
	}

	public function testBrowsingForCalendars(): void {
		$this->queue(self::ok('FindFolder', '<m:RootFolder TotalItemsInView="3" IncludesLastItemInRange="true"><t:Folders>'
			. '<t:CalendarFolder><t:FolderId Id="PF-AGENDA"/><t:DisplayName>Agenda commun</t:DisplayName><t:FolderClass>IPF.Appointment</t:FolderClass><t:ChildFolderCount>0</t:ChildFolderCount>'
			. '<t:EffectiveRights><t:CreateAssociated>false</t:CreateAssociated><t:CreateContents>true</t:CreateContents><t:CreateHierarchy>false</t:CreateHierarchy><t:Delete>true</t:Delete><t:Modify>true</t:Modify><t:Read>true</t:Read></t:EffectiveRights></t:CalendarFolder>'
			. '<t:ContactsFolder><t:FolderId Id="PF-CLIENTS"/><t:DisplayName>Clients</t:DisplayName><t:FolderClass>IPF.Contact</t:FolderClass><t:ChildFolderCount>0</t:ChildFolderCount></t:ContactsFolder>'
			. '</t:Folders></m:RootFolder>'));
		$folders = (new EwsContacts($this->client()))->listPublicFolders('PF-SOC', 'IPF.Appointment');
		$this->assertSame([['id' => 'PF-AGENDA', 'name' => 'Agenda commun', 'isContacts' => true, 'hasChildren' => false, 'canEdit' => true]], $folders);
	}

	public function testAContactFolderIsNotACalendar(): void {
		$this->queue(self::ok('GetFolder', '<m:Folders><t:ContactsFolder><t:FolderId Id="PF-CLIENTS"/><t:DisplayName>Clients</t:DisplayName><t:FolderClass>IPF.Contact</t:FolderClass></t:ContactsFolder></m:Folders>'));
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('pas un agenda');
		(new EwsContacts($this->client()))->getPublicBook('PF-CLIENTS', 'IPF.Appointment');
	}
}
