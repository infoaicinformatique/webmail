<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

class MarkFolderReadTest extends EwsTestCase {
	public function testWholeFolderReadInOneRequest(): void {
		$this->queue(self::ok('MarkAllItemsAsRead'));
		$this->client()->markFolderRead('F-CLIENTS');
		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:MarkAllItemsAsRead><m:ReadFlag>true</m:ReadFlag><m:SuppressReadReceipts>true</m:SuppressReadReceipts>'
			. '<m:FolderIds><t:FolderId Id="F-CLIENTS"/></m:FolderIds></m:MarkAllItemsAsRead>', $req);
	}

	public function testDistinguishedFolder(): void {
		$this->queue(self::ok('MarkAllItemsAsRead'));
		$this->client()->markFolderRead('inbox');
		$this->assertStringContainsString('<m:FolderIds><t:DistinguishedFolderId Id="inbox"/></m:FolderIds>', $this->lastRequest());
	}
}
