<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Exception\MailException;

class EmlImportTest extends EwsTestCase {
	private const EML = "From: Jean Dupont <jean@x.fr>\r\nTo: user@societe-a.fr\r\nSubject: Ancien devis\r\nDate: Mon, 2 Nov 2026 10:00:00 +0100\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBonjour\r\n";

	public function testImportedAsAReceivedMessage(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:Message><t:ItemId Id="NEW" ChangeKey="CK"/></t:Message></m:Items>'));
		$this->assertSame('NEW', $this->client()->importMime(self::EML, 'F-CLIENTS'));
		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:CreateItem MessageDisposition="SaveOnly"><m:SavedItemFolderId><t:FolderId Id="F-CLIENTS"/></m:SavedItemFolderId>', $req);
		$this->assertStringContainsString('<t:MimeContent CharacterSet="UTF-8">' . base64_encode(self::EML) . '</t:MimeContent>', $req);
		// not a draft: read and received (PR_MESSAGE_FLAGS)
		$this->assertStringContainsString('<t:ExtendedFieldURI PropertyTag="0x0E07" PropertyType="Integer"/><t:Value>1</t:Value>', $req);
	}

	public function testIntoTheInbox(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:Message><t:ItemId Id="NEW" ChangeKey="CK"/></t:Message></m:Items>'));
		$this->client()->importMime("\xEF\xBB\xBF" . self::EML, 'inbox');
		$this->assertStringContainsString('<t:DistinguishedFolderId Id="inbox"/>', $this->lastRequest());
	}

	public function testNotAMessage(): void {
		foreach (['%PDF-1.7 binary', "Bonjour,\nvoici le texte.\n", "<html><body>x</body></html>"] as $bad) {
			try {
				$this->client()->importMime($bad, 'inbox');
				$this->fail('refused: ' . substr($bad, 0, 12));
			} catch (MailException $e) {
				$this->assertSame('Ce fichier n\'est pas un message (.eml)', $e->getMessage());
			}
		}
		$this->assertSame([], $this->requests, 'nothing sent to Exchange');
	}
}
