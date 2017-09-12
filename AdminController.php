<?php
namespace Model;

class AdminController extends Controller {
	protected $options = [
		'table' => false,
	];

	protected function options(){}
	protected function customize(){}

	public function init(){
		$this->viewOptions['cache'] = false;
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
				$templateViewOptions = $templateModule->getViewOptions($config);
				if($this->viewOptions['template']!==false)
					unset($templateViewOptions['template']);
				$this->viewOptions = array_merge($this->viewOptions, $templateViewOptions);
				$this->model->_Admin->template = $templateModule;
			}

			$request = $this->model->_Admin->request;
			if (isset($request[0]) and $request[0]) {
				if (!isset($request[1]))
					$request[1] = '';

				switch ($request[1]) {
					case '':
						if(!$this->model->_Admin->canUser('L', false, $this->model->element))
							$this->model->error('You have not the permissions to view this page.');

						if(!(isset($this->options['table']) and $this->options['table']) and !(isset($this->options['element']) and $this->options['element']))
							break;

						$sId = $this->model->_Admin->getSessionId();
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

						$list['sId'] = $sId;
						$list['actions'] = $this->model->_Admin->getActions();

						if ($this->model->isCLI()) {
							$this->model->sendJSON($list);
						} else {
							$this->viewOptions = array_merge($this->viewOptions, $templateModule->respond($request, $list));
						}
						break;
					case 'delete':
						try {
							if (!checkCsrf() or !isset($_GET['id']))
								$this->model->error('Missing data');
							$ids = explode(',', $_GET['id']);

							$this->model->_Db->beginTransaction();

							foreach ($ids as $id) {
								$element = new $this->model->_Admin->options['element']($id, [
									'table' => $this->model->_Admin->options['table'],
									'model' => $this->model,
									'primary' => $this->model->_Admin->options['primary'],
								]);

								$this->model->_Admin->form = $element->getForm();
								$this->customize();

								if (!$this->model->_Admin->canUser('D', false, $element))
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
						}
						break;
					case 'save':
						try {
							if (!checkCsrf() or !isset($_POST['data']))
								$this->model->error('Dati errati');
							$data = json_decode($_POST['data'], true);
							if ($data === null)
								$this->model->error('Dati errati');
							if(!$this->model->element)
								$this->model->error('Element does not exist');

							if (!$this->model->_Admin->canUser('U', false, $this->model->element))
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
								$id = $this->model->_Admin->saveElement($data);
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
			}
		} catch (\Exception $e) {
			die(getErr($e));
		}
	}

	protected function beforeDelete(Element $element){
		return true;
	}

	protected function afterDelete($id, Element $element){}
}
