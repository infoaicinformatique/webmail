<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\AutoBcc;
use PHPUnit\Framework\TestCase;

class AutoBccTest extends TestCase {
	public function testDecode(): void {
		$this->assertSame([], AutoBcc::decode('nope'));
		$rules = AutoBcc::decode('[{"email":"CRM@x.fr"},{"email":"crm@x.fr","when":"external"},{"email":"bad"},{"email":"a@x.fr","when":"domains","domains":"@Client.fr, nope, client.fr"},{"email":"b@x.fr","when":"domains","domains":""},{"email":"c@x.fr","when":"later"}]');
		$this->assertSame([
			['email' => 'crm@x.fr', 'when' => 'all', 'domains' => []],
			['email' => 'a@x.fr', 'when' => 'domains', 'domains' => ['client.fr']],
		], $rules);
	}

	public function testValidate(): void {
		$this->assertCount(1, AutoBcc::validate([['email' => 'a@x.fr', 'when' => 'external']]));
		foreach ([[['email' => 'nope']], [['email' => 'a@x.fr', 'when' => 'domains', 'domains' => '']], array_fill(0, 11, ['email' => 'a@x.fr'])] as $bad) {
			try {
				AutoBcc::validate($bad);
				$this->fail('accepted: ' . json_encode($bad));
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testExtra(): void {
		$rules = AutoBcc::decode([
			['email' => 'crm@x.fr', 'when' => 'all'],
			['email' => 'out@x.fr', 'when' => 'external'],
			['email' => 'arch@x.fr', 'when' => 'domains', 'domains' => ['client.fr']],
		]);
		// colleagues only
		$this->assertSame(['crm@x.fr'], AutoBcc::extra($rules, 'me@moi.fr', ['bob@moi.fr'], [], []));
		$this->assertSame(['crm@x.fr'], AutoBcc::extra($rules, 'me@moi.fr', ['bob@paris.moi.fr'], [], []));
		// someone outside, in a client domain (a subdomain counts)
		$this->assertSame(['crm@x.fr', 'out@x.fr', 'arch@x.fr'], AutoBcc::extra($rules, 'me@moi.fr', ['bob@moi.fr'], ['Ann@Sales.Client.fr'], []));
		// not twice
		$this->assertSame(['out@x.fr'], AutoBcc::extra($rules, 'me@moi.fr', ['z@autre.fr', 'CRM@x.fr'], [], []));
		$this->assertSame([], AutoBcc::extra($rules, 'me@moi.fr', [], [], ['crm@x.fr']));
		// only in Bcc: not « written to »
		$this->assertSame([], AutoBcc::extra(AutoBcc::decode([['email' => 'arch@x.fr', 'when' => 'domains', 'domains' => ['client.fr']]]), 'me@moi.fr', [], [], ['a@client.fr']));
		$this->assertSame([], AutoBcc::extra([], 'me@moi.fr', ['a@b.fr'], [], []));
	}
}
