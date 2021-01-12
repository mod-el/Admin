<?php namespace Model\Admin;

use Model\Core\Autoloader;
use Model\Core\Module;
use Model\Form\Form;
use Model\Form\Field;
use Model\ORM\Element;
use Model\Paginator\Paginator;
use Model\User\User;
use Model\Core\Globals;

class Admin extends Module
{
	/** @var AdminPage */
	public $page = null;
	/** @var array */
	public $pageRule = null;
	/** @var string */
	private $path = null;
	/** @var array */
	private $pageOptions = null;
	/** @var Form */
	public $customFiltersForm;
	/** @var array */
	private $customFiltersCallbacks = [];
	/** @var array|bool */
	protected $privilegesCache = false;
	/** @var array */
	public $sublists = [];
	/** @var array */
	public $fieldsCustomizations = [];

	/**
	 * @param string $path
	 */
	public function setPath(string $path)
	{
		$this->path = $path;
	}

	/**
	 */
	public function getPath(): string
	{
		return $this->path;
	}

	/**
	 * @param string $rule
	 */
	public function setPage(string $rule)
	{
		if ($this->page)
			return;

		$pages = $this->getPages($this->path);
		$rule = $this->seekForRule($pages, $rule);
		if (!$rule or !$rule['page'])
			return;

		$className = Autoloader::searchFile('AdminPage', $rule['page']);
		if (!$className)
			$this->model->error('Admin Page class not found');

		$this->page = new $className($this->model);
		$this->page->customize();
		$this->pageRule = $rule;
	}

	/**
	 * @param string|null $page
	 * @return array
	 */
	private function getPageOptions(?string $page = null): array
	{
		if ($page !== null) {
			$className = Autoloader::searchFile('AdminPage', $page);
			if (!$className)
				$this->model->error('Admin Page class not found');

			$referencePage = new $className($this->model);
		} else {
			if ($this->pageOptions !== null)
				return $this->pageOptions;

			$referencePage = $this->page;
		}
		if (!$referencePage)
			throw new \Exception('No page to gather options from');

		$basicPageOptions = $referencePage->options();

		$options = array_merge_recursive_distinct([
			'element' => null,
			'table' => null,
			'where' => [],
			'order_by' => false,
			'group_by' => false,
			'having' => [],
			'min' => [],
			'max' => [],
			'sum' => [],
			'avg' => [],
			'count' => [],
			'perPage' => 20,
			'privileges' => [
				'C' => true,
				'R' => true,
				'U' => true,
				'D' => true,
				'L' => true,
			],
			'joins' => [],
			'required' => [],
			'fields' => [],
			'onclick' => null,
			'actions' => [],
			'export' => false,
			'print' => false,
			'items-navigation' => false,
			'visualizer' => 'Table',
		], $basicPageOptions);

		if ($options['element'] and !$options['table'])
			$options['table'] = $this->model->_ORM->getTableFor($options['element']);

		if ($options['table']) {
			if ($options['order_by'] === false) {
				$tableModel = $this->model->_Db->getTable($options['table']);
				$options['order_by'] = $tableModel->primary . ' DESC';

				if ($options['element']) {
					$elementData = $this->model->_ORM->getElementData($options['element']);
					if ($elementData and $elementData['order_by']) {
						$options['order_by'] = [];
						foreach ($elementData['order_by']['depending_on'] as $field)
							$options['order_by'][] = $field . ' ASC';
						$options['order_by'][] = $elementData['order_by']['field'] . ' ASC';

						$options['order_by'] = implode(',', $options['order_by']);
					}
				}
			}
		}

		if ($this->pageRule['visualizer'] ?? null) // Backward compatibility
			$options['visualizer'] = $this->pageRule['visualizer'];

		if (!$options['table'])
			$options['visualizer'] = 'Custom';

		// Backward compatibility
		$visualizerOptions = $referencePage->visualizerOptions();

		switch ($options['visualizer']) {
			case 'Table':
				if (isset($visualizerOptions['columns']))
					$options['fields'] = array_merge_recursive_distinct($options['fields'] ?? [], $visualizerOptions['columns']);
				break;
			case 'FormList':
				if (isset($visualizerOptions['fields']))
					$options['fields'] = array_merge_recursive_distinct($options['fields'] ?? [], $visualizerOptions['fields']);
				break;
		}

		if ($this->pageOptions === null and $page === null)
			$this->pageOptions = $options;

		return $options;
	}

