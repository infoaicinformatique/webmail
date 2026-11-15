<?php

declare(strict_types=1);

namespace OCA\Webmail\Calendar;

use Sabre\VObject\Component;
use Sabre\VObject\Document;

/**
 * The events and tasks handed to the CalDAV wrapper of Nextcloud (AppCalendar): it reads their
 * UID as « $object['UID'] », which a component of Sabre does not answer (a PHP warning, and an
 * empty UID: the occurrences changed of a series were then not kept with it). These answer it.
 */
final class AppComponents {
	/**
	 * @param iterable<Component> $components VEVENT or VTODO
	 * @return list<Component>
	 */
	public static function wrap(Document $root, iterable $components): array {
		$out = [];
		foreach ($components as $c) {
			$copy = match ($c->name) {
				'VEVENT' => new AppVEvent($root, 'VEVENT', [], false),
				'VTODO' => new AppVTodo($root, 'VTODO', [], false),
				default => null,
			};
			if ($copy === null) {
				$out[] = $c;
				continue;
			}
			foreach ($c->children() as $child) {
				$copy->add(clone $child);
			}
			$out[] = $copy;
		}
		return $out;
	}
}
