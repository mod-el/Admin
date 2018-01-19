<?php namespace Model\Admin;

use Model\Core\Module_Config;

class Config extends Module_Config {
	public $configurable = true;

	/**
	 * Saves configuration
	 *
	 * @param string $type
	 * @param array $data
	 * @return bool
	 * @throws \Model\Core\Exception
	 */
	public function saveConfig(string $type, array $data): bool
	{
		if(!is_dir(INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Admin'))
			mkdir(INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Admin');

		$config = $this->retrieveConfig();
		if(isset($config['url'])){
			foreach($config['url'] as $idx=>$url){
				if(isset($data[$idx.'-path']))
					$url['path'] = $data[$idx.'-path'];
				if(isset($data[$idx.'-table']))
					$url['table'] = $data[$idx.'-table'];
				if(isset($data[$idx.'-pages']))
					$url['pages'] = $this->parsePages(json_decode($data[$idx.'-pages'], true));
				$config['url'][$idx] = $url;
			}

			foreach($config['url'] as $idx=>$url){
				if(isset($data['delete-'.$idx]))
					unset($config['url'][$idx]);
			}
		}else{
			$config['url'] = [];
		}

		if($data['table']){
			$config['url'][] = [
				'path' => $data['path'],
				'table' => $data['table'],
				'pages' => [],
			];
		}

		if(isset($data['template'])) $config['template'] = $data['template'];
		if(isset($data['hide-menu'])) $config['hide-menu'] = $data['hide-menu'];
		if(isset($data['dateFormat'])) $config['dateFormat'] = $data['dateFormat'];
		if(isset($data['priceFormat'])) $config['priceFormat'] = $data['priceFormat'];
		if(isset($data['stringaLogin1'])) $config['stringaLogin1'] = $data['stringaLogin1'];
		if(isset($data['stringaLogin2'])) $config['stringaLogin2'] = $data['stringaLogin2'];

		if($this->model->isLoaded('Output')){
			$headerTemplate = $this->model->_Output->findTemplateFile('header', $config['template']);
			if($headerTemplate)
				$this->model->_Output->removeFileFromCache($headerTemplate['path']);
			$footerTemplate = $this->model->_Output->findTemplateFile('footer', $config['template']);
			if($footerTemplate)
				$this->model->_Output->removeFileFromCache($footerTemplate['path']);
		}

		$adminDictionaryFile = INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Admin'.DIRECTORY_SEPARATOR.'dictionary.php';
		if(!file_exists($adminDictionaryFile))
			file_put_contents($adminDictionaryFile, "<?php\n\$dictionary = [];\n");
		$this->checkAndInsertWords([]);

		$configFile = INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Admin'.DIRECTORY_SEPARATOR.'config.php';

		return (bool) file_put_contents($configFile, '<?php
$config = '.var_export($config, true).';
');
	}

	/**
	 * Parses the input pages, during config save, and returns them in a standard format
	 *
	 * @param array $pages
	 * @return array
	 * @throws \Model\Core\Exception
	 */
	private function parsePages(array $pages){
		foreach($pages as &$p){
			if(!isset($p['name']))
				$this->model->error('Name for all Admin pages is required.');

			if(isset($p['name']) and !isset($p['controller'])){
				if(!isset($p['sub']) or isset($p['link']))
					$p['controller'] = str_replace(["\t", "\n", "\r", "\0", "\x0B", " "], '', ucwords(strtolower($p['name'])));
			}

			if(isset($p['controller']) and !isset($p['rule'])){
				$p['rule'] = str_replace(' ', '-', strtolower($p['name']));
			}

			if(isset($p['sub']))
				$p['sub'] = $this->parsePages($p['sub']);
		}
		unset($p);

		return $pages;
	}

	/**
	 * @param array $request
	 * @return null|string
	 */
	public function getTemplate(array $request){
		if(!in_array($request[2], ['init', 'config']))
			return null;
		return $request[2];
	}

	/**
	 * @param array $data
	 * @return mixed
	 * @throws \Model\Core\Exception
	 */
	public function install(array $data = []): bool
	{
		if(empty($data))
			return true;

		if(isset($data['path'], $data['table'], $data['username'], $data['password']) and $data['table']){
			if(isset($data['make-account']) and $data['password']!=$data['repassword']){
				$this->model->error('The passwords do not match');
			}else{
				if($this->saveConfig('install', $data)){
					if(isset($data['make-users-table'])){
						$this->model->_Db->query('CREATE TABLE IF NOT EXISTS `'.$data['table'].'` (
						  `id` int(11) NOT NULL AUTO_INCREMENT,
						  `username` varchar(100) NOT NULL,
						  `password` char(40) NOT NULL,
						  PRIMARY KEY (`id`)
						) ENGINE=InnoDB DEFAULT CHARSET=utf8;');
					}
					if(isset($data['make-account'])){
						$this->model->_Db->query('INSERT INTO `'.$data['table'].'`(username,password) VALUES('.$this->model->_Db->quote($data['username']).','.$this->model->_Db->quote(sha1(md5($data['password']))).')');
					}

					$this->model->_Db->query('CREATE TABLE IF NOT EXISTS `admin_privileges`(
					  `id` INT NOT NULL AUTO_INCREMENT,
					  `path` VARCHAR(250) NOT NULL,
					  `user` INT NOT NULL,
					  `group` VARCHAR(250) NULL,
					  `page` VARCHAR(250) NULL,
					  `C` TINYINT NOT NULL,
					  `C_special` VARCHAR(250) NULL,
					  `R` TINYINT NOT NULL,
					  `R_special` VARCHAR(250) NULL,
					  `U` TINYINT NOT NULL,
					  `U_special` VARCHAR(250) NULL,
					  `D` TINYINT NOT NULL,
					  `D_special` VARCHAR(250) NULL,
					  `L` TINYINT NOT NULL,
					  `L_special` VARCHAR(250) NULL,
					  PRIMARY KEY (`id`)) ENGINE = InnoDB;');

					return true;
				}else{
					$this->model->error('Error while saving config data');
				}
			}
		}

		return false;
	}

	/**
	 * Admin pages rules
	 *
	 * @return array
	 */
	public function getRules(): array
	{
		$config = $this->retrieveConfig();

		$ret = [
			'rules' => [],
			'controllers' => [
				'AdminLogin',
			],
		];

		if(isset($config['url'])){
			foreach($config['url'] as $idx=>$p){
				$ret['rules'][$idx] = $p['path'];
				$this->parseControllers($p['pages'], $ret['controllers']);
			}
		}

		return $ret;
	}

	/**
	 * Looks recursively for controllers in the pages array
	 *
	 * @param array $pages
	 * @param array $controllers
	 */
	private function parseControllers(array $pages, array &$controllers){
		foreach($pages as $p){
			if(isset($p['controller'])){
				if(!in_array($p['controller'], $controllers))
					$controllers[] = $p['controller'];
			}

			if(isset($p['sub']))
				$this->parseControllers($p['sub'], $controllers);
		}
	}

	/**
	 * @return array
	 */
	public function searchTemplates(){
		$templates = [];

		$dirs = glob(INCLUDE_PATH.'model'.DIRECTORY_SEPARATOR.'*');
		foreach($dirs as $f){
			if(is_dir($f) and file_exists($f.DIRECTORY_SEPARATOR.'manifest.json')){
				$moduleData = json_decode(file_get_contents($f.DIRECTORY_SEPARATOR.'manifest.json'), true);
				if($moduleData and isset($moduleData['is-admin-template']) and $moduleData['is-admin-template']){
					$name = explode(DIRECTORY_SEPARATOR, $f);
					$name = end($name);
					$templates[$name] = $moduleData['name'];
				}
			}
		}

		return $templates;
	}

	public function checkAndInsertWords(array $words): bool {
		$adminDictionaryFile = INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Admin'.DIRECTORY_SEPARATOR.'dictionary.php';

		$dictionary = [];
		if(file_exists($adminDictionaryFile))
			require($adminDictionaryFile);

		foreach($words as $w => $langs){
			if(isset($dictionary[$w]))
				$dictionary[$w] = array_merge($dictionary[$w], $words[$w]);
			else
				$dictionary[$w] = $words[$w];
		}

		$w = (bool) file_put_contents($adminDictionaryFile, "<?php\n\$dictionary = ".var_export($dictionary, true).";\n");

		if($w and $this->model->isLoaded('Multilang'))
			return $this->model->_Multilang->checkAndInsertWords('admin', $words);
		else
			return $w;
	}
}
