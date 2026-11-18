<?php
/** @var array $_ */
?>
<div id="webmail-app"
	data-domains="<?php p(json_encode($_['domains'])); ?>"
	data-is-admin="<?php p($_['isAdmin'] ? '1' : '0'); ?>"
	data-autodiscover="<?php p(!empty($_['autodiscover']) ? '1' : '0'); ?>"
	data-talk="<?php p(!empty($_['talk']) ? '1' : '0'); ?>"
	data-deck="<?php p(!empty($_['deck']) ? '1' : '0'); ?>"
	data-wm-theme="<?php p($_['theme'] ?? ''); ?>"
	data-visio="<?php p($_['visio'] ?? ''); ?>"
	data-visio-mode="<?php p($_['visioMode'] ?? ''); ?>"
	data-max-attachments="<?php p((string)($_['maxAttachments'] ?? 0)); ?>"
	data-image-folders="<?php p(json_encode($_['imageFolders'] ?? null)); ?>">
	<div class="wm-loading"><span class="icon-loading"></span></div>
</div>
