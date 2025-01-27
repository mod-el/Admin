<?php

use Phinx\Migration\AbstractMigration;

class AdminUsers extends AbstractMigration
{
	public function change()
	{
		if (!$this->hasTable('admin_users')) {
			$this->table('admin_profiles')
				->addColumn('name', 'string', ['null' => false])
				->create();

			$this->table('admin_profiles')->insert([
				'id' => 1,
				'name' => 'admin',
			])->saveData();

			$this->table('admin_users')
				->addColumn('username', 'string', ['null' => false])
				->addColumn('password', 'string', ['null' => false])
				->addColumn('profile', 'integer', ['null' => true])
				->addForeignKey('profile', 'admin_profiles', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
				->create();

			$this->table('admin_users')->insert([
				'id' => 1,
				'username' => 'admin',
				'password' => password_hash('admin', PASSWORD_DEFAULT),
				'profile' => 1,
			])->saveData();

			$this->table('admin_privileges')
				->addColumn('page', 'string', ['null' => true])
				->addColumn('subpage', 'string', ['null' => true])
				->addColumn('profile', 'integer', ['null' => true])
				->addColumn('user', 'integer', ['null' => true])
				->addColumn('C', 'boolean', ['null' => false, 'default' => true])
				->addColumn('C_special', 'string', ['null' => true])
				->addColumn('R', 'boolean', ['null' => false, 'default' => true])
				->addColumn('R_special', 'string', ['null' => true])
				->addColumn('U', 'boolean', ['null' => false, 'default' => true])
				->addColumn('U_special', 'string', ['null' => true])
				->addColumn('D', 'boolean', ['null' => false, 'default' => true])
				->addColumn('D_special', 'string', ['null' => true])
				->addColumn('L', 'boolean', ['null' => false, 'default' => true])
				->addColumn('L_special', 'string', ['null' => true])
				->addForeignKey('user', 'admin_users', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
				->addForeignKey('profile', 'admin_profiles', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
				->create();
		}
	}
}
