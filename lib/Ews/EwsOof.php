<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;
use OCA\Webmail\Service\HtmlSanitizer;

/**
 * Automatic replies ("out of office") of the mailbox: GetUserOofSettings /
 * SetUserOofSettings, the same settings as Outlook's "Automatic replies".
 */
class EwsOof {
	public const STATES = ['Disabled', 'Enabled', 'Scheduled'];
	/** who outside the organisation gets a reply: nobody, my contacts, everybody */
	public const AUDIENCES = ['None', 'Known', 'All'];

	public function __construct(
		private EwsClient $client,
		private string $mailbox,
	) {
	}

	/**
	 * @return array{state: string, audience: string, start: ?string, end: ?string, internal: string, external: string, active: bool}
	 */
	public function get(?\DateTimeImmutable $now = null): array {
		$xp = $this->send('<m:GetUserOofSettingsRequest>' . $this->mailboxXml() . '</m:GetUserOofSettingsRequest>');
		$c = $this->client;
		$state = (string)$c->text($xp, '//t:OofSettings/t:OofState');
		$start = $c->text($xp, '//t:OofSettings/t:Duration/t:StartTime');
		$end = $c->text($xp, '//t:OofSettings/t:Duration/t:EndTime');
		$now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$active = $state === 'Enabled'
			|| ($state === 'Scheduled' && $start !== null && $end !== null
				&& new \DateTimeImmutable($start) <= $now && $now < new \DateTimeImmutable($end));
		return [
			'state' => in_array($state, self::STATES, true) ? $state : 'Disabled',
			'audience' => in_array($c->text($xp, '//t:OofSettings/t:ExternalAudience'), self::AUDIENCES, true)
				? (string)$c->text($xp, '//t:OofSettings/t:ExternalAudience') : 'None',
			'start' => $start,
			'end' => $end,
			'internal' => self::htmlToText((string)$c->text($xp, '//t:OofSettings/t:InternalReply/t:Message')),
			'external' => self::htmlToText((string)$c->text($xp, '//t:OofSettings/t:ExternalReply/t:Message')),
			'active' => $active,
		];
	}

	/**
	 * @param array{state: string, audience?: string, start?: ?string, end?: ?string, internal?: string, external?: string} $s
	 *        messages are plain text, dates ISO 8601
	 */
	public function set(array $s): void {
		$state = (string)($s['state'] ?? 'Disabled');
		$audience = (string)($s['audience'] ?? 'None');
		if (!in_array($state, self::STATES, true) || !in_array($audience, self::AUDIENCES, true)) {
			throw new MailException('Réglage de réponse automatique invalide');
		}
		$duration = '';
		if ($state === 'Scheduled') {
			$start = self::utc((string)($s['start'] ?? ''));
			$end = self::utc((string)($s['end'] ?? ''));
			if ($start >= $end) {
				throw new MailException('La fin de l\'absence doit être après son début');
			}
			$duration = '<t:Duration><t:StartTime>' . $start . '</t:StartTime><t:EndTime>' . $end . '</t:EndTime></t:Duration>';
		}
		$internal = trim((string)($s['internal'] ?? ''));
		if ($state !== 'Disabled' && $internal === '') {
			throw new MailException('Écrivez le message de réponse automatique');
		}
		$external = trim((string)($s['external'] ?? ''));
		$reply = fn (string $text) => '<t:Message>' . $this->client->esc($text !== '' ? HtmlSanitizer::textToHtml($text) : '') . '</t:Message>';
		$this->send('<m:SetUserOofSettingsRequest>' . $this->mailboxXml()
			. '<t:UserOofSettings><t:OofState>' . $state . '</t:OofState><t:ExternalAudience>' . $audience . '</t:ExternalAudience>'
			. $duration
			. '<t:InternalReply>' . $reply($internal) . '</t:InternalReply>'
			. '<t:ExternalReply>' . $reply($external !== '' ? $external : $internal) . '</t:ExternalReply>'
			. '</t:UserOofSettings></m:SetUserOofSettingsRequest>');
	}

	private function mailboxXml(): string {
		return '<t:Mailbox><t:Address>' . $this->client->esc($this->mailbox) . '</t:Address></t:Mailbox>';
	}

	/** These answers have a single ResponseMessage (not in ResponseMessages). */
	private function send(string $body): \DOMXPath {
		$xp = $this->client->request($body, false);
		$msg = $xp->query('//m:ResponseMessage')->item(0);
		if ($msg instanceof \DOMElement && $msg->getAttribute('ResponseClass') === 'Error') {
			throw new MailException(EwsClient::translateError($this->client->text($xp, 'm:ResponseCode', $msg), $this->client->text($xp, 'm:MessageText', $msg)));
		}
		return $xp;
	}

	private static function utc(string $iso): string {
		$d = $iso !== '' ? date_create_immutable($iso) : false;
		if ($d === false) {
			throw new MailException('Date d\'absence invalide');
		}
		return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}

	/** The reply as plain text, as Outlook shows it (Exchange stores HTML). */
	public static function htmlToText(string $html): string {
		if (trim($html) === '') {
			return '';
		}
		// line breaks in HTML source are only spaces; the tags make the lines
		$html = preg_replace('/\s*\R\s*/u', ' ', $html) ?? $html;
		$text = preg_replace('#<(br|/p|/div|/li|/tr|/h\d)\b[^>]*>#i', "\n", $html) ?? $html;
		$text = preg_replace('#<(style|script|head)\b.*?</\1>#is', '', $text) ?? $text;
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace("\u{a0}", ' ', $text);
		$lines = array_map('trim', explode("\n", $text));
		return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');
	}
}
