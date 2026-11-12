<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\EwsClient;
use PHPUnit\Framework\TestCase;

/**
 * Fake transport for EwsClient: records requests, validates each one
 * (SOAP header and body) against Microsoft's Exchange2013_SP1 EWS schema
 * (tests/fixtures/xsd) and returns queued canned responses.
 */
abstract class EwsTestCase extends TestCase {

	/** @var string[] SOAP bodies sent */
	protected array $requests = [];
	/** @var array<int, array{0: int, 1: string}> queued responses */
	protected array $responses = [];
	/** @var array<int, string[]> HTTP headers of each request */
	protected array $headers = [];
	/** @var string[] Autodiscover requests (not EWS, not validated against the EWS schema) */
	protected array $autodiscover = [];

	protected function client(): EwsClient {
		return new EwsClient('https://ex3.mail.ovh.net/EWS/Exchange.asmx', 'user@example.fr', 'secret', 30, true,
			function (string $url, string $body, array $headers = []): array {
				if ($url === 'https://ex3.mail.ovh.net/autodiscover/autodiscover.svc') {
					$this->autodiscover[] = $body;
					return array_shift($this->responses) ?? [404, ''];
				}
				$this->assertSame('https://ex3.mail.ovh.net/EWS/Exchange.asmx', $url);
				$this->requests[] = $body;
				$this->headers[] = $headers;
				$this->assertValidRequest($body);
				return array_shift($this->responses) ?? [200, self::envelope('')];
			});
	}

	protected function queue(string $bodyXml, int $status = 200): void {
		$this->responses[] = [$status, self::envelope($bodyXml)];
	}

	protected static function envelope(string $body): string {
		return '<?xml version="1.0" encoding="utf-8"?>'
			. '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<s:Header><h:ServerVersionInfo MajorVersion="15" MinorVersion="1" MajorBuildNumber="2507" MinorBuildNumber="6" xmlns:h="http://schemas.microsoft.com/exchange/services/2006/types"/></s:Header>'
			. '<s:Body xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">'
			. $body . '</s:Body></s:Envelope>';
	}

	protected static function ok(string $operation, string $inner = ''): string {
		return '<m:' . $operation . 'Response xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types">'
			. '<m:ResponseMessages><m:' . $operation . 'ResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode>'
			. $inner . '</m:' . $operation . 'ResponseMessage></m:ResponseMessages></m:' . $operation . 'Response>';
	}

	protected function assertValidRequest(string $envelope): void {
		$doc = new \DOMDocument();
		$this->assertTrue($doc->loadXML($envelope), 'request is well-formed XML');
		$xp = new \DOMXPath($doc);
		$xp->registerNamespace('soap', EwsClient::NS_SOAP);
		$xp->registerNamespace('t', EwsClient::NS_T);
		$this->assertSame('Exchange2013_SP1', $xp->query('//soap:Header/t:RequestServerVersion/@Version')->item(0)?->nodeValue);

		foreach ($xp->query('//soap:Header/*') as $headerEl) {
			$this->assertSchemaValid($headerEl, 'types.xsd');
		}

		$bodyEl = $xp->query('//soap:Body/*')->item(0);
		$this->assertNotNull($bodyEl);
		$this->assertSchemaValid($bodyEl, 'messages.xsd');
	}

	private function assertSchemaValid(\DOMNode $el, string $xsd): void {
		$single = new \DOMDocument();
		$single->appendChild($single->importNode($el, true));
		$single->loadXML($single->saveXML()); // re-parse so namespaces are declared on the root

		$prev = libxml_use_internal_errors(true);
		$valid = $single->schemaValidate(__DIR__ . '/../fixtures/xsd/' . $xsd);
		$errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$this->assertTrue($valid, "EWS request does not match the schema:\n" . implode("\n", $errors) . "\n" . $single->saveXML());
	}

	protected function lastRequest(): string {
		return (string)end($this->requests);
	}

}
