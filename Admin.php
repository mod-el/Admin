<?php namespace Model\Admin;

use Model\Core\Autoloader;
use Model\Core\Module;
use Model\Form\Form;
use Model\Form\Field;
use Model\ORM\Element;
use Model\Paginator\Paginator;

class Admin extends Module
{
	/** @var AdminPage */
	public $page = null;
	/** @var array */
	public $options = [];
	/** @var Paginator */
	public $paginator;
	/** @var Form */
	public $customFiltersForm;
	/** @var array */
	private $customFiltersCallbacks = [];
	/** @var Form */
	public $form;
	/** @var array|bool */
	protected $privilegesCache = false;
	/** @var array */
	public $usedWhere = [];
	/** @var array */
	public $sublists = [];
	/** @var array */
	public $fieldsCustomizations = [];

	public function init(array $options)
	{
		$options = array_merge([
			'page' => null,
			'id' => null,
		], $options);

		if (!$options['page'])
			return;

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

			$this->paginator = new Paginator();

			$this->customFiltersForm = new Form([
				'table' => $this->options['table'],
				'model' => $this->model,
			]);

			$element = $this->model->_ORM->loadMainElement($this->options['element'] ?: 'Element', $options['id'] ?: false, ['table' => $this->options['table']]);
			$this->form = $element->getForm();

			$values = $this->form->getValues();
		}

		$this->page->customize();

