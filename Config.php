<?php namespace Model\Admin;

use Model\Core\Module_Config;

class Config extends Module_Config
{
	/**
	 * @param array $data
	 * @return mixed
	 */
	public function init(?array $data = null): bool
	{
		$this->model->_Db->query('CREATE TABLE IF NOT EXISTS `admin_privileges` (
			  `id` int(11) NOT NULL AUTO_INCREMENT,
			  `page` varchar(250) COLLATE utf8_unicode_ci DEFAULT NULL,
			  `subpage` varchar(250) COLLATE utf8_unicode_ci DEFAULT NULL,
			  `profile` int(11) DEFAULT NULL,
			  `user` int(11) DEFAULT NULL,
			  `C` tinyint(4) NOT NULL,
			  `C_special` varchar(250) NULL,
			  `R` tinyint(4) NOT NULL,
			  `R_special` varchar(250) NULL,
			  `U` tinyint(4) NOT NULL,
			  `U_special` varchar(250) NULL,
			  `D` tinyint(4) NOT NULL,
			  `D_special` varchar(250) NULL,
			  `L` TINYINT NOT NULL,
			  `L_special` VARCHAR(250) NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;');
		return true;
	}

	/**
	 * @return bool
	 */
	public function postUpdate_1_1_0()
	{
		$this->model->_Db->query('ALTER TABLE `admin_privileges` 
			DROP COLUMN `group`,
			DROP COLUMN `path`,
			CHANGE COLUMN `page` `page` VARCHAR(250) CHARACTER SET \'utf8\' COLLATE \'utf8_unicode_ci\' NULL DEFAULT NULL AFTER `id`,
			CHANGE COLUMN `user` `user` INT(11) NULL ,
			ADD COLUMN `profile` INT NULL AFTER `page` ,
			ADD COLUMN `subpage` VARCHAR(250) NULL AFTER `page`;');
		return true;
	}
}
