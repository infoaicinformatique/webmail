<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Service\ServerConfigService;

class AutodiscoverTest extends EwsTestCase {
	public function testUrlsTriedForADomain(): void {
		$this->assertSame([
			'https://autodiscover.societe-c.fr/autodiscover/autodiscover.svc',
			'https://societe-c.fr/autodiscover/autodiscover.svc',
		], EwsClient::autodiscoverUrls('societe-c.fr'));
	}

	public function testEwsUrlFromAutodiscover(): void {
		$sent = null;
		$client = new EwsClient('https://autodiscover.societe-c.fr/autodiscover/autodiscover.svc', 'jean@societe-c.fr', 'secret', 20, true,
			function (string $url, string $body) use (&$sent): array {
				$sent = [$url, $body];
				return [200, '<?xml version="1.0"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
					. '<GetUserSettingsResponseMessage xmlns="http://schemas.microsoft.com/exchange/2010/Autodiscover"><Response><ErrorCode>NoError</ErrorCode>'
					. '<UserResponses><UserResponse><ErrorCode>NoError</ErrorCode><UserSettings>'
					. '<UserSetting><Name>InternalEwsUrl</Name><Value>https://ex3.mail.ovh.net/EWS/Exchange.asmx</Value></UserSetting>'
					. '<UserSetting><Name>ExternalEwsUrl</Name><Value>https://ex4.mail.ovh.net/EWS/Exchange.asmx</Value></UserSetting>'
					. '</UserSettings></UserResponse></UserResponses></Response></GetUserSettingsResponseMessage></s:Body></s:Envelope>'];
			});
		$this->assertSame('https://ex4.mail.ovh.net/EWS/Exchange.asmx', $client->discoverEwsUrl('https://autodiscover.societe-c.fr/autodiscover/autodiscover.svc'));
		$this->assertSame('https://autodiscover.societe-c.fr/autodiscover/autodiscover.svc', $sent[0]);
		$this->assertStringContainsString('<a:Mailbox>jean@societe-c.fr</a:Mailbox>', $sent[1]);
		$this->assertStringContainsString('<a:Setting>ExternalEwsUrl</a:Setting><a:Setting>InternalEwsUrl</a:Setting>', $sent[1]);
	}

	public function testNoAnswer(): void {
		$client = new EwsClient('https://x.example/autodiscover/autodiscover.svc', 'jean@x.example', 's', 20, true, fn () => [401, '']);
		$this->assertNull($client->discoverEwsUrl('https://x.example/autodiscover/autodiscover.svc'));
	}

	public function testAllowedServers(): void {
		$allowed = ['mail.ovh.net'];
		$this->assertTrue(ServerConfigService::isAllowedHost('ex3.mail.ovh.net', $allowed));
		$this->assertTrue(ServerConfigService::isAllowedHost('EX3.MAIL.OVH.NET.', $allowed));
		$this->assertTrue(ServerConfigService::isAllowedHost('mail.ovh.net', $allowed));
		$this->assertFalse(ServerConfigService::isAllowedHost('mail.ovh.net.evil.example', $allowed));
		$this->assertFalse(ServerConfigService::isAllowedHost('evilmail.ovh.net', $allowed));
		$this->assertFalse(ServerConfigService::isAllowedHost('ex3.mail.ovh.net', []));
	}

	public function testNeverTheLocalNetwork(): void {
		$this->assertTrue(ServerConfigService::arePublicIps(['51.210.1.10', '2001:41d0:1:2::1']));
		foreach (['127.0.0.1', '10.0.0.5', '192.168.1.2', '172.17.0.1', '169.254.169.254', '::1', 'fd00::1', '0.0.0.0'] as $ip) {
			$this->assertFalse(ServerConfigService::arePublicIps(['51.210.1.10', $ip]), $ip);
		}
		$this->assertFalse(ServerConfigService::arePublicIps([]), 'a name that does not resolve');
	}

	public function testDomainOf(): void {
		$this->assertSame('societe-c.fr', ServerConfigService::domainOf('Jean@Societe-C.fr'));
	}

	public function testConnectionPinnedToTheCheckedAddresses(): void {
		$this->assertSame([], \OCA\Webmail\Ews\EwsClient::pinOptions('autodiscover.x.fr', []), 'local servers allowed: no restriction');
		$this->assertSame([CURLOPT_RESOLVE => ['autodiscover.x.fr:443:203.0.113.7,[2001:db8::1]', 'autodiscover.x.fr:80:203.0.113.7,[2001:db8::1]']],
			\OCA\Webmail\Ews\EwsClient::pinOptions('autodiscover.x.fr', ['203.0.113.7', '2001:db8::1']));
	}
}
