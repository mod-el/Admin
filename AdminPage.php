<?php namespace Model\Admin;

use Model\Core\Core;
use Model\ORM\Element;

class AdminPage
{
	/** @var Core */
	protected $model;

	public function __construct(Core $model)
	{
		$this->model = $model;
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
}
