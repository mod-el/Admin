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
	private $options = null;
	/** @var Form */
	public $customFiltersForm;
	/** @var array */
	private $customFiltersCallbacks = [];
	/** @var Form */
	public $form;
	/** @var array|bool */
	protected $privilegesCache = false;
	/** @var array */
	public $sublists = [];
	/** @var array */
	public $fieldsCustomizations = [];

	/*public function init(array $options)
	{
		$options = array_merge([
			'path' => null,
			'page' => null,
			'rule' => null,
			'id' => null,
		], $options);

		if ($options['path'] !== null)
			$this->path = $options['path'];

		if (!$options['page']) {
			if ($options['rule']) {
				$pages = $this->getPages($options['path']);
				$rule = $this->seekForRule($pages, $options['rule']);
				if (!$rule or !$rule['page'])
					return;
				$options['page'] = $rule['page'];
			}

			if (!$options['page'])
				return;
		}

		$className = Autoloader::searchFile('AdminPage', $options['page']);
		if (!$className)
			$this->model->error('Admin Page class not found');

		$this->page = new $className($this->model);
		$pageOptions = $this->page->options();

		$this->options = array_merge_recursive_distinct([
			'page' => $options['page'],
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
		], $pageOptions);

		if ($this->options['element'] and !$this->options['table'])
			$this->options['table'] = $this->model->_ORM->getTableFor($this->options['element']);

		if ($this->options['table']) {
			if ($this->options['order_by'] === false) {
				$tableModel = $this->model->_Db->getTable($this->options['table']);
				$this->options['order_by'] = $tableModel->primary . ' DESC';

				if ($this->options['element']) {
					$elementData = $this->model->_ORM->getElementData($this->options['element']);
					if ($elementData and $elementData['order_by']) {
						$this->options['order_by'] = [];
						foreach ($elementData['order_by']['depending_on'] as $field)
							$this->options['order_by'][] = $field . ' ASC';
						$this->options['order_by'][] = $elementData['order_by']['field'] . ' ASC';

						$this->options['order_by'] = implode(',', $this->options['order_by']);
					}
				}
			}

			$this->customFiltersForm = new Form([
				'table' => $this->options['table'],
				'model' => $this->model,
			]);

			$element = $this->model->_ORM->loadMainElement($this->options['element'] ?: 'Element', $options['id'] ?: false, ['table' => $this->options['table']]);
			if (!$element)
				die('Requested element does not exist');
			$this->form = $element->getForm();

			$values = $this->form->getValues();
		}

		$this->page->customize();

		if ($this->form) {
			$replaceValues = $this->runFormThroughAdminCustomizations($this->form);
			$values = array_merge($values, $replaceValues);
			$this->form->setValues($values);
		}
	}*/

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
		$pages = $this->getPages($this->path);
		$rule = $this->seekForRule($pages, $rule);
		if (!$rule or !$rule['page'])
			return;

		$className = Autoloader::searchFile('AdminPage', $rule['page']);
		if (!$className)
			$this->model->error('Admin Page class not found');

		$this->page = new $className($this->model);
		$this->pageRule = $rule;
	}

	/**
	 * @return array
	 */
	private function getPageOptions(): array
	{
		if ($this->options === null) {
			$options = $this->page->options();

			$this->options = array_merge_recursive_distinct([ // TODO: da rivedere
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
				'columns' => [],
				'fields' => [],
			], $options);

			if ($this->options['element'] and !$this->options['table'])
				$this->options['table'] = $this->model->_ORM->getTableFor($this->options['element']);

			if ($this->options['table']) {
				if ($this->options['order_by'] === false) {
					$tableModel = $this->model->_Db->getTable($this->options['table']);
					$this->options['order_by'] = $tableModel->primary . ' DESC';

					if ($this->options['element']) {
						$elementData = $this->model->_ORM->getElementData($this->options['element']);
						if ($elementData and $elementData['order_by']) {
							$this->options['order_by'] = [];
							foreach ($elementData['order_by']['depending_on'] as $field)
								$this->options['order_by'][] = $field . ' ASC';
							$this->options['order_by'][] = $elementData['order_by']['field'] . ' ASC';

							$this->options['order_by'] = implode(',', $this->options['order_by']);
						}
					}
				}
			}

			if (!$this->options['table']) {
				$this->pageRule['visualizer'] = 'Custom';
				$this->pageRule['mobile-visualizer'] = 'Custom';
			}

			// Backward compatibility
			$visualizerOptions = $this->page->visualizerOptions();

			switch ($this->pageRule['visualizer']) {
				case 'Table':
					if (isset($visualizerOptions['columns']))
						$options['columns'] = array_merge_recursive_distinct($options['columns'] ?? [], $visualizerOptions['columns']);
					break;
				case 'FormList':
					if (isset($visualizerOptions['fields']))
						$options['fields'] = array_merge_recursive_distinct($options['fields'] ?? [], $visualizerOptions['fields']);
					break;
			}
		}

		return $this->options;
	}

	/**
	 * Returns current page details for the APIs
	 *
	 * @return array
	 */
	public function getPageDetails(): array
	{
		$options = $this->getPageOptions();
		$visualizerOptions = $this->page->visualizerOptions();

		// Backward compatibility
		switch ($this->pageRule['visualizer']) {
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
			'type' => $this->pageRule['visualizer'],
			'visualizer-options' => $visualizerOptions,
		];

		if ($this->pageRule['visualizer'] and $this->pageRule['visualizer'] !== 'Custom') {
			$fields = $this->getColumnsList();

			$columns = [];
			foreach ($fields['fields'] as $idx => $column) {
				$columns[$idx] = [
					'label' => $column['label'],
					'editable' => $column['editable'],
					'sortable' => $column['sortable'],
					'price' => $column['price'],
				];
			}

			$pageDetails['fields'] = $columns;
			$pageDetails['default-fields'] = $fields['default'];

			/* FILTERS */

			$dummy = $this->getDummy();
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
				$pageDetails['filters'][$filter->options['name']] = $this->convertFieldToArrayDescription($filter);
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

		$defaultColumns = array_keys($fields);
		if (count($options['columns'] ?? []) > 0) {
			$allColumns = $options['columns'];

			foreach ($fields as $field => $fieldOptions) {
				if (!isset($allColumns[$field]) and !in_array($field, $allColumns))
					$allColumns[] = $field;
			}

			if ($options['wipe-columns'] ?? true)
				$defaultColumns = $options['columns'];
		} else {
			$allColumns = array_keys($fields);
		}

		$columns = $this->elaborateColumns($allColumns, $options['table']);
		$defaultColumns = array_keys($this->elaborateColumns($defaultColumns, $options['table']));

		return [
			'fields' => $columns,
			'default' => $defaultColumns,
		];
	}

	/**
	 * @param array|null $options
	 * @return Element
	 */
	public function getDummy(array $options = null): Element
	{
		if ($options === null)
			$options = $this->options;

		return $this->model->_ORM->create($options['element'] ?: 'Element', ['table' => $options['table']]);
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
			$options = $this->options;

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

			$fields[$k] = $col;
		}

		if ($this->model->isLoaded('Multilang') and array_key_exists($options['table'], $this->model->_Multilang->tables)) {
			$mlTableOptions = $this->model->_Multilang->tables[$options['table']];
			$mlTable = $options['table'] . $mlTableOptions['suffix'];
			$mlTableModel = $this->model->_Db->getTable($mlTable);
			foreach ($mlTableModel->columns as $k => $col) {
				if ($k === $mlTableModel->primary or isset($fields[$k]) or $k === $mlTableOptions['keyfield'] or $k === $mlTableOptions['lang'] or in_array($k, $excludeColumns))
					continue;

				$fields[$k] = $col;
			}
		}

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
					$column = array(
						'field' => $column,
						'display' => $column,
					);
				} elseif (is_callable($column)) {
					$column = array(
						'field' => false,
						'display' => $column,
					);
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
			], $column);

			if (is_string($column['display']) and !$column['field'] and $column['display'])
				$column['field'] = $column['display'];
			if ($column['field'] === false and $tableModel and array_key_exists($k, $tableModel->columns))
				$column['field'] = $k;
			if (is_string($column['field']) and $column['field'] and !$column['display'])
				$column['display'] = $column['field'];

			$k = $this->fromLabelToColumnId($k);
			if ($k == '') {
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

		if (!is_string($column['display'])) {
			if (is_callable($column['display'])) {
				$c['text'] = call_user_func($column['display'], $el);
			} else {
				$this->model->error('Unknown display format in a column - either string or callable is expected');
			}
		} else {
			$form = $el->getForm();

			if (isset($form[$column['display']])) {
				$d = $form[$column['display']];
//				$c['text'] = $d->getText(array_merge($config, ['preview' => true]));
				$c['text'] = $d->getText(['preview' => true]);
			} else {
				$c['text'] = $el[$column['display']];
			}

			if (strlen($c['text']) > 150)
				$c['text'] = textCutOff($c['text'], 150);
		}

		$c['text'] = (string)$c['text'];
		if ($column['field'])
			$c['value'] = $el[$column['field']];

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
	 * @param Field $field
	 * @return array
	 */
	private function convertFieldToArrayDescription(Field $field): array
	{
		$response = [
			'type' => $field->options['type'],
			'label' => $field->getLabel(),
		];

		switch ($field->options['type']) {
			case 'select':
				$field->loadSelectOptions();
				$response['options'] = [];
				foreach ($field->options['options'] as $k => $v) {
					if ($k === '')
						continue;
					$response['options'][] = [
						'id' => $k,
						'text' => $v,
					];
				}
				break;
		}

		if ($field->options['default'])
			$response['default'] = $field->options['default'];

		return $response;
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
			'perPage' => $this->options['perPage'],
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
			$this->runFormThroughAdminCustomizations($el->getForm()); // TODO: serve ancora?

			$background = $pageOptions['background'] ?? null;
			if($background and !is_string($background) and is_callable($background))
				$background = $background($el);

			$color = $pageOptions['color'] ?? null;
			if($color and !is_string($color) and is_callable($color))
				$color = $color($el);

			yield [
				'element' => $el,
				'background' => $background,
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
	 * Returns an array with the possible actions that the user can take
	 *
	 * @param string $type
	 * @return array
	 */
	public function getActions(string $type): array
	{
		$actions = [];

		if (!(isset($this->options['table']) and $this->options['table']) and !(isset($this->options['element']) and $this->options['element']))
			return [];

		if ($this->canUser('C')) {
			$actions['new'] = [
				'text' => 'Nuovo',
				'action' => 'new',
			];
		}
		if ($this->canUser('D')) {
			$actions['delete'] = [
				'text' => 'Elimina',
				'action' => 'delete',
			];
		}

		switch ($type) {
			case 'edit':
				if ($this->canUser('U')) {
					$actions['save'] = [
						'text' => 'Salva',
						'action' => 'save',
					];
				}
				if ($this->canUser('C')) {
					$actions['duplicate'] = [
						'text' => 'Duplica',
						'action' => 'duplicate',
					];
				}
				break;
			case 'new':
				if ($this->canUser('C')) {
					$actions['save'] = [
						'text' => 'Salva',
						'action' => 'save',
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
	 * @param string $subpage
	 * @return bool
	 */
	public function canUser(string $what, string $page = null, Element $el = null, string $subpage = null): bool
	{
		if ($page === null)
			$page = $this->pageRule['page'];
		if ($el === null)
			$el = $this->model->element;

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
			'C' => $this->options['privileges']['C'] ?? true,
			'R' => $this->options['privileges']['R'] ?? true,
			'U' => $this->options['privileges']['U'] ?? true,
			'D' => $this->options['privileges']['D'] ?? true,
			'L' => $this->options['privileges']['L'] ?? true,
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
			$order_by = $this->options['order_by'];
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

		if (isset($this->form[$field])) {
			$d = $this->form[$field];
			if (in_array($d->options['type'], ['select', 'radio', 'select-cascade'])) {
				$tableModel = $this->model->_Db->getTable($this->options['table']);
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
	 */
	public function getElementData(): array
	{
		$element = $this->model->_ORM->element;

		if (!$element)
			$this->model->error('Element does not exist.');

		if (!$this->canUser('R', null, $element))
			$this->model->error('Can\'t read, permission denied.');

		$arr = [
			'data' => [
				'_model_version' => $this->model->_Db->getVersionLock($element->getTable(), $element[$element->settings['primary']]),
			],
			'children' => [],
		];

		$dataset = $this->form->getDataset();
		foreach ($dataset as $k => $d) {
			$arr['data'][$k] = $d->getJsValue(false);
		}

		foreach ($this->sublists as $s) {
			$options = $element->getChildrenOptions($s['options']['children']);
			if (!$options)
				$this->model->error($s['options']['children'] . ' is not a children list of the element!');

			$visualizer = null;
			if (isset($s['options']['visualizer']) and $s['options']['visualizer']) {
				$className = Autoloader::searchFile('DataVisualizer', $s['options']['visualizer']);
				if ($className) {
					$visualizer = new $className($this->model, [
						'name' => $s['name'],
						'table' => $options['table'],
						'element' => $options['element'],
					]);
				}
			}

			$arr['children'][$s['name']] = [
				'primary' => $options['primary'],
				'list' => [],
			];

			foreach ($element->{$s['options']['children']} as $chId => $ch) {
				$chArr = [];
				if ($visualizer) {
					$form = $visualizer->getRowForm($ch, $s['options']);
				} else {
					$form = $this->getSublistRowForm($ch, $s['options']);
				}
				$keys = array_keys($form->getDataset());

				$chArr[$options['primary']] = $ch[$options['primary']];
				foreach ($keys as $k) {
					$chArr[$k] = $form[$k]->getJsValue(false);
				}

				$arr['children'][$s['name']]['list'][] = $chArr;
			}
		}

		return $arr;
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
				$customAdminForm = clone $this->form;
				$this->runFormThroughAdminCustomizations($customAdminForm);

				if (isset($customAdminForm[$name])) {
					switch ($customAdminForm[$name]->options['type'] ?? 'text') {
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
	 * Clears all the fields of the form (in order to add new custom fields)
	 *
	 * @return bool
	 */
	public function clearForm(): bool
	{
		return $this->form->clear();
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
	 * @return array
	 */
	public function runFormThroughAdminCustomizations(Form $form): array
	{
		$replaceValues = [];
		foreach ($this->fieldsCustomizations as $name => $options) {
			if (array_key_exists('default', $options))
				$replaceValues[$name] = $options['default'];

			if (isset($form[$name]))
				$options = array_merge($form[$name]->options, $options);
			$form->add($name, $options);
		}
		return $replaceValues;
	}

	/**
	 * Saves the data in the provided element (or in the current one if not provided)
	 *
	 * @param array $data
	 * @param int $versionLock
	 * @param Element|null $element
	 * @return int
	 */
	public function saveElement(array $data, int $versionLock = null, Element $element = null): int
	{
		if ($element === null)
			$element = $this->model->element;

		if ($element->exists())
			$privilege = 'U';
		else
			$privilege = 'C';

		if (!$this->canUser($privilege, null, $element))
			$this->model->error('Can\'t save, permission denied.');

		$data = array_merge($this->options['where'], $data);

		foreach ($element->getForm()->getDataset() as $k => $d) {
			if (isset($data[$k])) {
				if ($d->options['nullable'] and $data[$k] === '')
					$data[$k] = null;

				if ($d->options['type'] === 'password') {
					if ($data[$k])
						$data[$k] = $this->model->_User_Admin->crypt($data[$k]);
					else
						unset($data[$k]);
				}
			}
		}

		foreach ($this->options['required'] as $mandatoryField) {
			if (!$this->checkMandatoryField($mandatoryField, $data)) {
				if (is_array($mandatoryField)) {
					$this->model->error('Missing mandatory field (on of the following: ' . implode(',', $mandatoryField));
				} else {
					$this->model->error('Missing mandatory field "' . $mandatoryField . '"');
				}
			}
		}

		return $element->save($data, [
			'children' => true,
			'version' => $versionLock,
		]);
	}

	/**
	 * Checks (recurively in case of multiple fields) if a mandatory field was compiled
	 *
	 * @param string|array $field
	 * @param array $data
	 * @return bool
	 */
	private function checkMandatoryField($field, array $data): bool
	{
		if (is_array($field)) {
			$atLeastOne = false;
			foreach ($field as $subfield) {
				if ($this->checkMandatoryField($subfield, $data)) {
					$atLeastOne = true;
					break;
				}
			}
			return $atLeastOne;
		} elseif (isset($data[$field]) or !$this->model->element->exists()) { // For new elements, all fields must be present. For a subsequent edit, they can be missing
			if ($data[$field] ?? null)
				return true;
			else
				return false;
		} else {
			return true;
		}
	}

	/**
	 * Deletes the element with the specified id
	 *
	 * @param int $id
	 */
	public function delete(int $id)
	{
		$element = $this->model->_ORM->one($this->options['element'] ?: 'Element', $id, [
			'table' => $this->options['table'],
		]);

		$this->form = $element->getForm();

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
		$options = array_merge([
			'visualizer' => 'FormList',
			'privileges' => false,
			'children' => $name,
		], $options);

		$this->sublists[] = [
			'name' => $name,
			'options' => $options,
		];
	}

	/**
	 * Returns the custom form of a single sublist row
	 *
	 * @param Element $el
	 * @param array $options
	 * @return Form
	 */
	public function getSublistRowForm(Element $el, array $options): Form
	{
		$form = $el->getForm();
		if (isset($options['fields']) and count($options['fields']) > 0) {
			$newForm = clone $form;
			if (!isset($options['clear-form']) or $options['clear-form'])
				$newForm->clear();

			foreach ($options['fields'] as $f => $fOpt) {
				if (!is_string($fOpt) and is_callable($fOpt)) {
					$fOpt = [
						'type' => 'custom',
						'custom' => $fOpt,
					];
				} elseif (is_numeric($f)) {
					$f = $fOpt;
					$fOpt = [];
				}

				$newForm->add($form[$f] ?? $f, $fOpt);
			}

			$form = $newForm;
		}

		return $form;
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
					'visualizer' => 'Table',
					'mobile-visualizer' => 'Table',
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
			'visualizer' => 'Table',
			'mobile-visualizer' => 'Table',
			'direct' => null,
			'hidden' => false,
			'sub' => [
				[
					'name' => 'Privileges',
					'page' => 'AdminPrivileges',
					'rule' => 'admin-privileges',
					'visualizer' => 'FormList',
					'mobile-visualizer' => 'FormList',
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