	/**
	 * Returns current page details for the APIs
	 *
	 * @return array
	 */
	public function getPageDetails(): array
	{
		if (!$this->canUser('L'))
			throw new \Exception('Unauthorized', 401);

		$options = $this->getPageOptions();
		$visualizerOptions = $this->page->visualizerOptions();

		// Backward compatibility
		switch ($options['visualizer']) {
			case 'Table':
				if (isset($visualizerOptions['columns']))
					unset($visualizerOptions['columns']);
				break;
			case 'FormList':
				if (isset($visualizerOptions['fields']))
					unset($visualizerOptions['fields']);
				break;
		}

		$pageDetails = [
			'type' => $options['visualizer'],
			'visualizer-options' => $visualizerOptions,
			'privileges' => [
				'C' => $this->canUser('C'),
				'D' => $this->canUser('D'),
			],
			'actions' => array_filter($options['actions'], function ($action) {
				return (!isset($action['specific']) or $action['specific'] === 'list' or $action['specific'] === 'table'); // Retrocompatibilità per "table"
			}),
			'export' => $options['export'],
		];

		if ($options['csv'] ?? false) // Backward compatibility
			$pageDetails['export'] = true;

		if ($pageDetails['export'] and !$this->model->moduleExists('Csv'))
			$pageDetails['export'] = false;

		if ($pageDetails['type'] === 'FormList' and !isset($pageDetails['visualizer-options']['type']))
			$pageDetails['visualizer-options']['type'] = 'inner-template';

		if ($pageDetails['type'] !== 'Custom') {
			$fields = $this->getColumnsList();

			$columns = [];
			foreach ($fields['fields'] as $idx => $column) {
				$columns[$idx] = [
					'label' => $column['label'],
					'editable' => $column['editable'],
					'sortable' => $column['sortable'],
					'print' => $column['print'],
					'price' => $column['price'],
					'raw' => $column['raw'],
				];
			}

			$pageDetails['fields'] = $columns;
			$pageDetails['default-fields'] = $fields['default'];

			/* FILTERS */

			$dummy = $this->getElement();
			$filtersForm = clone $dummy->getForm();
			$defaultFilters = $options['filters'] ?? [];

			foreach ($defaultFilters as $filter) {
				if (!isset($filter['field']))
					continue;

				$field = $filter['field'];
				unset($filter['field']);

				$filtersForm->add($field, $filter);
			}

			$pageDetails['filters'] = [
				'zk-all' => [
					'type' => 'text',
					'label' => 'Ricerca generale',
				],
			];
			foreach ($filtersForm->getDataset() as $filter) {
				$filter = $this->convertFieldToFilter($filter);
				$pageDetails['filters'][$filter->options['name']] = $filter->getJavascriptDescription();
			}

			$pageDetails['default-filters'] = [
				'primary' => [
					[
						'filter' => 'zk-all',
						'type' => '=',
					],
				],
				'secondary' => [],
			];

			if ($options['wipe-filters'] ?? false)
				$pageDetails['default-filters']['primary'] = [];

			if ($defaultFilters) {
				foreach ($defaultFilters as $defaultFilterOptions) {
					$position = $defaultFilterOptions['position'] ?? 'secondary';
					$filterType = $defaultFilterOptions['filter-type'] ?? '=';

					$pageDetails['default-filters'][$position][] = [
						'filter' => $defaultFilterOptions['field'],
						'type' => $filterType,
					];
				}
			}

			$pageDetails['custom-order'] = false;
			if ($options['element']) {
				$elementData = $this->model->_ORM->getElementData($options['element']);
				if ($elementData and $elementData['order_by'] and $elementData['order_by']['custom']) {
					$pageDetails['custom-order'] = [
						'field' => $elementData['order_by']['field'],
						'depending_on' => $elementData['order_by']['depending_on'],
					];
				}
			}
		}

		$pageDetails['js'] = array_map(function ($path) {
			if (strtolower(substr($path, 0, 4)) === 'http')
				return $path;
			else
				return PATH . $path;
		}, $options['js'] ?? []);
		$pageDetails['css'] = array_map(function ($path) {
			if (strtolower(substr($path, 0, 4)) === 'http')
				return $path;
			else
				return PATH . $path;
		}, $options['css'] ?? []);
		$pageDetails['cache'] = $options['cache'] ?? true;

		return $pageDetails;
	}

	/**
	 * @return array
	 */
	public function getColumnsList(): array
	{
		$options = $this->getPageOptions();
		$fields = $this->getAllFieldsList();

		$defaultColumns = $fields;
		if (count($options['fields'] ?? []) > 0) {
			$allColumns = $options['fields'];

			foreach ($fields as $field) {
				if (!isset($allColumns[$field]) and !in_array($field, $allColumns))
					$allColumns[] = $field;
			}

			if ($options['wipe-fields'] ?? true)
				$defaultColumns = $options['fields'];
		} else {
			$allColumns = $fields;
		}

		$columns = $this->elaborateColumns($allColumns, $options['table']);
		$defaultColumns = array_keys($this->elaborateColumns($defaultColumns, $options['table']));

		return [
			'fields' => $columns,
			'default' => $defaultColumns,
		];
	}

	/**
	 * Automatic field  extraction
	 *
	 * @param array $options
	 * @return array
	 */
	public function getAllFieldsList(array $options = null): array
	{
		if ($options === null)
			$options = $this->getPageOptions();

		$fields = [];

		$tableModel = $this->model->_Db->getTable($options['table']);
		$excludeColumns = array_merge([
			'zk_deleted',
		], ($options['exclude'] ?? []));

		if ($options['element']) {
			$elementData = $this->model->_ORM->getElementData($options['element']);
			if ($elementData and $elementData['order_by'])
				$excludeColumns[] = $elementData['order_by']['field'];
		}

		foreach ($tableModel->columns as $k => $col) {
			if (in_array($k, $excludeColumns))
				continue;

			$fields[] = $k;
		}

		if ($this->model->isLoaded('Multilang') and array_key_exists($options['table'], $this->model->_Multilang->tables)) {
			$mlTableOptions = $this->model->_Multilang->tables[$options['table']];
			$mlTable = $options['table'] . $mlTableOptions['suffix'];
			$mlTableModel = $this->model->_Db->getTable($mlTable);
			foreach ($mlTableModel->columns as $k => $col) {
				if ($k === $mlTableModel->primary or in_array($k, $fields) or $k === $mlTableOptions['keyfield'] or $k === $mlTableOptions['lang'] or in_array($k, $excludeColumns))
					continue;

				$fields[] = $k;
			}
		}

		$fields = array_unique(array_merge($fields, array_keys($this->fieldsCustomizations)));

		return $fields;
	}

