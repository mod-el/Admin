<?php
namespace Model;

class Admin extends Module {
	/** @var string */
	public $url;
	/** @var array */
	public $request;
	/** @var array */
	public $options = [];
	/** @var Paginator */
	public $paginator = [];
	/** @var Form */
	private $customFiltersForm;
	/** @var array */
	private $customFiltersCallbacks = [];
	/** @var Form */
	public $form;
	/** @var array|bool */
	protected $privilegesCache = false;
	/** @var array */
	protected $instantSaveIds = [];
	/** @var array */
	public $sublists = array();
	/** @var Module */
	public $template;

	public function init($options){
		if($options===[])
			return false;
		if($options===false) // Special controllers (like AdminLogin) can pass false to init without options
			$options = [];

		$config = $this->retrieveConfig();

		$user_table = 'admin_users';
		if(isset($config['url']) and is_array($config['url'])){
			foreach($config['url'] as $u){
				if(is_array($u) and $u['path']==$this->url){
					$user_table = $u['table'];
					break;
				}
			}
		}

		$this->model->load('User', array(
			'table'=>$user_table,
			'mandatory'=>true,
			'login-controller'=>'AdminLogin',
		), 'Admin');

		$this->model->load('Paginator');

		$this->options = array_merge([
			'element' => null,
			'table' => null,
			'columns' => [],
			'primary' => 'id',
			'where' => [],
			'order_by' => false,
			'columns-callback' => false,
			'perPage' => 20,
			'privileges'=>[],
		], $options);

		$this->options['privileges'] = array_merge([
			'C' => true,
			'R' => true,
			'U' => true,
			'D' => true,
			'L' => true,
		], $this->options['privileges']);

		if($this->options['table'] or $this->options['element']){
			if($this->options['element'] and !$this->options['table']){
				$element = $this->options['element'];
				$this->options['table'] = $element::$table;
			}

			if(!$this->options['table'])
				$this->model->error('Can\'t retrieve table name from the provided element.');

			if(!$this->options['element'])
				$this->options['element'] = '\\Model\\Element';

			$tableModel = $this->model->_Db->getTable($this->options['table']);
			if(!$tableModel)
				$this->model->error('Table model not found, please generate cache.');

			if($this->options['order_by']===false)
				$this->options['order_by'] = $this->options['primary'].' DESC';

			$new_fields = array(); // I loop through the columns to standardize the format
			foreach($this->options['columns'] as $k=>$f){
				/*
				 * ACCEPTED FORMATS: *
				 * 'field'
				 * * A single string, will be used as column id, label and as field name
				 * 'label'=>function(){}
				 * * The key is both column id and label, the callback will be used as "display" value
				 * 'label'=>'campo'
				 * * The key is both column id and label, the value is the db field to use
				 * 'label'=>array()
				 * * The key is the colum id, in the array there will be the remaining options (if a label is not provided, the column is will be used)
				*/
				if(is_numeric($k)){
					if(is_array($f)){
						if(isset($f['display']) and (is_string($f['display']) or is_numeric($f['display'])))
							$k = $f['display'];
						elseif(isset($k['field']) and (is_string($f['field']) or is_numeric($f['field'])))
							$k = $f['field'];
					}else{
						if(is_string($f) or is_numeric($f))
							$k = $f;
					}
					$k = str_replace('"', '', $this->getLabel($k));
				}

				if(!is_array($f)){
					if(is_string($f) or is_numeric($f)){
						$f = array(
							'field'=>$f,
							'display'=>$f,
						);
					}elseif(is_callable($f)){
						$f = array(
							'field'=>false,
							'display'=>$f,
						);
					}else{
						$this->model->error('Unknown column format with label "'.entities($k).'"');
					}
				}

				if(!isset($f['field']) and !isset($f['display']))
					$f['field'] = $k;

				$f = array_merge(array(
					'label'=>$k,
					'field'=>false,
					'display'=>false,
					'empty'=>'',
					'editable'=>false,
					'clickable'=>true,
					'print'=>true,
					'total'=>false,
				), $f);

				if(is_string($f['display']) and !$f['field'] and $f['display'])
					$f['field'] = $f['display'];
				if($f['field']===false and array_key_exists($k, $tableModel->columns))
					$f['field'] = $k;
				if(is_string($f['field']) and $f['field'] and !$f['display'])
					$f['display'] = $f['field'];

				$k = $this->standardizeLabel($k);
				if($k==''){
					if($f['field'])
						$k = $f['field'];
					if(!$k)
						$this->model->error('Can\'t assign id to column with label "'.entities($f['label']).'"');
				}

				$new_fields[$k] = $f;
			}
			$this->options['columns'] = $new_fields;

			$this->paginator = new Paginator();

			$this->customFiltersForm = new Form([
				'table' => $this->options['table'],
				'model' => $this->model,
			]);

			if(isset($this->request[2])){
				if(!is_numeric($this->request[2]))
					die('Element id must be numeric');

				$elId = (int) $this->request[2];
				if($elId<=0)
					$elId = false;
			}else{
				$elId = false;
			}

			$element = $this->model->_ORM->loadMainElement($this->options['element'], $elId, ['table' => $this->options['table']]);
			if($element)
				$this->form = $element->getForm();
		}

		return true;
	}

