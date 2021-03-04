<?php namespace Model\Admin\Controllers;

use Model\Admin\Auth;
use Model\Core\Controller;

class AdminApiController extends Controller
{
	/** @var array */
	private $token = null;
	/** @var array */
	private $request = [];

	/**
	 *
	 */
	public function init()
	{
		$config = $this->model->_Admin->retrieveConfig();
		$this->loadRequest($config['api-path'] ?? 'admin-api');

		if ($this->request[0] !== 'user' or $this->request[1] !== 'login') {
			$auth = new Auth($this->model);
			$this->token = $auth->getToken();
			if (!$this->token)
				$this->model->error('Invalid auth token', ['code' => 401]);

			$this->model->_Admin->setPath($this->token['path']);

			$user = $this->model->_Admin->loadUserModule();
			if ($user->logged() != $this->token['id'])
				$user->directLogin($this->token['id'], false);
		}
	}

	/**
	 * @return mixed|void
	 */
	public function get()
	{
		$request = $this->request[0] ?? '';
		try {
			switch ($request) {
				case 'keep-alive':
					$this->respond(['status' => true]);
					break;
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
							break;
						default:
							$this->model->error('Unknown action', ['code' => 400]);
							break;
					}
					break;
				case 'pages':
					$pages = $this->model->_Admin->getPages($this->token['path']);
					$cleanPages = $this->cleanPages($pages);
					$this->respond($cleanPages);
					break;
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
							$this->model->error('No page name defined', ['code' => 400]);
						}
					}

					$this->model->_Admin->setPage($adminPage);

					$id = $this->request[3] ?? null;
					if ($id !== null and (!is_numeric($id) or $id < 0))
						$this->model->error('Id should be a number greater than or equal to 0', ['code' => 400]);