		if ($this->form) {
			$this->runFormThroughAdminCustomizations($this->form);
			$this->form->setValues($values);
		}
	}

	/**
	 * Returns the list of elements, filtered by specified options
	 *
	 * @param array $options
	 * @return \Generator
	 */
	public function getList(array $options = []): \Generator
	{
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

		$this->customFiltersCallbacks['all'] = function ($v) use ($options) { // "all" is a special filter, that searches in all string columns
			$tableModel = $this->model->_Db->getTable($this->options['table']);
			$columns = $tableModel->columns;

			if ($this->model->isLoaded('Multilang') and array_key_exists($this->options['table'], $this->model->_Multilang->tables)) {
				$mlTableOptions = $this->model->_Multilang->tables[$this->options['table']];
				$mlTable = $this->options['table'] . $mlTableOptions['suffix'];
				$mlTableModel = $this->model->_Db->getTable($mlTable);
				foreach ($mlTableModel->columns as $k => $col) {
					if (isset($columns[$k]) or $k == $mlTableOptions['keyfield'] or $k == $mlTableOptions['lang'])
						continue;
					$columns[$k] = $col;
				}
			}

			$arr = [];
			foreach ($columns as $k => $col) {
				if ($tableModel->primary === $k or $col['foreign_key'] or ($options['search-columns'] and !in_array($k, $options['search-columns'])))
					continue;

				switch ($col['type']) {
					case 'tinyint':
					case 'smallint':
					case 'int':
					case 'mediumint':
					case 'bigint':
						if (is_numeric($v))
							$arr[] = [$k, '=', $v];
						break;
					case 'decimal':
						if (is_numeric($v))
							$arr[] = [$k, 'LIKE', $v . '.%'];
						break;
					case 'varchar':
					case 'char':
					case 'text':
					case 'tinytext':
					case 'enum':
						$arr[] = [$k, 'REGEXP', '(^|[^a-z0-9])' . $v];
						break;
				}
			}

			if ($options['search-columns'] and empty($arr)) { // If specific columns are provided and no criteria matched, then it's impossible, I return a never-matching query
				return [
					'1=2',
				];
			} else {
				return [
					['sub' => $arr, 'operator' => 'OR'],
				];
			}
		};

		foreach ($options['filters'] as $f) {
			$f_where = $this->getWhereFromFilter($f);
			if ($f_where)
				$where = array_merge($where, $f_where);
		}

		$this->usedWhere = $where;

		// Count how many total elements there are
		$count = $this->model->_Db->count($this->options['table'], $where, [
			'joins' => $this->options['joins'],
		]);

		// I pass the parameters to the paginator, so that it will calculate the total number of pages and the start limit
		$this->paginator->setOptions([
			'tot' => $count,
			'perPage' => $options['perPage'] ?: false,
			'pag' => $options['p'],
		]);
		$limit = $options['perPage'] ? $this->paginator->getStartLimit() . ',' . $options['perPage'] : null;

		// Get the rules to apply to the query, in order to sort as requested (what joins do I need to make and what order by clause I need to use)
		$sortingRules = $this->getSortingRules($options['sortBy'], $this->options['joins']);

		$queryOptions = [
			'stream' => true,
			'joins' => $sortingRules['joins'],
			'order_by' => $sortingRules['order_by'],
			'limit' => $limit,
			'table' => $this->options['table'],
		];

		$elementName = $this->options['element'] ?: 'Element';
		return $this->adminListGenerator($elementName, $where, $queryOptions);
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
		foreach ($this->model->_ORM->all($elementName, $where, $queryOptions) as $el) {
			$this->runFormThroughAdminCustomizations($el->getForm());
			yield $el;
		}
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
	 * @return bool
	 */
	public function canUser(string $what, string $page = null, Element $el = null): bool
	{
		if ($page === null)
			$page = $this->options['page'];
		if ($el === null)
			$el = $this->model->element;

		if ($this->privilegesCache === false) {
			$this->privilegesCache = $this->model->_Db->select_all('admin_privileges', [
				'or' => [
					['user', $this->model->_User_Admin->logged()],
					['user', null],
				],
			], [
				'order_by' => 'id DESC',
				'stream' => false,
			]);
		}

		$currentGuess = [
			'row' => false,
			'C' => $this->options['privileges']['C'],
			'R' => $this->options['privileges']['R'],
			'U' => $this->options['privileges']['U'],
			'D' => $this->options['privileges']['D'],
			'L' => $this->options['privileges']['L'],
		];
		if (!array_key_exists($what, $currentGuess) or $what === 'row')
			$this->model->error('Requested unknown privilege.');


		foreach ($this->privilegesCache as $p) {
			if (
				(($p['page'] === $page or ($p['page'] === null and $currentGuess['row']['page'] === null)) and ($currentGuess['row'] === false or ($currentGuess['row']['user'] === null and $p['user'] !== null)))
				or ($currentGuess['row']['page'] === null and $p['page'] === $page)
			) {
				$currentGuess['row'] = $p;

				foreach ($currentGuess as $idx => $priv) {
					if ($idx === 'row')
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
			return (bool)call_user_func($currentGuess[$what], $el);
		} else {
			return (bool)$currentGuess[$what];
		}
	}

	/**
	 * Given a input filter, returns a "where" array usable for Db module
	 *
	 * @param array $f
	 * @return bool|array
	 */
	private function getWhereFromFilter(array $f)
	{
		if (!is_array($f) or count($f) < 2 or count($f) > 3)
			return false;

		$k = $f[0];

		if (isset($this->customFiltersCallbacks[$k])) {
			if (count($f) != 2)
				return false;
			if (!is_callable($this->customFiltersCallbacks[$k]))
				$this->model->error('Wrong callback format for filter ' . $k);

			return call_user_func($this->customFiltersCallbacks[$k], $f[1]);
		} else {
			if (count($f) != 3 and (count($f) != 4 or $f[1] !== 'range'))
				return false;

			switch ($f[1]) {
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
							'sub' => [
								[$k, '!=', $f[2]],
								[$k, '=', null],
							],
							'operator' => 'OR',
						],
					];
					break;
				case 'contains':
					return [
						[$k, 'LIKE', '%' . $f[2] . '%'],
					];
					break;
				case 'starts':
					return [
						[$k, 'LIKE', $f[2] . '%'],
					];
					break;
				case 'empty':
					switch ($f[2]) {
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
										[$k, ''],
										[$k, null],
									],
									'operator' => 'OR',
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
	 * @param array $joins
	 * @return array
	 */
	private function getSortingRules(array $sortBy, array $joins): array
	{
		if ($sortBy) {
			$order_by = [];

			foreach ($sortBy as $idx => $sort) {
				if (!is_array($sort) or count($sort) != 2 or !in_array(strtolower($sort[1]), ['asc', 'desc']))
					$this->model->error('Wrong "sortBy" format!');

				$rules = $this->getSortingRulesFor($sort[0], $sort[1], $idx);
				if (!$rules)
					$this->model->error('Column ' . $sort[0] . ' is not sortable!');

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
	 * @param string|null $field
	 * @param string $dir
	 * @param int $idx
	 * @return array|null
	 */
	public function getSortingRulesFor($field, string $dir, int $idx)
	{
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
	public function getEditArray(): array
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
	 */
	public function runFormThroughAdminCustomizations(Form $form)
	{
		foreach ($this->fieldsCustomizations as $name => $options) {
			if (isset($form[$name])) {
				$datum = $form[$name];
				$datum->options = array_merge($datum->options, $options);
			} else {
				$form->add($name, $options);
			}
		}
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

		foreach ($element->getForm()->getDataset() as $k => $d) {
			if (isset($data[$k])) {
				if ($d->options['nullable'] and $data[$k] === '')
					$data[$k] = null;

				if ($d->options['type'] === 'password') {
					if ($data[$k])
						$data[$k] = sha1(md5($data[$k]));
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
}