	/**
	 * @param array $columns
	 * @param string|null $table
	 * @return array
	 */
	private function elaborateColumns(array $columns, ?string $table): array
	{
		$tableModel = $table ? $this->model->_Db->getTable($table) : false;

		$adminForm = null;

		$new_columns = []; // I loop through the columns to standardize the format
		foreach ($columns as $k => $column) {
			/*
			 * ACCEPTED FORMATS: *
			 * 'field'
			 * * A single string, will be used as column id, label and as field name
			 * 'label'=>function(){}
			 * * The key is both column id and label, the callback will be used as "display" value
			 * 'label'=>'campo'
			 * * The key is both column id and label, the value is the db field to use
			 * 'label'=>array()
			 * * The key is the column id, in the array there will be the remaining options (if a label is not provided, the column is will be used)
			*/
			if (is_numeric($k)) {
				if (is_array($column)) {
					if (isset($column['display']) and (is_string($column['display']) or is_numeric($column['display'])))
						$k = $column['display'];
					elseif (isset($k['field']) and (is_string($column['field']) or is_numeric($column['field'])))
						$k = $column['field'];
				} else {
					if (is_string($column) or is_numeric($column))
						$k = $column;
				}
			}

			if (!is_array($column)) {
				if (is_string($column) or is_numeric($column)) {
					$column = [
						'field' => $column,
						'display' => $column,
					];
				} elseif (is_callable($column)) {
					$column = [
						'field' => false,
						'display' => $column,
					];
				} else {
					$this->model->error('Unknown column format "' . entities($k) . '"');
				}
			}

			if (!isset($column['field']) and !isset($column['display']))
				$column['field'] = $k;

			$column = array_merge([
				'label' => str_replace('"', '', $this->makeLabel($k)),
				'field' => false,
				'display' => false,
				'empty' => '',
				'editable' => false,
				'clickable' => true,
				'print' => true,
				'total' => false,
				'price' => false,
				'raw' => false,
			], $column);

			if (!is_string($column['display']) and is_callable($column['display']))
				$column['raw'] = true;
			if (is_string($column['display']) and !$column['field'] and $column['display'])
				$column['field'] = $column['display'];
			if ($column['field'] === false and $tableModel and array_key_exists($k, $tableModel->columns))
				$column['field'] = $k;
			if (is_string($column['field']) and $column['field'] and !$column['display'])
				$column['display'] = $column['field'];

			if ($column['editable']) {
				if ($adminForm === null)
					$adminForm = $this->getForm();

				if ($adminForm[$column['field']])
					$column['editable'] = $adminForm[$column['field']]->getJavascriptDescription();
				else
					$column['editable'] = false;
			}

			if ($k and $k !== $column['field'])
				$k = $this->fromLabelToColumnId($k);
			if (!$k) {
				if ($column['field'])
					$k = $column['field'];

				if (!$k)
					$this->model->error('Can\'t assign id to column with label "' . entities($column['label']) . '"');
			}

			$column['sortable'] = $this->getSortingRulesFor($column, 'ASC', 0) ? true : false;
			$new_columns[$k] = $column;
		}

		return $new_columns;
	}

	/**
	 * Returns text and value to be shown by the visualizer, for the given column of the given element
	 *
	 * @param Element $el
	 * @param array $column
	 * @return array
	 */
	public function getElementColumn(Element $el, array $column): array
	{
		$c = [
			'value' => null,
			'text' => '',
		];

		$form = $this->getForm($el);

		if (!is_string($column['display'])) {
			if (is_callable($column['display'])) {
				$c['text'] = call_user_func($column['display'], $el);
			} else {
				$this->model->error('Unknown display format in a column - either string or callable is expected');
			}
		} else {
			if (isset($form[$column['display']])) {
				$d = $form[$column['display']];
				$c['text'] = $d->getText(['preview' => true]);
			} else {
				$c['text'] = $el[$column['display']];
			}

			if (strlen($c['text']) > 150)
				$c['text'] = textCutOff($c['text'], 150);
		}

		$c['text'] = (string)$c['text'];
		if ($column['field']) {
			if (isset($form[$column['field']])) {
				$c['value'] = $form[$column['field']]->getJsValue(false);
			} else {
				$c['value'] = $el[$column['field']];
			}
		}

		$background = $column['background'] ?? null;
		if ($background and !is_string($background) and is_callable($background))
			$background = $background($el);
		if ($background)
			$c['background'] = $background;

		$color = $column['color'] ?? null;
		if ($color and !is_string($color) and is_callable($color))
			$color = $color($el);
		if ($color)
			$c['color'] = $color;

		$clickable = $column['clickable'];
		if ($clickable and !is_string($clickable) and is_callable($clickable))
			$clickable = (bool)$clickable($el);
		if (!$clickable)
			$c['clickable'] = false;

		return $c;
	}

	/**
	 * Removes all unnecessary characters of a label to generate a column id
	 *
	 * @param string $k
	 * @return string
	 */
	private function fromLabelToColumnId(string $k): string
	{
		return preg_replace('/[^a-z0-9]/i', '', entities(strtolower($k)));
	}

	/**
	 * Converts a field name in a human-readable label
	 *
	 * @param string $k
	 * @return string
	 */
	private function makeLabel(string $k): string
	{
		return ucwords(str_replace(array('-', '_'), ' ', $k));
	}

	/**
	 * @param array $column
	 * @return string|null
	 */
	private function getFieldNameFromColumn(array $column)
	{
		if ($column['display'] and is_string($column['display'])) {
			return $column['display'];
		} elseif ($column['field'] and is_string($column['field'])) {
			return $column['field'];
		} else {
			return null;
		}
	}

	/**
	 * @param Field $field
	 * @return Field
	 */
	private function convertFieldToFilter(Field $field): Field
	{
		switch ($field->options['type']) {
			case 'textarea':
			case 'ckeditor':
				$field->options['type'] = 'text';
				break;
			case 'checkbox':
				$field->options['type'] = 'select';
				$field->options['options'] = [
					'' => '',
					0 => 'No',
					1 => 'Sì',
				];
				break;
		}

		return $field;
	}

	/**
	 * Builds the query from the provided search string and filters
	 *
	 * @param string $search
	 * @param array $filters
	 * @param array|null $searchFields
	 * @return array|null
	 */
	public function makeSearchQuery(string $search = '', array $filters = [], array $searchFields = []): ?array
	{
		$options = $this->getPageOptions();

		$where = $options['where'];

		$search = trim($search);
		if ($search) {
			$tableModel = $this->model->_Db->getTable($options['table']);
			$columns = $tableModel->columns;

			if ($this->model->isLoaded('Multilang') and array_key_exists($options['table'], $this->model->_Multilang->tables)) {
				$mlTableOptions = $this->model->_Multilang->tables[$options['table']];
				$mlTable = $options['table'] . $mlTableOptions['suffix'];
				$mlTableModel = $this->model->_Db->getTable($mlTable);
				foreach ($mlTableModel->columns as $k => $col) {
					if (isset($columns[$k]) or $k == $mlTableOptions['keyfield'] or $k == $mlTableOptions['lang'])
						continue;
					$columns[$k] = $col;
				}
			}

			$arr = [];
			foreach ($columns as $k => $col) {
				if ($tableModel->primary === $k or $col['foreign_key'] or ($searchFields and !in_array($k, $searchFields)))
					continue;

				switch ($col['type']) {
					case 'tinyint':
					case 'smallint':
					case 'int':
					case 'mediumint':
					case 'bigint':
						if (is_numeric($search))
							$arr[] = [$k, '=', $search];
						break;
					case 'decimal':
						if (is_numeric($search))
							$arr[] = [$k, 'LIKE', $search . '.%'];
						break;
					case 'varchar':
					case 'char':
					case 'longtext':
					case 'mediumtext':
					case 'smalltext':
					case 'text':
					case 'tinytext':
					case 'enum':
						$arr[] = [$k, 'REGEXP', '(^|[^a-z0-9])' . preg_quote($search)];
						break;
				}
			}

			if (count($searchFields) > 0 and count($arr) === 0) { // If specific columns are provided and no criteria matched, then it's impossible
				return null;
			} else {
				$where = array_merge($where, [
					['sub' => $arr, 'operator' => 'OR'],
				]);
			}
		}

		foreach ($filters as $filter) {
			$f_where = $this->getWhereFromFilter($filter);
			if ($f_where !== null)
				$where = array_merge($where, $f_where);
		}

		return $where;
	}

