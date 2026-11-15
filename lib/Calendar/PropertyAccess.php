<?php

declare(strict_types=1);

namespace OCA\Webmail\Calendar;

/** A property read as an array key: « $event['UID'] » (see AppComponents). */
trait PropertyAccess {
	#[\ReturnTypeWillChange]
	public function offsetGet($offset) {
		return is_string($offset) ? $this->__get($offset) : parent::offsetGet($offset);
	}

	#[\ReturnTypeWillChange]
	public function offsetExists($offset) {
		return is_string($offset) ? $this->__isset($offset) : parent::offsetExists($offset);
	}
}
