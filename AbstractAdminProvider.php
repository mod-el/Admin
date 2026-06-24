<?php namespace Model\Admin;

use Model\ProvidersFinder\AbstractProvider;

abstract class AbstractAdminProvider extends AbstractProvider
{
	public static function getAdditionalPages(): array
	{
		return [];
	}
}
