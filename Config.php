<?php namespace Model\Admin;

use Model\Core\Module_Config;

class Config extends Module_Config
{
	public $configurable = true;

	/**
	 * @param array $data
	 * @return mixed
	 */
	public function init(?array $data = null): bool
	{
		if ($data === null or !$this->model->moduleExists('Db'))
			return false;

		if (isset($data['api-path'])) {
			$this->model->_Db->query('CREATE TABLE IF NOT EXISTS `admin_privileges` (
				  `id` int(11) NOT NULL AUTO_INCREMENT,
				  `page` varchar(250) COLLATE utf8_unicode_ci DEFAULT NULL,
				  `subpage` varchar(250) COLLATE utf8_unicode_ci DEFAULT NULL,
				  `profile` int(11) DEFAULT NULL,
				  `user` int(11) DEFAULT NULL,
				  `C` tinyint(4) NOT NULL,
				  `C_special` varchar(250) NULL,
				  `R` tinyint(4) NOT NULL,
				  `R_special` varchar(250) NULL,
				  `U` tinyint(4) NOT NULL,
				  `U_special` varchar(250) NULL,
				  `D` tinyint(4) NOT NULL,
				  `D_special` varchar(250) NULL,
				  `L` TINYINT NOT NULL,
				  `L_special` VARCHAR(250) NULL,
				  PRIMARY KEY (`id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;');

			if ($this->saveConfig('init', $data)) {
				if (isset($data['make-users-table'])) {
					$this->model->_Db->query('CREATE TABLE IF NOT EXISTS `' . $data['table'] . '` (
						  `id` int(11) NOT NULL AUTO_INCREMENT,
						  `username` varchar(250) NOT NULL,
						  `password` varchar(250) NOT NULL,
						  PRIMARY KEY (`id`)
						) ENGINE=InnoDB DEFAULT CHARSET=utf8;');
				}
				if (isset($data['make-account']))
					$this->model->_Db->query('INSERT INTO `' . $data['table'] . '`(username,password) VALUES(' . $this->model->_Db->quote($data['username']) . ',' . $this->model->_Db->quote(password_hash($data['password'], PASSWORD_DEFAULT)) . ')');

				return true;
			} else {
				$this->model->error('Error while saving config data');
			}
		}

		return false;
	}

	/**
	 *
	 */
	protected function assetsList()
	{
		$this->addAsset('data', 'cache.php', function () {
			$arr = [
				'rules' => [],
				'macro' => [],
			];
			return "<?php\n\$cache = " . var_export($arr, true) . ";\n";
		});
	}

	/**
	 * @param string $type
	 * @return null|string
	 */
	public function getTemplate(string $type): ?string
	{
		if (!in_array($type, ['init', 'config']))
			return null;

		return $type;
	}

	/**
	 * Saves configuration
	 *
	 * @param string $type
	 * @param array $data
	 * @return bool
	 * @throws \Exception
	 */
	public function saveConfig(string $type, array $data): bool
	{
		$config = $this->retrieveConfig();
		if (isset($config['url'])) {
			foreach ($config['url'] as $idx => $url) {
				if (isset($data[$idx . '-path']))
					$url['path'] = $data[$idx . '-path'];
				if (isset($data[$idx . '-table']))
					$url['table'] = $data[$idx . '-table'];
				if (isset($data[$idx . '-element']))
					$url['element'] = $data[$idx . '-element'];
				if (isset($data[$idx . '-admin-page']))
					$url['admin-page'] = $data[$idx . '-admin-page'];
				if (isset($data[$idx . '-pages']))
					$url['pages'] = $this->parsePages(json_decode($data[$idx . '-pages'], true));
				$config['url'][$idx] = $url;
			}

			foreach ($config['url'] as $idx => $url) {
				if (isset($data['delete-' . $idx]))
					unset($config['url'][$idx]);
			}
		} else {
			$config['url'] = [];
		}

		if (isset($data['table']) and $data['table'] and empty($config['url'])) {
			$config['url'][] = [
				'path' => $data['path'],
				'table' => $data['table'],
				'element' => '',
				'admin-page' => '',
				'pages' => [],
			];
		}

		if (isset($data['api-path']))
			$config['api-path'] = $data['api-path'];

		$configFile = INCLUDE_PATH . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Admin' . DIRECTORY_SEPARATOR . 'config.php';

		return (bool)file_put_contents($configFile, '<?php
$config = ' . var_export($config, true) . ';
');
	}

	/**
	 * Parses the input pages, during config save, and returns them in a standard format
	 *
	 * @param array $pages
	 * @return array
	 * @throws \Model\Core\Exception
	 */
	private function parsePages(array $pages): array
	{
		foreach ($pages as &$p) {
			if (!isset($p['name']))
				$this->model->error('Name for all Admin pages is required.');

			if (isset($p['name']) and !isset($p['controller'])) {
				if (!isset($p['sub']) or isset($p['link']))
					$p['controller'] = str_replace(["\t", "\n", "\r", "\0", "\x0B", " "], '', ucwords(strtolower($p['name'])));
			}

			if (isset($p['controller']) and !isset($p['rule']))
				$p['rule'] = str_replace(' ', '-', strtolower($p['name']));

			if (isset($p['sub']))
				$p['sub'] = $this->parsePages($p['sub']);
		}
		unset($p);

		return $pages;
	}

	/**
	 * @return array
	 */
	public function retrieveConfig(): array
	{
		$config = parent::retrieveConfig();

		// Transition from the old version (where pages where managed by AdminFront) to the current one
		if (!isset($config['url']) and class_exists('\Model\AdminFront\Config')) {
			$adminFrontClass = new \Model\AdminFront\Config($this->model);
			$adminFrontConfig = $adminFrontClass->retrieveConfig();
			if (isset($adminFrontConfig['url'])) {
				$config['url'] = $adminFrontConfig['url'];
				unset($adminFrontConfig['url']);
				parent::saveConfig('config', $config);
				$adminFrontClass->saveConfig('config', $adminFrontConfig);
			}
		}

		if (!isset($config['api-path'])) {
			$config['api-path'] = 'admin-api';
			parent::saveConfig('config', $config);
		}

		return $config;
	}

	/**
	 * @return bool
	 */
	public function makeCache(): bool
	{
		$cache = $this->buildCache();
		return (bool)file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'cache.php', "<?php\n\$cache = " . var_export($cache, true) . ";\n");
	}

	/**
	 * @return array
	 */
	public function buildCache(): array
	{
		$config = $this->retrieveConfig();

		$rules = [];
		$macro = [];

		foreach (($config['url'] ?? []) as $path) {
			if (in_array($path['path'], $macro))
				$this->model->error('Duplicate admin path "' . $path['path'] . '""');

			$macro[] = $path['path'];

			foreach ($this->buildRules($path['pages'], $path['path'] ?: '') as $rule) {
				if (in_array($rule, $rules))
					$this->model->error('Duplicate admin rule "' . $rule . '"');
				$rules[] = $rule;
			}
		}

		usort($rules, function ($a, $b) {
			return strlen($b) <=> strlen($a);
		});

		usort($macro, function ($a, $b) {
			return strlen($b) <=> strlen($a);
		});

		return [
			'rules' => $rules,
			'macro' => $macro,
		];
	}

	/**
	 * @param array $pages
	 * @param string $prefix
	 * @return array
	 */
	private function buildRules(array $pages, string $prefix): array
	{
		$rules = [];
		foreach ($pages as $p) {
			if ($p['rule'] ?? null)
				$rules[] = ($prefix ? $prefix . '/' : '') . $p['rule'];

			if ($p['sub'] ?? [])
				$rules = array_merge($rules, $this->buildRules($p['sub'], $prefix));
		}

		return $rules;
	}

	/**
	 * Admin pages rules
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function getRules(): array
	{
		$config = $this->retrieveConfig();

		$ret = [
			'rules' => [
				'api' => $config['api-path'] ?? 'admin-api',
			],
			'controllers' => [
				'AdminApi',
			],
		];

		return $ret;
	}

	/**
	 * @return bool
	 */
	public function postUpdate_1_1_0(): bool
	{
		$this->model->_Db->query('ALTER TABLE `admin_privileges` 
			DROP COLUMN `group`,
			DROP COLUMN `path`,
			CHANGE COLUMN `page` `page` VARCHAR(250) CHARACTER SET \'utf8\' COLLATE \'utf8_unicode_ci\' NULL DEFAULT NULL AFTER `id`,
			CHANGE COLUMN `user` `user` INT(11) NULL ,
			ADD COLUMN `profile` INT NULL AFTER `page` ,
			ADD COLUMN `subpage` VARCHAR(250) NULL AFTER `page`;');
		return true;
	}

	/**
	 * @return bool
	 */
	public function postUpdate_1_2_0(): bool
	{
		$config = $this->retrieveConfig();
		if (!isset($config['api-path']) or $config['api-path'] === 'api')
			$config['api-path'] = 'admin-api';

		return $this->saveConfig('init', $config);
	}
}
