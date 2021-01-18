<?php namespace Model\Admin\Migrations;

use Model\Db\Migration;

class Migration_20200531165200_CreatePrivilegesTable extends Migration
{
	public function exec()
	{
		$adminConfig = $this->model->_Admin->retrieveConfig();

		$alreadySeenPrefixes = [];

		foreach ($adminConfig['url'] as $url) {
			if (!in_array($url['users-tables-prefix'], $alreadySeenPrefixes)) {
				$this->createTable($url['users-tables-prefix'] . 'privileges');
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'page');
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'subpage');
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'profile', ['type' => 'int']);
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'user', ['type' => 'int']);
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'C', ['type' => 'tinyint', 'null' => false]);
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'C_special');
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'R', ['type' => 'tinyint', 'null' => false]);
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'R_special');
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'U', ['type' => 'tinyint', 'null' => false]);
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'U_special');
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'D', ['type' => 'tinyint', 'null' => false]);
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'D_special');
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'L', ['type' => 'tinyint', 'null' => false]);
				$this->addColumn($url['users-tables-prefix'] . 'privileges', 'L_special');

				$alreadySeenPrefixes[] = $url['users-tables-prefix'];
			}
		}
	}

	public function check(): bool
	{
		$adminConfig = $this->model->_Admin->retrieveConfig();

		foreach ($adminConfig['url'] as $url)
			return $this->tableExists($url['users-tables-prefix'] . 'privileges');

		return false;
	}
}