	/**
	 * Returns the list of elements, filtered by specified options
	 *
	 * @param array $options
	 * @return array
	 */
	public function getList(array $options = []): array
	{
		$options = array_merge([
			'where' => [],
			'p' => 1,
			'goTo' => null,
			'perPage' => $this->getPageOptions()['perPage'],
			'sortBy' => [],
		], $options);

		$pageOptions = $this->getPageOptions();

		$where = $options['where'];

		// Count how many total elements there are
		$count = $this->model->_Db->count($pageOptions['table'], $where, [
			'joins' => $pageOptions['joins'],
		]);

		// Get the rules to apply to the query, in order to sort as requested (what joins do I need to make and what order by clause I need to use)
		$sortingRules = $this->getSortingRules($options['sortBy'], $pageOptions['joins']);

		// If I am asked to go to a specific element, I calculate its position in the list to pick the right page
		if ($options['goTo'] and $options['perPage'] and $count > 0) {
			$customList = $this->model->_Db->select_all($pageOptions['table'], $where, [
				'joins' => $sortingRules['joins'],
				'order_by' => $sortingRules['order_by'],
			]);
			$c_element = 0;
			$element_found = false;
			$tableModel = $this->model->_Db->getTable($pageOptions['table']);
			foreach ($customList as $row) {
				if ($row[$tableModel->primary] == $options['goTo']) {
					$element_found = $c_element;
					break;
				}
				$c_element++;
			}
			if ($element_found !== false)
				$options['p'] = ceil($element_found / $options['perPage']);
		}

		// I pass the parameters to the paginator, so that it will calculate the total number of pages and the start limit
		$paginator = new Paginator();
		$paginator->setOptions([
			'tot' => $count,
			'perPage' => $options['perPage'] ?: false,
			'pag' => $options['p'],
		]);
		$limit = $options['perPage'] ? $paginator->getLimit() : false;

		$queryOptions = [
			'stream' => true,
			'joins' => $sortingRules['joins'],
			'order_by' => $sortingRules['order_by'],
			'limit' => $limit,
			'table' => $pageOptions['table'],
			'group_by' => $pageOptions['group_by'],
			'having' => $pageOptions['having'],
			'min' => $pageOptions['min'],
			'max' => $pageOptions['max'],
			'sum' => $pageOptions['sum'],
			'avg' => $pageOptions['avg'],
			'count' => $pageOptions['count'],
		];

		$customOrder = null;
		if ($pageOptions['element']) {
			$elementData = $this->model->_ORM->getElementData($pageOptions['element']);
			if ($elementData and $elementData['order_by'] and $elementData['order_by']['custom'])
				$customOrder = $elementData['order_by']['field'];
		}

		$elementName = $pageOptions['element'] ?: 'Element';
		return [
			'tot' => $count,
			'pages' => $paginator->tot,
			'page' => $paginator->pag,
			'custom-order' => $customOrder,
			'list' => $this->adminListGenerator($elementName, $where, $queryOptions),
		];
	}

	/**
	 * Iteratively returns a list of form-customized elements
	 *
	 * @param string $elementName
	 * @param array $where
	 * @param array $queryOptions
	 * @return \Generator
	 */
	protected function adminListGenerator(string $elementName, array $where, array $queryOptions): \Generator
	{
		$pageOptions = $this->getPageOptions();

		foreach ($this->model->_ORM->all($elementName, $where, $queryOptions) as $el) {
			$background = $pageOptions['background'] ?? null;
			if ($background and !is_string($background) and is_callable($background))
				$background = $background($el);

			$color = $pageOptions['color'] ?? null;
			if ($color and !is_string($color) and is_callable($color))
				$color = $color($el);

			$onclick = $pageOptions['onclick'] ?? null;
			if ($onclick and !is_string($onclick) and is_callable($onclick))
				$onclick = $onclick($el);

			yield [
				'element' => $el,
				'background' => $background,
				'onclick' => $onclick,
				'color' => $color,
			];
		}
	}

	/**
	 * Given a column and a where array, returns the total sum for that column
	 *
	 * @param array $column
	 * @param array $where
	 * @return float
	 */
	public function getColumnTotal(array $column, array $where): float
	{
		$pageOptions = $this->getPageOptions();

		return $this->model->_Db->select($pageOptions['table'], $where, [
			'joins' => $pageOptions['joins'],
			'sum' => $column['field'],
		]);
	}

