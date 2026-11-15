<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$servers = $_['servers'];
if ($servers === []) {
	$servers = [
		['domain' => '', 'label' => $l->t('Serveur OVH %s', [1]), 'url' => ''],
		['domain' => '', 'label' => $l->t('Serveur OVH %s', [2]), 'url' => ''],
	];
}
/** <code> around a text (escaped), for the parameters of a translated text */
$code = fn (string $text): string => '<code>' . \OCP\Util::sanitizeHTML($text) . '</code>';
?>
<div id="webmail-admin" class="section">
	<h2><?php p($l->t('Webmail Exchange — serveurs')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Associez chaque domaine e-mail au serveur Exchange OVH qui l\'héberge. L\'utilisateur saisit seulement son adresse et son mot de passe : le serveur est choisi d\'après le domaine.')); ?><br>
		<?php print_unescaped($l->t('Vous trouverez l\'adresse du serveur dans l\'espace client OVH (Hosted Exchange → Informations générales, par ex. %1$s). Un nom d\'hôte suffit : l\'URL EWS %2$s est complétée automatiquement.',
			[$code('ex3.mail.ovh.net'), $code('https://<' . $l->t('serveur') . '>/EWS/Exchange.asmx')])); ?>
		<?php print_unescaped($l->t('Utilisez %s comme domaine pour un serveur par défaut.', [$code('*')])); ?>
	</p>
	<table class="webmail-admin-table">
		<thead>
			<tr><th><?php p($l->t('Domaine')); ?></th><th><?php p($l->t('Libellé')); ?></th><th><?php p($l->t('Serveur Exchange (hôte ou URL EWS)')); ?></th><th></th></tr>
		</thead>
		<tbody id="webmail-servers">
			<?php foreach ($servers as $s): ?>
			<tr>
				<td><input type="text" class="wm-domain" placeholder="<?php p($l->t('exemple.fr')); ?>" value="<?php p($s['domain']); ?>"></td>
				<td><input type="text" class="wm-label" placeholder="<?php p($l->t('Libellé')); ?>" value="<?php p($s['label']); ?>"></td>
				<td><input type="text" class="wm-url" placeholder="ex3.mail.ovh.net" value="<?php p($s['url']); ?>"></td>
				<td><button type="button" class="wm-remove icon-delete" title="<?php p($l->t('Supprimer')); ?>" aria-label="<?php p($l->t('Supprimer')); ?>"></button></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<h3><?php p($l->t('Découverte automatique (Autodiscover)')); ?></h3>
	<p class="settings-hint">
		<?php print_unescaped($l->t('Pour une adresse dont le domaine n\'est pas dans le tableau, le webmail peut chercher son serveur comme Outlook (%1$s), avec l\'adresse et le mot de passe saisis. Le serveur trouvé est retenu pour ce domaine. Par sécurité, seuls les serveurs dont le nom se termine par l\'un de ceux indiqués ci-dessous sont acceptés (%2$s pour OVH Hosted Exchange), et jamais une adresse du réseau local.',
			[$code('autodiscover.<' . $l->t('domaine') . '>'), $code('mail.ovh.net')])); ?>
	</p>
	<p>
		<input type="checkbox" id="webmail-autodiscover" class="checkbox" <?php if ($_['autodiscover']['enabled']) { p('checked'); } ?>>
		<label for="webmail-autodiscover"><?php p($l->t('Chercher automatiquement le serveur des autres domaines')); ?></label>
	</p>
	<p>
		<label for="webmail-autodiscover-hosts"><?php p($l->t('Serveurs autorisés')); ?></label>
		<input type="text" id="webmail-autodiscover-hosts" value="<?php p(implode(', ', $_['autodiscover']['hosts'])); ?>" placeholder="mail.ovh.net" size="40">
	</p>
	<ul id="webmail-discovered">
		<?php foreach ($_['discovered'] as $domain => $url): ?>
		<li data-domain="<?php p($domain); ?>"><?php p($domain); ?> → <code><?php p($url); ?></code>
			<button type="button" class="wm-forget" title="<?php p($l->t('Oublier : le prochain compte de ce domaine le cherchera à nouveau')); ?>"><?php p($l->t('Oublier')); ?></button></li>
		<?php endforeach; ?>
	</ul>
	<h3><?php p($l->t('Visio des réunions (Matrix / Element)')); ?></h3>
	<p class="settings-hint">
		<?php print_unescaped($l->t('Adresse de votre serveur Element Call (par ex. %1$s) : le formulaire d\'un événement propose alors « Visio Element », qui met dans l\'invitation le lien d\'une salle chiffrée, propre à la réunion. Une autre visio (Jitsi…) : une adresse avec %2$s à la place du nom de la salle, par ex. %3$s. Laissez vide : le chat de l\'organisation (Element, déclaré dans les Sites externes de Nextcloud) est utilisé s\'il est trouvé, sinon Nextcloud Talk.',
			[$code('https://call.element.io'), $code('{room}'), $code('https://meet.example.org/{room}')])); ?>
	</p>
	<?php $element = $_['element'] ?? null; $roomMode = $_['visioMode'] === 'element-room' || ($_['visio'] === '' && $element !== null); ?>
	<p>
		<label for="webmail-visio"><?php p($l->t('Adresse de la visio')); ?></label>
		<input type="url" id="webmail-visio" value="<?php p($_['visio'] !== '' ? $_['visio'] : ($element['url'] ?? '')); ?>" placeholder="https://call.element.io" size="50">
	</p>
	<p>
		<input type="checkbox" class="checkbox" id="webmail-visio-room" <?php if ($roomMode) { print_unescaped('checked'); } ?>>
		<label for="webmail-visio-room"><?php p($l->t('Element Web de l\'organisation : un salon visio Matrix créé pour chaque réunion')); ?></label>
	</p>
	<p class="settings-hint">
		<?php p($l->t('Pour un Element sans service Element Call autonome (appels intégrés à Element Web) : « Visio Element » crée, avec le compte Matrix de l\'organisateur (relié dans Comptes et signature), un salon visio fermé aux autres serveurs, que tout compte de votre serveur rejoint par le lien de l\'invitation.')); ?>
	</p>
	<?php if ($_['visio'] === '' && $element !== null): ?>
	<p id="webmail-visio-detected" class="wm-detected">
		<?php p($l->t('Préconfiguré avec votre Element des Sites externes de Nextcloud : « %1$s » (%2$s, serveur Matrix %3$s). Vérifiez puis enregistrez.', [$element['name'] !== '' ? $element['name'] : 'Element', $element['url'], $element['homeserver']])); ?>
	</p>
	<?php endif; ?>
	<h3><?php p($l->t('Réponse automatique pendant les absences')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Une absence saisie dans Nextcloud (Paramètres personnels › Disponibilité › Absence) programme la réponse automatique Exchange de la personne sur les mêmes dates. Si l\'absence n\'a pas de message, ce modèle sert, sauf si la personne a écrit le sien. Les dates sont remplies : {debut}, {fin}, {retour}, {nom}, {remplacement}. Laissez vide pour le modèle du webmail.')); ?>
	</p>
	<p>
		<textarea id="webmail-absence-template" rows="8" cols="70" placeholder="<?php p($_['absenceDefault']); ?>"><?php p($_['absenceTemplate']); ?></textarea>
	</p>
	<p>
		<button type="button" id="webmail-absence-save"><?php p($l->t('Enregistrer le modèle')); ?></button>
		<span id="webmail-absence-msg" class="msg"></span>
	</p>
	<h3><?php p($l->t('État des services')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Vérifie maintenant ce dont le webmail a besoin : les serveurs Exchange, le chat de l\'organisation et sa connexion avec Nextcloud, les tâches de fond de Nextcloud.')); ?>
	</p>
	<p>
		<button type="button" id="webmail-health-check"><?php p($l->t('Vérifier')); ?></button>
	</p>
	<ul id="webmail-health" class="wm-health"></ul>
	<h3><?php p($l->t('Sécurité et limites')); ?></h3>
	<p class="settings-hint">
		<?php p($l->t('Taille totale des pièces jointes d\'un message, pour tous les comptes. Exchange applique aussi sa propre limite (25 Mo par défaut chez OVH Hosted Exchange) : ne la dépassez pas, sinon il refusera les messages. Au-delà, les utilisateurs envoient leurs fichiers en lien Nextcloud.')); ?>
	</p>
	<p>
		<label for="webmail-max-attachments"><?php p($l->t('Taille maximale des pièces jointes par message (Mo)')); ?></label>
		<input type="number" id="webmail-max-attachments" min="1" max="<?php p((string)$_['maxAttachmentsLimit']); ?>" step="1" value="<?php p((string)$_['maxAttachmentsMb']); ?>">
	</p>
	<p>
		<input type="checkbox" class="checkbox" id="webmail-no-verify-tls" <?php if (!$_['verifyTls']) { print_unescaped('checked'); } ?>>
		<label for="webmail-no-verify-tls"><?php p($l->t('Ne pas vérifier le certificat des serveurs (Exchange, Matrix)')); ?></label>
	</p>
	<p id="webmail-tls-warning" class="wm-tls-warning" role="alert" <?php if ($_['verifyTls']) { print_unescaped('hidden'); } ?>>
		<?php p($l->t('Attention : sans vérification du certificat, une personne placée sur le réseau peut se faire passer pour le serveur et lire les mots de passe et les messages. À n\'utiliser que pour un serveur interne avec un certificat auto-signé, jamais pour OVH Hosted Exchange dont les certificats sont valides.')); ?>
	</p>
	<p>
		<button type="button" id="webmail-add"><?php p($l->t('Ajouter un domaine')); ?></button>
		<button type="button" id="webmail-save" class="primary"><?php p($l->t('Enregistrer')); ?></button>
		<span id="webmail-admin-msg" class="msg" role="status"></span>
	</p>
</div>
