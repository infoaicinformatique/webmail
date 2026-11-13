<?php

declare(strict_types=1);

namespace OCA\Webmail\Ews;

use OCA\Webmail\Exception\MailException;

/**
 * Delegates of the mailbox (GetDelegate / AddDelegate / UpdateDelegate /
 * RemoveDelegate): who may manage the calendar, inbox, contacts and tasks
 * of this mailbox and receive copies of its meeting invitations.
 * Same settings as Outlook's "File > Account settings > Delegate access".
 */
class EwsDelegates {
	/** Folder permission levels Exchange accepts for a delegate. */
	public const LEVELS = ['None', 'Reviewer', 'Author', 'Editor'];
	/** UI key => element of DelegatePermissionsType (schema order). */
	private const FOLDERS = ['calendar' => 'CalendarFolderPermissionLevel', 'tasks' => 'TasksFolderPermissionLevel',
		'inbox' => 'InboxFolderPermissionLevel', 'contacts' => 'ContactsFolderPermissionLevel'];
	public const DELIVERY = ['DelegatesOnly', 'DelegatesAndMe', 'DelegatesAndSendInformationToMe', 'NoForward'];

	public function __construct(
		private EwsClient $client,
		private string $mailbox,
	) {
	}

	/**
	 * @return array{delegates: array<int, array<string, mixed>>, deliverMeetingRequests: string}
	 */
	public function list(): array {
		$xp = $this->send('<m:GetDelegate IncludePermissions="true">' . $this->mailboxXml() . '</m:GetDelegate>', 'GetDelegateResponse',
			['ErrorDelegateMissingConfiguration']);
		if ($xp === null) {
			// never configured: no delegates yet
			return ['delegates' => [], 'deliverMeetingRequests' => 'DelegatesAndMe'];
		}
		$delegates = [];
		foreach ($xp->query('//m:ResponseMessages/m:DelegateUserResponseMessageType/m:DelegateUser') as $node) {
			$perms = [];
			foreach (self::FOLDERS as $key => $el) {
				$perms[$key] = $this->client->text($xp, 't:DelegatePermissions/t:' . $el, $node) ?? 'None';
			}
			$delegates[] = [
				'email' => strtolower((string)$this->client->text($xp, 't:UserId/t:PrimarySmtpAddress', $node)),
				'name' => (string)$this->client->text($xp, 't:UserId/t:DisplayName', $node),
				'permissions' => $perms,
				'receiveCopies' => $this->client->text($xp, 't:ReceiveCopiesOfMeetingMessages', $node) === 'true',
				'viewPrivate' => $this->client->text($xp, 't:ViewPrivateItems', $node) === 'true',
			];
		}
		usort($delegates, fn ($a, $b) => strcasecmp($a['name'] ?: $a['email'], $b['name'] ?: $b['email']));
		return [
			'delegates' => $delegates,
			'deliverMeetingRequests' => $this->client->text($xp, '//m:DeliverMeetingRequests') ?? 'DelegatesAndMe',
		];
	}

	/**
	 * @param array<string, mixed> $delegate email, permissions{calendar, inbox, contacts, tasks}, receiveCopies, viewPrivate
	 */
	public function add(array $delegate): void {
		$this->send('<m:AddDelegate>' . $this->mailboxXml()
			. '<m:DelegateUsers>' . $this->delegateXml($delegate) . '</m:DelegateUsers></m:AddDelegate>', 'AddDelegateResponse');
	}

	/**
	 * @param array<string, mixed> $delegate
	 */
	public function update(array $delegate): void {
		$this->send('<m:UpdateDelegate>' . $this->mailboxXml()
			. '<m:DelegateUsers>' . $this->delegateXml($delegate) . '</m:DelegateUsers></m:UpdateDelegate>', 'UpdateDelegateResponse');
	}

	public function remove(string $email): void {
		$this->send('<m:RemoveDelegate>' . $this->mailboxXml()
			. '<m:UserIds><t:UserId><t:PrimarySmtpAddress>' . $this->client->esc(self::email($email)) . '</t:PrimarySmtpAddress></t:UserId></m:UserIds>'
			. '</m:RemoveDelegate>', 'RemoveDelegateResponse');
	}

