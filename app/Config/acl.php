<?php
/**
 * Acl.
 *
 * Permissions are set in the fashion:
 *   'groupName' => array(
 *     array('allow' => array(
 *       'controllers/ControllerA',
 *       'controllers/ControllerB'
 *     )),
 *     array('allow' => array(
 *       'controllers/ControllerB/action1'
 *     )),
 *     array('deny' => array(
 *       'controllers/ControllerA/action2'
 *     )),
 *   )
 *
 * The recommended order is:
 * - Allow Controllers
 * - Allow Actions
 * - Deny Actions
 */
$config['Acl'] = array(
	'permissions' => array(
		'Admins' => array(
			array('allow' => 'controllers')
		),
		'Users' => array(
			array('allow' => array(
				'controllers/Posts',
				'controllers/Users/index'
			)),
			array('deny' => array(
				
			))
		),
		'Managers' => array(
			array('allow' => array(
				'controllers/Users',
				'controllers/Posts'
			))
		)
	)
);
?>