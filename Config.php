<?php namespace Model\Admin;

use Model\Core\Module_Config;

class Config extends Module_Config
{
	/**
	 * @param array $data
	 * @return mixed
	 */
	public function install(array $data = []): bool
	{
		$this->model->_Db->query('CREATE TABLE IF NOT EXISTS `admin_privileges`(
			  `id` INT NOT NULL AUTO_INCREMENT,
			  `path` VARCHAR(250) NOT NULL,
			  `user` INT NOT NULL,
			  `group` VARCHAR(250) NULL,
			  `page` VARCHAR(250) NULL,
			  `C` TINYINT NOT NULL,
			  `C_special` VARCHAR(250) NULL,
			  `R` TINYINT NOT NULL,
			  `R_special` VARCHAR(250) NULL,
			  `U` TINYINT NOT NULL,
			  `U_special` VARCHAR(250) NULL,
			  `D` TINYINT NOT NULL,
			  `D_special` VARCHAR(250) NULL,
			  `L` TINYINT NOT NULL,
			  `L_special` VARCHAR(250) NULL,
			  PRIMARY KEY (`id`)) ENGINE = InnoDB;');

		return true;
	}
}