	/**
	 * Can the current user do something? (Privilege check, basically)
	 *
	 * @param string $what
	 * @param string $page
	 * @param Element $el
	 * @param string $subpage
	 * @return bool
	 */
	public function canUser(string $what, string $page = null, Element $el = null, string $subpage = null): bool
	{
		if ($page === null)
			$page = $this->pageRule['page'];
		if ($el === null)
			$el = $this->model->element;

		$pageOptions = $this->getPageOptions($page);

		if ($this->privilegesCache === false) {
			$this->privilegesCache = $this->model->_Db->select_all('admin_privileges', [
				'or' => [
//					['profile', TODO],
					['user', $this->model->_User_Admin->logged()],
					'and' => [
						['profile', null],
						['user', null],
					],
				],
			], [
				'order_by' => 'id DESC',
				'stream' => false,
			]);
		}

		$currentGuess = [
			'score' => 0,
			'C' => $pageOptions['privileges']['C'] ?? true,
			'R' => $pageOptions['privileges']['R'] ?? true,
			'U' => $pageOptions['privileges']['U'] ?? true,
			'D' => $pageOptions['privileges']['D'] ?? true,
			'L' => $pageOptions['privileges']['L'] ?? true,
		];
		if (!array_key_exists($what, $currentGuess) or $what === 'score')
			$this->model->error('Requested unknown privilege.');

		foreach ($this->privilegesCache as $p) {
			$score = 1;

			if ($p['user'])
				$score += 2;
			elseif ($p['profile'])
				$score += 1;

			if ($p['page']) {
				if ($p['page'] !== $page)
					continue;
				$score++;

				if ($p['subpage']) {
					if ($p['subpage'] !== $subpage)
						continue;
					$score++;
				}
			}

			if ($score > $currentGuess['score']) {
				$currentGuess['score'] = $score;

				foreach ($currentGuess as $idx => $priv) {
					if ($idx === 'score')
						continue;
					if ($currentGuess[$idx] !== true)
						continue;

					if ($p[$idx . '_special']) {
						eval('$currentGuess[$idx] = function($el){ return ' . $p[$idx . '_special'] . '; }');
					} else {
						$currentGuess[$idx] = $p[$idx];
					}
				}
			}
		}

		if (!is_string($currentGuess[$what]) and is_callable($currentGuess[$what])) {
			return ($el and $el->exists()) ? (bool)call_user_func($currentGuess[$what], $el) : true;
		} else {
			return (bool)$currentGuess[$what];
		}
	}

	/**
	 * Given a input filter, returns a "where" array usable for Db module
	 *
	 * @param array $filter
	 * @return array|null
	 */
	private function getWhereFromFilter(array $filter): ?array
	{
		if (!is_array($filter) or count($filter) !== 3 or !isset($filter['filter'], $filter['type'], $filter['value']))
			return null;

		$k = $filter['filter'];

		switch ($filter['type']) {
			case '=':
			case '<':
			case '<=':
			case '>':
			case '>=':
				return [
					[
						$k,
						$filter['type'],
						$filter['value'],
					],
				];
				break;
			case '<>':
			case '!=':
				return [
					[
						'sub' => [
							[$k, '!=', $filter['value']],
							[$k, '=', null],
						],
						'operator' => 'OR',
					],
				];
				break;
			case 'contains':
				return [
					[$k, 'LIKE', '%' . $filter['value'] . '%'],
				];
				break;
			case 'begins':
				return [
					[$k, 'LIKE', $filter['value'] . '%'],
				];
				break;
			case 'empty':
				switch ($filter['value']) {
					case 0:
						return [
							[$k, '!=', null],
							[$k, '!=', ''],
						];
						break;
					case 1:
						return [
							[
								'sub' => [
									[$k, '=', ''],
									[$k, '=', null],
								],
								'operator' => 'OR',
							],
						];
						break;
				}
				break;
			default:
				$this->model->error('Unrecognized filter type');
				break;
		}

		return [];
	}

	/**
	 * In order to properly do column sorting I need to return a correct "order by" clause, and eventually I need to join with other tables (to sort alphabetically by external fields, for example)
	 * In this method, I look through the provided fields and try to guess what joins I need to perform
	 *
	 * @param array $sortBy
	 * @param array $joins
	 * @return array
	 */
	private function getSortingRules(array $sortBy, array $joins): array
	{
		if (count($sortBy) > 0) {
			$order_by = [];

			$columns = $this->getColumnsList();

			foreach ($sortBy as $idx => $sort) {
				if (!is_array($sort) or !isset($sort['field'], $columns['fields'][$sort['field']]) or !in_array(strtolower($sort['dir']), ['asc', 'desc']))
					$this->model->error('Wrong "sortBy" format!');

				$rules = $this->getSortingRulesFor($columns['fields'][$sort['field']], $sort['dir'], $idx);
				if (!$rules)
					$this->model->error('Column ' . $sort['field'] . ' is not sortable!');

				$order_by[] = $rules['order_by'];
				if ($rules['joins']) {
					foreach ($rules['joins'] as $j)
						$joins[] = $j;
				}
			}
			$order_by = implode(',', $order_by);
		} else {
			$order_by = $this->getPageOptions()['order_by'];
		}

		return [
			'order_by' => $order_by,
			'joins' => $joins,
		];
	}

	/**
	 * Extension of getSortingRules method, here I look at the rules for the specific column
	 * Returns false if the column is not sortable
	 *
	 * @param array $column
	 * @param string $dir
	 * @param int $idx
	 * @return array|null
	 */
	public function getSortingRulesFor(array $column, string $dir, int $idx): ?array
	{
		$field = $this->getFieldNameFromColumn($column);
		if (!$field)
			return null;

		$form = $this->getForm();
		if (isset($form[$field])) {
			$d = $form[$field];
			if (in_array($d->options['type'], ['select', 'radio', 'select-cascade'])) {
				$tableModel = $this->model->_Db->getTable($this->getPageOptions()['table']);
				if ($tableModel and isset($tableModel->columns[$d->options['field']]) and $tableModel->columns[$d->options['field']]['type'] == 'enum') {
					return [
						'order_by' => $d->options['field'] . ' ' . $dir,
						'joins' => [],
					];
				}

				if ($d->options['table'] and $d->options['text-field']) {
					if (is_array($d->options['text-field'])) {
						$text_fields = $d->options['text-field'];
					} elseif (is_string($d->options['text-field'])) {
						$text_fields = [$d->options['text-field']];
					} else {
						return null;
					}

					$order_by = [];
					$join_fields = [];
					foreach ($text_fields as $cf => $tf) {
						$order_by[] = 'ord' . $idx . '_' . $cf . '_' . $tf . ' ' . $dir;
						$join_fields[$tf] = 'ord' . $idx . '_' . $cf . '_' . $tf;
					}
					return [
						'order_by' => implode(',', $order_by),
						'joins' => [
							[
								'type' => 'LEFT',
								'table' => $d->options['table'],
								'fields' => $join_fields,
							],
						],
					];
				}

				return [
					'order_by' => $d->options['field'] . ' ' . $dir,
					'joins' => [],
				];
			} elseif ($d->options['type'] === 'instant-search') {
				if (isset($d->options['table'], $d->options['text-field'])) {
					if (!is_array($d->options['text-field']))
						$d->options['text-field'] = [$d->options['text-field']];

					$order_by = $d->options['text-field'];
					foreach ($order_by as &$f) {
						$f = 'ord' . $idx . '_' . $f . ' ' . $dir;
					}
					unset($f);

					$join_fields = array();
					foreach ($d->options['text-field'] as $f) {
						$join_fields[$f] = 'ord' . $idx . '_' . $f;
					}

					return [
						'order_by' => implode(',', $order_by),
						'joins' => [
							[
								'type' => 'LEFT',
								'table' => $d->options['table'],
								'fields' => $join_fields,
								'on' => $d->options['field'],
							],
						],
					];
				}
			} else {
				return [
					'order_by' => $d->options['field'] . ' ' . $dir,
					'joins' => [],
				];
			}

			return null;
		} else {
			return [
				'order_by' => $field . ' ' . $dir,
				'joins' => [],
			];
		}
	}

