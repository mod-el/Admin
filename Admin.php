<?php namespace Model\Admin;

use Model\Core\Autoloader;
use Model\Core\Module;
use Model\DbParser\Table;
use Model\Form\Form;
use Model\Form\Field;
use Model\ORM\Element;
use Model\Paginator\Paginator;
use Model\User\User;
use Model\Core\Globals;

class Admin extends Module
{
	public ?AdminPage $page = null;
	public ?array $pageRule = null;
	private ?string $path = null;
	private ?array $pageOptions = null;
	protected array $privilegesCache;
	public array $sublists = [];
	public array $fieldsCustomizations = [];

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
	public function getPageOptions(?string $page = null): array
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
				$options['order_by'] = $tableModel->primary[0] . ' DESC';

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
			'endpoint' => $this->pageRule['rule'],
			'type' => $options['visualizer'],
			'visualizer-options' => $visualizerOptions,
			'privileges' => [
				'C' => $this->canUser('C'),
				'U' => $this->canUser('U'),
				'D' => $this->canUser('D'),
			],
			'actions' => array_filter($options['actions'], function ($action) {
				return (!isset($action['specific']) or $action['specific'] === 'list' or $action['specific'] === 'table'); // Retrocompatibilità per "table"
			}),
			'export' => $options['export'],
			'default_per_page' => $options['perPage'],
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

			$filtersForm = $this->getForm();
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

					$parsedFilter = [
						'filter' => $defaultFilterOptions['field'],
						'type' => $filterType,
					];
					if (isset($defaultFilterOptions['default']))
						$parsedFilter['default'] = $defaultFilterOptions['default'];

					$pageDetails['default-filters'][$position][] = $parsedFilter;
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
				if ($k === $mlTableModel->primary[0] or in_array($k, $fields) or $k === $mlTableOptions['keyfield'] or $k === $mlTableOptions['lang'] or in_array($k, $excludeColumns))
					continue;

