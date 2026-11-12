<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;

/**
 * Exchange tasks (the « Tâches » folder of Outlook): list, create, edit,
 * mark as done, delete.
 */
class EwsTasks {
	public const STATUSES = ['NotStarted', 'InProgress', 'Completed', 'WaitingOnOthers', 'Deferred'];
	private const IMPORTANCES = ['Low', 'Normal', 'High'];
	private const FIELDS = ['item:Subject', 'item:Importance', 'item:Categories', 'task:DueDate', 'task:StartDate', 'task:Status',
		'task:PercentComplete', 'task:CompleteDate', 'task:IsRecurring', 'item:DateTimeCreated'];

	public function __construct(
		private EwsClient $client,
	) {
	}

	/**
	 * The tasks, those to do first (by due date, then those without one),
	 * then, if asked, the completed ones (latest first).
	 * @return list<array<string, mixed>>
	 */
	public function list(bool $withCompleted = false): array {
		$restriction = $withCompleted ? '' : '<m:Restriction><t:IsNotEqualTo><t:FieldURI FieldURI="task:Status"/>'
			. '<t:FieldURIOrConstant><t:Constant Value="Completed"/></t:FieldURIOrConstant></t:IsNotEqualTo></m:Restriction>';
		$xp = $this->client->request(
			'<m:FindItem Traversal="Shallow">'
			. '<m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . self::fields(self::FIELDS) . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:IndexedPageItemView MaxEntriesReturned="500" Offset="0" BasePoint="Beginning"/>'
			. $restriction
			. '<m:ParentFolderIds><t:DistinguishedFolderId Id="tasks"/></m:ParentFolderIds>'
			. '</m:FindItem>'
		);
		$tasks = [];
		foreach ($xp->query('//m:RootFolder/t:Items/t:Task') as $node) {
			$tasks[] = $this->parse($xp, $node);
		}
		return self::sort($tasks);
	}

	/** @return array<string, mixed> one task with its notes */
	public function get(string $id): array {
		$xp = $this->client->request(
			'<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:BodyType>Text</t:BodyType>'
			. '<t:AdditionalProperties>' . self::fields(array_merge(self::FIELDS, ['item:Body'])) . '</t:AdditionalProperties></m:ItemShape>'
			. '<m:ItemIds><t:ItemId Id="' . $this->client->esc($id) . '"/></m:ItemIds></m:GetItem>'
		);
		$node = $xp->query('//m:Items/t:Task')->item(0);
		if ($node === null) {
			throw new MailException('Tâche introuvable');
		}
		$task = $this->parse($xp, $node);
		$task['notes'] = (string)$this->client->text($xp, 't:Body', $node);
		return $task;
	}

	/** @param array<string, mixed> $data subject, notes, due (Y-m-d or ''), status, importance */
	public function create(array $data): string {
		$t = self::normalize($data);
		$xp = $this->client->request(
			'<m:CreateItem MessageDisposition="SaveOnly"><m:SavedItemFolderId><t:DistinguishedFolderId Id="tasks"/></m:SavedItemFolderId>'
			. '<m:Items><t:Task>'
			. '<t:Subject>' . $this->client->esc($t['subject']) . '</t:Subject>'
			. '<t:Body BodyType="Text">' . $this->client->esc($t['notes']) . '</t:Body>'
			. '<t:Importance>' . $t['importance'] . '</t:Importance>'
			. ($t['due'] !== null ? '<t:DueDate>' . $t['due'] . '</t:DueDate>' : '')
			. ($t['status'] === 'Completed' ? '<t:PercentComplete>100</t:PercentComplete>' : '')
			. '<t:Status>' . $t['status'] . '</t:Status>'
			. '</t:Task></m:Items></m:CreateItem>'
		);
		return (string)$this->client->attr($xp, '//m:Items/t:Task/t:ItemId', 'Id');
	}

	/** @param array<string, mixed> $data */
	public function update(string $id, array $data): void {
		$t = self::normalize($data);
		$set = fn (string $uri, string $xml) => '<t:SetItemField><t:FieldURI FieldURI="' . $uri . '"/><t:Task>' . $xml . '</t:Task></t:SetItemField>';
		$updates = $set('item:Subject', '<t:Subject>' . $this->client->esc($t['subject']) . '</t:Subject>')
			. $set('item:Body', '<t:Body BodyType="Text">' . $this->client->esc($t['notes']) . '</t:Body>')
			. $set('item:Importance', '<t:Importance>' . $t['importance'] . '</t:Importance>')
			. ($t['due'] !== null
				? $set('task:DueDate', '<t:DueDate>' . $t['due'] . '</t:DueDate>')
				: '<t:DeleteItemField><t:FieldURI FieldURI="task:DueDate"/></t:DeleteItemField>')
			. $set('task:Status', '<t:Status>' . $t['status'] . '</t:Status>');
		$this->change($id, $updates);
	}