	/**
	 * Returns an array to use in the "edit" section
	 * @param int $id
	 * @return array
	 */
	public function getElementData(int $id): array
	{
		$element = $this->getElement($id);
		if (!$element)
			$this->model->error('Element does not exist.');

		if (!$this->canUser('R', null, $element))
			$this->model->error('Can\'t read, permission denied.');

		$pageOptions = $this->getPageOptions();

		$arr = [
			'version' => $this->model->_Db->getVersionLock($element->getTable(), $element[$element->settings['primary']]),
			'fields' => [],
			'data' => [],
			'sublists' => [],
			'actions' => array_filter($pageOptions['actions'], function ($action) use ($id) {
				if (!isset($action['specific']))
					return true;
				if ($action['specific'] === 'element')
					return true;
				if ($id === 0 and $action['specific'] === 'element-new')
					return true;
				if ($id > 0 and $action['specific'] === 'element-edit')
					return true;
				return false;
			}),
			'privileges' => [
				'U' => $this->canUser('U', null, $element),
				'D' => $this->canUser('D', null, $element),
			],
			'warnings' => $this->page->warnings($element),
		];

		if ($id > 0) {
			$url = $element->getUrl();
			if ($url) {
				$arr['actions'][] = [
					'id' => 'public-url',
					'text' => 'URL Pubblico',
					'fa-icon' => 'fas fa-external-link-alt',
					'url' => $url,
				];
			}
		}

		$form = $this->getForm();
		$dataset = $form->getDataset();
		foreach ($dataset as $k => $d) {
			$arr['fields'][$k] = $d->getJavascriptDescription();
			$arr['data'][$k] = $d->getJsValue(false);
		}

		foreach ($this->sublists as $sublistName => $sublist) {
			$options = $element->getChildrenOptions($sublist['relationship']);
			if (!$options or $options['type'] !== 'multiple')
				$this->model->error($sublist['relationship'] . ' is not a valid relationship of the element!');

			$sublistArr = [
				'name' => $sublistName,
				'visualizer' => $sublist['visualizer'],
				'fields' => [],
				'list' => [],
				'privileges' => [
					'C' => true,
					'R' => true,
					'U' => true,
					'D' => true,
				],
			];

			if ($sublist['privileges'])
				$sublistArr['privileges'] = array_merge($sublistArr['privileges'], $sublist['privileges']);

			$dummy = $element->create($sublist['relationship']);
			$dummyForm = $dummy->getForm();
			$dummyForm->remove($options['field']);

			$dummyDataset = $dummyForm->getDataset();
			foreach ($dummyDataset as $k => $d) {
				$sublistArr['fields'][$k] = $d->getJavascriptDescription();
				$sublistArr['fields'][$k]['default'] = $d->getJsValue(false);
			}

			foreach ($element->{$sublist['relationship']} as $item) {
				$itemArr = [
					'id' => $item[$options['primary']],
					'privileges' => [],
					'data' => [],
				];

				foreach ($sublistArr['privileges'] as $privilege => $privilegeValue) {
					if (!in_array($privilege, ['R', 'U', 'D']))
						continue;

					if (is_callable($privilegeValue))
						$privilegeValue = call_user_func($privilegeValue, $item);

					if ($privilege === 'R') {
						if (!$privilegeValue)
							continue 2;
					} else {
						$itemArr['privileges'][$privilege] = $privilegeValue;
					}
				}

				$itemForm = $item->getForm();
				foreach ($dummyDataset as $k => $d)
					$itemArr['data'][$k] = $itemForm[$k]->getJsValue(false);

				$sublistArr['list'][] = $itemArr;
			}

			foreach ($sublistArr['privileges'] as $privilege => $privilegeValue) {
				if (is_callable($privilegeValue))
					$sublistArr['privileges'][$privilege] = true;
			}

			$arr['sublists'][] = $sublistArr;
		}

		return $arr;
	}

	/**
	 * Ritorna l'item precedente o successivo a quello attuale
	 *
	 * @param int $id
	 * @param string $type
	 * @return array|null
	 */
	public function getAdjacentItem(int $id, string $type): ?array
	{
		if (!$id)
			return null;

		$pageOptions = $this->getPageOptions();
		if (!$pageOptions['element'] and !$pageOptions['table'])
			return null;
		if (!$pageOptions['items-navigation'])
			return null;

		if (!is_array($pageOptions['items-navigation']))
			$pageOptions['items-navigation'] = [];

		$element = $this->model->one($pageOptions['element'] ?: 'Element', [
			[
				'id',
				$type === 'prev' ? '<' : '>',
				$id,
			],
		], [
			'table' => $pageOptions['table'],
			'order_by' => $type === 'prev' ? 'id DESC' : 'id ASC',
		]);
		if (!$element or !$element->exists())
			return null;

		$text = [];
		foreach (($pageOptions['items-navigation']['fields'] ?? []) as $field)
			$text[] = $element[$field];

		return [
			'id' => $element['id'],
			'text' => implode(' ', $text),
		];
	}

