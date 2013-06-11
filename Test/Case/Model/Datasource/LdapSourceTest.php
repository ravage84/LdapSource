<?php
/**
 * Ldap Datasource Test file
 *
 * PHP versions 4 and 5
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright 2005-2010, Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2005-2010, Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @package       datasources
 * @subpackage    datasources.tests.cases.models.datasources
 * @since         CakePHP Datasources v 0.3
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

App::uses('LdapSource', 'Datasources.Model/Datasource');
App::uses('ConnectionManager', 'Model');

/**
 * Ldap Testing Model
 *
 */
class LdapTestModel extends CakeTestModel {

/**
 * Database Configuration
 *
 * @var string
 */
	public $useDbConfig = 'test_ldap';

/**
 * Set recursive
 *
 * @var integer
 */
	public $recursive = -1;

}


/**
 * Ldap Datasource Test
 *
 */
class LdapSourceTest extends CakeTestCase {

/**
 * Ldap Source Instance
 *
 * @var LdapSource
 */
	public $Ldap = null;

/**
 * Mock class name
 *
 * @var string
 */
	public $mockClass = null;

/**
 * Set up for Tests
 *
 * @return void
 */
	public function setUp() {
		parent::setUp();
		// Add new db config
		$config = array(
			'login' => 'test_user',
			'password' => 'test_pasword'
		);
		$this->Ldap = $this->getMock('LdapSource', array('_call', 'log'), array($config));
	}

/**
 * Drop down for Tests
 *
 * @return void
 */
	public function tearDown() {
		ConnectionManager::drop('_transactionServiceMock');
		parent::tearDown();
	}


/**
 * testConnect
 *
 * @return void
 */
	public function testConnect() {
		$this->Ldap->expects($this->any())
			->method('_call')
			->will($this->returnValue(true));
		$result = $this->Ldap->connect();
		$this->assertTrue($result);
	}

	public function testRead() {
	}

}
