<?php namespace Model\Admin\Migrations;

use Model\Db\Migration;

class Migration_20200531165000_CreateUsersTable extends Migration
{
	public function exec()
	{
		$adminConfig = $this->model->_Admin->retrieveConfig();

		$alreadySeenPrefixes = [];

		foreach ($adminConfig['url'] as $url) {
			if (($url['model-managed'] ?? true) and !in_array($url['users-tables-prefix'], $alreadySeenPrefixes)) {
				$this->createTable($url['users-tables-prefix'] . 'users');
				$this->addColumn($url['users-tables-prefix'] . 'users', 'username');
				$this->addColumn($url['users-tables-prefix'] . 'users', 'password');

				$this->query('INSERT INTO ' . $this->model->_Db->parseField($url['users-tables-prefix'] . 'users') . '(`username`,`password`) VALUES(\'admin\',' . $this->model->_Db->parseValue(password_hash('admin', PASSWORD_DEFAULT)) . ')');

				$alreadySeenPrefixes[] = $url['users-tables-prefix'];
			}
		}
	}

	public function check(): bool
	{
		$adminConfig = $this->model->_Admin->retrieveConfig();

		foreach ($adminConfig['url'] as $url)
			if (($url['model-managed'] ?? true))
				return $this->tableExists($url['users-tables-prefix'] . 'users');

		return false;
	}
}