	/**
	 * Returns the appropriate controller name, given the request
	 *
	 * @param array $request
	 * @param mixed $rule
	 * @return array|bool
	 */
	public function getController(array $request, $rule){
		$config = $this->retrieveConfig();

		if(!isset($config['url'][$rule]) or (!empty($config['url'][$rule]['path']) and strpos(implode('/', $request), $config['url'][$rule]['path'])!==0))
			return false;

		$this->url = $config['url'][$rule]['path'];

		$realRequest = $this->getAdminRequest($request, $this->url);
		if($realRequest===false)
			return false;

		$this->request = $realRequest;

		if(isset($realRequest[0])){
			switch($realRequest[0]){
				case 'login':
				case 'logout':
					return [
						'controller' => 'AdminLogin',
					];
					break;
			}
		}else{
			return [
				'controller' => 'Model\\Admin',
			];
		}

		$pages = $this->getPages();
		$controller = $this->seekForController($pages, $realRequest[0]);
		if(!$controller)
			return false;

		$folder = ($this->url and file_exists(INCLUDE_PATH.'data/controllers/'.$this->url.'/'.$controller.'Controller.php')) ? $this->url.'/' : '';
		return [
			'controller' => $folder.$controller,
		];
	}

	/**
	 * Given the real request url, strips the first part (the admin path) to return the request to be parsed by the Admin module (returns false on failure)
	 *
	 * @param array $request
	 * @param string $path
	 * @return array|bool
	 */
	private function getAdminRequest(array $request, $path){
		if(empty($path))
			return $request;

		$path = explode('/', $path);
		foreach($path as $p){
			$shift = array_shift($request);
			if($shift!==$p)
				return false;
		}
		return $request;
	}

	/**
	 * Recursively looks for the controller corresponding to a given request, in the pages and sub-pages
	 *
	 * @param array $pages
	 * @param string $request
	 * @return string|bool
	 */
	private function seekForController(array $pages, $request){
		foreach($pages as $p){
			if(isset($p['controller'], $p['rule']) and $p['rule']===$request)
				return $p['controller'];
			if(isset($p['sub'])){
				$controller = $this->seekForController($p['sub'], $request);
				if($controller)
					return $controller;
			}
		}
		return false;
	}

	/**
	 * Removes all unnecessary characters of a label to generate a column id
	 *
	 * @param string $k
	 * @return string
	 */
	private function standardizeLabel($k){
		return preg_replace('/[^a-z0-9]/i', '', entities(strtolower($k)));
	}

	/**
	 * Converts a field name in a human-readable label
	 *
	 * @param string $k
	 * @return string
	 */
	public function getLabel($k){
		return ucwords(str_replace(array('-', '_'), ' ', $k));
	}

