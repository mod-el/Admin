<?php namespace Model\Admin;

use Model\Core\Core;

class AdminRest
{
	public function __construct(private Core $model, private string $pageName)
	{
		$this->model->_Admin->setPage($this->pageName);
	}

	public function list(array $query = []): array
	{
		$list = $this->model->_Admin->getList([
			'p' => (int)($query['page'] ?? 1),
			'perPage' => (int)($query['per_page'] ?? 20),
			'sortBy' => $query['sort_by'] ?? [],
			'rest' => true,
		]);

		$response = [
			'tot' => $list['tot'],
			'pages' => $list['pages'],
			'page' => $list['page'],
			'list' => [],
		];

		foreach ($list['list'] as $item) {
			$itemData = [
				'id' => $item['element'][$item['element']->settings['primary']],
			];
			$form = $this->model->_Admin->getForm($item['element']);
			foreach ($form->getDataset() as $k => $d) {
				$itemData[$k] = $d->getJsValue(false);
				if ($d->options['type'] === 'checkbox')
					$itemData[$k] = (bool)$itemData[$k];
			}

			$response['list'][] = $itemData;
		}

		return $response;
	}

	public function get(int $id): array
	{
		$adminResponse = $this->model->_Admin->getElementData($id);

		$response = [];
		foreach ($adminResponse['data'] as $k => $v) {
			$response[$k] = $v;
			if (isset($adminResponse['fields'], $adminResponse['fields'][$k]) and $adminResponse['fields'][$k]['type'] === 'checkbox')
				$response[$k] = (bool)$v;
		}

		foreach ($adminResponse['sublists'] as $sublist) {
			$response[$sublist['name']] = [];
			foreach ($sublist['list'] as $subItem)
				$response[$sublist['name']][] = ['id' => $subItem['id'], ...$subItem['data']];
		}

		return $response;
	}

	public function save(int $id, array $data): int
	{
		return $this->model->_Admin->save($id, $data);
	}

	public function delete(int $id): void
	{
		$this->model->_Admin->delete($id);
	}
}
