<?php namespace Model\Admin\AdminPages;

use Model\Admin\AdminPage;

class AdminProfiles extends AdminPage
{
	public function options(): array
	{
		$config = $this->model->_Admin->retrieveConfig();
		$profilesTable = null;

		if (isset($config['url']) and is_array($config['url'])) {
			foreach ($config['url'] as $u) {
				if (is_array($u) and $u['path'] == $this->model->_Admin->getPath()) {
					$profilesTable = $u['users-tables-prefix'] . 'profiles';
					break;
				}
			}
		}

		$options = [
			'visualizer' => 'FormList',
			'order_by' => 'name',
			'fields' => [
				'name',
			],
			'privileges' => [
				'D' => function ($el) {
					return (bool)($el['id'] != $this->model->_User_Admin->profile);
				},
			],
		];
		if ($profilesTable)
			$options['table'] = $profilesTable;
		return $options;
	}
}