	/**
	 * Returns the list of elements, filtered by specified options
	 *
	 * @param array $options
	 * @return array
	 */
	public function getList(array $options=[]){
		$options = array_merge([
			'p' => 1,
			'perPage' => $this->options['perPage'],
			'sortBy' => [],
			'filters' => [],
			'search-columns' => false,
			'html' => false,
		], $options);

		// Create the filters array
		$where = $this->options['where'];

		$this->customFiltersCallbacks['all'] = function($v) use($options){ // "all" is a special filter, that searches in all string columns
			$tableModel = $this->model->_Db->getTable($this->options['table']);
			$columns = $tableModel->columns;

			if($this->model->isLoaded('Multilang') and array_key_exists($this->options['table'], $this->model->_Multilang->tables)){
				$mlTableOptions = $this->model->_Multilang->tables[$this->options['table']];
				$mlTable = $this->options['table'].$mlTableOptions['suffix'];
				$mlTableModel = $this->model->_Db->getTable($mlTable);
				foreach ($mlTableModel->columns as $k=>$col){
					if(isset($columns[$k]) or $k==$mlTableOptions['keyfield'] or $k==$mlTableOptions['lang'])
						continue;
					$columns[$k] = $col;
				}
			}

			$arr = [];
			foreach($columns as $k=>$col){
				if($this->options['primary']==$k or $col['foreign_key'] or ($options['search-columns'] and !in_array($k, $options['search-columns'])))
					continue;

				switch($col['type']){
					case 'tinyint':
					case 'smallint':
					case 'int':
					case 'mediumint':
					case 'bigint':
						if(is_numeric($v))
							$arr[] = [$k, '=', $v];
						break;
					case 'decimal':
						if(is_numeric($v))
							$arr[] = [$k, 'LIKE', $v.'.%'];
						break;
					case 'varchar':
					case 'char':
					case 'text':
					case 'tinytext':
					case 'enum':
						$arr[] = [$k, 'REGEXP', '(^|[^a-z0-9])'.$v];
						break;
				}
			}

			if($options['search-columns'] and empty($arr)){ // If specific columns are provided and no criteria matched, then it's impossible, I return a never-matching query
				return [
					'1=2',
				];
			}else{
				return [
					['sub'=>$arr, 'operator'=>'OR'],
				];
			}
		};

		foreach($options['filters'] as $f){
			$f_where = $this->getWhereFromFilter($f);
			if($f_where)
				$where = array_merge($where, $f_where);
		}

		// Count how many total elements there are
		$count = $this->model->_Db->count($this->options['table'], $where);

		// I pass the parameters to the paginator, so that it will calculate the total number of pages and the start limit
		$this->paginator->setOptions([
			'tot' => $count,
			'perPage' => $options['perPage'] ?: false,
			'pag' => $options['p'],
		]);
		$limit = $options['perPage'] ? $this->paginator->getStartLimit().','.$options['perPage'] : null;

		// Get the rules to apply to the query, in order to sort as requested (what joins do I need to make and what order by clause I need to use)
		$sortingRules = $this->getSortingRules($options['sortBy']);

		$queryOptions = [
			'stream' => true,
			'joins' => $sortingRules['joins'],
			'order_by' => $sortingRules['order_by'],
			'limit' => $limit,
			'table' => $this->options['table'],
		];

		// If a Element type is specified, I retrieve them through ORM module, otherwise I just execute a select query
		if($this->options['element']){
			$elements = $this->model->_ORM->all($this->options['element'], $where, $queryOptions);
		}else{
			$elements = $this->model->_Db->select_all($this->options['table'], $where, $queryOptions);
		}

		if($elements===false)
			$this->model->error('Error in retrieving elements list');

		// I run through the elements to get the data I need
		$arr_elements = [];
		foreach($elements as $el){
			if(is_array($el)){ // Retrieved through normal query, I convert into Element for consistency
				$el = new Element($el, [
					'table' => $this->options['table'],
					'pre_loaded' => true,
					'model' => $this->model,
				]);
			}

			$arr_el = [];
			foreach($this->options['columns'] as $k=>$cOpt){
				$cOpt['html'] = $options['html'];
				$c = $this->getElementColumn($el, $cOpt);
				$arr_el[$k] = $c;
			}

			$elId = $el[$this->options['primary']];
			$arr_elements[$elId] = [
				'element' => $el,
				'columns' => $arr_el,
			];
		}

		$totals = [];
		foreach($this->options['columns'] as $k=>$c){
			if($c['total'] and $c['field']){
				$totals[$k] = $this->model->_Db->select($this->options['table'], $where, [
					'sum'=>$c['field'],
				]);
			}
		}

		return [
			'tot' => $count,
			'pages' => $this->paginator->tot,
			'current-page' => $this->paginator->pag,
			'columns' => $this->getColumns(),
			'elements' => $arr_elements,
			'totals' => $totals,
			'sortedBy' => $options['sortBy'],
		];
	}

