<?php namespace Model\Admin;

use Model\Exporter\DataProvider;

class ExportProvider implements DataProvider
{
	public static string $name = 'Semplice';
	protected array $searchOptions;

	public function __construct(protected readonly Admin $adminModule, protected readonly array $exportPayload, protected readonly array $searchPayload)
	{
		$searchQuery = $this->adminModule->makeSearchQuery(
			$this->searchPayload['search'] ?? '',
			$this->searchPayload['filters'] ?? [],
			$this->searchPayload['search-fields'] ?? []
		);

		$this->searchOptions = [
			'where' => $searchQuery['where'],
			'joins' => $searchQuery['joins'],
			'sortBy' => $this->searchPayload['sort-by'] ?? [],
		];
	}

	public function getHeader(): array
	{
		$header = [];
		foreach ($this->getColumns() as $column)
			$header[] = $column['label'];

		return $header;
	}

	public function getTot(int $paginate): int
	{
		$list = $this->getAdminList($paginate, 1);
		return $list['pages'];
	}

	public function getNext(int $paginate, int $current): \Generator
	{
		$list = $this->getAdminList($paginate, $current);

		$columns = $this->getColumns();

		foreach ($list['list'] as $item) {
			$cells = [];

			foreach ($columns as $column) {
				$itemColumn = $this->adminModule->getElementColumn($item['element'], $column);

				$itemKey = $this->exportPayload['data_key'] ?? 'text';
				$cellValue = $itemColumn ? $itemColumn[$itemKey] : '';
				if ($itemKey === 'text')
					$cellValue = html_entity_decode($cellValue, ENT_QUOTES, 'UTF-8');

				$cell = [
					'value' => $cellValue,
				];

				if (!empty($itemColumn['background']))
					$cell['background'] = $itemColumn['background'];
				if (!empty($itemColumn['color']))
					$cell['color'] = $itemColumn['color'];

				$cells[] = $cell;
			}

			$row = [
				'cells' => $cells,
			];

			if (!empty($item['background']))
				$row['background'] = $item['background'];
			if (!empty($item['color']))
				$row['color'] = $item['color'];

			yield $row;
		}
	}

	protected function getAdminList(int $paginate, int $current): array
	{
		$tmpOptions = $this->searchOptions;
		$tmpOptions['perPage'] = $paginate;
		$tmpOptions['p'] = $current;
		return $this->adminModule->getList($tmpOptions);
	}

	protected function getColumns(): array
	{
		$totalFields = $this->adminModule->getColumnsList();
		$columnNames = (count($this->searchPayload['fields'] ?? []) > 0) ? $this->searchPayload['fields'] : $totalFields['default'];

		$columns = [];
		foreach ($columnNames as $columnName) {
			if (isset($totalFields['fields'][$columnName]))
				$columns[$columnName] = $totalFields['fields'][$columnName];
		}

		return $columns;
	}
}
