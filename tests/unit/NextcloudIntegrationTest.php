<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsClient;
use OCA\Webmail\Service\AccountService;
use PHPUnit\Framework\TestCase;

class NextcloudIntegrationTest extends TestCase {
	public function testProxyOfNextcloud(): void {
		$opts = EwsClient::networkOptions('proxy.lan:3128', 'jean:secret', ['intranet.lan', ' ', '.societe.fr'], null);
		$this->assertSame([
			CURLOPT_PROXY => 'proxy.lan:3128',
			CURLOPT_PROXYUSERPWD => 'jean:secret',
			CURLOPT_NOPROXY => 'intranet.lan,.societe.fr',
		], $opts);
	}

	public function testNoProxy(): void {
		$this->assertSame([], EwsClient::networkOptions(null, 'jean:secret', ['intranet.lan'], null));
		$this->assertSame([], EwsClient::networkOptions('  ', null, [], null));
	}

	public function testCertificateBundleOfNextcloud(): void {
		$bundle = tempnam(sys_get_temp_dir(), 'ca');
		try {
			$this->assertSame([CURLOPT_CAINFO => $bundle], EwsClient::networkOptions(null, null, [], $bundle));
		} finally {
			unlink($bundle);
		}
		// not there (yet): the system bundle is used
		$this->assertSame([], EwsClient::networkOptions(null, null, [], '/nonexistent/rootcerts.crt'));
	}

	public function testPreferencesOfADeletedAccount(): void {
		$keys = ['bell_3', 'bell_since_3', 'bell_seen_3', 'remind_3', 'remind_seen_3', 'shared_calendars_3', 'shared_public_contacts_3',
			'shared_mailboxes_3', 'bell_13', 'shared_calendars_31', 'bell_4', 'notify'];
		$this->assertSame(['bell_3', 'bell_since_3', 'bell_seen_3', 'remind_3', 'remind_seen_3', 'shared_calendars_3', 'shared_public_contacts_3', 'shared_mailboxes_3'],
			AccountService::accountKeys($keys, 3));
	}
}