	/**
	 * Returns an array with the columns of the page
	 *
	 * @return array
	 */
	public function getColumns(){
		$columns = [];
		foreach($this->options['columns'] as $k=>$c){
			$sortingRules = $this->getSortingRulesFor($c, 'ASC', 0);

			unset($c['display']);
			unset($c['empty']);
			unset($c['total']);
			$c['sortable'] = $sortingRules ? true : false;

			$columns[$k] = $c;
		}

		return $columns;
	}

	/**
	 * Returns text and value to be shown in the table, for the given column of the given element
	 *
	 * @param Element $el
	 * @param array $cOpt
	 * @return array
	 */
	private function getElementColumn(Element $el, array $cOpt){
		$config = $this->retrieveConfig();

		$c = [
			'text' => '',
			'value' => null,
		];

		if(!is_string($cOpt['display'])){
			if(is_callable($cOpt['display'])){
				$c['text'] = call_user_func($cOpt['display'], $el);
			}else{
				$this->model->error('Unknown display format in a column - either string or callable is expected');
			}
		}else{
			$form = $el->getForm();

			if(isset($form[$cOpt['display']])){
				$d = $form[$cOpt['display']];
				$c['text'] = $d->getText($config);
			}else{
				$c['text'] = $el[$cOpt['display']];
			}

			if($this->options['columns-callback'] and is_callable($this->options['columns-callback']))
				$c['text'] = call_user_func($this->options['columns-callback'], $c['text']);

			if($cOpt['html'])
				$c['text'] = entities($c['text']);
		}

		if($cOpt['field']){
			$c['value'] = $el[$cOpt['field']];
		}

		return $c;
	}

	/**
	 * Given a input filter, returns a "where" array usable for Db module
	 *
	 * @param array $f
	 * @return bool|array
	 */
	private function getWhereFromFilter(array $f){
		if(!is_array($f) or count($f)<2 or count($f)>3)
			return false;

		$k = $f[0];

		if(isset($this->customFiltersCallbacks[$k])){
			if(count($f)!=2)
				return false;
			if(!is_callable($this->customFiltersCallbacks[$k]))
				$this->model->error('Wrong callback format for filter '.$k);

			return call_user_func($this->customFiltersCallbacks[$k], $f[1]);
		}else{
			if(count($f)!=3 and (count($f)!=4 or $f[1]!=='range'))
				return false;

			switch($f[1]){
				case '=':
				case '<':
				case '<=':
				case '>':
				case '>=':
					return [$f];
					break;
				case '<>':
				case '!=':
					return [
						[
							'sub'=>[
								[$k, '!=', $f[2]],
								[$k, '=', null],
							],
							'operator'=>'OR',
						],
					];
					break;
				case 'contains':
					return [
						[$k, 'LIKE', '%'.$f[2].'%'],
					];
					break;
				case 'starts':
					return [
						[$k, 'LIKE', $f[2].'%'],
					];
					break;
				case 'empty':
					switch($f[2]){
						case 0:
							return [
								[$k, '!=', null],
								[$k, '!=', ''],
							];
							break;
						case 1:
							return [
								[
									'sub'=>[
										[$k, ''],
										[$k, null],
									],
									'operator'=>'OR',
								],
							];
							break;
					}
					break;
				case 'range':
					return [
						[$k, 'BETWEEN', $f[2], $f[3]],
					];
					break;
			}
		}

		return [];
	}

