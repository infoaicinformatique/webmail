<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;

/**
 * Inbox rules run by Exchange on arriving messages (GetInboxRules /
 * UpdateInboxRules), the same rules as "Règles" in Outlook on the web.
 *
 * The webmail edits the common conditions and actions. A rule using
 * something else (made in Outlook) is shown as it is: it can still be
 * switched on or off, moved up or down, or deleted — it is then sent back
 * to Exchange untouched except for that change.
 */
class EwsRules {
	/** condition key => [EWS element, kind]; in the schema's order */
	public const CONDITIONS = [
		'senderContains' => ['ContainsSenderStrings', 'strings'],
		'words' => ['ContainsSubjectOrBodyStrings', 'strings'],
		'subject' => ['ContainsSubjectStrings', 'strings'],
		'from' => ['FromAddresses', 'addresses'],
		'hasAttachments' => ['HasAttachments', 'bool'],
		'importance' => ['Importance', 'importance'],
		'sentOnlyToMe' => ['SentOnlyToMe', 'bool'],
		'sentTo' => ['SentToAddresses', 'addresses'],
	];

	/** action key => [EWS element, kind]; in the schema's order */
	public const ACTIONS = [
		'categories' => ['AssignCategories', 'strings'],
		'copyTo' => ['CopyToFolder', 'folder'],
		'delete' => ['Delete', 'bool'],
		'forwardTo' => ['ForwardToRecipients', 'addresses'],
		'markImportance' => ['MarkImportance', 'importance'],
		'markRead' => ['MarkAsRead', 'bool'],
		'moveTo' => ['MoveToFolder', 'folder'],
		'stop' => ['StopProcessingRules', 'bool'],
	];

	private const IMPORTANCES = ['Low', 'Normal', 'High'];

	public function __construct(
		private EwsClient $client,
		private string $mailbox,
	) {
	}

	/**
	 * @return list<array{id: string, name: string, enabled: bool, priority: int, editable: bool, inError: bool,
	 *   conditions: array<string, mixed>, actions: array<string, mixed>}> by priority
	 */
	public function list(): array {
		$rules = [];
		foreach ($this->fetch() as $node) {
			$rules[] = self::parse($node);
		}
		usort($rules, fn ($a, $b) => $a['priority'] <=> $b['priority']);
		return $rules;
	}

	/**
	 * Create (no id) or replace a rule edited in the webmail.
	 * @param array{id?: string, name?: string, enabled?: bool, priority?: int, conditions?: array<string, mixed>, actions?: array<string, mixed>} $rule
	 */
	public function save(array $rule): void {
		$id = trim((string)($rule['id'] ?? ''));
		$priority = (int)($rule['priority'] ?? 0);
		if ($id !== '') {
			$current = $this->find($id);
			if (!self::parse($current)['editable']) {
				throw new MailException('Cette règle a été créée dans Outlook : modifiez-la dans Outlook');
			}
			$priority = $priority > 0 ? $priority : (int)self::childText($current, 'Priority');
		} elseif ($priority < 1) {
			$priority = count($this->fetch()) + 1; // last: run after the existing rules
		}
		$xml = self::ruleXml($rule, $priority, fn (string $s) => $this->client->esc($s));
		$this->update(($id !== '' ? '<t:SetRuleOperation>' : '<t:CreateRuleOperation>')
			. $xml
			. ($id !== '' ? '</t:SetRuleOperation>' : '</t:CreateRuleOperation>'));
	}

	public function setEnabled(string $id, bool $enabled): void {
		$this->change($id, ['IsEnabled' => $enabled ? 'true' : 'false']);
	}

	/** Exchange shifts the other rules to make room. */
	public function setPriority(string $id, int $priority): void {
		$this->change($id, ['Priority' => (string)max(1, $priority)]);
	}

