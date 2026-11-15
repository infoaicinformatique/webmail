<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCA\Webmail\Db\Account;
use OCA\Webmail\Db\AccountMapper;
use OCA\Webmail\Ews\EwsOof;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use OCP\User\IOutOfOfficeData;
use Psr\Log\LoggerInterface;

/**
 * The absences of Nextcloud (Paramètres personnels › Disponibilité › Absence) as the automatic
 * replies of Exchange: the same dates, the message of the absence, or else the user's model (or
 * the one of the administrator) with the dates filled in. An absence removed takes its reply
 * away, unless it was changed meanwhile (in Outlook, in the webmail).
 */
class AbsenceReplyService {
	/** the reply of each account made for an absence: its id and dates (a reply changed since is left alone) */
	private const MARK = 'oof_absence_';

	public function __construct(
		private AccountMapper $mapper,
		private AccountService $accounts,
		private IConfig $config,
		private IFactory $l10nFactory,
		private IDateTimeFormatter $formatter,
		private IManager $notifications,
		private LoggerInterface $logger,
	) {
	}

	public function isEnabled(string $userId): bool {
		return $this->config->getUserValue($userId, Application::APP_ID, 'oof_from_absence', '1') === '1';
	}

	public function setEnabled(string $userId, bool $enabled): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'oof_from_absence', $enabled ? '1' : '0');
	}

	/** The model of the user ('' = the one of the administrator). */
	public function userTemplate(string $userId): string {
		return $this->config->getUserValue($userId, Application::APP_ID, 'oof_template', '');
	}

	public function setUserTemplate(string $userId, string $template): void {
		$template = trim(str_replace("\r\n", "\n", $template));
		if ($template === '') {
			$this->config->deleteUserValue($userId, Application::APP_ID, 'oof_template');
		} else {
			$this->config->setUserValue($userId, Application::APP_ID, 'oof_template', mb_substr($template, 0, 4000));
		}
	}

	/** The model of the administrator ('' = the one of the app). */
	public function adminTemplate(): string {
		return $this->config->getAppValue(Application::APP_ID, 'oof_template', '');
	}

	public function setAdminTemplate(string $template): void {
		$template = trim(str_replace("\r\n", "\n", $template));
		if ($template === '') {
			$this->config->deleteAppValue(Application::APP_ID, 'oof_template');
		} else {
			$this->config->setAppValue(Application::APP_ID, 'oof_template', mb_substr($template, 0, 4000));
		}
	}

	/** The model of the app, in the language given. */
	public static function defaultTemplate(?IL10N $l): string {
		return L10n::t($l, "Bonjour,\n\nJe suis absent du {debut} au {fin} inclus, sans accès à mes messages. Je vous répondrai dès mon retour, le {retour}.{remplacement}\n\nCordialement,\n{nom}");
	}

	/**
	 * The text of a model: {debut}, {fin}, {retour} (the day after), {nom}, {remplacement}
	 * (« En mon absence, vous pouvez joindre … », or nothing).
	 * @param array{debut: string, fin: string, retour: string, nom: string, remplacant: string} $values
	 */
	public static function render(string $template, array $values, ?IL10N $l = null): string {
		$replacement = $values['remplacant'] !== '' ? "\n\n" . L10n::t($l, 'En mon absence, vous pouvez joindre %s.', [$values['remplacant']]) : '';
		return strtr($template, [
			'{debut}' => $values['debut'],
			'{fin}' => $values['fin'],
			'{retour}' => $values['retour'],
			'{nom}' => $values['nom'],
			'{remplacant}' => $values['remplacant'],
			'{remplacement}' => $replacement,
		]);
	}

	/** An absence saved (new or changed): the replies of every account of its user scheduled. */
	public function scheduled(IOutOfOfficeData $data): void {
		$user = $data->getUser();
		$userId = $user->getUID();
		if (!$this->isEnabled($userId)) {
			return;
		}
		$start = (new \DateTimeImmutable('@' . $data->getStartDate()));
		// (the last day ends at 23:59: the reply until the next midnight)
		$end = (new \DateTimeImmutable('@' . $data->getEndDate()))->modify('+1 minute');
		if ($end <= new \DateTimeImmutable('now')) {
			return;
		}
		$message = trim($data->getMessage()) !== '' ? trim($data->getMessage()) : $this->message($user, $data);
		$done = [];
		foreach ($this->mapper->findAllForUser($userId) as $account) {
			try {
				$this->oof($account)->set([
					'state' => 'Scheduled',
					'audience' => 'All',
					'start' => $start->format(\DateTimeInterface::ATOM),
					'end' => $end->format(\DateTimeInterface::ATOM),
					'internal' => $message,
					'external' => $message,
				]);
				$this->config->setUserValue($userId, Application::APP_ID, self::MARK . $account->getId(), (string)json_encode([
					'id' => $data->getId(), 'start' => $start->getTimestamp(), 'end' => $end->getTimestamp(),
				]));
				$done[] = $account->getEmail();
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: automatic reply of ' . $account->getEmail() . ' not scheduled for an absence: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		if ($done !== []) {
			$n = $this->notifications->createNotification();
			$n->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('absence', $data->getId())
				->setSubject('oof_scheduled', ['start' => $data->getStartDate(), 'end' => $data->getEndDate(), 'accounts' => implode(', ', $done)]);
			$this->notifications->notify($n);
		}
	}

	/** An absence removed: the replies made for it taken away (not one changed since). */
	public function cleared(IOutOfOfficeData $data): void {
		$userId = $data->getUser()->getUID();
		foreach ($this->mapper->findAllForUser($userId) as $account) {
			$mark = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::MARK . $account->getId(), ''), true);
			if (!is_array($mark) || (string)($mark['id'] ?? '') !== $data->getId()) {
				continue;
			}
			try {
				$oof = $this->oof($account);
				$now = $oof->get();
				$same = $now['state'] === 'Scheduled' && $now['start'] !== null && $now['end'] !== null
					&& abs((new \DateTimeImmutable($now['start']))->getTimestamp() - (int)$mark['start']) < 120
					&& abs((new \DateTimeImmutable($now['end']))->getTimestamp() - (int)$mark['end']) < 120;
				if ($same) {
					$oof->set(['state' => 'Disabled', 'audience' => $now['audience'], 'internal' => $now['internal'], 'external' => $now['external']]);
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Webmail: automatic reply of ' . $account->getEmail() . ' not taken away after an absence: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				continue;
			}
			$this->config->deleteUserValue($userId, Application::APP_ID, self::MARK . $account->getId());
			$this->notifications->markProcessed($this->notifications->createNotification()
				->setApp(Application::APP_ID)->setUser($userId)->setObject('absence', $data->getId()));
		}
	}

	/** An absence under way whose replies were never scheduled (saved before this version, or Exchange out of reach then). */
	public function catchUp(IOutOfOfficeData $data): void {
		$userId = $data->getUser()->getUID();
		foreach ($this->mapper->findAllForUser($userId) as $account) {
			$mark = json_decode($this->config->getUserValue($userId, Application::APP_ID, self::MARK . $account->getId(), ''), true);
			if (!is_array($mark) || (string)($mark['id'] ?? '') !== $data->getId()) {
				$this->scheduled($data);
				return;
			}
		}
	}

	private function oof(Account $account): EwsOof {
		return new EwsOof($this->accounts->getClient($account, AccountService::BACKGROUND_TIMEOUT), $account->getEmail());
	}

	/** The model of the user (or of the administrator, or of the app) with the dates of the absence. */
	private function message(IUser $user, IOutOfOfficeData $data): string {
		$userId = $user->getUID();
		$lang = $this->config->getUserValue($userId, 'core', 'lang', '');
		$l = $this->l10nFactory->get(Application::APP_ID, $lang !== '' ? $lang : null);
		$tz = $this->config->getUserValue($userId, 'core', 'timezone', '') ?: date_default_timezone_get();
		try {
			$zone = new \DateTimeZone($tz);
		} catch (\Exception) {
			$zone = new \DateTimeZone('UTC');
		}
		$template = $this->userTemplate($userId);
		if ($template === '') {
			$template = $this->adminTemplate();
		}
		if ($template === '') {
			$template = self::defaultTemplate($l);
		}
		$day = fn (int $ts) => $this->formatter->formatDate($ts, 'long', $zone, $l);
		$replacement = method_exists($data, 'getReplacementUserDisplayName') ? (string)$data->getReplacementUserDisplayName() : '';
		return self::render($template, [
			'debut' => $day($data->getStartDate()),
			'fin' => $day($data->getEndDate()),
			'retour' => $day($data->getEndDate() + 60),
			'nom' => $user->getDisplayName(),
			'remplacant' => $replacement,
		], $l);
	}
}