	/**
	 * In order to properly do column sorting I need to return a correct "order by" clause, and eventually I need to join with other tables (to sort alphabetically by external fields, for example)
	 * In this method, I look through the provided fields and try to guess what joins I need to perform
	 *
	 * @param array $sortBy
	 * @return array
	 */
	private function getSortingRules(array $sortBy){
		$joins = array();

		if($sortBy){
			$order_by = array();

			foreach($sortBy as $idx=>$sort){
				if(!is_array($sort) or count($sort)!=2 or !in_array(strtolower($sort[1]), ['asc', 'desc']))
					$this->model->error('Wrong "sortBy" format!');
				if(!isset($this->options['columns'][$sort[0]]))
					$this->model->error('Column '.$sort[0].' in "sortBy" doesn\'t exist!');

				$rules = $this->getSortingRulesFor($this->options['columns'][$sort[0]], $sort[1], $idx);
				if(!$rules)
					$this->model->error('Column '.$sort[0].' is not sortable!');

				$order_by[] = $rules['order_by'];
				if($rules['joins']){
					foreach($rules['joins'] as $j)
						$joins[] = $j;
				}
			}
			$order_by = implode(',', $order_by);
		}else{
			$order_by = $this->options['order_by'];
		}

		return ['order_by'=>$order_by, 'joins'=>$joins];
	}

	/**
	 * Extension of getSortingRules method, here I look at the rules for the specific column
	 * Returns false if the column is not sortable
	 *
	 * @param array $column
	 * @param string $dir
	 * @param int $idx
	 * @return array|bool
	 */
	private function getSortingRulesFor(array $column, $dir, $idx){
		if(!is_string($column['display']) and is_callable($column['display'])){
			if($column['field'] and is_string($column['field'])){
				return ['order_by'=>$column['field'].' '.$dir, 'joins'=>[]];
			}
		}else{
			if(isset($this->form[$column['display']])){
				$d = $this->form[$column['display']];
				if(in_array($d->options['type'], array('select', 'radio', 'select-cascade'))){
					$tableModel = $this->model->_Db->getTable($this->options['table']);
					if($tableModel and isset($tableModel->columns[$d->options['field']]) and $tableModel->columns[$d->options['field']]['type']=='enum'){
						return ['order_by'=>$d->options['field'].' '.$dir, 'joins'=>[]];
					}

					if($d->options['table'] and $d->options['text-field']){
						if(is_array($d->options['text-field'])){
							$text_fields = $d->options['text-field'];
						}elseif(is_string($d->options['text-field'])){
							$text_fields = [$d->options['text-field']];
						}else{
							return false;
						}

						$order_by = []; $join_fields = [];
						foreach($text_fields as $cf=>$tf){
							$order_by[] = 'ord'.$idx.'_'.$cf.'_'.$tf.' '.$dir;
							$join_fields[$tf] = 'ord'.$idx.'_'.$cf.'_'.$tf;
						}
						return [
							'order_by'=>implode(',', $order_by),
							'joins'=>[
								['type'=>'LEFT', 'table'=>$d->options['table'], 'fields'=>$join_fields],
							],
						];
					}
				}elseif($d->options['type']=='instant-search'){
					$options = $d->options['ra_options'];
					if(isset($options['table'], $options['field'])){
						if(!is_array($options['field']))
							$options['field'] = [$options['field']];

						$order_by = $options['field'];
						foreach($order_by as &$f){
							$f = 'ord'.$idx.'_'.$f.' '.$dir;
						}
						unset($f);

						$join_fields = array();
						foreach($options['field'] as $f){
							$join_fields[$f] = 'ord'.$idx.'_'.$f;
						}

						return [
							'order_by'=>implode(',', $order_by),
							'joins'=>[
								['type'=>'LEFT', 'table'=>$options['table'], 'fields'=>$join_fields],
							],
						];
					}
				}else{
					return ['order_by'=>$d->options['field'].' '.$dir, 'joins'=>[]];
				}
				return false;
			}elseif(is_string($column['display'])){
				return ['order_by'=>$column['display'].' '.$dir, 'joins'=>[]];
			}
		}
		return false;
	}

