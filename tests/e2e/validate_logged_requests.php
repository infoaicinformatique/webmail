<?php
/**
 * Validate every SOAP request logged by fake_ews.py (--log DIR) against the
 * official EWS schema:  php tests/e2e/validate_logged_requests.php DIR
 */
declare(strict_types=1);

$dir = $argv[1] ?? '';
$files = glob(rtrim($dir, '/') . '/*.xml') ?: [];
if ($files === []) {
	fwrite(STDERR, "no logged requests in $dir\n");
	exit(2);
}
$xsd = __DIR__ . '/../fixtures/xsd/messages.xsd';
$failed = 0;
$ops = [];
foreach ($files as $file) {
	$doc = new DOMDocument();
	$doc->load($file);
	$xp = new DOMXPath($doc);
	$xp->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
	$body = $xp->query('//soap:Body/*')->item(0);
	$single = new DOMDocument();
	$single->appendChild($single->importNode($body, true));
	$single->loadXML($single->saveXML());
	libxml_use_internal_errors(true);
	$ok = $single->schemaValidate($xsd);
	$op = $body->localName;
	$ops[$op] = ($ops[$op] ?? 0) + 1;
	if (!$ok) {
		$failed++;
		echo basename($file) . " ($op) INVALID:\n";
		foreach (libxml_get_errors() as $e) {
			echo '  ' . trim($e->message) . "\n";
		}
	}
	libxml_clear_errors();
}
ksort($ops);
echo count($files) . " requests checked: " . json_encode($ops) . "\n";
echo $failed === 0 ? "all valid\n" : "$failed invalid\n";
exit($failed === 0 ? 0 : 1);
