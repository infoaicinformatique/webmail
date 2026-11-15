<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCP\Accounts\IAccountManager;
use OCP\IConfig;
use OCP\IUser;

/**
 * The signature of the organisation: a model set by the administrator (logo, layout, legal
 * notice), filled in with each person's Nextcloud profile. « default »: the signature of those
 * who have none of their own; « forced »: everybody's, their own ones put aside.
 */
class OrgSignatureService {
	public const MODES = ['off', 'default', 'forced'];
	/** the fields of the model, and the property of the Nextcloud profile each one is */
	public const FIELDS = [
		// (IAccountManager::PROPERTY_*, written out: the same values)
		'nom' => 'displayname',
		'fonction' => 'role',
		'titre' => 'headline',
		'organisation' => 'organisation',
		'telephone' => 'phone',
		'adresse' => 'address',
		'site' => 'website',
	];
	private const DROP = "\u{E000}";

	public function __construct(
		private IConfig $config,
		private IAccountManager $accountManager,
	) {
	}

	public function mode(): string {
		$mode = $this->config->getAppValue(Application::APP_ID, 'org_signature_mode', 'off');
		return in_array($mode, self::MODES, true) && $this->template() !== '' ? $mode : 'off';
	}

	public function template(): string {
		return $this->config->getAppValue(Application::APP_ID, 'org_signature', '');
	}

	/** @throws \OCA\Webmail\Exception\MailException a model too heavy */
	public function save(string $template, string $mode): void {
		$clean = HtmlSanitizer::signature($template);
		if ($clean === '') {
			$this->config->deleteAppValue(Application::APP_ID, 'org_signature');
		} else {
			$this->config->setAppValue(Application::APP_ID, 'org_signature', $clean);
		}
		$this->config->setAppValue(Application::APP_ID, 'org_signature_mode', in_array($mode, self::MODES, true) ? $mode : 'off');
	}

	/** The model filled in for a person and one of their addresses ('' when there is none). */
	public function forUser(IUser $user, string $email): string {
		$template = $this->template();
		if ($template === '') {
			return '';
		}
		$values = ['email' => $email];
		try {
			$account = $this->accountManager->getAccount($user);
			foreach (self::FIELDS as $field => $property) {
				try {
					$values[$field] = trim((string)$account->getProperty($property)->getValue());
				} catch (\Throwable) {
					$values[$field] = '';
				}
			}
		} catch (\Throwable) {
		}
		$values['nom'] = ($values['nom'] ?? '') !== '' ? $values['nom'] : $user->getDisplayName();
		return self::render($template, $values);
	}

	/**
	 * The model with its fields ({nom}, {fonction}, {telephone}…, HTML-escaped). A line whose
	 * field is empty goes away with it (« Tél. : {telephone} » for someone without a phone).
	 * @param array<string, string> $values
	 */
	public static function render(string $template, array $values): string {
		$html = (string)preg_replace_callback('/\{([a-z]+)\}/', function ($m) use ($values) {
			if (!array_key_exists($m[1], $values) && !array_key_exists($m[1], self::FIELDS) && $m[1] !== 'email') {
				return $m[0];
			}
			$v = trim((string)($values[$m[1]] ?? ''));
			return $v === '' ? self::DROP : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
		}, $template);
		if (!str_contains($html, self::DROP)) {
			return $html;
		}
		// the lines of a block (between <br>), then the blocks themselves, holding an empty field
		$html = (string)preg_replace_callback('~(<(p|div|li|td)\b[^>]*>)(.*?)(</\2>)~is', function ($m) {
			if (!str_contains($m[3], self::DROP)) {
				return $m[0];
			}
			$lines = array_filter(preg_split('~<br\s*/?>~i', $m[3]) ?: [], fn ($l) => !str_contains($l, self::DROP));
			return $lines === [] ? '' : $m[1] . implode('<br>', $lines) . $m[4];
		}, $html);
		$lines = array_filter(preg_split('~<br\s*/?>~i', $html) ?: [], fn ($l) => !str_contains($l, self::DROP));
		return str_replace(self::DROP, '', implode('<br>', $lines));
	}
}