	/**
	 * Registers a custom filter
	 *
	 * @param string $name
	 * @param array $options
	 * @return Field
	 */
	public function filter(string $name, array $options = []): Field
	{
		if (isset($this->customFiltersCallbacks[$name]))
			$this->model->error('Duplicate custom filter ' . $name);

		$options['nullable'] = true;
		if (!isset($options['default']))
			$options['default'] = null;

		switch ($options['admin-type'] ?? null) {
			case 'empty':
				$options['type'] = 'select';
				$options['depending-on'] = false;
				$options['options'] = [
					'' => '',
					0 => 'No',
					1 => 'Sì',
				];
				break;
			default:
				$adminForm = $this->getForm();
				if (isset($adminForm[$name])) {
					switch ($adminForm[$name]->options['type'] ?? 'text') {
						case 'checkbox':
							$options['type'] = 'select';
							$options['depending-on'] = false;
							$options['options'] = [
								'' => '',
								0 => 'No',
								1 => 'Sì',
							];
							break;
					}
				}
				break;
		}

		$d = $this->customFiltersForm->add($name, $options);

		if (isset($options['callback'])) {
			$this->customFiltersCallbacks[$name] = $options['callback'];
		} elseif (!isset($options['admin-type'])) {
			$this->customFiltersCallbacks[$name] = function ($v) use ($d) {
				return [
					[$d->options['field'], '=', $v],
				];
			};
		}

		return $d;
	}

	/**
	 * Adds or edits a field in the form
	 *
	 * @param string $name
	 * @param array|string $options
	 */
	public function field(string $name, $options = [])
	{
		if (!is_array($options))
			$options = ['type' => $options];

		$this->fieldsCustomizations[$name] = array_merge($this->fieldsCustomizations[$name] ?? [], $options);
	}

	/**
	 * Takes a form as argument and runs it against all the customization made in the admin page
	 *
	 * @param Form $form
	 * @return Form
	 */
	private function runFormThroughAdminCustomizations(Form $form): Form
	{
		$form = clone $form;
		$form->options['render-only-placeholders'] = true;

		$pageOptions = $this->getPageOptions();

		foreach ($this->fieldsCustomizations as $name => $options) {
			if (isset($form[$name]))
				$options = array_merge($form[$name]->options, $options);
			if (in_array($name, $pageOptions['required'] ?? []))
				$options['mandatory'] = true;
			$form->add($name, $options);
		}
		return $form;
	}

	/**
	 * @param int|null $id
	 * @return Element|null
	 */
	public function getElement(?int $id = null): ?Element
	{
		$element = $this->model->element;
		if (!$element or $id !== null) {
			$pageOptions = $this->getPageOptions();
			if (!$pageOptions['element'] and !$pageOptions['table'])
				return null;

			$element = $this->model->_ORM->loadMainElement($pageOptions['element'] ?: 'Element', $id ?: false, ['table' => $pageOptions['table']]);
			if ((!$element or !$element->exists()) and $id)
				throw new \Exception('L\'elemento non esiste');
		}

		return $element;
	}

	/**
	 * @param Element|null $element
	 * @return Form|null
	 */
	public function getForm(?Element $element = null): ?Form
	{
		if (!$element)
			$element = $this->getElement();

		if (!$element)
			return null;

		return $this->runFormThroughAdminCustomizations($element->getForm());
	}

	/**
	 * Saves the data in the provided element (or in the current one if not provided)
	 *
	 * @param int $id
	 * @param array $data
	 * @param array $sublists
	 * @param int|null $versionLock
	 * @return int
	 */
	public function save(int $id, array $data, array $sublists = [], ?int $versionLock = null): int
	{
		$element = $this->getElement($id);

		if ($element->exists())
			$privilege = 'U';
		else
			$privilege = 'C';

		if (!$this->canUser($privilege, null, $element))
			$this->model->error('Can\'t save, permission denied.');

		$pageOptions = $this->getPageOptions();
		$data = array_merge($pageOptions['where'], $data);

		$form = $this->getForm();

		$mainElementId = $this->subsave($element, $data, $form, $versionLock);

		foreach ($sublists as $sublistName => $sublistData) {
			if (!isset($this->sublists[$sublistName]))
				continue;

			$relationship = $this->sublists[$sublistName]['relationship'];

			foreach (($sublistData['create'] ?? []) as $childData) {
				$newChild = $element->create($relationship);
				$this->subsave($newChild, $childData);
			}

			foreach (($sublistData['update'] ?? []) as $childId => $childData) {
				$child = $element->{$relationship}[$childId] ?? null;
				if ($child)
					$this->subsave($child, $childData);
			}

			foreach (($sublistData['delete'] ?? []) as $childId) {
				$child = $element->{$relationship}[$childId] ?? null;
				if ($child)
					$child->delete();
			}
		}

		return $mainElementId;
	}

	/**
	 * @param Element $element
	 * @param array $data
	 * @param Form|null $form
	 * @param int|null $versionLock
	 * @return int
	 */
	private function subsave(Element $element, array $data, ?Form $form = null, ?int $versionLock = null): int
	{
		if ($form === null)
			$form = $element->getForm();

		foreach ($form->getDataset() as $k => $d) {
			if (isset($data[$k])) {
				if ($d->options['multilang']) {
					if (!is_array($data[$k]))
						throw new \Exception('I campi multilingua devono essere array/oggetti', 400);

					foreach ($data[$k] as $lang => $v) {
						$newV = $this->checkSingleDatum($d, $v);
						if ($newV !== false)
							$data[$k][$lang] = $newV;
						else
							unset($data[$k][$lang]);
					}

					if (count($data[$k]) === 0)
						unset($data[$k]);
				} else {
					if (is_array($data[$k]) and $d->options['type'] !== 'file')
						throw new \Exception('I campi non multilingua non possono essere array/oggetti', 400);

					$newV = $this->checkSingleDatum($d, $data[$k]);
					if ($newV !== false)
						$data[$k] = $newV;
					else
						unset($data[$k][$lang]);
				}
			}
		}

		return $element->save($data, [
			'version' => $versionLock,
		]);
	}

