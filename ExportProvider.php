<?php namespace Model\Admin;

use Model\Exporter\DataProvider;

class ExportProvider implements DataProvider
{
	private array $searchOptions;

	public function __construct(private readonly Admin $adminModule, private readonly array $exportPayload, private readonly array $searchPayload)
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
		$tmpOptions = $this->searchOptions;
		$tmpOptions['perPage'] = $paginate;
		$list = $this->adminModule->getList($tmpOptions);
		return $list['pages'];
	}

	public function getNext(int $paginate, int $current): iterable
	{
		$tmpOptions = $this->searchOptions;
		$tmpOptions['perPage'] = $paginate;
		$list = $this->adminModule->getList($tmpOptions);

		$columns = $this->getColumns();

		$exported = [];
		foreach ($list['list'] as $item) {
			$row = [];

			foreach ($columns as $columnId => $column) {
				$itemColumn = $this->adminModule->getElementColumn($item['element'], $column);
				$row[$columnId] = $itemColumn ? $itemColumn[$this->exportPayload['data_key'] ?? 'text'] : '';
			}

			$exported[] = array_values($row);
		}

		return $exported;
	}

	private function getColumns(): array
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