	/**
	 * sId (for storing and retrieving list options, hence mantaining the page settings on refreshing) is either passed via input parameters or a new one is calculated
	 *
	 * @return int
	 */
	public function getSessionId(){
		$sId = $this->model->getInput('sId');
		if($sId===null){
			$sId = 0;
			while(isset($_SESSION[SESSION_ID]['admin-search-sessions'][$this->request[0]][$sId]))
				$sId++;
		}
		return $sId;
	}

	/**
	 * Returns options array for the list page, retrieving it from session if possible
	 *
	 * @param int $sId
	 * @return array
	 */
	public function getListOptions($sId = null){
		if($sId===null)
			$sId = $this->getSessionId();

		if(isset($_SESSION[SESSION_ID]['admin-search-sessions'][$this->request[0]][$sId])){
			$options = $_SESSION[SESSION_ID]['admin-search-sessions'][$this->request[0]][$sId];
		}else{
			$options = [
				'p' => 1,
				'filters' => [],
				'search-columns' => [],
				'sortBy' => [],
				'html' => !$this->model->isCLI(),
			];

			$defaultFilters = $this->customFiltersForm->getDataset();
			foreach($defaultFilters as $k => $d){
				$v = $d->getValue();
				if($v)
					$options['filters'][] = [$k, $v];
			}
		}

		return $options;
	}

	/**
	 * Stores in session the current list options array
	 *
	 * @param int|null $sId
	 * @param array $options
	 */
	public function setListOptions($sId, array $options){
		$_SESSION[SESSION_ID]['admin-search-sessions'][$this->request[0]][$sId] = $options;
	}

	/**
	 * Registers a custom filter
	 *
	 * @param string $name
	 * @param array $options
	 * @return MField|bool
	 */
	public function filter($name, array $options=[]){
		if(isset($this->customFiltersCallbacks[$name]))
			$this->model->error('Duplicate custom filter '.$name);

		$d = $this->customFiltersForm->add($name, $options);
		if(!$d)
			return false;

		if(isset($options['callback'])){
			$this->customFiltersCallbacks[$name] = $options['callback'];
		}elseif(!isset($options['admin-type'])){
			$this->customFiltersCallbacks[$name] = function($v) use($d){
				return [
					[$d->options['field'], '=', $v],
				];
			};
		}

		return $d;
	}

	/**
	 * Getter for the form of custom filters
	 *
	 * @return Form
	 */
	public function getCustomFiltersForm(){
		return $this->customFiltersForm;
	}

	/**
	 * Given a controller name, return the corresponding url.
	 *
	 * @param string|bool $controller
	 * @param int|bool $id
	 * @param array $tags
	 * @param array $opt
	 * @return bool|string
	 */
	public function getUrl($controller=false, $id=false, array $tags=[], array $opt=[]){
		switch($controller){
			case 'AdminLogin':
				return ($this->url ? $this->url.'/' : '').'login';
				break;
			default:
				return false;
				break;
		}
	}

	/**
	 * Retrieves the array of pages
	 *
	 * @return array
	 */
	public function getPages(){
		$config = $this->retrieveConfig();

		$pages = [];

		if(isset($config['url']) and is_array($config['url'])) {
			foreach ($config['url'] as $u) {
				if (is_array($u) and $u['path'] == $this->url) {
					$pages = $u['pages'];
					break;
				}
			}
		}

		if(isset(Globals::$data['adminAdditionalPages']))
			$pages = array_merge($pages, Globals::$data['adminAdditionalPages']);

		return $pages;
	}

