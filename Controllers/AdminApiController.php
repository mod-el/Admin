<?php namespace Model\Admin\Controllers;

use Model\Admin\AdminRest;
use Model\Admin\Auth;
use Model\Core\Controller;
use Model\Core\Model;
use Model\Db\Db;
use Model\Jwt\JWT;

class AdminApiController extends Controller
{
	private ?array $token = null;
	private array $request = [];

	/**
	 *
	 */
	public function init()
	{
		$config = $this->model->_Admin->retrieveConfig();
		$this->loadRequest($config['api-path'] ?? 'admin-api');

		if ($this->request[0] === 'openapi') {
			$this->model->_Admin->setPath($this->request[1] ?? '');
		} elseif (!($this->request[0] === 'user' and $this->request[1] === 'login')) {
			$this->token = Auth::getToken();
			if (!$this->token)
				$this->respond(['error' => 'Invalid auth token'], 401);

			$this->model->_Admin->setPath($this->token['path']);

			$user = $this->model->_Admin->loadUserModule();
			if ($user->logged() != $this->token['id'])
				$user->directLogin($this->token['id'], false);
		}
	}

	public function get()
	{
		$request = $this->request[0] ?? '';
		try {
			switch ($request) {
				case 'keep-alive':
					$this->respond(['status' => true]);

				case 'user':
					$subrequest = $this->request[1] ?? null;
					switch ($subrequest) {
						case 'auth':
							// Token was already loaded in the "init" method
							$usernameColumn = $this->model->_User_Admin->getUsernameColumn();
							$this->respond([
								'id' => $this->token['id'],
								'username' => $this->model->_User_Admin->get($usernameColumn),
							]);

						default:
							throw new \Exception('Unknown action', 400);
					}

				case 'pages':
					$pages = $this->model->_Admin->getPages($this->token['path']);
					$cleanPages = $this->cleanPages($pages);
					$this->respond($cleanPages);

				case 'rest':
					$adminPage = $this->request[1] ?? null;
					if (!$adminPage)
						throw new \Exception('No page name defined', 400);

					$helper = new AdminRest($this->model, $adminPage);

					$id = $this->request[2] ?? null;
					if ($id !== null and (!is_numeric($id) or $id < 0))
						throw new \Exception('Id should be a number greater than or equal to 0', 400);

					if ($id) {
						$response = $helper->get($id);
					} else {
						$response = $helper->list([
							'page' => (int)($_GET['page'] ?? 1),
							'per_page' => (int)($_GET['per_page'] ?? 20),
							'sort_by' => !empty($_GET['sort_by']) ? json_decode($_GET['sort_by'], true) : [],
						]);
					}

					$this->respond($response);

				case 'openapi':
					$pages = $this->model->_Admin->getPages();
					$pages = $this->extractRestPages($pages);

					$openapi = [
						'openapi' => '3.0.4',
						'info' => [
							'title' => APP_NAME,
							'version' => '1.0.0',
						],
						'servers' => [
							[
								'url' => 'https://' . $_SERVER['HTTP_HOST'] . PATH . 'admin-api/rest/',
							],
						],
						'paths' => [],
					];

					foreach ($pages as $page) {
						$openapi['paths']['/' . $page['path']] = [
							'get' => [
								'operationId' => 'list_' . $page['path'],
								'description' => 'List ' . $page['name'],
								'parameters' => [
									[
										'name' => 'page',
										'in' => 'query',
										'description' => 'Page to retrieve; if not specified, it defaults to 1',
										'required' => false,
										'schema' => [
											'type' => 'integer',
										],
									],
									[
										'name' => 'per_page',
										'in' => 'query',
										'description' => 'Items per page; if not specified, it defaults to 20',
										'required' => false,
										'schema' => [
											'type' => 'integer',
										],
									],
								],
								'responses' => [
									'200' => [
										'description' => 'Successful response',
										'content' => [
											'application/json' => [
												'schema' => [
													'type' => 'object',
													'properties' => [
														'tot' => [
															'type' => 'integer',
														],
														'pages' => [
															'type' => 'integer',
														],
														'page' => [
															'type' => 'integer',
														],
														'list' => [
															'type' => 'array',
															'items' => [
																'$ref' => '#/components/schemas/' . $page['path'],
															],
														],
													],
												],
											],
										],
									],
								],
							],
							'post' => [
								'operationId' => 'create_' . $page['path'],
								'description' => 'Create ' . $page['name'],
								'requestBody' => [
									'required' => true,
									'content' => [
										'application/json' => [
											'schema' => [
												'$ref' => '#/components/schemas/' . $page['path'],
											],
										],
									],
								],
								'responses' => [
									'200' => [
										'description' => 'Successful response',
										'content' => [
											'application/json' => [
												'schema' => [
													'$ref' => '#/components/schemas/' . $page['path'],
												],
											],
										],
									],
								],
							],
						];

						$openapi['paths']['/' . $page['path'] . '/{id}'] = [
							'get' => [
								'operationId' => 'get_' . $page['path'],
								'description' => 'Get ' . $page['name'],
								'parameters' => [
									[
										'name' => 'id',
										'in' => 'path',
										'description' => 'ID of the ' . $page['name'] . ' to get',
										'required' => true,
										'schema' => [
											'type' => 'integer',
										],
									],
								],
								'responses' => [
									'200' => [
										'description' => 'Successful response',
										'content' => [
											'application/json' => [
												'schema' => [
													'$ref' => '#/components/schemas/' . $page['path'],
												],
											],
										],
									],
									'404' => [
										'description' => 'Element not found',
									],
								],
							],
							'put' => [
								'operationId' => 'update_' . $page['path'],
								'description' => 'Update ' . $page['name'],
								'parameters' => [
									[
										'name' => 'id',
										'in' => 'path',
										'description' => 'ID of the ' . $page['name'] . ' to update',
										'required' => true,
										'schema' => [
											'type' => 'integer',
										],
									],
								],
								'requestBody' => [
									'required' => true,
									'content' => [
										'application/json' => [
											'schema' => [
												'$ref' => '#/components/schemas/' . $page['path'],
											],
										],
									],
								],
								'responses' => [
									'200' => [
										'description' => 'Successful response',
										'content' => [
											'application/json' => [
												'schema' => [
													'$ref' => '#/components/schemas/' . $page['path'],
												],
											],
										],
									],
									'404' => [
										'description' => 'Element not found',
									],
								],
							],
							'delete' => [
								'operationId' => 'delete_' . $page['path'],
								'description' => 'Delete ' . $page['name'],
								'parameters' => [
									[
										'name' => 'id',
										'in' => 'path',
										'description' => 'ID of the ' . $page['name'] . ' to delete',
										'required' => true,
										'schema' => [
											'type' => 'integer',
										],
									],
								],
								'responses' => [
									'200' => [
										'description' => 'Successful response',
										'content' => [
											'application/json' => [
												'schema' => [
													'type' => 'object',
													'properties' => [
														'success' => [
															'type' => 'boolean',
														],
													],
												],
											],
										],
									],
									'404' => [
										'description' => 'Element not found',
									],
								],
							],
						];

						$dummy = $this->model->_ORM->loadMainElement($page['options']['element'] ?: 'Element', false, ['table' => $page['options']['table']]);
						$form = $this->model->_Admin->getForm($dummy);

						$openapi['components']['schemas'][$page['path']] = $this->convertFormToOpenApi($form);

						foreach (($page['options']['sublists'] ?? []) as $sublist) {
							$dummyChild = $dummy->create($sublist);
							$dummyChildForm = $dummyChild->getForm(true);
							$dummyChildForm->remove($dummy->getChildrenOptions($sublist)['field']);

							$childConvertedForm = $this->convertFormToOpenApi($dummyChildForm);

							$openapi['components']['schemas'][$page['path']]['properties'][$sublist] = [
								'type' => 'array',
								'items' => $childConvertedForm,
							];
						}
					}

					$this->respond($openapi);

				case 'page':
					$adminPage = $this->request[1] ?? null;
					$action = $this->request[2] ?? null;

					if (!$adminPage) {
						if ($action === null) { // Dashboard
							$this->respond([
								'type' => 'Custom',
								'js' => [],
								'css' => [],
							]);
						} else {
							throw new \Exception('No page name defined', 400);
						}
					}

					$this->model->_Admin->setPage($adminPage);

					$id = $this->request[3] ?? null;
					if ($id !== null and (!is_numeric($id) or $id < 0))
						throw new \Exception('Id should be a number greater than or equal to 0', 400);

					switch ($action) {
						case null:
							$response = $this->model->_Admin->getPageDetails();
							$this->respond($response);

						case 'data':
							$response = $this->model->_Admin->getElementData($id);

							$response['prev-item'] = $this->model->_Admin->getAdjacentItem($id, 'prev');
							$response['next-item'] = $this->model->_Admin->getAdjacentItem($id, 'next');

							$this->respond($response);

						default:
							$this->customAction($action, $id);
							break;
					}

				default:
					throw new \Exception('Unknown action', 400);
			}
		} catch (\Exception $e) {
			$this->respond(['error' => getErr($e), 'backtrace' => $e->getTrace()], (int)$e->getCode());
		} catch (\Error $e) {
			$this->respond(['error' => $e->getMessage() . ' in file ' . $e->getFile() . ' at line ' . $e->getLine()], 500);
		}
	}

