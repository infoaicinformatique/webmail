<?php
/** @var array $_ */
?>
<div id="webmail-app"
	data-domains="<?php p(json_encode($_['domains'])); ?>"
	data-is-admin="<?php p($_['isAdmin'] ? '1' : '0'); ?>">
	<div class="wm-loading"><span class="icon-loading"></span></div>
</div>