	/**
	 * Returns an array with the possible actions that the user can take
	 *
	 * @param array $request
	 * @return array
	 */
	public function getActions(array $request = null){
		if($request===null)
			$request = $this->request;

		$actions = [];

		if($this->canUser('C')){
			$actions['new'] = [
				'text' => 'Nuovo',
				'action' => 'new',
			];
		}
		if($this->canUser('D') and isset($request[2])){
			$actions['delete'] = [
				'text' => 'Elimina',
				'action' => 'delete',
			];
		}

		if(!isset($request[1]))
			$request[1] = '';

		switch($request[1]){
			case 'edit':
				if($this->canUser('U')){
					$actions['save'] = [
						'text' => 'Salva',
						'action' => 'save',
					];
				}
				if(isset($request[2]) and $this->canUser('C')){
					$actions['duplicate'] = [
						'text' => 'Duplica',
						'action' => 'duplicate',
					];
				}
				break;
		}

		return $actions;
	}

	/**
	 * Can the current user do something? (Privilege check, basically)
	 *
	 * @param string $what
	 * @param string $page
	 * @param Element $el
	 * @return bool
	 */
	public function canUser($what, $page = null, Element $el = null){
		if($page===null)
			$page = $this->request[0];
		if($el===null)
			$el = $this->model->element;

		if($this->privilegesCache===false){
			$this->privilegesCache = $this->model->_Db->select_all('admin_privileges', [
				'or'=>[
					['user', $this->model->_User_Admin->logged()],
					['user', null],
				],
			], ['order_by'=>'id DESC']);
		}

		$currentGuess = [
			'row'=>false,
			'C'=>$this->options['privileges']['C'],
			'R'=>$this->options['privileges']['R'],
			'U'=>$this->options['privileges']['U'],
			'D'=>$this->options['privileges']['D'],
			'L'=>$this->options['privileges']['L'],
		];
		if(!array_key_exists($what, $currentGuess) or $what==='row')
			$this->model->error('Requested unknown privilege.');

		$groups = $this->findPageGroups($this->getPages(), $page);
		if($groups===false)
			return $currentGuess[$what];

		foreach($this->privilegesCache as $p){
			if(
				((in_array($p['group'], $groups) or $p['page']===$page or ($p['page']===null and $p['group']===null and $currentGuess['row']['page']===null)) and ($currentGuess['row']===false or ($currentGuess['row']['user']===null and $p['user']!==null)))
				or ($currentGuess['row']['group']===null and $currentGuess['row']['page']===null and in_array($p['group'], $groups))
				or ($currentGuess['row']['page']===null and $p['page']===$page)
			){
				$currentGuess['row'] = $p;

				foreach($currentGuess as $idx=>$priv){
					if($idx==='row')
						continue;
					if($p[$idx.'_special']){
						eval('$currentGuess[$idx] = function($el){ return '.$p[$idx.'_special'].'; }');
					}else{
						$currentGuess[$idx] = $p[$idx];
					}
				}
			}
		}

		if(!is_string($currentGuess[$what]) and is_callable($currentGuess[$what])){
			return (bool) call_user_func($currentGuess[$what], $el);
		}else{
			return (bool) $currentGuess[$what];
		}
	}

	/**
	 * @param array $pages
	 * @param string $page
	 * @return array|bool
	 */
	private function findPageGroups(array $pages, $page){
		foreach($pages as $p){
			if(isset($p['rule']) and $p['rule']==$page){
				return [
					$p['name'],
				];
			}elseif(isset($p['sub'])){
				$search = $this->findPageGroups($p['sub'], $page);
				if($search!==false){
					array_unshift($search, $p['name']);
					return $search;
				}
			}
		}
		return false;
	}

	/**
	 * Clears all the fields of the form (in order to add new custom fields)
	 *
	 * @return bool
	 */
	public function clearForm(){
		if($this->form)
			return $this->form->clear();
		else
			return false;
	}

