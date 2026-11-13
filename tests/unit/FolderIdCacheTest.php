<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

class FolderIdCacheTest extends EwsTestCase {
	private function folders(string ...$ids): string {
		$out = '<m:GetFolderResponse xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"><m:ResponseMessages>';
		foreach ($ids as $id) {
			$out .= $id === ''
				? '<m:GetFolderResponseMessage ResponseClass="Error"><m:MessageText>no</m:MessageText><m:ResponseCode>ErrorFolderNotFound</m:ResponseCode><m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:GetFolderResponseMessage>'
				: '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="' . $id . '" ChangeKey="x"/></t:Folder></m:Folders></m:GetFolderResponseMessage>';
		}
		return $out . '</m:ResponseMessages></m:GetFolderResponse>';
	}

	public function testAskedOnceThenRemembered(): void {
		$store = [];
		$set = [];
		$c = $this->client();
		$c->setIdCache(function (string $k) use (&$store) { return $store[$k] ?? null; }, function (string $k, array $v, int $ttl) use (&$store, &$set) {
			$store[$k] = $v;
			$set[] = $ttl;
		});
		$this->queue($this->folders('F-INBOX', 'F-SENT'));
		$this->assertSame(['inbox' => 'F-INBOX', 'sentitems' => 'F-SENT'], $c->distinguishedFolderIds(['inbox', 'sentitems']));
		$this->assertCount(1, $this->requests);
		$this->assertSame([86400], $set);

		// known: no request
		$this->assertSame(['sentitems' => 'F-SENT', 'inbox' => 'F-INBOX'], $c->distinguishedFolderIds(['sentitems', 'inbox']));
		$this->assertCount(1, $this->requests);

		// only the ones not known yet are asked; one not found is not remembered
		$this->queue($this->folders('F-CAL', ''));
		$this->assertSame(['inbox' => 'F-INBOX', 'calendar' => 'F-CAL'], $c->distinguishedFolderIds(['inbox', 'calendar', 'tasks']));
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:DistinguishedFolderId Id="calendar"/><t:DistinguishedFolderId Id="tasks"/></m:FolderIds>', $req);
		$this->assertStringNotContainsString('Id="inbox"', $req);
		$this->assertSame(['inbox', 'sentitems', 'calendar'], array_keys(array_values($store)[0]));
	}

	public function testOtherMailboxKeptApart(): void {
		$store = [];
		$c = $this->client();
		$c->setIdCache(function (string $k) use (&$store) { return $store[$k] ?? null; }, function (string $k, array $v) use (&$store) { $store[$k] = $v; });
		$this->queue($this->folders('F-INBOX'));
		$this->queue($this->folders('F-SHARED-INBOX'));
		$this->assertSame(['inbox' => 'F-INBOX'], $c->distinguishedFolderIds(['inbox']));
		$this->assertSame(['inbox' => 'F-SHARED-INBOX'], $c->distinguishedFolderIds(['inbox'], 'contact@societe-a.fr'));
		$this->assertCount(2, $store);
	}

	public function testWithoutCacheAsEver(): void {
		$this->queue($this->folders('F-INBOX'));
		$this->queue($this->folders('F-INBOX'));
		$c = $this->client();
		$c->distinguishedFolderIds(['inbox']);
		$c->distinguishedFolderIds(['inbox']);
		$this->assertCount(2, $this->requests);
	}
}
