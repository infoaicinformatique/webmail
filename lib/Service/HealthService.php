<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;

/**
 * « État des services » of the administration: what the webmail needs, checked now —
 * the Exchange servers reachable, the chat of the organisation and its single sign-on,
 * the background jobs of Nextcloud, the apps used, the users whose chat session ended.
 */
class HealthService {
	public const OK = 'ok';
	public const WARNING = 'warning';
	public const ERROR = 'error';

	public function __construct(
		private ServerConfigService $servers,
		private MatrixService $matrix,
		private IClientService $http,
		private IConfig $config,
		private IAppManager $apps,
		private IL10N $l,
	) {
	}

	/** @return list<array{check: string, state: string, detail: string}> */
	public function checks(): array {
		$out = [];
		foreach ($this->servers->getServers() as $s) {
			$out[] = $this->exchange((string)$s['domain'], (string)$s['url']);
		}
		if ($out === []) {
			$out[] = $this->row($this->l->t('Serveurs Exchange'), self::WARNING, $this->l->t('Aucun domaine configuré : seule la découverte automatique peut trouver les serveurs'));
		}
		$out[] = $this->chat();
		$out[] = $this->cron();
		$out[] = $this->row($this->l->t('Agendas et contacts de Nextcloud'), $this->apps->isInstalled('dav') ? self::OK : self::ERROR,
			$this->apps->isInstalled('dav') ? $this->l->t('Application « dav » active') : $this->l->t('Application « dav » désactivée : agendas et carnets Nextcloud indisponibles'));
		$expired = count($this->config->getUsersForUserValue(Application::APP_ID, 'matrix_expired', '1'));
		if ($expired > 0) {
			$out[] = $this->row($this->l->t('Sessions du chat'), self::WARNING,
				$expired === 1 ? $this->l->t('Un utilisateur doit se reconnecter au chat (Comptes et signature)')
					: $this->l->t('%s utilisateurs doivent se reconnecter au chat (Comptes et signature)', [(string)$expired]));
		}
		return $out;
	}

	/** An EWS address answers (401 without credentials: it is there and asks to sign in). */
	private function exchange(string $domain, string $url): array {
		$name = $this->l->t('Exchange — %s', [$domain]);
		try {
			$r = $this->http->newClient()->post($url, [
				'body' => '', 'timeout' => 8, 'http_errors' => false,
				'verify' => $this->servers->getVerifyTls(),
				'headers' => ['Content-Type' => 'text/xml; charset=utf-8'],
			]);
			$status = $r->getStatusCode();
		} catch (\Throwable $e) {
			return $this->row($name, self::ERROR, $this->l->t('Injoignable (%s) : %s', [$url, mb_substr($e->getMessage(), 0, 160)]));
		}
		return in_array($status, [401, 400, 405, 500], true)
			? $this->row($name, self::OK, $this->l->t('Joignable (%s)', [$url]))
			: $this->row($name, self::WARNING, $this->l->t('Réponse inattendue HTTP %1$s (%2$s)', [(string)$status, $url]));
	}

	/** The chat of the organisation: its server, and its sign-in by Nextcloud. */
	private function chat(): array {
		$name = $this->l->t('Chat (Matrix / Element)');
		$element = $this->matrix->element();
		if ($element === null) {
			$visio = $this->servers->getVisio();
			return $this->row($name, self::OK, $visio !== '' ? $this->l->t('Pas de chat déclaré ; visio : %s', [$visio]) : $this->l->t('Aucun chat déclaré dans les Sites externes (facultatif)'));
		}
		try {
			$r = $this->http->newClient()->get(rtrim($element['homeserver'], '/') . '/_matrix/client/v3/login', [
				'timeout' => 8, 'http_errors' => false, 'verify' => $this->servers->getVerifyTls(),
			]);
			$flows = json_decode((string)$r->getBody(), true);
		} catch (\Throwable $e) {
			return $this->row($name, self::ERROR, $this->l->t('Serveur %1$s injoignable : %2$s', [$element['homeserver'], mb_substr($e->getMessage(), 0, 160)]));
		}
		$types = array_column(is_array($flows['flows'] ?? null) ? $flows['flows'] : [], 'type');
		if ($r->getStatusCode() !== 200) {
			return $this->row($name, self::ERROR, $this->l->t('Serveur %1$s : HTTP %2$s', [$element['homeserver'], (string)$r->getStatusCode()]));
		}
		return in_array('m.login.sso', $types, true) && in_array('m.login.token', $types, true)
			? $this->row($name, self::OK, $this->l->t('« %1$s » (%2$s) : connexion avec Nextcloud disponible', [$element['name'], $element['homeserver']]))
			: $this->row($name, self::WARNING, $this->l->t('« %1$s » (%2$s) : pas de connexion unique (SSO) ; chacun indique un jeton', [$element['name'], $element['homeserver']]));
	}

	/** The background jobs of Nextcloud (new mail alerts, reminders, snoozed messages…). */
	private function cron(): array {
		$name = $this->l->t('Tâches de fond de Nextcloud');
		$mode = $this->config->getAppValue('core', 'backgroundjobs_mode', 'ajax');
		$last = (int)$this->config->getAppValue('core', 'lastcron', '0');
		$ago = $last > 0 ? time() - $last : null;
		if ($mode !== 'cron' && $mode !== 'webcron') {
			return $this->row($name, self::WARNING, $this->l->t('Mode « %s » : choisissez Cron pour les alertes, rappels et messages reportés à l\'heure', [$mode]));
		}
		if ($ago === null || $ago > 900) {
			return $this->row($name, self::ERROR, $ago === null ? $this->l->t('Jamais lancées') : $this->l->t('Dernier passage il y a %s minutes', [(string)intdiv($ago, 60)]));
		}
		return $this->row($name, self::OK, $this->l->t('Dernier passage il y a %s minutes', [(string)max(0, intdiv($ago, 60))]));
	}

	/** @return array{check: string, state: string, detail: string} */
	private function row(string $check, string $state, string $detail): array {
		return ['check' => $check, 'state' => $state, 'detail' => $detail];
	}
}
