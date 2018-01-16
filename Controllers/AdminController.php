<?php namespace Model\Admin\Controllers;

use Model\Core\Controller;
use Model\ORM\Element;

class AdminController extends Controller {
	protected $options = [
		'table' => null,
	];

	protected function options(){}

	protected function customize(){}

	/**
	 * @throws \Model\Core\Exception
	 */
	public function init(){
		if(!$this->model->isLoaded('Admin'))
			$this->model->error('Admin controllers can be accessed only through Admin module.');

		$this->options();
		$this->model->_Admin->init($this->options);

		if($this->model->_Admin->form)
			$values = $this->model->_Admin->form->getValues();

		$this->customize();

		if($this->model->_Admin->form)
			$this->model->_Admin->form->setValues($values);
	}

	public function index(){
		try {
			$config = $this->model->_Admin->retrieveConfig();
			if (!$this->model->isCLI()) {
				if (!$config['template'])
					$this->model->error('No template module was defined in the configuration.');

				$templateModule = $this->model->load($config['template']);
				$this->model->_Admin->template = $templateModule;
			}

			$request = $this->model->_Admin->request;
			if (isset($request[0]) and $request[0]) {
				if (!isset($request[1]))
					$request[1] = '';

				switch ($request[1]) {
					case '':
						if(!$this->model->_Admin->canUser('L'))
							$this->model->error('You have not the permissions to view this page.');

						$sId = $this->model->_Admin->getSessionId();

						if((isset($this->options['table']) and $this->options['table']) or (isset($this->options['element']) and $this->options['element'])){
							$options = $this->model->_Admin->getListOptions($sId);

							if ($this->model->getInput('p'))
								$options['p'] = (int)$this->model->getInput('p');

							if ($this->model->getInput('nopag')){
								$options['p'] = 1;
								$options['perPage'] = 0;
							}else{
								$options['perPage'] = isset($this->options['perPage']) ? $this->options['perPage'] : 20;
							}

							if ($this->model->getInput('filters')) {
								$options['filters'] = json_decode($this->model->getInput('filters'), true);
								if (!$options['filters'])
									$options['filters'] = [];
							}

							if ($this->model->getInput('search-columns')) {
								$options['search-columns'] = json_decode($this->model->getInput('search-columns'), true);
								if (!$options['search-columns'])
									$options['search-columns'] = [];
							}

							if ($this->model->getInput('sortBy')) {
								$options['sortBy'] = json_decode($this->model->getInput('sortBy'), true);
								if (!$options['sortBy'])
									$options['sortBy'] = [];
							}

							$this->model->_Admin->setListOptions($sId, $options);

							$list = $this->model->_Admin->getList($options);
						}else{
							$list = [];
						}

						$list['sId'] = $sId;
						$list['actions'] = $this->model->_Admin->getActions();

						if ($this->model->isCLI()) {
							$this->model->sendJSON($list);
						} else {
							$templateViewOptions = $templateModule->respond($request, $list);
							if($this->viewOptions['template']){
								unset($templateViewOptions['template']);
								unset($templateViewOptions['template-module']);
							}
							$this->viewOptions = array_merge($this->viewOptions, $templateViewOptions);
						}
						break;
					case 'delete':
						try {
							if (!$this->model->_CSRF->checkCsrf() or !isset($_GET['id']))
								$this->model->error('Missing data');
							$ids = explode(',', $_GET['id']);

							$this->model->_Db->beginTransaction();

							foreach ($ids as $id) {
								$element = $this->model->_ORM->one($this->model->_Admin->options['element'], $id, [
									'table' => $this->model->_Admin->options['table'],
									'primary' => $this->model->_Admin->options['primary'],
								]);

								$this->model->_Admin->form = $element->getForm();

								if (!$this->model->_Admin->canUser('D', null, $element))
									$this->model->error('Can\'t delete, permission denied.');

								if ($this->beforeDelete($element)) {
									if ($element->delete())
										$this->afterDelete($id, $element);
									else
										$this->model->error('Error while deleting.');
								}
							}

							$this->model->_Db->commit();

							$this->model->sendJSON(['deleted' => $ids]);
						} catch (\Exception $e) {
							$this->model->_Db->rollBack();
							$this->model->sendJSON(['err' => getErr($e)]);
						}
						break;
					case 'edit':
						if ($this->model->isCLI()) {
							$arr = $this->model->_Admin->getEditArray();
							$this->model->sendJSON($arr);
						}else{
							$templateViewOptions = $templateModule->respond($request);
							if($this->viewOptions['template']){
								unset($templateViewOptions['template']);
								unset($templateViewOptions['template-module']);
							}
							$this->viewOptions = array_merge($this->viewOptions, $templateViewOptions);
						}
						break;
					case 'save':
						try {
							if (!$this->model->_CSRF->checkCsrf() or !isset($_POST['data']))
								$this->model->error('Dati errati');
							$data = json_decode($_POST['data'], true);
							if ($data === null)
								$this->model->error('Dati errati');
							if(!$this->model->element)
								$this->model->error('Element does not exist');

							if (!$this->model->_Admin->canUser('U', null, $this->model->element))
								$this->model->error('Can\'t save, permission denied.');

							if(isset($_GET['instant'])){
								$instantIds = explode(',', $_GET['instant']);
								$changed = $this->model->_Admin->saveElementViaInstant($data, $instantIds);
								if ($changed!==false) {
									$changed = [
										'elements' => $changed,
										'columns' => $this->model->_Admin->getColumns(),
									];

									if(isset($templateModule)){
										$data = $templateModule->respond(['', ''], $changed);
										$changed = $data['data'];
									}

									foreach($changed['elements'] as &$row){
										unset($row['element']);
									}
									unset($row);

									$this->model->sendJSON([
										'status' => 'ok',
										'changed' => $changed['elements'],
									]);
								} else {
									$this->model->error('Error while saving');
								}
							}else{
								$versionLock = null;
								if(isset($_POST['version']) and is_numeric($_POST['version']))
									$versionLock = $_POST['version'];
								$id = $this->model->_Admin->saveElement($data, $versionLock);
								if ($id!==false) {
									$this->model->sendJSON([
										'status' => 'ok',
										'id' => $id,
									]);
								} else {
									$this->model->error('Error while saving');
								}
							}
						} catch (\Exception $e) {
							$this->model->sendJSON(['status' => 'err', 'err' => getErr($e)]);
						}
						break;
					case 'duplicate':
						try{
							if(!$this->model->element or !$this->model->element->exists())
								$this->model->error('Error: attempting to duplicate a non existing element.');

							$newElement = $this->model->element->duplicate();
							$this->model->redirect($this->model->_Admin->getUrlPrefix().$this->model->_Admin->request[0].'/edit/'.$newElement['id'].'?duplicated');
						} catch (\Exception $e) {
							$err = getErr($e);
							die($err);
						}
						break;
					default:
						if (!$this->model->isCLI() and method_exists($templateModule, $request[1])) {
							$this->viewOptions = array_merge($this->viewOptions, call_user_func([$templateModule, $request[1]]));
						} elseif (method_exists($this, $request[1])) {
							call_user_func([$this, $request[1]]);
						} else {
							if ($this->model->isCLI())
								die('Unknown action');
							else
								$this->viewOptions['errors'][] = 'Unknown action.';
						}
						break;
				}
			}else{
				$templateViewOptions = $templateModule->respond($request);
				$this->viewOptions = array_merge($this->viewOptions, $templateViewOptions);
			}
		} catch (\Exception $e) {
			die(getErr($e));
		}
	}

	/**
	 * @param Element $element
	 * @return bool
	 */
	protected function beforeDelete(Element $element){
		return true;
	}

	/**
	 * @param int $id
	 * @param Element $element
	 */
	protected function afterDelete($id, Element $element){}
}
