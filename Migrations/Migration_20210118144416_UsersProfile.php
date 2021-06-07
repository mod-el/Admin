<?php namespace Model\Admin\Migrations;

use Model\Db\Migration;

class Migration_20210118144416_UsersProfile extends Migration
{
	public function exec()
	{
		$adminConfig = $this->model->_Admin->retrieveConfig();

		$alreadySeenPrefixes = [];

		foreach ($adminConfig['url'] as $url) {
			if (($url['model-managed'] ?? true) and !in_array($url['users-tables-prefix'], $alreadySeenPrefixes)) {
				$this->createTable($url['users-tables-prefix'] . 'profiles');
				$this->addColumn($url['users-tables-prefix'] . 'profiles', 'name', ['null' => false]);

				$this->addColumn($url['users-tables-prefix'] . 'users', 'profile', ['type' => 'int']);
				$this->addIndex($url['users-tables-prefix'] . 'users', $url['users-tables-prefix'] . 'users_profile', ['profile']);
				$this->addForeignKey($url['users-tables-prefix'] . 'users', $url['users-tables-prefix'] . 'users_profile', 'profile', $url['users-tables-prefix'] . 'profiles');

				$this->addIndex($url['users-tables-prefix'] . 'privileges', $url['users-tables-prefix'] . 'privileges_profile', ['profile']);
				$this->addIndex($url['users-tables-prefix'] . 'privileges', $url['users-tables-prefix'] . 'privileges_user', ['user']);
				$this->addForeignKey($url['users-tables-prefix'] . 'privileges', $url['users-tables-prefix'] . 'privileges_profile', 'profile', $url['users-tables-prefix'] . 'profiles');
				$this->addForeignKey($url['users-tables-prefix'] . 'privileges', $url['users-tables-prefix'] . 'privileges_user', 'user', $url['users-tables-prefix'] . 'users');

				$this->query('INSERT INTO ' . $this->model->_Db->parseField($url['users-tables-prefix'] . 'profiles') . '(`name`) VALUES(\'admin\')');
				$this->query('UPDATE ' . $this->model->_Db->parseField($url['users-tables-prefix'] . 'users') . ' SET `profile` = 1');

				$alreadySeenPrefixes[] = $url['users-tables-prefix'];
			}
		}
	}
}