	public function delete(string $id): void {
		$this->find($id); // a clear message when it is gone already
		$this->update('<t:DeleteRuleOperation><t:RuleId>' . $this->client->esc($id) . '</t:RuleId></t:DeleteRuleOperation>');
	}

	// ------------------------------------------------------------------

	/** @return list<\DOMElement> */
	private function fetch(): array {
		$xp = $this->send('<m:GetInboxRules><m:MailboxSmtpAddress>' . $this->client->esc($this->mailbox) . '</m:MailboxSmtpAddress></m:GetInboxRules>');
		$rules = [];
		foreach ($xp->query('//m:InboxRules/t:Rule') as $node) {
			if ($node instanceof \DOMElement) {
				$rules[] = $node;
			}
		}
		return $rules;
	}

	private function find(string $id): \DOMElement {
		foreach ($this->fetch() as $node) {
			if (self::childText($node, 'RuleId') === $id) {
				return $node;
			}
		}
		throw new MailException('Cette règle n\'existe plus');
	}

	/** Send the rule back as Exchange gave it, with a few values changed. */
	private function change(string $id, array $values): void {
		$this->update('<t:SetRuleOperation>' . self::reemit($this->find($id), $values) . '</t:SetRuleOperation>');
	}

	private function update(string $operations): void {
		// rules kept by Outlook in its own format ("blob") would block the
		// update: Exchange replaces them, as Outlook on the web does
		$this->send('<m:UpdateInboxRules><m:MailboxSmtpAddress>' . $this->client->esc($this->mailbox) . '</m:MailboxSmtpAddress>'
			. '<m:RemoveOutlookRuleBlob>true</m:RemoveOutlookRuleBlob>'
			. '<m:Operations>' . $operations . '</m:Operations></m:UpdateInboxRules>');
	}

	/** These answers carry their ResponseClass on the response element itself. */
	private function send(string $body): \DOMXPath {
		$xp = $this->client->request($body, false);
		$resp = $xp->query('//m:GetInboxRulesResponse | //m:UpdateInboxRulesResponse')->item(0);
		if ($resp instanceof \DOMElement && $resp->getAttribute('ResponseClass') === 'Error') {
			$code = $this->client->text($xp, 'm:ResponseCode', $resp);
			$message = $this->client->text($xp, 'm:RuleOperationErrors//t:ErrorMessage', $resp);
			if ($code === 'ErrorInboxRulesValidationError' && $message !== null && $message !== '') {
				throw new MailException('Règle refusée par Exchange : ' . $message);
			}
			throw new MailException(EwsClient::translateError($code, $this->client->text($xp, 'm:MessageText', $resp)));
		}
		return $xp;
	}

	// ------------------------------------------------------------------
	// Pure functions (tested without a server)
	// ------------------------------------------------------------------

	/** @return array{id: string, name: string, enabled: bool, priority: int, editable: bool, inError: bool, conditions: array<string, mixed>, actions: array<string, mixed>} */
	public static function parse(\DOMElement $rule): array {
		$editable = self::childText($rule, 'IsNotSupported') !== 'true';
		$conditions = [];
		$cond = self::child($rule, 'Conditions');
		if ($cond !== null) {
			[$conditions, $known] = self::readPart($cond, self::CONDITIONS);
			$editable = $editable && $known;
		}
		$exceptions = self::child($rule, 'Exceptions');
		if ($exceptions !== null && self::elements($exceptions) !== []) {
			$editable = false; // "sauf si…": not edited here
		}
		$actions = [];
		$act = self::child($rule, 'Actions');
		if ($act !== null) {
			[$actions, $known] = self::readPart($act, self::ACTIONS);
			$editable = $editable && $known;
		}
		return [
			'id' => (string)self::childText($rule, 'RuleId'),
			'name' => (string)self::childText($rule, 'DisplayName'),
			'enabled' => self::childText($rule, 'IsEnabled') === 'true',
			'priority' => (int)self::childText($rule, 'Priority'),
			'editable' => $editable,
			'inError' => self::childText($rule, 'IsInError') === 'true',
			'conditions' => $conditions,
			'actions' => $actions,
		];
	}

