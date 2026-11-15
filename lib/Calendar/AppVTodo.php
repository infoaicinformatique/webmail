<?php

declare(strict_types=1);

namespace OCA\Webmail\Calendar;

use Sabre\VObject\Component\VTodo;

/** A task of the CalDAV wrapper (see AppComponents). */
final class AppVTodo extends VTodo {
	use PropertyAccess;
}
