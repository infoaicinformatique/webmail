<?php
/** @var array $_ */
?>
<div id="webmail-app"
	data-domains="<?php p(json_encode($_['domains'])); ?>"
	data-is-admin="<?php p($_['isAdmin'] ? '1' : '0'); ?>"
	data-autodiscover="<?php p(!empty($_['autodiscover']) ? '1' : '0'); ?>"
	data-talk="<?php p(!empty($_['talk']) ? '1' : '0'); ?>"
	data-visio="<?php p($_['visio'] ?? ''); ?>">
	<div class="wm-loading"><span class="icon-loading"></span></div>
</div>
