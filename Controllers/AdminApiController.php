<?php namespace Model\Admin\Controllers;

use Model\Admin\Auth;
use Model\Core\Controller;

class AdminApiController extends Controller
{
	/** @var array */
	private $token = null;

	public function init()
	{
		if ($this->model->getRequest(1) !== 'user' or $this->model->getRequest(2) !== 'login') {
			$auth = new Auth($this->model);
			$this->token = $auth->getToken();
			if (!$this->token)
				$this->model->error('Invalid auth token', ['code' => 401]);
		}
	}

	/**
	 * @return mixed|void
	 */
	public function get()
	{
		$request = $this->model->getRequest(1) ?? '';
		try {
			switch ($request) {
				case 'user':
					$subrequest = $this->model->getRequest(2) ?? null;
					switch ($subrequest) {
						case 'auth':
							// Token was already loaded in the "init" method
							$user = $this->model->_Admin->loadUserModule($this->token['path']);

							$user->directLogin($this->token['id'], false);
							$usernameColumn = $user->getUsernameColumn();
							$this->respond([
								'id' => $this->token['id'],
								'username' => $user->get($usernameColumn),
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
				case 'get':
					$adminPage = $this->model->getRequest(2) ?? null;
					if (!$adminPage)
						$this->model->error('No page name defined', ['code' => 400]);
					$id = $this->model->getRequest(3) ?? null;
					if (!$id or !is_numeric($id) or $id < 1)
						$this->model->error('Id should be a number greater than 0', ['code' => 400]);

					$arr = $this->model->_Admin->getEditArray();
					$this->respond($arr);
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
		$request = $this->model->getRequest(1) ?? '';
		try {
			switch ($request) {
				case 'user':
					$subrequest = $this->model->getRequest(2) ?? null;
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
}
