<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\MailNotificationService;
use PHPUnit\Framework\TestCase;

class MailNotificationServiceTest extends TestCase {
	public function testOnlyMessagesNotAnnouncedYet(): void {
		$items = [
			['id' => 'C', 'date' => '2026-11-12T10:05:00Z'],
			['id' => 'B', 'date' => '2026-11-12T10:05:00Z'],
			['id' => 'A', 'date' => '2026-11-12T10:00:00Z'],
		];
		// A was announced last time (it was the newest, asked again "on or after")
		[$fresh, $seen] = MailNotificationService::fresh($items, ['A'], '2026-11-12T10:05:00Z');
		$this->assertSame(['C', 'B'], array_column($fresh, 'id'));
		// both received in the newest second: asked again next time, not announced twice
		$this->assertSame(['C', 'B'], $seen);

		[$fresh, $seen] = MailNotificationService::fresh([['id' => 'C', 'date' => '2026-11-12T10:05:00Z']], $seen, '2026-11-12T10:05:00Z');
		$this->assertSame([], $fresh);
		$this->assertSame(['C'], $seen);
	}

	public function testTexts(): void {
		[$subject, $message] = MailNotificationService::texts(
			['from' => 'Jean Dupont', 'subject' => 'Devis', 'email' => 'user@societe-a.fr'],
			"Bonjour,\n\nvoici   le devis. " . str_repeat('x', 200));
		$this->assertSame('Nouveau message de Jean Dupont', $subject);
		$this->assertStringStartsWith('« Devis » — Bonjour, voici le devis. xxx', $message);
		$this->assertStringEndsWith('x… · user@societe-a.fr', $message);
		$this->assertSame(140, mb_strlen(explode(' — ', explode(' · ', $message)[0])[1]));

		$this->assertSame("Nouveau message d'Éloïse Martin", MailNotificationService::texts(['from' => 'Éloïse Martin'], '')[0]);
		$this->assertSame("Nouveau message d'Utilisateur", MailNotificationService::texts(['from' => 'Utilisateur'], '')[0]);

		[$subject, $message] = MailNotificationService::texts(['from' => '', 'subject' => '', 'email' => 'a@b.fr'], '');
		$this->assertSame('Nouveau message', $subject);
		$this->assertSame('« (sans objet) » · a@b.fr', $message);
	}

	public function testObjectIdFitsTheNotificationsTable(): void {
		$id = MailNotificationService::objectId(str_repeat('AAMkAGI2', 30));
		$this->assertSame(64, strlen($id));
		$this->assertSame($id, MailNotificationService::objectId(str_repeat('AAMkAGI2', 30)));
	}
}