	/**
	 * Adds or edits a field in the form
	 *
	 * @param string $name
	 * @param array $options
	 * @return MField|bool
	 */
	public function field($name, $options = []){
		if(!$this->form)
			return false;

		if(!is_array($options))
			$options = ['type'=>$options];

		if(isset($this->form[$name])){
			$datum = $this->form[$name];
			$datum->options = array_merge($datum->options, $options);
		}else{
			$datum = $this->form->add($name, $options);
		}

		return $datum;
	}

	/**
	 * Returns an array to use in the "edit" section
	 */
	public function getEditArray(){
		$element = $this->model->_ORM->element;

		if(!$element)
			$this->model->error('Element does not exist.');

		if(!$this->canUser('R', false, $element))
			$this->model->error('Can\'t read, permission denied.');

		$arr = [
			'data' => [],
			'children' => [],
		];

		$dataset = $this->form->getDataset();
		foreach($dataset as $k => $d){
			$arr['data'][$k] = $d->getValue(false);
		}

		foreach($this->sublists as $s){
			$arr['children'][$s['name'].'-'.$s['options']['cont']] = [];
			foreach($element->{$s['name']} as $chId => $ch){
				$chArr = [];
				$form = $ch->getForm();
				if(count($s['options']['fields'])>0){
					$newForm = clone $form;
					$newForm->clear();

					$keys = [];
					foreach($s['options']['fields'] as $f => $fOpt){
						if(is_numeric($f)){
							$f = $fOpt;
							$fOpt = [];
						}

						$keys[] = $f;
						$newForm->add($form[$f], ['attributes'=>$fOpt]);
					}

					$form = $newForm;
				}else{
					$keys = $ch->getDataKeys();
				}

				foreach($keys as $k){
					$chArr[$k] = $form[$k]->getValue();
				}

				$arr['children'][$s['name'].'-'.$s['options']['cont']][$chId] = $chArr;
			}
		}

		return $arr;
	}

	/**
	 * Saves data in the current element, via JS instant-save function
	 * Returns an array of elements that got changed in the process
	 *
	 * @param array $data
	 * @param array $instant
	 * @return array|bool
	 */
	public function saveElementViaInstant(array $data, array $instant = []){
		$this->model->on('Db_update', function($e) use($instant){
			$primary = $this->model->element->settings['primary'];
			if(isset($e['where'][$primary])){
				if(in_array($e['where'][$primary], $instant) and !in_array($e['where'][$primary], $this->instantSaveIds))
					$this->instantSaveIds[] = $e['where'][$primary];
			}
		});

		if(!$this->saveElement($data))
			return false;

		$changed = [];

		if($this->instantSaveIds){
			foreach($this->instantSaveIds as $id){
				$el = $this->model->_ORM->one($this->options['element'], $id);
				$arr_el = [];

				foreach($this->options['columns'] as $k=>$cOpt) {
					$cOpt['html'] = true;
					$c = $this->getElementColumn($el, $cOpt);
					$arr_el[$k] = $c;
				}

				$changed[$id] = [
					'element' => $el,
					'columns' => $arr_el,
				];
			}
		}

		return $changed;
	}

	/**
	 * Saves the data in the current element
	 *
	 * @param array $data
	 * @return bool|int
	 */
	public function saveElement(array $data){
		return $this->model->element->save($data, [
			'children' => true,
		]);
	}

	/**
	 * Adds a sublist in the array, so that it will be rendered
	 *
	 * @param string $name
	 * @param array $options
	 */
	public function sublist($name, array $options = []){
		$options = array_merge([
			'type' => 'row',
			'fields' => [],
			'cont' => $name,
		], $options);

		$this->sublists[] = [
			'name'=>$name,
			'options'=>$options,
		];
	}

	/**
	 * Renders a sublist (via the loaded template module)
	 * $name has to be a declared children-set of the current element
	 *
	 * @param string $name
	 * @param array $options
	 */
	public function renderSublist($name, array $options = []){
		if(!$this->template)
			return;
		$this->template->renderSublist($name, $options);
	}

	/**
	 * @return string
	 */
	public function getUrlPrefix(){
		return $this->model->prefix().($this->url ? $this->url.'/' : '');
	}
}
