<?php namespace Model\Admin\Migrations;

use Model\Db\Migration;

class Migration_20200531164700_CreatePrivilegesTable extends Migration
{
	public function exec()
	{
		$this->createTable('admin_privileges');
		$this->addColumn('admin_privileges', 'page');
		$this->addColumn('admin_privileges', 'subpage');
		$this->addColumn('admin_privileges', 'profile', ['type' => 'int']);
		$this->addColumn('admin_privileges', 'user', ['type' => 'int']);
		$this->addColumn('admin_privileges', 'C', ['type' => 'tinyint', 'null' => false]);
		$this->addColumn('admin_privileges', 'C_special');
		$this->addColumn('admin_privileges', 'R', ['type' => 'tinyint', 'null' => false]);
		$this->addColumn('admin_privileges', 'R_special');
		$this->addColumn('admin_privileges', 'U', ['type' => 'tinyint', 'null' => false]);
		$this->addColumn('admin_privileges', 'U_special');
		$this->addColumn('admin_privileges', 'D', ['type' => 'tinyint', 'null' => false]);
		$this->addColumn('admin_privileges', 'D_special');
		$this->addColumn('admin_privileges', 'L', ['type' => 'tinyint', 'null' => false]);
		$this->addColumn('admin_privileges', 'L_special');
	}

	public function check(): bool
	{
		return $this->tableExists('admin_privileges');
	}
}
