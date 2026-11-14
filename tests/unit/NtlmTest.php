<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Ews\Ntlm;
use OCA\Webmail\Exception\MailException;
use PHPUnit\Framework\TestCase;

/** NTLMv2 against the examples of the specification (MS-NLMP 4.2.4). */
class NtlmTest extends TestCase {
	/** MsvAvNbDomainName « Domain », MsvAvNbComputerName « Server », MsvAvEOL */
	private const TARGET_INFO = '02000c0044006f006d00610069006e0001000c005300650072007600650072000000000000';

	public function testKeyOfTheSpecification(): void {
		$this->assertSame('0c868a403bfd7a93a3001ef22ef02e3f', bin2hex(Ntlm::ntowfv2('User', 'Domain', 'Password')));
	}

	public function testAuthenticateOfTheSpecification(): void {
		$challenge = ['challenge' => hex2bin('0123456789abcdef'), 'flags' => 0xe28a8233, 'targetInfo' => hex2bin(self::TARGET_INFO)];
		$m = base64_decode(Ntlm::authenticate('Domain\\User', 'Password', $challenge, str_repeat("\xaa", 8), str_repeat("\0", 8)));
		$this->assertSame("NTLMSSP\0", substr($m, 0, 8));
		$this->assertSame(3, unpack('V', substr($m, 8, 4))[1]);
		$field = function (int $i) use ($m): string {
			$b = unpack('vlen/vmax/Voffset', substr($m, 12 + 8 * $i, 8));
			return substr($m, $b['offset'], $b['len']);
		};
		// LMv2 of the specification (4.2.4.2.1)
		$this->assertSame('86c35097ac9cec102554764a57cccc19aaaaaaaaaaaaaaaa', bin2hex($field(0)));
		$this->assertSame('0101000000000000' . '0000000000000000' . 'aaaaaaaaaaaaaaaa' . '00000000' . self::TARGET_INFO . '00000000', bin2hex(substr($field(1), 16)));
		$this->assertSame('Domain', mb_convert_encoding($field(2), 'UTF-8', 'UTF-16LE'));
		$this->assertSame('User', mb_convert_encoding($field(3), 'UTF-8', 'UTF-16LE'));
		$this->assertSame('', $field(4));
	}

	/** The same NTLMv2 response as libcurl's (its own implementation), for its client challenge and time. */
	public function testSameResponseAsLibcurl(): void {
		$curl = base64_decode('TlRMTVNTUAADAAAAGAAYAEAAAABVAFUAWAAAAAwADACtAAAACAAIALkAAAAWABYAwQAAAAAAAAAAAAAABYKIAMnOCHsHdTk40wbjuW2Y6svXAs72TcF7ggEnQg5+OlaYjbCl8107GFQBAQAAAAAAAABTzM8hdN0B1wLO9k3Be4IAAAAAAgAMAEQAbwBtAGEAaQBuAAEADABTAGUAcgB2AGUAcgAAAAAAAAAAAABEAG8AbQBhAGkAbgBVAHMAZQByAFcATwBSAEsAUwBUAEEAVABJAE8ATgA=');
		$field = fn (string $m, int $i): string => substr($m, unpack('vlen/vmax/Voffset', substr($m, 12 + 8 * $i, 8))['offset'], unpack('vlen/vmax/Voffset', substr($m, 12 + 8 * $i, 8))['len']);
		$nt = $field($curl, 1);
		$challenge = ['challenge' => hex2bin('0123456789abcdef'), 'flags' => 0x00888205, 'targetInfo' => hex2bin(self::TARGET_INFO)];
		$mine = base64_decode(Ntlm::authenticate('Domain\User', 'Password', $challenge, substr($nt, 32, 8), substr($nt, 24, 8)));
		$this->assertSame(bin2hex($nt), bin2hex($field($mine, 1)));
	}

	public function testAnAddressAsUserAndTheTimeOfTheServer(): void {
		$time = pack('P', 133800000000000000);
		$info = pack('vv', 7, 8) . $time . pack('vv', 0, 0);
		$m = base64_decode(Ntlm::authenticate('info@exemple.fr', 'p', ['challenge' => str_repeat("\1", 8), 'flags' => 0x00888205, 'targetInfo' => $info]));
		$b = fn (int $i) => unpack('vlen/vmax/Voffset', substr($m, 12 + 8 * $i, 8));
		// with the time of the server: that time in the response, the LM response left empty
		$this->assertSame(str_repeat("\0", 24), substr($m, $b(0)['offset'], 24));
		$this->assertSame($time, substr($m, $b(1)['offset'] + 16 + 8, 8));
		// the whole address as user, no domain
		$this->assertSame(0, $b(2)['len']);
		$this->assertSame('info@exemple.fr', mb_convert_encoding(substr($m, $b(3)['offset'], $b(3)['len']), 'UTF-8', 'UTF-16LE'));
	}

	public function testNegotiateAndChallenge(): void {
		$n = base64_decode(Ntlm::negotiate());
		$this->assertSame("NTLMSSP\0\x01\0\0\0", substr($n, 0, 12));
		$this->assertSame(32, strlen($n));
		$msg = "NTLMSSP\0" . pack('V', 2) . pack('vvV', 0, 0, 56) . pack('V', 0x00888205) . 'ABCDEFGH' . str_repeat("\0", 8) . pack('vvV', 4, 4, 56) . str_repeat("\0", 8) . "\0\0\0\0";
		$c = Ntlm::parseChallenge(base64_encode($msg));
		$this->assertSame('ABCDEFGH', $c['challenge']);
		$this->assertSame(0x00888205, $c['flags']);
		$this->assertSame("\0\0\0\0", $c['targetInfo']);
	}

	public function testBadChallenge(): void {
		$this->expectException(MailException::class);
		Ntlm::parseChallenge(base64_encode('not ntlm'));
	}
}