	/**
	 * Who receives the meeting invitations sent to this mailbox.
	 */
	public function setDeliverMeetingRequests(string $mode): void {
		if (!in_array($mode, self::DELIVERY, true)) {
			throw new MailException('Option d\'envoi des invitations inconnue');
		}
		$this->send('<m:UpdateDelegate>' . $this->mailboxXml()
			. '<m:DeliverMeetingRequests>' . $mode . '</m:DeliverMeetingRequests></m:UpdateDelegate>', 'UpdateDelegateResponse');
	}

	// ------------------------------------------------------------------

	private function mailboxXml(): string {
		return '<m:Mailbox><t:EmailAddress>' . $this->client->esc($this->mailbox) . '</t:EmailAddress></m:Mailbox>';
	}

	/**
	 * @param array<string, mixed> $d
	 */
	private function delegateXml(array $d): string {
		$email = self::email((string)($d['email'] ?? ''));
		if (strcasecmp($email, $this->mailbox) === 0) {
			throw new MailException('Vous ne pouvez pas être votre propre délégué');
		}
		$perms = '';
		$levels = [];
		foreach (self::FOLDERS as $key => $el) {
			$level = (string)($d['permissions'][$key] ?? 'None');
			if (!in_array($level, self::LEVELS, true)) {
				throw new MailException('Niveau d\'autorisation inconnu : %s', [$level]);
			}
			$levels[$key] = $level;
			$perms .= '<t:' . $el . '>' . $level . '</t:' . $el . '>';
		}
		$receiveCopies = !empty($d['receiveCopies']);
		if ($receiveCopies && $levels['calendar'] !== 'Editor') {
			throw new MailException('Pour recevoir les invitations, le délégué doit pouvoir modifier l\'agenda (niveau « Éditeur »)');
		}
		return '<t:DelegateUser><t:UserId><t:PrimarySmtpAddress>' . $this->client->esc($email) . '</t:PrimarySmtpAddress></t:UserId>'
			. '<t:DelegatePermissions>' . $perms . '</t:DelegatePermissions>'
			. '<t:ReceiveCopiesOfMeetingMessages>' . ($receiveCopies ? 'true' : 'false') . '</t:ReceiveCopiesOfMeetingMessages>'
			. '<t:ViewPrivateItems>' . (!empty($d['viewPrivate']) ? 'true' : 'false') . '</t:ViewPrivateItems>'
			. '</t:DelegateUser>';
	}

	private static function email(string $email): string {
		$email = strtolower(trim($email));
		if (preg_match('/<([^>]+)>/', $email, $m)) {
			$email = trim($m[1]);
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			throw new MailException('Adresse e-mail invalide');
		}
		return $email;
	}

	/**
	 * Delegate operations report errors on the response element itself and
	 * per delegate (DelegateUserResponseMessageType).
	 */
	/**
	 * @param string[] $tolerated error codes for which null is returned instead of an exception
	 */
	private function send(string $body, string $responseElement, array $tolerated = []): ?\DOMXPath {
		$xp = $this->client->request($body, false);
		$root = $xp->query('//m:' . $responseElement)->item(0);
		$errors = [];
		if ($root instanceof \DOMElement && $root->getAttribute('ResponseClass') === 'Error') {
			$errors[] = $root;
		}
		foreach ($xp->query('//m:ResponseMessages/*[@ResponseClass="Error"]') as $e) {
			$errors[] = $e;
		}
		if ($errors !== []) {
			$code = $this->client->text($xp, 'm:ResponseCode', $errors[0]);
			if (in_array($code, $tolerated, true)) {
				return null;
			}
			throw match ($code) {
				'ErrorDelegateNoUser', 'ErrorMissingEmailAddress' => new MailException('Aucune boîte Exchange ne correspond à cette adresse'),
				'ErrorDelegateAlreadyExists' => new MailException('Cette personne est déjà déléguée'),
				'ErrorNotDelegate' => new MailException('Cette personne n\'est pas déléguée'),
				'ErrorDelegateCannotAddOwner' => new MailException('Vous ne pouvez pas être votre propre délégué'),
				'ErrorDelegateMissingConfiguration' => new MailException('La délégation n\'est pas encore configurée pour cette boîte : ajoutez d\'abord un délégué'),
				'ErrorDelegateValidationFailed' => new MailException('Exchange a refusé ces réglages de délégation'),
				'ErrorAccessDenied' => new MailException('Accès refusé : seul le propriétaire de la boîte peut gérer ses délégués'),
				default => EwsClient::exchangeError($code, $this->client->text($xp, 'm:MessageText', $errors[0])),
			};
		}
		return $xp;
	}
}