	public function post()
	{
		$db = Db::getConnection();

		try {
			$request = $this->request[0] ?? '';
			$input = Model::getInput();

			switch ($request) {
				case 'user':
					$subrequest = $this->request[1] ?? null;
					switch ($subrequest) {
						case 'login':
							$path = $input['path'];
							$user = $this->model->_Admin->loadUserModule($path);

							if ($id = $user->login($input['username'], $input['password'], false)) {
								$token = JWT::build([
									'path' => $path,
									'id' => $id,
								]);
								$this->respond(['token' => $token]);
							} else {
								throw new \Exception('Wrong username or password', 401);
							}

						default:
							throw new \Exception('Unknown action', 400);
					}

				case 'page':
					$adminPage = $this->request[1] ?? null;
					$action = $this->request[2] ?? null;

					if (!$adminPage) {
						if ($action === null) { // Dashboard
							$this->respond([
								'type' => 'Custom',
								'js' => [],
								'css' => [],
							]);
						} else {
							throw new \Exception('No page name defined', 400);
						}
					}

					$this->model->_Admin->setPage($adminPage);

					$id = $this->request[3] ?? null;
					if ($id !== null and (!is_numeric($id) or $id < 0))
						throw new \Exception('Id should be a number greater than or equal to 0', 400);

					switch ($action) {
						case 'search':
							$searchQuery = $this->model->_Admin->makeSearchQuery(
								$input['search'] ?? '',
								$input['filters'] ?? [],
								$input['search-fields'] ?? []
							);

							$options = [
								'where' => $searchQuery['where'],
								'joins' => $searchQuery['joins'],
							];
							if (isset($input['page']))
								$options['p'] = $input['page'];
							if (isset($input['go-to']))
								$options['goTo'] = $input['go-to'];
							if (isset($input['per-page']))
								$options['perPage'] = $input['per-page'];
							if (isset($input['sort_by']))
								$options['sortBy'] = $input['sort_by'];

							$list = $this->model->_Admin->getList($options);

							$response = [
								'tot' => $list['tot'],
								'pages' => $list['pages'],
								'current' => $list['page'],
								'list' => [],
								'totals' => [],
							];

							$fields = $this->model->_Admin->getColumnsList();

							if (count($input['fields'] ?? []) > 0)
								$fieldsList = $input['fields'];
							else
								$fieldsList = $fields['default'];

							foreach ($fieldsList as $idx) {
								if (!isset($fields['fields'][$idx]))
									throw new \Exception('"' . $idx . '" field not existing');
							}

							foreach ($list['list'] as $item) {
								$element_array = [
									'id' => $item['element'][$item['element']->settings['primary']],
									'privileges' => [
										'R' => $this->model->_Admin->canUser('R', null, $item['element']),
										'U' => $this->model->_Admin->canUser('U', null, $item['element']),
										'D' => $this->model->_Admin->canUser('D', null, $item['element']),
									],
									'data' => [],
									'order-idx' => null,
								];

								if ($item['background'])
									$element_array['background'] = $item['background'];
								if ($item['color'])
									$element_array['color'] = $item['color'];
								if ($item['onclick'])
									$element_array['onclick'] = $item['onclick'];
								if ($list['custom-order'])
									$element_array['order-idx'] = $item['element'][$list['custom-order']];

								foreach ($fieldsList as $idx) {
									$column = $fields['fields'][$idx];
									$element_array['data'][$idx] = $this->model->_Admin->getElementColumn($item['element'], $column);
								}

								$response['list'][] = $element_array;
							}

							foreach ($fieldsList as $idx) {
								if ($fields['fields'][$idx]['total'])
									$response['totals'][$idx] = $this->model->_Admin->getColumnTotal($fields['fields'][$idx], $searchQuery);
							}

							$this->respond($response);

						case 'file-save-begin':
							$tmp_files_path = INCLUDE_PATH . 'app-data' . DIRECTORY_SEPARATOR . 'temp-admin-files';
							if (!is_dir($tmp_files_path))
								mkdir($tmp_files_path, 0777, true);

							do {
								$id = uniqid();
								if (!empty($input['ext']))
									$id .= '.' . $input['ext'];
							} while (file_exists($tmp_files_path . DIRECTORY_SEPARATOR . $id));

							$this->respond(['id' => $id]);

						case 'file-save-process':
							if (empty($input['id']) or !isset($input['chunk']))
								throw new \Exception('Missing data', 400);

							$tmp_files_path = INCLUDE_PATH . 'app-data' . DIRECTORY_SEPARATOR . 'temp-admin-files';

							$handle = fopen($tmp_files_path . DIRECTORY_SEPARATOR . $input['id'], 'a+');
							fwrite($handle, base64_decode($input['chunk']));
							fclose($handle);

							$this->respond(['success' => true]);

						case 'save':
							$db->beginTransaction();

							$data = $input['data'] ?? null;
							$newId = $this->model->_Admin->save($id, $data);

							$db->commit();

							$this->respond(['id' => $newId]);

						case 'save-many':
							$db->beginTransaction();

							foreach ($input['list'] as $item)
								$this->model->_Admin->save(empty($item['id']) ? 0 : $item['id'], $item);

							foreach (($input['deleted'] ?? []) as $id)
								$this->model->_Admin->delete($id);

							$db->commit();

							$this->respond(['success' => true]);

						case 'delete':
							$ids = $input['ids'] ?? [];

							$db->beginTransaction();

							foreach ($ids as $id)
								$this->model->_Admin->delete($id);

							$db->commit();

							$this->respond(['deleted' => $ids]);

						case 'duplicate':
							$element = $this->model->_Admin->getElement($id);
							if (!$element or !$element->exists())
								throw new \Exception('Error: attempting to duplicate a non existing element.');

							$newElement = $element->duplicate();
							$this->respond(['id' => $newElement['id']]);

						case 'change-order':
							$element = $this->model->_Admin->getElement($id);
							if (!$element or !$element->exists())
								throw new \Exception('Error: attempting to change order to a non existing element.');

							$to = $input['to'] ?? null;
							if (!$to or !is_numeric($to))
								throw new \Exception('Bad parameters');

							if ($element->changeOrder($to))
								$this->respond(['success' => true]);
							else
								throw new \Exception('Error while changing order');

						default:
							$this->customAction($action, $id, $input);
							break;
					}

				case 'rest':
					$adminPage = $this->request[1] ?? null;
					if (!$adminPage)
						throw new \Exception('No page name defined', 400);

					$helper = new AdminRest($this->model, $adminPage);

					$db->beginTransaction();
					$id = $helper->save(0, $input);
					$db->commit();

					$this->respond($helper->get($id));

				default:
					throw new \Exception('Unknown action', 400);
			}
		} catch (\Exception $e) {
			if ($db->inTransaction())
				$db->rollBack();

			$this->respond([
				'error' => getErr($e),
				...(DEBUG_MODE ? [
					'backtrace' => $e->getTrace(),
				] : []),
			], (int)$e->getCode());
		} catch (\Error $e) {
			if ($db->inTransaction())
				$db->rollBack();
			$this->respond(['error' => $e->getMessage() . ' in file ' . $e->getFile() . ' at line ' . $e->getLine()], 500);
		}
	}