	/**
	 * The <t:Rule> of a rule edited in the webmail.
	 * @param callable(string): string $esc
	 */
	public static function ruleXml(array $rule, int $priority, callable $esc): string {
		$name = trim((string)($rule['name'] ?? ''));
		if ($name === '') {
			throw new MailException('Donnez un nom à la règle');
		}
		if (mb_strlen($name) > 256) {
			throw new MailException('Nom de règle trop long');
		}
		$conditions = self::writePart((array)($rule['conditions'] ?? []), self::CONDITIONS, $esc);
		$actionsIn = (array)($rule['actions'] ?? []);
		if (!empty($actionsIn['delete']) && trim((string)($actionsIn['moveTo'] ?? '')) !== '') {
			throw new MailException('Une règle ne peut pas à la fois déplacer et supprimer le message');
		}
		$actions = self::writePart($actionsIn, self::ACTIONS, $esc);
		$doing = array_diff(array_keys(array_filter($actionsIn, fn ($v) => self::filled($v))), ['stop']);
		if ($doing === []) {
			throw new MailException('Choisissez ce que la règle doit faire');
		}
		$id = trim((string)($rule['id'] ?? ''));
		return '<t:Rule>'
			. ($id !== '' ? '<t:RuleId>' . $esc($id) . '</t:RuleId>' : '')
			. '<t:DisplayName>' . $esc($name) . '</t:DisplayName>'
			. '<t:Priority>' . max(1, $priority) . '</t:Priority>'
			. '<t:IsEnabled>' . (($rule['enabled'] ?? true) ? 'true' : 'false') . '</t:IsEnabled>'
			. ($conditions !== '' ? '<t:Conditions>' . $conditions . '</t:Conditions>' : '')
			. '<t:Actions>' . $actions . '</t:Actions>'
			. '</t:Rule>';
	}

	/**
	 * A rule as Exchange gave it, written back with some values replaced.
	 * IsNotSupported and IsInError are what Exchange reports, not settings.
	 * @param array<string, string> $values element name => new text
	 */
	public static function reemit(\DOMElement $rule, array $values = []): string {
		$out = '';
		foreach (self::elements($rule) as $child) {
			if (in_array($child->localName, ['IsNotSupported', 'IsInError'], true)) {
				continue;
			}
			$out .= array_key_exists($child->localName, $values)
				? '<t:' . $child->localName . '>' . htmlspecialchars($values[$child->localName], ENT_XML1) . '</t:' . $child->localName . '>'
				: self::elementXml($child);
		}
		return '<t:Rule>' . $out . '</t:Rule>';
	}

	private static function elementXml(\DOMElement $e): string {
		$attrs = '';
		foreach ($e->attributes as $a) {
			if ($a->namespaceURI === null || $a->namespaceURI === '') {
				$attrs .= ' ' . $a->localName . '="' . htmlspecialchars($a->value, ENT_XML1 | ENT_QUOTES) . '"';
			}
		}
		$children = self::elements($e);
		$inner = '';
		if ($children === []) {
			$inner = htmlspecialchars($e->textContent, ENT_XML1);
		} else {
			foreach ($children as $c) {
				$inner .= self::elementXml($c);
			}
		}
		return '<t:' . $e->localName . $attrs . '>' . $inner . '</t:' . $e->localName . '>';
	}

