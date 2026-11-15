<?php

declare(strict_types=1);

namespace OCA\Webmail\Tests\unit;

use OCA\Webmail\Service\AbsenceReplyService;
use PHPUnit\Framework\TestCase;

class AbsenceReplyTest extends TestCase {
	private const VALUES = ['debut' => '22 décembre 2026', 'fin' => '2 janvier 2027', 'retour' => '3 janvier 2027', 'nom' => 'Nicolas Martin', 'remplacant' => ''];

	public function testTheModelOfTheAppWithTheDates(): void {
		$text = AbsenceReplyService::render(AbsenceReplyService::defaultTemplate(null), ['message' => 'Bonnes fêtes !'] + self::VALUES);
		$this->assertSame("Bonjour,\n\nJe suis absent du 22 décembre 2026 au 2 janvier 2027 inclus, sans accès à mes messages. Je vous répondrai dès mon retour, le 3 janvier 2027.\n\nBonnes fêtes !\n\nCordialement,\nNicolas Martin", $text);
		// no message: no blank lines left
		$this->assertSame("Bonjour,\n\nJe suis absent du 22 décembre 2026 au 2 janvier 2027 inclus, sans accès à mes messages. Je vous répondrai dès mon retour, le 3 janvier 2027.\n\nCordialement,\nNicolas Martin",
			AbsenceReplyService::render(AbsenceReplyService::defaultTemplate(null), self::VALUES));
	}

	public function testTheReplacementWhenThereIsOne(): void {
		$text = AbsenceReplyService::render('Absent jusqu\'au {fin}.{remplacement}', ['remplacant' => 'Marie Durand'] + self::VALUES);
		$this->assertSame("Absent jusqu'au 2 janvier 2027.\n\nEn mon absence, vous pouvez joindre Marie Durand.", $text);
		// its name alone, where the model wants it
		$this->assertSame('Voir Marie Durand', AbsenceReplyService::render('Voir {remplacant}', ['remplacant' => 'Marie Durand'] + self::VALUES));
	}

	public function testUnknownBracesStayAsTheyAre(): void {
		$this->assertSame('Retour le 3 janvier 2027 {heure}', AbsenceReplyService::render('Retour le {retour} {heure}', self::VALUES));
	}

	public function testTheInvitationsToAnswer(): void {
		$invite = ['isMeeting' => true, 'isOrganizer' => false, 'isCancelled' => false, 'response' => 'NoResponseReceived'];
		$this->assertTrue(AbsenceReplyService::toAnswer($invite));
		$this->assertFalse(AbsenceReplyService::toAnswer(['response' => 'Accept'] + $invite), 'already answered');
		$this->assertFalse(AbsenceReplyService::toAnswer(['isOrganizer' => true] + $invite), 'one\'s own meeting');
		$this->assertFalse(AbsenceReplyService::toAnswer(['isCancelled' => true] + $invite));
		$this->assertFalse(AbsenceReplyService::toAnswer(['isMeeting' => false] + $invite), 'an appointment');
	}
}
