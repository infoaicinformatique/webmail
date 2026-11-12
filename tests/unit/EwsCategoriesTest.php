<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsCategories;
use OCA\Webmail\Exception\MailException;

class EwsCategoriesTest extends EwsTestCase {
	private const NS = 'xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"';

	private const LIST = "\xEF\xBB\xBF" . '<?xml version="1.0"?><categories default="Catégorie rouge" lastSavedSession="2" xmlns="CategoryList.xsd">'
		. '<category name="Clients" color="7" keyboardShortcut="0" guid="{A}" renameOnFirstUse="0"/>'
		. '<category name="Catégorie rouge" color="0" guid="{B}" renameOnFirstUse="1"/>'
		. '<category name="Sans couleur" color="-1" guid="{C}"/>'
		. '</categories>';

	private function config(?string $xml): string {
		if ($xml === null) {
			return '<m:GetUserConfigurationResponse ' . self::NS . '><m:ResponseMessages><m:GetUserConfigurationResponseMessage ResponseClass="Error">'
				. '<m:MessageText>The specified object was not found in the store.</m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode>'
				. '</m:GetUserConfigurationResponseMessage></m:ResponseMessages></m:GetUserConfigurationResponse>';
		}
		return self::ok('GetUserConfiguration', '<m:UserConfiguration><t:UserConfigurationName Name="CategoryList"><t:FolderId Id="CAL"/></t:UserConfigurationName>'
			. '<t:XmlData>' . base64_encode($xml) . '</t:XmlData></m:UserConfiguration>');
	}

	public function testList(): void {
		$this->queue($this->config(self::LIST));
		$this->assertSame([
			['name' => 'Catégorie rouge', 'color' => '#e7a1a2', 'colorIndex' => 0],
			['name' => 'Clients', 'color' => '#9db7e8', 'colorIndex' => 7],
			['name' => 'Sans couleur', 'color' => null, 'colorIndex' => -1],
		], (new EwsCategories($this->client()))->list());
		$this->assertStringContainsString('<m:GetUserConfiguration><m:UserConfigurationName Name="CategoryList"><t:DistinguishedFolderId Id="calendar"/></m:UserConfigurationName>'
			. '<m:UserConfigurationProperties>XmlData</m:UserConfigurationProperties></m:GetUserConfiguration>', $this->lastRequest());
	}

	public function testNoListYet(): void {
		$this->queue($this->config(null));
		$this->assertSame([], (new EwsCategories($this->client()))->list());
	}

	public function testAddKeepsWhatOutlookWrote(): void {
		$this->queue($this->config(self::LIST));
		$this->queue(self::ok('UpdateUserConfiguration'));
		(new EwsCategories($this->client()))->add('Fournisseurs', 4);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:UpdateUserConfiguration><m:UserConfiguration><t:UserConfigurationName Name="CategoryList"><t:DistinguishedFolderId Id="calendar"/></t:UserConfigurationName><t:XmlData>', $req);
		preg_match('#<t:XmlData>([^<]+)</t:XmlData>#', $req, $m);
		$xml = base64_decode($m[1]);
		$this->assertStringContainsString('renameOnFirstUse="1"', $xml);
		$this->assertStringContainsString('default="Catégorie rouge"', $xml);
		$this->assertMatchesRegularExpression('#<category name="Fournisseurs" color="4" [^>]*guid="\{[0-9A-F-]{36}\}"/>#', $xml);
		$this->assertCount(4, EwsCategories::parse($xml));
	}

	public function testAddCreatesTheList(): void {
		$this->queue($this->config(null));
		$this->queue(self::ok('CreateUserConfiguration'));
		(new EwsCategories($this->client()))->add('Urgent', 15);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:CreateUserConfiguration><m:UserConfiguration>', $req);
		preg_match('#<t:XmlData>([^<]+)</t:XmlData>#', $req, $m);
		$this->assertSame([['name' => 'Urgent', 'color' => '#c11a25', 'colorIndex' => 15]], EwsCategories::parse(base64_decode($m[1])));
	}

	public function testExistingNameIsNotAddedTwice(): void {
		$this->assertNull(EwsCategories::withCategory(self::LIST, 'clients', 2, '{X}', new \DateTimeImmutable()));
	}

	public function testBadNames(): void {
		foreach (['', 'a,b', 'a;b'] as $name) {
			try {
				(new EwsCategories($this->client()))->add($name, 1);
				$this->fail('accepted: ' . $name);
			} catch (MailException $e) {
				$this->assertStringContainsString('invalide', $e->getMessage());
			}
		}
	}

	public function testSetAndClearCategoriesOfMessages(): void {
		$this->queue(self::ok('UpdateItem'));
		$this->client()->setCategories(['M1', 'M2'], ['Clients', ' Clients', 'Urgent & important', '']);
		$req = $this->lastRequest();
		$this->assertSame(2, substr_count($req, '<t:SetItemField><t:FieldURI FieldURI="item:Categories"/><t:Message><t:Categories><t:String>Clients</t:String><t:String>Urgent &amp; important</t:String></t:Categories></t:Message></t:SetItemField>'));

		$this->queue(self::ok('UpdateItem'));
		$this->client()->setCategories(['M1'], []);
		$this->assertStringContainsString('<t:ItemChange><t:ItemId Id="M1"/><t:Updates><t:DeleteItemField><t:FieldURI FieldURI="item:Categories"/></t:DeleteItemField></t:Updates></t:ItemChange>', $this->lastRequest());
	}
}
