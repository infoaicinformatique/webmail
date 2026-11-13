<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$servers = $_['servers'];
if ($servers === []) {
	$servers = [
		['domain' => '', 'label' => 'Serveur OVH 1', 'url' => ''],
		['domain' => '', 'label' => 'Serveur OVH 2', 'url' => ''],
	];
}
?>
<div id="webmail-admin" class="section">
	<h2>Webmail Exchange — serveurs</h2>
	<p class="settings-hint">
		Associez chaque domaine e-mail au serveur Exchange OVH qui l'héberge. L'utilisateur saisit seulement
		son adresse et son mot de passe&nbsp;: le serveur est choisi d'après le domaine.<br>
		Vous trouverez l'adresse du serveur dans l'espace client OVH (Hosted Exchange → Informations générales,
		par ex. <code>ex3.mail.ovh.net</code>). Un nom d'hôte suffit&nbsp;: l'URL EWS
		<code>https://&lt;serveur&gt;/EWS/Exchange.asmx</code> est complétée automatiquement.
		Utilisez <code>*</code> comme domaine pour un serveur par défaut.
	</p>
	<table class="webmail-admin-table">
		<thead>
			<tr><th>Domaine</th><th>Libellé</th><th>Serveur Exchange (hôte ou URL EWS)</th><th></th></tr>
		</thead>
		<tbody id="webmail-servers">
			<?php foreach ($servers as $s): ?>
			<tr>
				<td><input type="text" class="wm-domain" placeholder="exemple.fr" value="<?php p($s['domain']); ?>"></td>
				<td><input type="text" class="wm-label" placeholder="Libellé" value="<?php p($s['label']); ?>"></td>
				<td><input type="text" class="wm-url" placeholder="ex3.mail.ovh.net" value="<?php p($s['url']); ?>"></td>
				<td><button type="button" class="wm-remove icon-delete" title="Supprimer" aria-label="Supprimer"></button></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<h3>Découverte automatique (Autodiscover)</h3>
	<p class="settings-hint">
		Pour une adresse dont le domaine n'est pas dans le tableau, le webmail peut chercher son serveur comme Outlook
		(<code>autodiscover.&lt;domaine&gt;</code>), avec l'adresse et le mot de passe saisis. Le serveur trouvé est retenu pour ce domaine.
		Par sécurité, seuls les serveurs dont le nom se termine par l'un de ceux indiqués ci-dessous sont acceptés
		(<code>mail.ovh.net</code> pour OVH Hosted Exchange), et jamais une adresse du réseau local.
	</p>
	<p>
		<input type="checkbox" id="webmail-autodiscover" class="checkbox" <?php if ($_['autodiscover']['enabled']) { p('checked'); } ?>>
		<label for="webmail-autodiscover">Chercher automatiquement le serveur des autres domaines</label>
	</p>
	<p>
		<label for="webmail-autodiscover-hosts">Serveurs autorisés</label>
		<input type="text" id="webmail-autodiscover-hosts" value="<?php p(implode(', ', $_['autodiscover']['hosts'])); ?>" placeholder="mail.ovh.net" size="40">
	</p>
	<ul id="webmail-discovered">
		<?php foreach ($_['discovered'] as $domain => $url): ?>
		<li data-domain="<?php p($domain); ?>"><?php p($domain); ?> → <code><?php p($url); ?></code>
			<button type="button" class="wm-forget" title="Oublier : le prochain compte de ce domaine le cherchera à nouveau">Oublier</button></li>
		<?php endforeach; ?>
	</ul>
	<p>
		<button type="button" id="webmail-add">Ajouter un domaine</button>
		<button type="button" id="webmail-save" class="primary">Enregistrer</button>
		<span id="webmail-admin-msg" class="msg" role="status"></span>
	</p>
</div>
