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
		<?php print_unescaped($l->t('Adresse de votre serveur Element Call (par ex. %1$s) : le formulaire d\'un événement propose alors « Visio Element », qui met dans l\'invitation le lien d\'une salle chiffrée, propre à la réunion. Une autre visio (Jitsi…) : une adresse avec %2$s à la place du nom de la salle, par ex. %3$s. Laissez vide pour ne rien proposer.',
			[$code('https://call.element.io'), $code('{room}'), $code('https://meet.example.org/{room}')])); ?>
	</p>
	<p>
		<label for="webmail-visio"><?php p($l->t('Adresse de la visio')); ?></label>
		<input type="url" id="webmail-visio" value="<?php p($_['visio']); ?>" placeholder="https://call.element.io" size="50">
	</p>
	<p>
		<button type="button" id="webmail-add"><?php p($l->t('Ajouter un domaine')); ?></button>
		<button type="button" id="webmail-save" class="primary"><?php p($l->t('Enregistrer')); ?></button>
		<span id="webmail-admin-msg" class="msg" role="status"></span>
	</p>
</div>
