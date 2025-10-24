<?php namespace Model\Admin\Providers;

use Model\Router\AbstractRouterProvider;

class RouterProvider extends AbstractRouterProvider
{
	public static function getRoutes(): array
	{
		$configClass = new \Model\Admin\Config();
		$config = $configClass->retrieveConfig();

		return [
			[
				'pattern' => ($config['api-path'] ?? 'admin-api'),
				'controller' => 'AdminApi',
			],
		];
	}
}