					switch ($action) {
						case null:
							$response = $this->model->_Admin->getPageDetails();
							$this->respond($response);
							break;
						case 'data':
							$response = $this->model->_Admin->getElementData($id);

							$response['prev-item'] = $this->model->_Admin->getAdjacentItem($id, 'prev');
							$response['next-item'] = $this->model->_Admin->getAdjacentItem($id, 'next');

							$this->respond($response);
							break;
						default:
							$this->customAction($action, $id);
							break;
					}
					break;
				default:
					$this->model->error('Unknown action', ['code' => 400]);
					break;
			}
		} catch (\Exception $e) {
			$this->respond(['error' => getErr($e)], (int)$e->getCode());
		} catch (\Error $e) {
			$this->respond(['error' => $e->getMessage() . ' in file ' . $e->getFile() . ' at line ' . $e->getLine()], 500);
		}
	}

	/**
	 * @return mixed|void
	 */
	public function post()
	{
		try {
			if (!$this->model->_CSRF->checkCsrf())
				throw new \Exception('Unauthorized, try refreshing the page', 401);

			$request = $this->request[0] ?? '';
			$input = $this->getInput();

			switch ($request) {
				case 'user':
					$subrequest = $this->request[1] ?? null;
					switch ($subrequest) {
						case 'login':
							$path = $input['path'];
							$user = $this->model->_Admin->loadUserModule($path);

							if ($id = $user->login($input['username'], $input['password'], false)) {
								$token = $this->model->_JWT->build([
									'path' => $path,
									'id' => $id,
								]);
								$this->respond(['token' => $token]);
							} else {
								$this->model->error('Wrong username or password', ['code' => 401]);
							}
							break;
						default:
							$this->model->error('Unknown action', ['code' => 400]);
							break;
					}
					break;
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
							$this->model->error('No page name defined', ['code' => 400]);
						}
					}

					$this->model->_Admin->setPage($adminPage);

					$id = $this->request[3] ?? null;
					if ($id !== null and (!is_numeric($id) or $id < 0))
						$this->model->error('Id should be a number greater than or equal to 0', ['code' => 400]);

					switch ($action) {
						case 'search':
							$where = $this->model->_Admin->makeSearchQuery(
								$input['search'] ?? '',
								$input['filters'] ?? [],
								$input['search-fields'] ?? []
							);

							$options = [
								'where' => $where,
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
									$this->model->error('"' . $idx . '" field not existing');
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
									$response['totals'][$idx] = $this->model->_Admin->getColumnTotal($fields['fields'][$idx], $where);
							}

							$this->respond($response);
							break;
						case 'save':
							$data = $input['data'] ?? null;
							$sublists = $input['sublists'] ?? [];

							$versionLock = null;
							if (isset($input['version']) and is_numeric($input['version']))
								$versionLock = $input['version'];

							$newId = $this->model->_Admin->save($id, $data, $sublists, $versionLock);

							$this->respond(['id' => $newId]);
							break;
						case 'save-many':
							foreach (($input['create'] ?? []) as $item)
								$this->model->_Admin->save(0, $item);

							foreach (($input['update'] ?? []) as $id => $item)
								$this->model->_Admin->save($id, $item);

							foreach (($input['delete'] ?? []) as $id)
								$this->model->_Admin->delete($id);

							$this->respond(['success' => true]);
							break;
						case 'delete':
							$ids = $input['ids'] ?? [];

							$this->model->_Db->beginTransaction();

							foreach ($ids as $id)
								$this->model->_Admin->delete($id);

							$this->model->_Db->commit();

							$this->respond(['deleted' => $ids]);
							break;
						case 'duplicate':
							$element = $this->model->_Admin->getElement($id);
							if (!$element or !$element->exists())
								$this->model->error('Error: attempting to duplicate a non existing element.');

							$newElement = $element->duplicate();
							$this->respond(['id' => $newElement['id']]);
							break;
						case 'change-order':
							$element = $this->model->_Admin->getElement($id);
							if (!$element or !$element->exists())
								$this->model->error('Error: attempting to change order to a non existing element.');

							$to = $input['to'] ?? null;
							if (!$to or !is_numeric($to))
								$this->model->error('Bad parameters');

							if ($element->changeOrder($to))
								$this->respond(['success' => true]);
							else
								throw new \Exception('Error while changing order');
							break;
						default:
							$this->customAction($action, $id, $input);
							break;
					}
					break;
				default:
					$this->model->error('Unknown action', ['code' => 400]);
					break;
			}
		} catch (\Exception $e) {
			if ($this->model->_Db->inTransaction())
				$this->model->_Db->rollBack();
			$this->respond(['error' => getErr($e)], (int)$e->getCode());
		} catch (\Error $e) {
			if ($this->model->_Db->inTransaction())
				$this->model->_Db->rollBack();
			$this->respond(['error' => $e->getMessage() . ' in file ' . $e->getFile() . ' at line ' . $e->getLine()], 500);
		}
	}

	/**
	 * Returns API request
	 *
	 * @return array|null
	 */
	private function getInput(): ?array
	{
		$body = file_get_contents('php://input');
		if (empty($body))
			return null;

		$body = json_decode($body, true);
		if ($body === null)
			$this->respond(['error' => 'Invalid request syntax'], 400);

		return $body;
	}

	/**
	 * @param array $response
	 * @param int $code
	 */
	private function respond(array $response, int $code = 200)
	{
		if ($code <= 0)
			$code = 500;

		http_response_code($code);

		echo json_encode($response);
		die();
	}

	/**
	 * @param string|null $action
	 * @param int|null $id
	 * @param array $input
	 */
	private function customAction(?string $action = null, ?int $id = null, array $input = [])
	{
		if ($action === null)
			$this->model->error('No action provided', ['code' => 400]);

		if (!$this->model->_Admin->page)
			$this->model->error('No admin page loaded', ['code' => 500]);

		$action = str_replace(' ', '', lcfirst(ucwords(str_replace('-', ' ', $action))));

		if (method_exists($this->model->_Admin->page, $action)) {
			$element = $id !== null ? $this->model->_Admin->getElement($id) : null;
			if ($id !== null and !$element)
				$this->model->error('Element does not exist.');

			$response = $this->model->_Admin->page->{$action}($input, $element);
			$this->respond($response);
		} else {
			if ($this->model->_Db->inTransaction())
				$this->model->_Db->rollBack();

			$this->model->error('Unrecognized action', ['code' => 400]);
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
			if (($p['page'] ?? null) and !$this->model->_Admin->canUser('L', $p['page']))
				continue;
			$cleanPages[] = [
				'name' => $p['name'] ?? '',
				'path' => $p['rule'] ?? null,
				'direct' => $p['direct'] ?? null,
				'sub' => $this->cleanPages($p['sub'] ?? []),
			];
		}
		return $cleanPages;
	}

	/**
	 * @param string $path
	 */
	private function loadRequest(string $path)
	{
		$mainRequest = implode('/', $this->model->getRequest());
		$request = substr($mainRequest, strlen($path));
		if ($request[0] === '/')
			$request = substr($request, 1);
		$this->request = explode('/', $request);
	}
}