	/**
	 * @param array<string, array{0: string, 1: string}> $spec
	 * @return array{0: array<string, mixed>, 1: bool} values, and whether everything was understood
	 */
	private static function readPart(\DOMElement $part, array $spec): array {
		$byElement = [];
		foreach ($spec as $key => [$element, $kind]) {
			$byElement[$element] = [$key, $kind];
		}
		$values = [];
		$known = true;
		foreach (self::elements($part) as $el) {
			if (!isset($byElement[$el->localName])) {
				$known = false;
				continue;
			}
			[$key, $kind] = $byElement[$el->localName];
			switch ($kind) {
				case 'strings':
					$values[$key] = array_map(fn ($s) => $s->textContent, self::elements($el, 'String'));
					break;
				case 'addresses':
					$values[$key] = [];
					foreach (self::elements($el, 'Address') as $a) {
						$addr = (string)self::childText($a, 'EmailAddress');
						if ($addr === '') {
							$known = false; // an address book entry without an address
							continue;
						}
						$values[$key][] = $addr;
					}
					break;
				case 'bool':
					$values[$key] = $el->textContent === 'true';
					break;
				case 'importance':
					$values[$key] = $el->textContent;
					break;
				case 'folder':
					$f = self::child($el, 'FolderId');
					if ($f === null) {
						$d = self::child($el, 'DistinguishedFolderId');
						$values[$key] = $d !== null ? $d->getAttribute('Id') : '';
						$known = $known && $d !== null;
					} else {
						$values[$key] = $f->getAttribute('Id');
					}
					break;
			}
		}
		return [$values, $known];
	}

	/**
	 * @param array<string, array{0: string, 1: string}> $spec
	 * @param callable(string): string $esc
	 */
	private static function writePart(array $values, array $spec, callable $esc): string {
		$xml = '';
		foreach ($spec as $key => [$element, $kind]) {
			$v = $values[$key] ?? null;
			if (!self::filled($v)) {
				continue;
			}
			switch ($kind) {
				case 'strings':
					$items = self::cleanList($v);
					if ($items === []) {
						continue 2;
					}
					$xml .= '<t:' . $element . '>' . implode('', array_map(fn ($s) => '<t:String>' . $esc($s) . '</t:String>', $items)) . '</t:' . $element . '>';
					break;
				case 'addresses':
					$items = self::cleanList($v);
					foreach ($items as $a) {
						if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
							throw new MailException('Adresse invalide : ' . $a);
						}
					}
					if ($items === []) {
						continue 2;
					}
					$xml .= '<t:' . $element . '>' . implode('', array_map(fn ($a) => '<t:Address><t:EmailAddress>' . $esc($a) . '</t:EmailAddress></t:Address>', $items)) . '</t:' . $element . '>';
					break;
				case 'bool':
					$xml .= '<t:' . $element . '>true</t:' . $element . '>';
					break;
				case 'importance':
					if (!in_array($v, self::IMPORTANCES, true)) {
						throw new MailException('Importance invalide');
					}
					$xml .= '<t:' . $element . '>' . $v . '</t:' . $element . '>';
					break;
				case 'folder':
					$xml .= '<t:' . $element . '><t:FolderId Id="' . $esc(trim((string)$v)) . '"/></t:' . $element . '>';
					break;
			}
		}
		return $xml;
	}

	private static function filled(mixed $v): bool {
		if (is_array($v)) {
			return self::cleanList($v) !== [];
		}
		return $v !== null && $v !== false && trim((string)$v) !== '';
	}

	/** @return list<string> */
	private static function cleanList(mixed $v): array {
		$items = is_array($v) ? $v : preg_split('/[;,\n]/', (string)$v);
		return array_values(array_unique(array_filter(array_map(fn ($s) => trim((string)$s), $items), fn ($s) => $s !== '')));
	}

	/** @return list<\DOMElement> */
	private static function elements(\DOMElement $e, ?string $name = null): array {
		$out = [];
		foreach ($e->childNodes as $c) {
			if ($c instanceof \DOMElement && ($name === null || $c->localName === $name)) {
				$out[] = $c;
			}
		}
		return $out;
	}

	private static function child(\DOMElement $e, string $name): ?\DOMElement {
		return self::elements($e, $name)[0] ?? null;
	}

	private static function childText(\DOMElement $e, string $name): ?string {
		$c = self::child($e, $name);
		return $c?->textContent;
	}
}
