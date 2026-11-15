<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div class="guest-box wm-booking">
<?php if ($_['state'] === 'gone'): ?>
	<h2><?php p($l->t('Proposition de rendez-vous introuvable')); ?></h2>
	<p><?php p($l->t('Ce lien n\'est plus valable : la proposition a expiré ou a été retirée. Écrivez à la personne qui vous l\'a envoyée.')); ?></p>
<?php elseif ($_['state'] === 'taken'): ?>
	<h2><?php p($_['subject']); ?></h2>
	<p><?php p($l->t('Un créneau a déjà été choisi pour ce rendez-vous avec %s.', [$_['organizer']])); ?></p>
<?php elseif ($_['state'] === 'done'): ?>
	<h2>✅ <?php p($l->t('Rendez-vous confirmé')); ?></h2>
	<p class="wm-booking-when"><strong><?php p($_['subject']); ?></strong><br><?php p($_['when']); ?></p>
	<p><?php p($l->t('%1$s vous envoie l\'invitation à %2$s : acceptez-la pour l\'ajouter à votre agenda.', [$_['organizer'], $_['email']])); ?></p>
<?php else: ?>
	<h2><?php p($_['subject']); ?></h2>
	<p><?php p($l->t('%1$s vous propose ces créneaux (%2$s minutes, heure de %3$s). Choisissez celui qui vous convient :', [$_['organizer'], (string)$_['minutes'], $_['tz']])); ?></p>
	<?php if ($_['error'] !== ''): ?>
	<p class="wm-booking-error" role="alert"><?php p($_['error']); ?></p>
	<?php endif; ?>
	<?php if ($_['slots'] === []): ?>
	<p><?php p($l->t('Tous les créneaux proposés sont passés. Écrivez à %s pour en avoir d\'autres.', [$_['organizer']])); ?></p>
	<?php else: ?>
	<form method="post" action="<?php p($_['action']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<fieldset class="wm-booking-slots">
			<legend><?php p($l->t('Créneau')); ?></legend>
			<?php foreach ($_['slots'] as $i => $s): ?>
			<label class="wm-booking-slot">
				<input type="radio" name="slot" value="<?php p((string)$s['index']); ?>" required <?php if ($s['index'] === $_['selected'] || ($_['selected'] < 0 && $i === 0)) { print_unescaped('checked'); } ?>>
				<span><?php p($s['label']); ?></span>
			</label>
			<?php endforeach; ?>
		</fieldset>
		<p>
			<label for="wm-booking-name"><?php p($l->t('Votre nom')); ?></label>
			<input type="text" id="wm-booking-name" name="name" required maxlength="100" autocomplete="name" value="<?php p($_['name']); ?>">
		</p>
		<p>
			<label for="wm-booking-email"><?php p($l->t('Votre adresse e-mail')); ?></label>
			<input type="email" id="wm-booking-email" name="email" required autocomplete="email" value="<?php p($_['email']); ?>">
		</p>
		<p><button type="submit" class="primary"><?php p($l->t('Confirmer ce rendez-vous')); ?></button></p>
	</form>
	<?php endif; ?>
<?php endif; ?>
</div>
