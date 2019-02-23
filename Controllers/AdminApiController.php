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
					if ($id !== null and (!is_numeric($id) or $id <= 0))
						$this->model->error('Id should be a number greater than 0', ['code' => 400]);

					switch ($action) {
						case null:
							$response = $this->model->_Admin->getPageDetails();
							$this->respond($response);
							break;
						case 'data':
							$response = $this->model->_Admin->getElementData();
							$this->respond($response);
							break;
						default:
							$this->model->error('Unrecognized action', ['code' => 400]);
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
		$request = $this->request[0] ?? '';
		try {
			switch ($request) {
				case 'user':
					$subrequest = $this->request[1] ?? null;
					switch ($subrequest) {
						case 'login':
							$path = $this->model->getInput('path');
							$user = $this->model->_Admin->loadUserModule($path);

							if ($id = $user->login($this->model->getInput('username'), $this->model->getInput('password'), false)) {
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
		if ($request{0} === '/')
			$request = substr($request, 1);
		$this->request = explode('/', $request);
	}
}
