<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Ews\EwsOof;
use OCP\IL10N;

/** A message as a PDF: its subject, who wrote to whom and when, its files, its text. */
class MessagePdf {
	/**
	 * @param array<string, mixed> $m the message (EwsClient::getItem)
	 * @return string the PDF
	 */
	public static function render(array $m, IL10N $l, \DateTimeZone $zone): string {
		$subject = trim((string)($m['subject'] ?? '')) !== '' ? trim((string)$m['subject']) : $l->t('(sans objet)');
		$pdf = new SimplePdf($subject);
		$pdf->text($subject, 15, true);
		$pdf->space(4);
		$who = fn (?array $p) => $p === null ? '' : (trim((string)($p['name'] ?? '')) !== '' && ($p['name'] ?? '') !== ($p['email'] ?? '')
			? $p['name'] . ' <' . ($p['email'] ?? '') . '>' : (string)($p['email'] ?? ''));
		$list = fn (array $people) => implode(', ', array_filter(array_map($who, $people)));
		$row = function (string $label, string $value) use ($pdf) {
			if (trim($value) !== '') {
				$pdf->text($label . ' : ' . $value, 9.5, false, true);
			}
		};
		$row($l->t('De'), $who($m['from'] ?? null));
		$row($l->t('À'), $list((array)($m['to'] ?? [])));
		$row($l->t('Cc'), $list((array)($m['cc'] ?? [])));
		$date = (string)($m['date'] ?? '');
		if ($date !== '') {
			try {
				$row($l->t('Date'), (new \DateTimeImmutable($date))->setTimezone($zone)->format('d/m/Y H:i'));
			} catch (\Exception) {
			}
		}
		$files = array_values(array_filter((array)($m['attachments'] ?? []), fn ($a) => empty($a['isInline'])));
		if ($files !== []) {
			$row($l->t('Pièces jointes'), implode(', ', array_map(fn ($a) => (string)$a['name'], $files)));
		}
		$pdf->rule();
		$body = (string)($m['body'] ?? '');
		$text = ($m['bodyType'] ?? 'HTML') === 'Text' ? $body : EwsOof::htmlToText($body);
		$pdf->text($text !== '' ? $text : $l->t('(message sans texte)'), 10.5);
		return $pdf->output();
	}

	/** « 2026-11-16 Devis maintenance.pdf » */
	public static function fileName(array $m): string {
		$subject = trim((string)($m['subject'] ?? '')) ?: 'message';
		$date = '';
		try {
			$date = (new \DateTimeImmutable((string)($m['date'] ?? 'now')))->format('Y-m-d') . ' ';
		} catch (\Exception) {
		}
		$name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $date . mb_substr($subject, 0, 100)) ?? 'message';
		return trim(preg_replace('/\s+/u', ' ', $name) ?? 'message') . '.pdf';
	}
}