	public function put()
	{
		$db = Db::getConnection();

		try {
			$request = $this->request[0] ?? '';
			$input = Model::getInput();

			switch ($request) {
				case 'rest':
					$adminPage = $this->request[1] ?? null;
					if (!$adminPage)
						throw new \Exception('No page name defined', 400);

					$helper = new AdminRest($this->model, $adminPage);

					$id = $this->request[2] ?? null;
					if ($id !== null and (!is_numeric($id) or $id <= 0))
						throw new \Exception('Id should be a number greater than 0', 400);

					$db->beginTransaction();
					$helper->save($id, $input);
					$db->commit();

					$this->respond($helper->get($id));

				default:
					throw new \Exception('Unknown action', 400);
			}
		} catch (\Exception $e) {
			if ($db->inTransaction())
				$db->rollBack();
			$this->respond(['error' => getErr($e), 'backtrace' => $e->getTrace()], (int)$e->getCode());
		} catch (\Error $e) {
			if ($db->inTransaction())
				$db->rollBack();
			$this->respond(['error' => $e->getMessage() . ' in file ' . $e->getFile() . ' at line ' . $e->getLine()], 500);
		}
	}

	public function delete()
	{
		$db = Db::getConnection();

		try {
			$request = $this->request[0] ?? '';

			switch ($request) {
				case 'rest':
					$adminPage = $this->request[1] ?? null;
					if (!$adminPage)
						throw new \Exception('No page name defined', 400);

					$helper = new AdminRest($this->model, $adminPage);

					$id = $this->request[2] ?? null;
					if ($id !== null and (!is_numeric($id) or $id <= 0))
						throw new \Exception('Id should be a number greater than 0', 400);

					$db->beginTransaction();
					$helper->delete($id);
					$db->commit();

					$this->respond(['success' => true]);

				default:
					throw new \Exception('Unknown action', 400);
			}
		} catch (\Exception $e) {
			if ($db->inTransaction())
				$db->rollBack();
			$this->respond(['error' => getErr($e), 'backtrace' => $e->getTrace()], (int)$e->getCode());
		} catch (\Error $e) {
			if ($db->inTransaction())
				$db->rollBack();
			$this->respond(['error' => $e->getMessage() . ' in file ' . $e->getFile() . ' at line ' . $e->getLine()], 500);
		}
	}

