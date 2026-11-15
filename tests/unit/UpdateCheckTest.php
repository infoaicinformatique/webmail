<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\Unit;

use OCA\Webmail\Service\UpdateCheck;
use PHPUnit\Framework\TestCase;

class UpdateCheckTest extends TestCase {
	private function release(string $tag, array $more = []): array {
		return $more + ['tag_name' => $tag, 'html_url' => 'https://github.com/o/r/releases/tag/' . $tag, 'published_at' => '2026-11-20T10:00:00Z', 'body' => 'notes ' . $tag];
	}

	public function testOnlyTheNewerPublishedVersionsTheNewestFirst(): void {
		$newer = UpdateCheck::newer('1.150.0', [
			$this->release('v1.149.0'),
			$this->release('v1.152.0'),
			$this->release('v1.150.0'),
			$this->release('v1.160.0', ['draft' => true]),
			$this->release('v1.161.0-rc1'),
			$this->release('v1.155.0', ['prerelease' => true]),
			$this->release('v1.151.0'),
			'junk',
		]);
		$this->assertSame(['1.152.0', '1.151.0'], array_column($newer, 'version'));
		$this->assertSame('2026-11-20', $newer[0]['date']);
		$this->assertSame('notes v1.152.0', $newer[0]['notes']);
	}

	public function testVersionsAreComparedAsVersions(): void {
		$this->assertSame(['1.100.0'], array_column(UpdateCheck::newer('1.99.9', [$this->release('1.100.0'), $this->release('1.9.0')]), 'version'));
	}

	public function testAnUnsafeLinkIsLeftOut(): void {
		$this->assertSame('', UpdateCheck::newer('1.0.0', [$this->release('v2.0.0', ['html_url' => 'javascript:alert(1)'])])[0]['url']);
	}

	public function testTheVersionOfATag(): void {
		$this->assertSame('1.2.3', UpdateCheck::version('v1.2.3'));
		$this->assertNull(UpdateCheck::version('latest'));
		$this->assertNull(UpdateCheck::version('v1'));
	}
}
