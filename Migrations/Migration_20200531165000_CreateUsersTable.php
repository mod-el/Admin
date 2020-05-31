<?php namespace Model\Admin\Migrations;

use Model\Db\Migration;

class Migration_20200531165000_CreateUsersTable extends Migration
{
	public function exec()
	{
		$adminConfig = $this->model->_Admin->retrieveConfig();

		foreach ($adminConfig['url'] as $url) {
			if (($url['model-managed'] ?? true) and $url['table']) {
				$this->createTable($url['table']);
				$this->addColumn($url['table'], 'username');
				$this->addColumn($url['table'], 'password');

				$this->query('INSERT INTO `' . $this->model->_Db->makeSafe($url['table']) . '`(`username`,`password`) VALUES(\'admin\',' . $this->db->quote(password_hash('admin', PASSWORD_DEFAULT)) . ')');
			}
		}
	}

	public function check(): bool
	{
		$adminConfig = $this->model->_Admin->retrieveConfig();

		foreach ($adminConfig['url'] as $url)
			if (($url['model-managed'] ?? true) and $url['table'])
				return $this->tableExists($url['table']);
	}
}
