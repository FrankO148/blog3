<?php
App::uses('Controller', 'Controller');
App::uses('ComponentCollection', 'Controller');
App::uses('AclComponent', 'Controller/Component');

class GroupShell extends Shell {

	public $uses = array('Group');

/**
 * Controller.
 *
 * Used to initialize AclComponent.
 */
	private $__Controller = null;

/**
 * AclComponent.
 */
	private $__Acl = null;

/**
 * Permissions.
 */
	private $__permissions = array();

/**
 * Initializes the Shell.
 *
 * @return void
 * @link http://book.cakephp.org/2.0/en/console-and-shells.html#Shell::initialize
 */
	public function initialize() {
		parent::initialize();

		$this->__Controller = new Controller();
		$this->__Acl = new AclComponent(new ComponentCollection());
		$this->__Acl->initialize($this->__Controller);

		$this->__permissions = Configure::read('Acl.permissions');

		$this->stdout->styles('allow', array(
			'text' => 'green',
			'bold' => true
		));
		$this->stdout->styles('deny', array(
			'text' => 'red',
			'bold' => true
		));
	}

/**
 * Add a new group.
 *
 * 1. Populate Group.
 * 2. Regenerate AROs.
 */
	public function add() {
		if (empty($this->args)) {
			$this->error('Missing arguments.');
		}

		$newGroups = array_unique($this->args);
		$oldGroups = $this->Group->find('list');

		$newGroups = array_diff($newGroups, $oldGroups);
		if (empty($newGroups)) {
			$this->error('Groups already exist.');
		}

		foreach ($newGroups as $v) {
			$this->Group->create(array('name' => $v));
			if ($this->Group->save()) {
				$out = 'Added group: ' . $v;
			} else {
				$out = '<warning>Something went wrong with group: ' . $v . '</warning>';
			}
			$this->out($out);
		}
	}

/**
 * Clear ACOs & ARO/ACO relationship.
 *
 * 1. Clear AROs
 * 2. Clear ACOs
 * 3. Clear ARO/ACO relationship.
 */
	public function clearAll() {
		if ($this->__clearTable($this->__Acl->Aro)) {
			$this->out('<comment>Cleared AROs.</comment>');
		}
		if ($this->__clearTable($this->__Acl->Aco)) {
			$this->out('<comment>Cleared ACOs.</comment>');
		}
		if ($this->__clearTable($this->__Acl->Aco->Permission)) {
			$this->out('<comment>Cleared ARO/ACO relationship.</comment>');
		}
	}

/**
 * Update ACL data.
 *
 * Intended to be executed after going to a working environment.
 *
 * 1. Update AROs
 * 2. Update ACOs
 * 3. Update ARO/ACO relationship.
 */
	public function updateAll() {
		$this->updateAros(false);
		$this->updateAcos(false);
		$this->updatePermissions();
	}

/**
 * Update AROs.
 *
 * 1. Clear AROs
 * 2. Populate AROs.
 * 3. Update ARO/ACO relationship [optional].
 *
 * @param boolean $updatePermissions Should update ARO/ACO relationship
 */
	public function updateAros($updatePermissions = true) {
		$groups = $this->Group->find('list');
		if (empty($groups)) {
			$this->error('No Groups found.');
		}

		$this->__clearTable($this->__Acl->Aro); // TODO is this needed?¿

		$AclBehavior = $this->Group->Behaviors->Acl;

		foreach ($groups as $k => $v) {
			$this->Group->id = $k;
			$AclBehavior->afterSave($this->Group, true);
			$this->out('Update group: <success>' . $v . '</success>');
		}

		$this->out(sprintf('<comment>%s update completed.</comment>', $this->__Acl->Aro->name));
		$this->out();

		if ($updatePermissions) {
			$this->updatePermissions();
		}
	}

/**
 * Update ACOs.
 *
 * 1. Clear ACOs
 * 2. Populate ACOs.
 * 3. Update ARO/ACO relationship [optional].
 *
 * @param boolean $updatePermissions Should update ARO/ACO relationship
 * @TODO Should ACOs be cleared before calling AclExtras.AclExtras aco_sync ?¿
 */
	public function updateAcos($updatePermissions = true) {
		$this->__clearTable($this->__Acl->Aco);
		$this->dispatchShell('AclExtras.AclExtras aco_sync');

		$this->out(sprintf('<comment>%s update completed.</comment>', $this->__Acl->Aco->name));
		$this->out();

		if ($updatePermissions) {
			$this->updatePermissions();
		}
	}
/**
 * Update permissions.
 *
 * Rebuild ARO/ACO relationship.
 */
	public function updatePermissions() {
		if (empty($this->__permissions)) {
			$this->error('No permissions set.');
		}

		$groups = $this->Group->find('list', array('fields' => array('name', 'id')));
		if (empty($groups)) {
			$this->error('No Groups found.');
		}

		$this->__clearTable($this->__Acl->Aco->Permission);

		$_permissions = array();
		foreach ($this->__permissions as $group => $rules) {
			if (!is_string($group) || empty($groups[$group])) {
				continue;
			}
			if (!is_array($rules) || empty($rules)) {
				continue;
			}
			$_permissions[] = sprintf('[%s]', $group);
			foreach ($rules as $rule) {
				$permission = mb_strtolower(key($rule));
				if (!is_string($permission) || !in_array($permission, array('allow', 'deny'))) {
					continue;
				}
				$acos = $rule[$permission];
				if (is_string($acos)) {
					$acos = array($acos);
				}
				$type = $permission == 'allow' ? 'allow' : 'deny';
				$symbol = $permission == 'allow' ? '[+]' : '[-]';
				foreach ($acos as $aco) {
					if (!is_string($aco) || empty($aco)) {
						continue;
					}
					$this->Group->id = $groups[$group];
					$this->__Acl->{$permission}($this->Group, $aco);
					$_permissions[] = sprintf('  <%1$s>%2$s</%1$s> %3$s', $type, $symbol, $aco);
				}
			}
		}

		$this->out(implode("\n", $_permissions));
		$this->out(sprintf('<comment>%s update completed.</comment>', $this->__Acl->Aco->Permission->name));
		$this->out();
	}

/**
 * SQL syntax used to clear the table used by a model.
 *
 * Note: MySQL TRUNCATE is not used because it calls DROP and CREATE, which requires a Mysql user with higher priviledges.
 *
 * @param Model $Model
 * @return boolean
 * @see Model::query()
 */
	private function __clearTable($Model) {
		$tableName = $Model->getDataSource()->fullTableName($Model);

		$clearQuery = <<<QUERY
DELETE FROM {$tableName};
ALTER TABLE {$tableName} AUTO_INCREMENT = 1;
QUERY;

		$Model->query($clearQuery);
		$empty = !$Model->find('count');

		$type = $empty ? 'success' : 'error';
		$this->out(sprintf('<%1$s>%2$s clear completed.</%1$s>', $type, $Model->name));

		return $empty;
	}
}
