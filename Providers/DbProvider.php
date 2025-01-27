<?php namespace Model\Admin\Providers;

use Model\Db\AbstractDbProvider;

class DbProvider extends AbstractDbProvider
{
	public static function getMigrationsPaths(): array
	{
		return [
			[
				'path' => 'model/Admin/migrations',
			],
		];
	}
}
