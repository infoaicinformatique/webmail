<?php

declare(strict_types=1);

namespace OCA\Webmail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261112000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('webmail_accounts')) {
			return null;
		}
		$table = $schema->createTable('webmail_accounts');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('email', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('display_name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('password', Types::TEXT, ['notnull' => true]);
		$table->addColumn('signature', Types::TEXT, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id'], 'webmail_acc_user');
		return $schema;
	}
}
