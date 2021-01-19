<?php namespace Model\Admin\Migrations;

use Model\Db\Migration;

class Migration_20210119160327_DefaultPrivileges extends Migration
{
	public function exec()
	{
		$adminConfig = $this->model->_Admin->retrieveConfig();

		$alreadySeenPrefixes = [];

		foreach ($adminConfig['url'] as $url) {
			if (($url['model-managed'] ?? true) and !in_array($url['users-tables-prefix'], $alreadySeenPrefixes)) {
				$this->changeColumn($url['users-tables-prefix'] . 'privileges', 'C', ['type' => 'tinyint', 'null' => false, 'default' => 1]);
				$this->changeColumn($url['users-tables-prefix'] . 'privileges', 'R', ['type' => 'tinyint', 'null' => false, 'default' => 1]);
				$this->changeColumn($url['users-tables-prefix'] . 'privileges', 'U', ['type' => 'tinyint', 'null' => false, 'default' => 1]);
				$this->changeColumn($url['users-tables-prefix'] . 'privileges', 'D', ['type' => 'tinyint', 'null' => false, 'default' => 1]);
				$this->changeColumn($url['users-tables-prefix'] . 'privileges', 'L', ['type' => 'tinyint', 'null' => false, 'default' => 1]);
				$alreadySeenPrefixes[] = $url['users-tables-prefix'];
			}
		}
	}
}