	/**
	 * @param Field $d
	 * @param mixed $v
	 * @return mixed
	 */
	private function checkSingleDatum(Field $d, $v)
	{
		if ($d->options['nullable'] and $v === '')
			$v = null;
		if (!$d->options['nullable'] and $v === null)
			$v = '';

		if ($d->options['type'] === 'password') {
			if ($v)
				$v = $this->model->_User_Admin->crypt($v);
			else
				return false;
		}

		return $v;
	}

	/**
	 * Deletes the element with the specified id
	 *
	 * @param int $id
	 */
	public function delete(int $id)
	{
		$pageOptions = $this->getPageOptions();
		$element = $this->model->_ORM->one($pageOptions['element'] ?: 'Element', $id, [
			'table' => $pageOptions['table'],
		]);

		if (!$this->canUser('D', null, $element))
			$this->model->error('Can\'t delete, permission denied.');

		if ($this->page->beforeDelete($element)) {
			if ($element->delete())
				$this->page->afterDelete($id, $element);
			else
				$this->model->error('Error while deleting.');
		}
	}

	/**
	 * Adds a sublist in the array, so that it will be rendered
	 *
	 * @param string $name
	 * @param array $options
	 */
	public function sublist(string $name, array $options = [])
	{
		$this->sublists[$name] = array_merge([
			'visualizer' => 'FormList',
			'relationship' => $name,
			'privileges' => [],
		], $options);
	}

	/**
	 * Given an Element, returns the page that handles it (if any)
	 * TODO: make this info be cached by Admin, and not looked up in runtime
	 *
	 * @param string $element
	 * @return null|string
	 */
	public function getAdminPageForElement(string $element): ?string
	{
		$adminPages = Autoloader::getFilesByType('AdminPage');
		foreach ($adminPages as $module => $files) {
			foreach ($files as $name => $className) {
				$adminPage = new $className($this->model);
				$pageOptions = $adminPage->options();
				if (($pageOptions['element'] ?? null) === $element)
					return $name;
			}
		}
		return null;
	}

	/**
	 * We need to return the controller name in order for the API to work
	 *
	 * @param array $request
	 * @param mixed $rule
	 * @return array|null
	 */
	public function getController(array $request, string $rule): ?array
	{
		$config = $this->retrieveConfig();

		if ($rule === 'api' and $config['api-path'] === $request[0]) {
			return [
				'controller' => 'AdminApi',
			];
		} else {
			return null;
		}
	}

	/**
	 * @return string
	 */
	public function getApiPath(): string
	{
		$config = $this->retrieveConfig();
		$apiPath = $config['api-path'] ?? 'admin-api';
		if (stripos($apiPath, 'http://') !== 0 and stripos($apiPath, 'https://') !== 0)
			$apiPath = PATH . $apiPath;

		if (substr($apiPath, -1) === '/')
			return $apiPath;
		else
			return $apiPath . '/';
	}

	/**
	 * @return array
	 */
	public function getAdminPaths(): array
	{
		$config = $this->retrieveConfig();
		return $config['url'] ?? [];
	}

	/**
	 * @param string|null $path
	 * @return User
	 */
	public function loadUserModule(?string $path = null): User
	{
		if (!$this->model->isLoaded('User', 'Admin')) {
			if ($path === null)
				$path = $this->path;

			$config = $this->retrieveConfig();

			$user_table = null;
			if (isset($config['url']) and is_array($config['url'])) {
				foreach ($config['url'] as $u) {
					if (is_array($u) and $u['path'] == $path) {
						$user_table = $u['table'];
						break;
					}
				}
			}
			if (!$user_table)
				$this->model->error('Wrong admin path');

			$this->model->load('User', [
				'table' => $user_table,
			], 'Admin');

			if ($this->model->_User_Admin->options['algorithm-version'] === 'old')
				$this->model->_User_Admin->options['password'] = 'old_password';
		}

		return $this->model->_User_Admin;
	}

	/**
	 * Retrieves the array of pages
	 *
	 * @param string|null $path
	 * @return array
	 */
	public function getPages(?string $path = null): array
	{
		if ($path === null)
			$path = $this->path;

		$config = $this->retrieveConfig();

		$pages = [];

		if (isset($config['url']) and is_array($config['url'])) {
			foreach ($config['url'] as $u) {
				if (is_array($u) and $u['path'] == $path) {
					$pages = $u['pages'];
					break;
				}
			}
		}

		if (isset(Globals::$data['adminAdditionalPages'])) {
			foreach (Globals::$data['adminAdditionalPages'] as $p) {
				$pages[] = array_merge([
					'name' => '',
					'rule' => '',
					'page' => null,
					'direct' => null,
					'hidden' => false,
					'sub' => [],
				], $p);
			}
		}

		$usersAdminPage = 'AdminUsers';
		if (isset($config['url']) and is_array($config['url'])) {
			foreach ($config['url'] as $u) {
				if (is_array($u) and $u['path'] == $path and ($u['admin-page'] ?? '')) {
					$usersAdminPage = $u['admin-page'];
					break;
				}
			}
		}

		$pages[] = [
			'name' => 'Users',
			'page' => $usersAdminPage,
			'rule' => 'admin-users',
			'direct' => null,
			'hidden' => false,
			'sub' => [
				[
					'name' => 'Privileges',
					'page' => 'AdminPrivileges',
					'rule' => 'admin-privileges',
					'direct' => null,
					'hidden' => false,
					'sub' => [],
				],
			],
		];

		return $pages;
	}

	/**
	 * Recursively looks for the rule corresponding to a given request, in the pages and sub-pages
	 *
	 * @param array $pages
	 * @param string $request
	 * @return array|null
	 */
	public function seekForRule(array $pages, string $request): ?array
	{
		foreach ($pages as $p) {
			if (isset($p['rule']) and $p['rule'] === $request)
				return $p;
			if (isset($p['sub'])) {
				$rule = $this->seekForRule($p['sub'], $request);
				if ($rule)
					return $rule;
			}
		}
		return null;
	}
}
