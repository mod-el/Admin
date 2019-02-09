<?php namespace Model\Admin\Controllers;

use Model\Core\Controller;

class AdminApiController extends Controller
{
	public function init()
	{
		try {
			$token = $this->model->getInput('token');
			if (($this->model->getRequest(1) ?? '') !== 'user' and !in_array($token, $_SESSION['admin-auth-tokens'] ?? []))
				$this->model->error('Unauthorized', ['code' => 401]);

			$this->model->_AdminFront->initialize($this->model->getRequest(2) ?? null, $this->model->getRequest(3) ?? null);
		} catch (\Exception $e) {
			$this->respond(['error' => getErr($e)], (int)$e->getCode());
		} catch (\Error $e) {
			$this->respond(['error' => $e->getMessage()], 500);
		}
	}

	public function get()
	{
		$request = $this->model->getRequest(1) ?? '';
		try {
			switch ($request) {
				case 'pages':
					$pages = $this->model->_AdminFront->getPages();
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
				case 'user':
					$subrequest = $this->model->getRequest(2) ?? null;
					switch ($subrequest) {
						case 'logout':
							$this->model->_User_Admin->logout();
							setcookie('admin-user', '', 0, $this->model->_AdminFront->getUrlPrefix());
							$this->respond([]);
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
			$this->respond(['error' => $e->getMessage()], 500);
		}
	}

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

							if ($id = $user->login($this->model->getInput('username'), $this->model->getInput('password'))) {
								$token = $this->model->_JWT->build([
									'path' => $path,
									'id' => $id,
								]);
								$this->respond(['token' => $token]);
							} else {
								$this->model->error('Wrong username or password', ['code' => 401]);
							}
							break;
						case 'auth':
							$token = $this->model->getInput('token');
							if (!$token)
								$this->model->error('Token not provided', ['code' => 401]);

							try {
								$decodedToken = $this->model->_JWT->verify($token);
							} catch (\Exception $e) {
								$decodedToken = null;
							}

							if ($decodedToken and isset($decodedToken['id'], $decodedToken['path'])) {
								if (!isset($_SESSION['admin-auth-tokens']))
									$_SESSION['admin-auth-tokens'] = [];
								if (!in_array($token, $_SESSION['admin-auth-tokens']))
									$_SESSION['admin-auth-tokens'][] = $token;

								$usernameColumn = $this->model->_User_Admin->getUsernameColumn();
								$this->respond([
									'username' => $this->model->_User_Admin->get($usernameColumn),
								]);
							} else {
								$this->model->error('Invalid auth token', ['code' => 401]);
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
			$this->respond(['error' => $e->getMessage()], 500);
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
