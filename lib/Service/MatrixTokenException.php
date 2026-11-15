<?php

declare(strict_types=1);

namespace OCA\Webmail\Service;

use OCA\Webmail\Exception\MailException;

/** The Matrix server does not know the access token (any more). */
class MatrixTokenException extends MailException {
}
