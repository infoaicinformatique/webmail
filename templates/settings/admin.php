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
	<p>
		<button type="button" id="webmail-add">Ajouter un domaine</button>
		<button type="button" id="webmail-save" class="primary">Enregistrer</button>
		<span id="webmail-admin-msg" class="msg" role="status"></span>
	</p>
</div>