				$fields[] = $k;
			}
		}

		$form = $this->getForm();
		return array_unique(array_merge($fields, array_keys($form->getDataset())));
	}

	/**
	 * @param array $columns
	 * @param string|null $table
	 * @param bool $getSortingRules
	 * @return array
	 */
	public function elaborateColumns(array $columns, ?string $table = null, bool $getSortingRules = true): array
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
				'label' => null,
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

			if ((!is_string($column['display']) and is_callable($column['display'])) or $column['price'])
				$column['raw'] = true;
			if (is_string($column['display']) and !$column['field'] and $column['display'])
				$column['field'] = $column['display'];
			if ($column['field'] === false and $tableModel and array_key_exists($k, $tableModel->columns))
				$column['field'] = $k;
			if (is_string($column['field']) and $column['field'] and !$column['display'])
				$column['display'] = $column['field'];

			if (($column['editable'] or $column['label'] === null) and $adminForm === null)
				$adminForm = $this->page ? $this->getForm() : null;

			if ($column['editable']) {
				if ($adminForm and isset($adminForm[$column['field']]))
					$column['editable'] = $adminForm[$column['field']]->getJavascriptDescription();
				else
					$column['editable'] = false;
			}

			if ($column['label'] === null) {
				if ($adminForm and isset($adminForm[$column['field']]))
					$column['label'] = $adminForm[$column['field']]->getLabel();
				else
					$column['label'] = $this->makeLabel($k);
			}
			$column['label'] = str_replace('"', '', $column['label']);

			if ($k and $k !== $column['field'])
				$k = $this->fromLabelToColumnId($k);

			if (!$k) {
				if ($column['field'])
					$k = $column['field'];

				if (!$k)
					$this->model->error('Can\'t assign id to column with label "' . entities($column['label']) . '"');
			}

			$column['sortable'] = $getSortingRules and (bool)$this->getSortingRulesFor($column, 'ASC', 0);
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
		$elaborated = [
			'value' => null,
			'text' => '',
		];

		$form = $this->getForm($el);

		if ($column['field']) {
			if (isset($form[$column['field']]))
				$elaborated['value'] = $form[$column['field']]->getJsValue(false);
			else
				$elaborated['value'] = $el[$column['field']];
		}

		if (!is_string($column['display'])) {
			if (is_callable($column['display']))
				$elaborated['text'] = call_user_func($column['display'], $el);
			else
				$this->model->error('Unknown display format in a column - either string or callable is expected');
		} else {
			if ($column['price']) {
				$elaborated['text'] = $elaborated['value'] !== null ? makePrice($elaborated['value']) : '';
			} elseif (isset($form[$column['display']])) {
				$d = $form[$column['display']];
				$elaborated['text'] = $d->getText(['preview' => true]);
			} else {
				$elaborated['text'] = $el[$column['display']];
			}

			if (strlen($elaborated['text']) > 150)
				$elaborated['text'] = textCutOff($elaborated['text'], 150);
		}

		$elaborated['text'] = (string)$elaborated['text'];

		$background = $column['background'] ?? null;
		if ($background and !is_string($background) and is_callable($background))
			$background = $background($el);
		if ($background)
			$elaborated['background'] = $background;

		$color = $column['color'] ?? null;
		if ($color and !is_string($color) and is_callable($color))
			$color = $color($el);
		if ($color)
			$elaborated['color'] = $color;

		$clickable = $column['clickable'];
		if ($clickable and !is_string($clickable) and is_callable($clickable))
			$clickable = (bool)$clickable($el);
		if (!$clickable)
			$elaborated['clickable'] = false;

		return $elaborated;
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
		if ($column['display'] and is_string($column['display']))
			return $column['display'];
		elseif ($column['field'] and is_string($column['field']))
			return $column['field'];
		else
			return null;
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
			case 'radio':
				$field->options['type'] = 'select';
				$field->options['if-null'] = $field->getLabel();
				break;
			case 'checkbox':
				$field->options['type'] = 'select';
				$field->options['if-null'] = $field->getLabel();
				$field->options['options'] = [
					'' => '',
					0 => 'No',
					1 => 'Sì',
				];
				break;
			case 'select':
				$field->options['if-null'] = $field->getLabel();
				break;
		}

		$field->options['default'] = null;
		$field->options['attributes'] = [];

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
		$joins = [];

		$tableModel = $this->model->_Db->getTable($options['table']);

		$search = trim($search);
		if ($search) {
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
				if ($tableModel->primary[0] === $k or count($col['foreign_keys']) > 0 or ($searchFields and !in_array($k, $searchFields)))
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
			$customFilterExists = null;
			foreach (($options['filters'] ?? []) as $customFilter) {
				if ($customFilter['field'] === $filter['filter']) {
					$customFilterExists = $customFilter;
					break;
				}
			}

			$f_where = $this->getWhereFromFilter($filter, $tableModel, $customFilterExists);
			if ($f_where) {
				$where = array_merge($where, $f_where);

				if ($customFilterExists and !empty($customFilterExists['filter-joins'])) {
					$filter_joins = $customFilterExists['filter-joins'];
					if (is_callable($filter_joins))
						$filter_joins = call_user_func($filter_joins, $filter['value']);

					$joins = array_merge($joins, $filter_joins);
				}
			}
		}

		return [
			'where' => $where,
			'joins' => $joins,
		];
	}

	/**
	 * Returns the list of elements, filtered by specified options
	 *
	 * @param array $options
	 * @return array
	 */
	public function getList(array $options = []): array
	{
		$pageOptions = $this->getPageOptions();

		$options = array_merge([
			'where' => [],
			'p' => 1,
			'goTo' => null,
			'perPage' => $pageOptions['perPage'],
			'sortBy' => [],
			'joins' => [],
		], $options);

		$where = $options['where'];
		$joins = array_merge($pageOptions['joins'], $options['joins']);

		// Count how many total elements there are
		$count = $this->model->_Db->count($pageOptions['table'], $where, [
			'joins' => $joins,
			'group_by' => $pageOptions['group_by'],
		]);

		// Get the rules to apply to the query, in order to sort as requested (what joins do I need to make and what order by clause I need to use)
		$sortingRules = $this->getSortingRules($options['sortBy'], $joins);

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
				if ($row[$tableModel->primary[0]] == $options['goTo']) {
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
	 * @param array $searchQuery
	 * @return float
	 */
	public function getColumnTotal(array $column, array $searchQuery): float
	{
		$pageOptions = $this->getPageOptions();

		return (float)$this->model->_Db->select($pageOptions['table'], $searchQuery['where'], [
			'joins' => array_merge($pageOptions['joins'], $searchQuery['joins']),
			'sum' => $column['field'],
		]);
	}

	/**
	 * Can the current user do something? (Privilege check, basically)
	 *
	 * @param string $what
	 * @param string|null $page
	 * @param Element|null $el
	 * @param string|null $subpage
	 * @return bool
	 */
	public function canUser(string $what, ?string $page = null, ?Element $el = null, ?string $subpage = null): bool
	{
		if ($page === null)
			$page = $this->pageRule['page'];
		if ($el === null)
			$el = $this->model->element;

		$pageOptions = $this->getPageOptions($page);

		if (!isset($this->privilegesCache)) {
			$config = $this->retrieveConfig();

			$privileges_table = null;
			if (isset($config['url']) and is_array($config['url'])) {
				foreach ($config['url'] as $u) {
					if (is_array($u) and $u['path'] == $this->path) {
						$privileges_table = $u['users-tables-prefix'] . 'privileges';
						break;
					}
				}
			}
			if (!$privileges_table)
				$this->model->error('Wrong admin path');

			$this->privilegesCache = $this->model->_Db->select_all($privileges_table, [
				'or' => [
					['profile', $this->model->_User_Admin->profile],
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
	 * @param Table $tableModel
	 * @param array|null $customFilterExists
	 * @return array|null
	 */
	private function getWhereFromFilter(array $filter, Table $tableModel, ?array $customFilterExists = null): ?array
	{
		if (count($filter) !== 3 or !array_key_exists('filter', $filter) or !array_key_exists('type', $filter) or !array_key_exists('value', $filter))
			return null;

		if ($customFilterExists and !empty($customFilterExists['custom']) and is_callable($customFilterExists['custom']))
			return call_user_func($customFilterExists['custom'], $filter['value']);

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

			case 'contains':
				return [
					[$k, 'LIKE', '%' . $filter['value'] . '%'],
				];

			case 'begins':
				return [
					[$k, 'LIKE', $filter['value'] . '%'],
				];

			case 'empty':
				$empty_value = '';
				if (isset($tableModel->columns[$k])) {
					switch ($tableModel->columns[$k]['type']) {
						case 'date':
							$empty_value = '0000-00-00';
							break;
						case 'time':
							$empty_value = '00:00:00';
							break;
						case 'datetime':
							$empty_value = '0000-00-00 00:00:00';
							break;
					}
				}

				switch ($filter['value']) {
					case 0:
						return [
							[$k, '!=', null],
							[$k, '!=', $empty_value],
						];

					case 1:
						return [
							[
								'sub' => [
									[$k, '=', $empty_value],
									[$k, '=', null],
								],
								'operator' => 'OR',
							],
						];
				}
				break;

			default:
				$this->model->error('Unrecognized filter type');
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
			'actions' => array_filter($pageOptions['actions'], function ($action) use ($id, $element) {
				if (isset($action['if']) and !$action['if']($element))
					return false;

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

		foreach ($arr['actions'] as &$action) {
			if (isset($action['text']) and !is_string($action['text']) and is_callable($action['text']))
				$action['text'] = $action['text']($element);
			if (isset($action['action']) and !is_string($action['action']) and is_callable($action['action']))
				$action['action'] = $action['action']($element);
			if (isset($action['url']) and !is_string($action['url']) and is_callable($action['url']))
				$action['url'] = $action['url']($element);
		}
		unset($action);

		$form = $this->getForm();
		$dataset = $form->getDataset();
		foreach ($dataset as $k => $d) {
			$arr['fields'][$k] = $d->getJavascriptDescription();
			$arr['data'][$k] = $d->getJsValue(false);
		}

		foreach ($this->getSublists($pageOptions) as $sublistName => $sublist) {
			$sublistArr = [
				'name' => $sublistName,
				'label' => $sublist['label'],
				'visualizer' => $sublist['visualizer'],
				'visualizer-options' => $sublist['visualizer-options'] ?? [],
				'fields' => [],
				'list' => [],
				'privileges' => [
					'C' => true,
					'R' => true,
					'U' => true,
					'D' => true,
				],
			];

			if ($sublist['custom']) {
				$sublistArr['privileges'] = [
					'C' => false,
					'R' => true,
					'U' => false,
					'D' => false,
				];

				$sublistArr['fields'] = [];
				$dummyForm = is_callable($sublist['custom']['form']) ? $sublist['custom']['form']() : $sublist['custom']['form'];
				$dummyDataset = $dummyForm->getDataset();
				foreach ($dummyDataset as $k => $d)
					$sublistArr['fields'][$k] = $d->getJavascriptDescription();

				$list = is_callable($sublist['custom']['list']) ? $sublist['custom']['list']($element) : $sublist['custom']['list'];
				foreach ($list as $item) {
					$sublistArr['list'][] = [
						'id' => null,
						'privileges' => [],
						'data' => $item,
					];
				}
			} else {
				$options = $element->getChildrenOptions($sublist['relationship']);
				if (!$options or $options['type'] !== 'multiple')
					$this->model->error($sublist['relationship'] . ' is not a valid relationship of the element!');

				if ($sublist['privileges'])
					$sublistArr['privileges'] = array_merge($sublistArr['privileges'], $sublist['privileges']);

				$dummy = $element->create($sublist['relationship']);
				$dummyForm = $dummy->getForm(true);
				$dummyForm->remove($options['field']);

				$dummyDataset = $dummyForm->getDataset();
				foreach ($dummyDataset as $k => $d) {
					$sublistArr['fields'][$k] = $d->getJavascriptDescription();
					$sublistArr['fields'][$k]['default'] = $d->getJsValue(false);
				}

				foreach ($element->{$sublist['relationship']} as $item) {
					$itemArr = [
						'id' => !empty($item->options['assoc']) ? $item->options['assoc'][$options['primary']] : $item[$options['primary']],
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

					$itemForm = $item->getForm(true);
					foreach ($dummyDataset as $k => $d)
						$itemArr['data'][$k] = $itemForm[$k]->getJsValue(false);

					$sublistArr['list'][] = $itemArr;
				}

				foreach ($sublistArr['privileges'] as $privilege => $privilegeValue) {
					if (is_callable($privilegeValue))
						$sublistArr['privileges'][$privilege] = true;
				}
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
	 * Deprecated
	 *
	 * @param string $name
	 * @param array $options
	 * @return Field
	 * @deprecated use "filters" option instead
	 */
	public function filter(string $name, array $options = []): Field
	{
		throw new \Exception('admin->filter() method is deprecated');
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

		if ($this->page) {
			$pageOptions = $this->getPageOptions();

			foreach ($this->fieldsCustomizations as $name => $options) {
				if (isset($form[$name]))
					$options = array_merge($form[$name]->options, $options);
				if (in_array($name, $pageOptions['required'] ?? []))
					$options['required'] = true;
				$form->add($name, $options);
			}
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

		$this->page->beforeSave($element, $data, $sublists);

		$pageOptions = $this->getPageOptions();
		$data = array_merge($pageOptions['where'], $data);

		$form = $this->getForm();

		$mainElementId = $this->subsave($element, $data, [
			'form' => $form,
			'versionLock' => $versionLock,
			'afterSave' => false,
		]);

		$pageSublists = $this->getSublists($pageOptions);
		foreach ($sublists as $sublistName => $sublistData) {
			if (!isset($pageSublists[$sublistName]) or $pageSublists[$sublistName]['custom'])
				continue;

			$relationship = $pageSublists[$sublistName]['relationship'];

			foreach (($sublistData['create'] ?? []) as $childData) {
				$newChild = $element->create($relationship);
				$this->subsave($newChild, $childData, ['isChild' => true]);
			}

			foreach (($sublistData['update'] ?? []) as $childId => $childData) {
				$child = $element->{$relationship}[$childId] ?? null;
				if ($child)
					$this->subsave($child, $childData, ['isChild' => true]);
			}

			foreach (($sublistData['delete'] ?? []) as $childId) {
				$child = $element->{$relationship}[$childId] ?? null;
				if ($child) {
					if (!empty($child->options['assoc']))
						$this->model->_Db->delete($child->settings['assoc']['table'], $child->options['assoc'][$child->settings['assoc']['primary'] ?? 'id']);
					else
						$child->delete();
				}
			}
		}

		if ($element->lastAfterSaveData) {
			$this->_flagSaving = true;
			$element->afterSave($element->lastAfterSaveData['previous_data'], $element->lastAfterSaveData['saving']);
			$this->_flagSaving = false;
		}

		$this->page->afterSave($element, $data, $sublists);

		return $mainElementId;
	}

	/**
	 * @param Element $element
	 * @param array $data
	 * @param array $options
	 * @return int
	 * @throws \Model\Core\Exception
	 */
	private function subsave(Element $element, array $data, array $options = []): int
	{
		$options = array_merge([
			'form' => null,
			'versionLock' => null,
			'afterSave' => true,
			'isChild' => false,
		], $options);

		if ($options['form'] === null)
			$options['form'] = $element->getForm($options['isChild']);

		foreach ($options['form']->getDataset() as $k => $d) {
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
						unset($data[$k]);
				}
			}
		}

		if ($options['isChild'] and !empty($element->options['assoc'])) {
			if ($element->exists()) {
				$this->model->_Db->update($element->settings['assoc']['table'], $element->options['assoc'][$element->settings['assoc']['primary'] ?? 'id'], $data);
				return $element->options['assoc']['id'];
			} else {
				$data = array_merge($element->options['assoc'], $data);
				if (isset($data[$element->settings['assoc']['primary'] ?? 'id']))
					unset($data[$element->settings['assoc']['primary'] ?? 'id']);
				return $this->model->_Db->insert($element->settings['assoc']['table'], $data);
			}
		} else {
			$options['saveForm'] = true;
			return $element->save($data, $options);
		}
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
	 * Deprecated
	 *
	 * @param string $name
	 * @param array $options
	 * @deprecated use "sublists" option instead
	 */
	public function sublist(string $name, array $options = [])
	{
		$this->sublists[$name] = array_merge([
			'visualizer' => 'FormList',
			'label' => $this->makeLabel($name),
			'relationship' => $name,
			'privileges' => [],
		], $options);
	}

	/**
	 * @return array
	 */
	public function getSublists(?array $pageOptions = null): array
	{
		if ($pageOptions === null)
			$pageOptions = $this->getPageOptions();

		$sublists = $this->sublists; // Retrocompatibilità

		foreach (($pageOptions['sublists'] ?? []) as $name => $options) {
			if (is_numeric($name) and is_string($options)) {
				$name = $options;
				$options = [];
			}

			if (isset($sublists[$name]))
				throw new \Exception('Sublist "' . $name . '" declared twice');

			$sublists[$name] = array_merge([
				'visualizer' => 'FormList',
				'label' => $this->makeLabel($name),
				'relationship' => $name,
				'privileges' => [],
				'custom' => null,
			], $options);
		}

		return $sublists;
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
						$user_table = $u['users-tables-prefix'] . 'users';
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
				[
					'name' => 'Profiles',
					'page' => 'AdminProfiles',
					'rule' => 'admin-profiles',
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