	/** Done, or to do again. */
	public function complete(string $id, bool $done): void {
		$this->change($id,
			'<t:SetItemField><t:FieldURI FieldURI="task:PercentComplete"/><t:Task><t:PercentComplete>' . ($done ? '100' : '0') . '</t:PercentComplete></t:Task></t:SetItemField>'
			. '<t:SetItemField><t:FieldURI FieldURI="task:Status"/><t:Task><t:Status>' . ($done ? 'Completed' : 'NotStarted') . '</t:Status></t:Task></t:SetItemField>');
	}

	public function delete(string $id): void {
		$this->client->request(
			'<m:DeleteItem DeleteType="MoveToDeletedItems" AffectedTaskOccurrences="AllOccurrences">'
			. '<m:ItemIds><t:ItemId Id="' . $this->client->esc($id) . '"/></m:ItemIds></m:DeleteItem>'
		);
	}

	private function change(string $id, string $updates): void {
		$this->client->request(
			'<m:UpdateItem MessageDisposition="SaveOnly" ConflictResolution="AlwaysOverwrite">'
			. '<m:ItemChanges><t:ItemChange><t:ItemId Id="' . $this->client->esc($id) . '"/><t:Updates>' . $updates . '</t:Updates></t:ItemChange></m:ItemChanges>'
			. '</m:UpdateItem>'
		);
	}

	/** @return array<string, mixed> */
	private function parse(\DOMXPath $xp, \DOMNode $node): array {
		$c = $this->client;
		$due = $c->text($xp, 't:DueDate', $node);
		$status = (string)$c->text($xp, 't:Status', $node);
		return [
			'id' => $c->attr($xp, 't:ItemId', 'Id', $node),
			'subject' => (string)$c->text($xp, 't:Subject', $node),
			'importance' => $c->text($xp, 't:Importance', $node) ?? 'Normal',
			'categories' => $c->strings($xp, 't:Categories/t:String', $node),
			// the day only: Exchange keeps it as midnight of the user's time zone, in UTC
			'due' => $due !== null ? self::day($due) : null,
			'status' => in_array($status, self::STATUSES, true) ? $status : 'NotStarted',
			'done' => $status === 'Completed',
			'percent' => (int)round((float)$c->text($xp, 't:PercentComplete', $node)),
			'completed' => $c->text($xp, 't:CompleteDate', $node),
			'recurring' => $c->text($xp, 't:IsRecurring', $node) === 'true',
			'created' => $c->text($xp, 't:DateTimeCreated', $node),
		];
	}

	// ------------------------------------------------------------------
	// Pure functions (tested without a server)
	// ------------------------------------------------------------------

	/**
	 * A due date of Exchange (midnight in the time zone of the person who
	 * set it, sent in UTC, e.g. 2026-11-19T23:00:00Z for the 20th in Paris)
	 * as the day it means.
	 */
	public static function day(string $iso): string {
		// 23:00Z (Paris) or 22:00Z (summer) → the next day; 12:00Z (set here) or 05:00Z (Americas) → that day
		return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->modify('+11 hours')->format('Y-m-d');
	}

	/** @param list<array<string, mixed>> $tasks */
	public static function sort(array $tasks): array {
		usort($tasks, function ($a, $b) {
			if ($a['done'] !== $b['done']) {
				return $a['done'] ? 1 : -1;
			}
			if ($a['done']) {
				return strcmp((string)$b['completed'], (string)$a['completed']);
			}
			if ($a['due'] !== $b['due']) {
				if ($a['due'] === null) {
					return 1;
				}
				if ($b['due'] === null) {
					return -1;
				}
				return strcmp($a['due'], $b['due']);
			}
			$ia = array_search($a['importance'], ['High', 'Normal', 'Low'], true);
			$ib = array_search($b['importance'], ['High', 'Normal', 'Low'], true);
			return $ia <=> $ib ?: strcasecmp($a['subject'], $b['subject']);
		});
		return $tasks;
	}

	/** @return array{subject: string, notes: string, due: ?string, status: string, importance: string} */
	public static function normalize(array $data): array {
		$subject = trim((string)($data['subject'] ?? ''));
		if ($subject === '') {
			throw new MailException('Donnez un titre à la tâche');
		}
		if (mb_strlen($subject) > 255) {
			throw new MailException('Titre trop long');
		}
		$due = trim((string)($data['due'] ?? ''));
		if ($due !== '') {
			$d = \DateTimeImmutable::createFromFormat('!Y-m-d', $due, new \DateTimeZone('UTC'));
			if ($d === false || $d->format('Y-m-d') !== $due) {
				throw new MailException('Échéance invalide');
			}
			// noon UTC: the same day in every time zone of Europe and Africa
			$due = $d->format('Y-m-d') . 'T12:00:00Z';
		}
		$status = (string)($data['status'] ?? 'NotStarted');
		$importance = (string)($data['importance'] ?? 'Normal');
		if (!in_array($status, self::STATUSES, true) || !in_array($importance, self::IMPORTANCES, true)) {
			throw new MailException('Tâche invalide');
		}
		return ['subject' => $subject, 'notes' => (string)($data['notes'] ?? ''), 'due' => $due !== '' ? $due : null, 'status' => $status, 'importance' => $importance];
	}

	private static function fields(array $uris): string {
		return implode('', array_map(fn ($u) => '<t:FieldURI FieldURI="' . $u . '"/>', $uris));
	}
}
