<?php namespace Model\Admin;

use Model\Core\Controller;

class AdminLoginController extends Controller {
	function index(){
		$this->model->_Admin->init(false);
		$user = $this->model->getModule('User', 'Admin');

		if(!$this->model->isCLI()){
			$config = $this->model->_Admin->retrieveConfig();
			if(!$config['template'])
				$this->model->error('No template module was defined in the configuration.');

			$templateModule = $this->model->load($config['template']);
			$this->viewOptions = array_merge($this->viewOptions, $templateModule->getViewOptions($config));
		}

		switch($this->model->_Admin->request[0]){
			case 'login':
				if($user->logged()){
					$this->model->redirect($this->model->_Admin->getUrlPrefix());
					die();
				}

				if($this->model->isCLI()){
					$handle = fopen ("php://stdin","r");
					echo "Effettua il login:\nUsername: ";
					$username = trim(fgets($handle));
					echo "Password: ";
					$password = trim(fgets($handle));
					if($user->login($username, $password)){
						echo "Login effettuato con successo!\n---------------------\n";
						$this->model->redirect($this->model->getInput('redirect'));
					}else{
						echo "Dati errati.\n";
					}
					fclose($handle);
					die();
				}

				if(isset($_POST['username'], $_POST['password'])){
					if($user->login($_POST['username'], $_POST['password'])){
						$this->model->redirect($this->model->_Admin->getUrlPrefix());
					}else{
						$this->viewOptions['errors'][] = 'Wrong data';
					}
				}
				break;
			case 'logout':
				$user->logout();
				$this->model->redirect($this->model->getUrl('AdminLogin'));
				break;
		}
	}
}