	/**
	 * @param array $response
	 * @param int $code
	 */
	private function respond(array $response, int $code = 200): never
	{
		if ($code <= 0)
			$code = 500;

		http_response_code($code);

		header('Content-Type: application/json');
		echo json_encode($response, JSON_INVALID_UTF8_IGNORE | JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
		die();
	}

	/**
	 * @param string|null $action
	 * @param int|null $id
	 * @param array $input
	 */
	private function customAction(?string $action = null, ?int $id = null, array $input = []): void
	{
		if ($action === null)
			throw new \Exception('No action provided', 400);

		if (!$this->model->_Admin->page)
			throw new \Exception('No admin page loaded', 500);

		$action = str_replace(' ', '', lcfirst(ucwords(str_replace('-', ' ', $action))));

		if (method_exists($this->model->_Admin->page, $action)) {
			$element = $id !== null ? $this->model->_Admin->getElement($id) : null;
			if ($id !== null and !$element)
				throw new \Exception('Element does not exist.');

			$response = $this->model->_Admin->page->{$action}($input, $element);
			$this->respond($response);
		} else {
			$db = Db::getConnection();
			if ($db->inTransaction())
				$db->rollBack();

			throw new \Exception('Unrecognized action', 400);
		}
	}

	/**
	 * @param array $pages
	 * @return array
	 */
	private function cleanPages(array $pages): array
	{
		$cleanPages = [];
		foreach ($pages as $p) {
			if ($p['hidden'] ?? false)
				continue;

			$rule = $p['rule'] ?? null;
			$sub = $this->cleanPages($p['sub'] ?? []);

			if ($p['page'] ?? null) {
				if (!$this->model->_Admin->canUser('L', $p['page'])) {
					$rule = null;
					if (count($sub) === 0)
						continue;
				}
			} else {
				if (count($p['sub'] ?? []) > 0 and count($sub) === 0)
					continue;
			}

			$iconIdentifier = rewriteUrlWords([$p['name'] ?? $rule]);
			$iconPath = 'app/assets/img/' . ($this->token['path'] ? $this->token['path'] . '/' : '') . 'menu/' . $iconIdentifier . '.png';

			$cleanPages[] = [
				'icon' => file_exists(INCLUDE_PATH . $iconPath) ? PATH . $iconPath : null,
				'name' => $p['name'] ?? '',
				'path' => $rule,
				'direct' => $p['direct'] ?? null,
				'sub' => $sub,
			];
		}
		return $cleanPages;
	}

	/**
	 * @param string $path
	 */
	private function loadRequest(string $path): void
	{
		$mainRequest = implode('/', $this->model->getRequest());
		$request = substr($mainRequest, strlen($path));
		if ($request[0] === '/')
			$request = substr($request, 1);
		$this->request = explode('/', $request);
	}

	private function extractRestPages(array $pages): array
	{
		$cleanPages = [];
		foreach ($pages as $p) {
			if ($p['hidden'] ?? false)
				continue;

			if ($p['page'] ?? null) {
				$pageOptions = $this->model->_Admin->getPageOptions($p['page']);
				if (!($pageOptions['table'] ?? null) and !($pageOptions['element'] ?? null))
					continue;
				if (!($pageOptions['openapi'] ?? false))
					continue;

				$cleanPages[] = [
					'name' => $p['name'] ?? '',
					'path' => $p['rule'] ?? null,
					'options' => $pageOptions,
				];
			}

			$cleanPages = array_merge($cleanPages, $this->extractRestPages($p['sub'] ?? []));
		}
		return $cleanPages;
	}

	private function convertFormToOpenApi(\Model\Form\Form $form): array
	{
		$response = [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
					'readOnly' => true,
				],
			],
			'required' => [],
		];

		foreach ($form->getDataset() as $k => $d) {
			$converted = [
				'type' => match ($d->options['type']) {
					'number' => 'number',
					'checkbox' => 'boolean',
					'instant-search' => 'integer',
					default => 'string',
				},
			];

			switch ($d->options['type']) {
				case 'select':
					if ($d->options['table'])
						$converted['type'] = 'integer';
					elseif ($d->options['options'])
						$converted['enum'] = array_values(array_filter(array_keys($d->options['options']), fn($k) => $k !== null and $k !== ''));
					break;
				case 'date':
					$converted['format'] = 'date';
					break;
				case 'datetime':
					$converted['format'] = 'date-time';
					break;
			}

			$response['properties'][$k] = $converted;

			if ($d->options['required'])
				$response['required'][] = $k;
		}

		return $response;
	}
}
