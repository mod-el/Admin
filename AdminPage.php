<?php namespace Model\Admin;

use Model\Core\Core;
use Model\ORM\Element;

class AdminPage
{
	public function __construct(protected Core $model)
	{
	}

	public function options(): array
	{
		return [];
	}

	public function customize()
	{
	}

	public function visualizerOptions(): array
	{
		return [];
	}

	public function warnings(Element $element): array
	{
		return [];
	}

	/**
	 * @param Element $element
	 * @param array $data
	 */
	public function beforeSave(Element $element, array &$data)
	{
	}

	/**
	 * @param Element $element
	 * @param array $saving
	 */
	public function afterSave(Element $element, array $saving)
	{
	}

	/**
	 * @param Element $element
	 * @return bool
	 */
	public function beforeDelete(Element $element): bool
	{
		return true;
	}

	/**
	 * @param int $id
	 * @param Element $element
	 */
	public function afterDelete(int $id, Element $element)
	{
	}

	/**
	 * @param array $init_data
	 * @return array
	 */
	public function initData(array $init_data = []): array
	{
		return $init_data;
	}
}
