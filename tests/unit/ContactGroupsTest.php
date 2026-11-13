<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsContacts;
use OCA\Webmail\Exception\MailException;

class ContactGroupsTest extends EwsTestCase {
	private function contacts(): EwsContacts {
		return new EwsContacts($this->client());
	}

	public function testCreateAGroup(): void {
		$this->queue(self::ok('CreateItem', '<m:Items><t:DistributionList><t:ItemId Id="G1" ChangeKey="C"/></t:DistributionList></m:Items>'));
		$id = $this->contacts()->createGroup(['displayName' => 'Équipe chantier', 'members' => [
			['name' => 'Jean Dupont', 'email' => 'Jean.Dupont@exemple.fr'],
			['name' => '', 'email' => 'paul@exemple.fr'],
			['name' => 'Doublon', 'email' => 'jean.dupont@exemple.fr'],
			['email' => ''],
		]]);
		$this->assertSame('G1', $id);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:DistributionList><t:DisplayName>Équipe chantier</t:DisplayName><t:FileAs>Équipe chantier</t:FileAs><t:Members>', $req);
		$this->assertSame(2, substr_count($req, '<t:Member>'));
		$this->assertStringContainsString('<t:Name>Jean Dupont</t:Name><t:EmailAddress>jean.dupont@exemple.fr</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType><t:MailboxType>OneOff</t:MailboxType>', $req);
		$this->assertStringContainsString('<t:Name>paul@exemple.fr</t:Name>', $req);
	}

	public function testUpdateAGroup(): void {
		$this->contacts()->updateGroup('G1', ['displayName' => 'Équipe', 'members' => [['name' => 'Paul', 'email' => 'paul@exemple.fr']]]);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<t:FieldURI FieldURI="distributionlist:Members"/><t:DistributionList><t:Members><t:Member>', $req);
		$this->assertStringContainsString('<t:FieldURI FieldURI="contacts:DisplayName"/><t:DistributionList><t:DisplayName>Équipe</t:DisplayName>', $req);
		// everybody removed
		$this->contacts()->updateGroup('G1', ['displayName' => 'Équipe', 'members' => []]);
		$this->assertStringContainsString('<t:DeleteItemField><t:FieldURI FieldURI="distributionlist:Members"/></t:DeleteItemField>', $this->lastRequest());
	}

	public function testMembersOfAGroup(): void {
		$this->queue(self::ok('GetItem', '<m:Items><t:DistributionList><t:ItemId Id="G1" ChangeKey="C"/><t:Members>'
			. '<t:Member><t:Mailbox><t:Name>Jean Dupont</t:Name><t:EmailAddress>jean@exemple.fr</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType><t:MailboxType>Contact</t:MailboxType></t:Mailbox></t:Member>'
			. '<t:Member><t:Mailbox><t:Name>Ancien collègue</t:Name><t:EmailAddress>/o=ExchangeLabs/ou=x/cn=y</t:EmailAddress><t:RoutingType>EX</t:RoutingType></t:Mailbox></t:Member>'
			. '</t:Members></t:DistributionList></m:Items>'));
		$this->assertSame([['name' => 'Jean Dupont', 'email' => 'jean@exemple.fr'], ['name' => 'Ancien collègue', 'email' => '']], $this->contacts()->groupMembers('G1'));
	}

	public function testInvalid(): void {
		$this->expectException(MailException::class);
		EwsContacts::normalizeGroup(['displayName' => 'x', 'members' => [['email' => 'pas une adresse']]]);
	}

	public function testNameNeeded(): void {
		$this->expectException(MailException::class);
		EwsContacts::normalizeGroup(['displayName' => '  ', 'members' => []]);
	}
}
