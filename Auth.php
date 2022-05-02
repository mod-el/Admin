<?php namespace Model\Admin;

use Model\Core\Core;
use Model\JWT\JWT;

class Auth
{
	/** @var Core */
	private $model;

	/**
	 * Auth constructor.
	 *
	 * @param Core $model
	 */
	public function __construct(Core $model)
	{
		$this->model = $model;
	}

	/**
	 * @return array|null
	 */
	public function getToken(): ?array
	{
		$token = $this->getAccessToken();
		if (!$token)
			return null;

		try {
			$token = JWT::verify($token);
			if (!isset($token['id'], $token['path']))
				$token = null;
		} catch (\Exception $e) {
			$token = null;
		}

		return $token;
	}

	/**
	 * @return string|null
	 */
	private function getAccessToken(): ?string
	{
		$header = null;
		if (function_exists('apache_request_headers')) {
			$requestHeaders = apache_request_headers();
			// Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization)
			$requestHeaders = array_combine(array_map('strtolower', array_keys($requestHeaders)), array_values($requestHeaders));

			if (isset($requestHeaders['x-access-token']))
				$header = trim($requestHeaders['x-access-token']);
		}

		return $header;
	}
}
