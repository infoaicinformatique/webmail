<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsRules;
use OCA\Webmail\Exception\MailException;

class EwsRulesTest extends EwsTestCase {
	private const NS = 'xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"';

	private function rules(): EwsRules {
		return new EwsRules($this->client(), 'user@example.fr');
	}

	private const FACTURES = '<t:Rule><t:RuleId>R1</t:RuleId><t:DisplayName>Factures</t:DisplayName><t:Priority>1</t:Priority><t:IsEnabled>true</t:IsEnabled>'
		. '<t:Conditions><t:ContainsSubjectStrings><t:String>facture</t:String><t:String>avoir</t:String></t:ContainsSubjectStrings>'
		. '<t:FromAddresses><t:Address><t:Name>Compta</t:Name><t:EmailAddress>compta@fournisseur.fr</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType></t:Address></t:FromAddresses></t:Conditions>'
		. '<t:Actions><t:MarkAsRead>true</t:MarkAsRead><t:MoveToFolder><t:FolderId Id="F-FACT" ChangeKey="CK"/></t:MoveToFolder><t:StopProcessingRules>true</t:StopProcessingRules></t:Actions></t:Rule>';

	/** made in Outlook: "sauf si" and a condition the webmail does not edit */
	private const OUTLOOK = '<t:Rule><t:RuleId>R2</t:RuleId><t:DisplayName>Grosses pièces jointes</t:DisplayName><t:Priority>2</t:Priority><t:IsEnabled>false</t:IsEnabled>'
		. '<t:IsNotSupported>false</t:IsNotSupported><t:IsInError>false</t:IsInError>'
		. '<t:Conditions><t:WithinSizeRange><t:MinimumSize>5000</t:MinimumSize></t:WithinSizeRange></t:Conditions>'
		. '<t:Exceptions><t:SentOnlyToMe>true</t:SentOnlyToMe></t:Exceptions>'
		. '<t:Actions><t:AssignCategories><t:String>Lourd &amp; lent</t:String></t:AssignCategories></t:Actions></t:Rule>';

	private function inbox(string ...$rules): string {
		return '<m:GetInboxRulesResponse ResponseClass="Success" ' . self::NS . '><m:ResponseCode>NoError</m:ResponseCode>'
			. '<m:OutlookRuleBlobExists>true</m:OutlookRuleBlobExists><m:InboxRules>' . implode('', $rules) . '</m:InboxRules></m:GetInboxRulesResponse>';
	}

	private function updated(): string {
		return '<m:UpdateInboxRulesResponse ResponseClass="Success" ' . self::NS . '><m:ResponseCode>NoError</m:ResponseCode></m:UpdateInboxRulesResponse>';
	}

	public function testList(): void {
		$this->queue($this->inbox(self::OUTLOOK, self::FACTURES));
		$rules = $this->rules()->list();
		$this->assertSame(['R1', 'R2'], array_column($rules, 'id'), 'by priority');
		$this->assertSame([
			'id' => 'R1', 'name' => 'Factures', 'enabled' => true, 'priority' => 1, 'editable' => true, 'inError' => false,
			'conditions' => ['subject' => ['facture', 'avoir'], 'from' => ['compta@fournisseur.fr']],
			'actions' => ['markRead' => true, 'moveTo' => 'F-FACT', 'stop' => true],
		], $rules[0]);
		$this->assertFalse($rules[1]['editable']);
		$this->assertFalse($rules[1]['enabled']);
		$this->assertSame(['categories' => ['Lourd & lent']], $rules[1]['actions']);
		$this->assertStringContainsString('<m:GetInboxRules><m:MailboxSmtpAddress>user@example.fr</m:MailboxSmtpAddress></m:GetInboxRules>', $this->lastRequest());
	}

	public function testCreateGoesLast(): void {
		$this->queue($this->inbox(self::FACTURES, self::OUTLOOK));
		$this->queue($this->updated());
		$this->rules()->save([
			'name' => 'Newsletters <lues>',
			'conditions' => ['senderContains' => 'news, promo', 'hasAttachments' => false, 'importance' => ''],
			'actions' => ['markRead' => true, 'categories' => ['Pub'], 'forwardTo' => '', 'delete' => false],
		]);
		$req = $this->lastRequest();
		$this->assertStringContainsString('<m:RemoveOutlookRuleBlob>true</m:RemoveOutlookRuleBlob><m:Operations><t:CreateRuleOperation><t:Rule>'
			. '<t:DisplayName>Newsletters &lt;lues&gt;</t:DisplayName><t:Priority>3</t:Priority><t:IsEnabled>true</t:IsEnabled>'
			. '<t:Conditions><t:ContainsSenderStrings><t:String>news</t:String><t:String>promo</t:String></t:ContainsSenderStrings></t:Conditions>'
			. '<t:Actions><t:AssignCategories><t:String>Pub</t:String></t:AssignCategories><t:MarkAsRead>true</t:MarkAsRead></t:Actions>'
			. '</t:Rule></t:CreateRuleOperation></m:Operations>', $req);
	}

