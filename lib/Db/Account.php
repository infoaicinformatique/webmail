<?php

declare(strict_types=1);

namespace OCA\Webmail\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string|null getDisplayName()
 * @method void setDisplayName(?string $displayName)
 * @method string getPassword()
 * @method void setPassword(string $password)
 * @method string|null getSignature()
 * @method void setSignature(?string $signature)
 */
class Account extends Entity implements \JsonSerializable {
	protected $userId;
	protected $email;
	protected $displayName;
	protected $password;
	protected $signature;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('email', 'string');
		$this->addType('displayName', 'string');
		$this->addType('password', 'string');
		$this->addType('signature', 'string');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'email' => $this->getEmail(),
			'displayName' => $this->getDisplayName() ?: $this->getEmail(),
			'signature' => $this->getSignature() ?? '',
		];
	}
}
