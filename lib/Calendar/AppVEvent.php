<?php

declare(strict_types=1);

namespace OCA\Webmail\Calendar;

use Sabre\VObject\Component\VEvent;

/** An event of the CalDAV wrapper (see AppComponents). */
final class AppVEvent extends VEvent {
	use PropertyAccess;
}