	public function testEditKeepsPriority(): void {
		$this->queue($this->inbox(self::FACTURES));
		$this->queue($this->updated());
		$this->rules()->save([
			'id' => 'R1', 'name' => 'Factures', 'enabled' => false,
			'conditions' => ['from' => ['compta@fournisseur.fr'], 'sentTo' => 'moi@example.fr'],
			'actions' => ['moveTo' => 'F-FACT', 'forwardTo' => ['paul@example.fr'], 'markImportance' => 'High'],
		]);
		$this->assertStringContainsString('<t:SetRuleOperation><t:Rule><t:RuleId>R1</t:RuleId><t:DisplayName>Factures</t:DisplayName><t:Priority>1</t:Priority><t:IsEnabled>false</t:IsEnabled>'
			. '<t:Conditions><t:FromAddresses><t:Address><t:EmailAddress>compta@fournisseur.fr</t:EmailAddress></t:Address></t:FromAddresses>'
			. '<t:SentToAddresses><t:Address><t:EmailAddress>moi@example.fr</t:EmailAddress></t:Address></t:SentToAddresses></t:Conditions>'
			. '<t:Actions><t:ForwardToRecipients><t:Address><t:EmailAddress>paul@example.fr</t:EmailAddress></t:Address></t:ForwardToRecipients>'
			. '<t:MarkImportance>High</t:MarkImportance><t:MoveToFolder><t:FolderId Id="F-FACT"/></t:MoveToFolder></t:Actions></t:Rule></t:SetRuleOperation>', $this->lastRequest());
	}

	public function testOutlookRuleIsNotEditedHere(): void {
		$this->queue($this->inbox(self::OUTLOOK));
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('créée dans Outlook');
		$this->rules()->save(['id' => 'R2', 'name' => 'x', 'actions' => ['markRead' => true]]);
	}

	public function testToggleSendsTheRuleBackUntouched(): void {
		$this->queue($this->inbox(self::FACTURES, self::OUTLOOK));
		$this->queue($this->updated());
		$this->rules()->setEnabled('R2', true);
		$this->assertStringContainsString('<t:SetRuleOperation><t:Rule><t:RuleId>R2</t:RuleId><t:DisplayName>Grosses pièces jointes</t:DisplayName><t:Priority>2</t:Priority><t:IsEnabled>true</t:IsEnabled>'
			. '<t:Conditions><t:WithinSizeRange><t:MinimumSize>5000</t:MinimumSize></t:WithinSizeRange></t:Conditions>'
			. '<t:Exceptions><t:SentOnlyToMe>true</t:SentOnlyToMe></t:Exceptions>'
			. '<t:Actions><t:AssignCategories><t:String>Lourd &amp; lent</t:String></t:AssignCategories></t:Actions></t:Rule></t:SetRuleOperation>', $this->lastRequest());
	}

	public function testMoveAndDelete(): void {
		$this->queue($this->inbox(self::FACTURES, self::OUTLOOK));
		$this->queue($this->updated());
		$this->rules()->setPriority('R2', 1);
		$this->assertStringContainsString('<t:Priority>1</t:Priority><t:IsEnabled>false</t:IsEnabled>', $this->lastRequest());
		$this->assertStringContainsString('<t:MoveToFolder><t:FolderId Id="F-FACT" ChangeKey="CK"></t:FolderId></t:MoveToFolder>', EwsRules::reemit($this->dom(self::FACTURES)));

		$this->queue($this->inbox(self::FACTURES));
		$this->queue($this->updated());
		$this->rules()->delete('R1');
		$this->assertStringContainsString('<m:Operations><t:DeleteRuleOperation><t:RuleId>R1</t:RuleId></t:DeleteRuleOperation></m:Operations>', $this->lastRequest());
	}

	public function testGoneRule(): void {
		$this->queue($this->inbox(self::FACTURES));
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('n\'existe plus');
		$this->rules()->delete('R9');
	}

	public function testValidation(): void {
		$esc = fn (string $s) => htmlspecialchars($s, ENT_XML1);
		foreach ([
			[['name' => ' ', 'actions' => ['markRead' => true]], 'nom'],
			[['name' => 'x', 'actions' => ['stop' => true]], 'ce que la règle doit faire'],
			[['name' => 'x', 'actions' => ['delete' => true, 'moveTo' => 'F1']], 'déplacer et supprimer'],
			[['name' => 'x', 'conditions' => ['from' => 'pas une adresse'], 'actions' => ['markRead' => true]], 'Adresse invalide'],
			[['name' => 'x', 'conditions' => ['importance' => 'Urgent'], 'actions' => ['markRead' => true]], 'Importance'],
		] as [$rule, $message]) {
			try {
				EwsRules::ruleXml($rule, 1, $esc);
				$this->fail('accepted: ' . json_encode($rule));
			} catch (MailException $e) {
				$this->assertStringContainsString($message, $e->getMessage());
			}
		}
	}

	public function testValidationErrorFromExchange(): void {
		$this->queue($this->inbox());
		$this->queue('<m:UpdateInboxRulesResponse ResponseClass="Error" ' . self::NS . '><m:MessageText>x</m:MessageText><m:ResponseCode>ErrorInboxRulesValidationError</m:ResponseCode>'
			. '<m:RuleOperationErrors><t:RuleOperationError><t:OperationIndex>0</t:OperationIndex><t:ValidationErrors><t:Error>'
			. '<t:FieldURI>Action:MoveToFolder</t:FieldURI><t:ErrorCode>InvalidValue</t:ErrorCode><t:ErrorMessage>Le dossier n\'existe pas.</t:ErrorMessage><t:FieldValue>F9</t:FieldValue>'
			. '</t:Error></t:ValidationErrors></t:RuleOperationError></m:RuleOperationErrors></m:UpdateInboxRulesResponse>');
		$this->expectException(MailException::class);
		$this->expectExceptionMessage('Règle refusée par Exchange : Le dossier n\'existe pas.');
		$this->rules()->save(['name' => 'x', 'actions' => ['moveTo' => 'F9']]);
	}

	private function dom(string $xml): \DOMElement {
		$doc = new \DOMDocument();
		$doc->loadXML('<root xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types">' . $xml . '</root>');
		return $doc->documentElement->firstChild;
	}
}
