<?php
/**
 * LDAP Datasource
 *
 * Connect to LDAPv3 style datasource with full CRUD support.
 * Still needs HABTM support
 * Discussion at http://www.analogrithems.com/rant/2009/06/12/cakephp-with-full-crud-a-living-example/
 * Tested with OpenLDAP, Netscape Style LDAP {iPlanet, Fedora, RedhatDS} Active Directory.
 * Supports TLS, multiple ldap servers (Failover not, mirroring), Scheme Detection
 *
 * PHP 5
 *
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @package       Datasources
 * @since         CakePHP Datasources v 0.3
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */

App::uses('DataSource', 'Model/Datasource');

/**
 * Ldap Datasource
 *
 * @package Datasources
 * @todo move long description code to class comment block
 * @todo rewrite file header comment block
 * @todo move updated content from discussion to GitHub
 * @todo check for new mandatory/recommended properties to implement
 * @todo check for new mandatory/recommended methods to implement
 */
class LdapSource extends DataSource {

/**
 * DataSource description
 *
 * @var string
 */
	public $description = "LDAP DataSource";

/**
 * Cache Sources
 *
 * @var boolean
 * @todo check if still needed/useful
 */
	public $cacheSources = true;

	/**
	 * Print full query debug info?
	 *
	 * @var boolean
	 */
	public $fullDebug = false;

	/**
	 * String to hold how many rows were affected by the last SQL operation.
	 *
	 * @var string
	 */
	public $affected = null;

	/**
	 * Number of rows in current resultset
	 *
	 * @var integer
	 */
	public $numRows = null;

	/**
	 * Time the last query took
	 *
	 * @var integer
	 */
	public $took = null;

/**
 * A reference to the physical connection of this DataSource
 *
 * @var array
 */
	protected $_connection = null;

/**
 * Count
 *
 * @var integer
 * @todo remove/replace with dbosource equivalent
 */
	public $count = 0;

/**
 * Model
 *
 * @var mixed
 * @todo check if still needed/useful
 */
	public $model;

/**
 * Operational Attributes
 *
 * @var mixed
 */
	public $OperationalAttributes;

/**
 * Schema DN
 *
 * @var string
 * @todo check if still needed/useful
 */
	public $SchemaDN;

/**
 * Schema Attributes
 *
 * @var string
 * @todo check if still needed/useful
 */
	public $SchemaAtributes;

/**
 * Schema Filter
 *
 * @var string
 * @todo check if still needed/useful
 */
	public $SchemaFilter;

/**
 * Queries count.
 *
 * @var integer
 * @todo check if still needed/useful
 */
	protected $_queriesCnt = 0;

/**
 * Total duration of all queries.
 *
 * @var integer
 * @todo check if still needed/useful
 */
	protected $_queriesTime = null;

/**
 * Log of queries executed by this DataSource
 *
 * @var array
 * @todo check if still needed/useful
 */
	protected $_queriesLog = array();

/**
 * Maximum number of items in query log
 *
 * This is to prevent query log taking over too much memory.
 *
 * @var integer Maximum number of queries in the queries log.
 * @todo check if still needed/useful
 */
	protected $_queriesLogMax = 200;

/**
 * Result for formal queries
 *
 * @var mixed
 * @todo check if still needed/useful
 */
	protected $_result = false;

/**
 * The (default) DataSource configuration
 *
 * Our default config options. These options will be customized in our
 * ``app/Config/database.php`` and will be merged in ``__construct()``.
 *
 * @var array
 */
	public $config = array (
		'host' => 'localhost',
		'port' => 389,
		'tls' => false,
		'database' => '',
		'basedn' => '',
		'version' => 3
	);

/**
 * MultiMaster Use
 *
 * @var integer
 * @todo check if still needed/useful
 */
	protected $_multiMasterUse = 0;

/**
 * Descriptions
 *
 * @var array
 * @todo check if still needed/useful
 */
	protected $_descriptions = array();

/**
 * Results
 *
 * @var array
 * @todo check if still needed/useful
 */
	protected $_results = array();

/**
 * Constructor
 *
 * @param array $config Configuration
 * @todo check if still needed/useful
 * @todo replace debug & fullDebug by direct calls to Configure:read
 * @todo Insert Exception when PHP's ldap module is not active
 * @todo check what happens if type is empty
 */
	public function __construct($config = null) {
		parent::__construct($config);
		$this->fullDebug = Configure::read('debug') > 1;

		// Check if PHP's LDAP module is enabled
		if(!function_exists('ldap_connect')){
			// TODO insert Exception
		}

		$link = $this->connect(); // TODO we don't use $link

		// Set environment according to LDAP type
		if (isset($config['type']) && !empty($config['type'])) {
			switch($config['type']) {
				case 'Netscape':
					$this->setNetscapeEnv();
					break;
				case 'OpenLDAP':
					$this->setOpenLDAPEnv();
					break;
				case 'ActiveDirectory':
					$this->setActiveDirectoryEnv();
					break;
				default:
					$this->setNetscapeEnv();
					break;
			}
		}

		$this->setSchemaPath();
	}

/**
 * Closes the current datasource.
 *
 * @return void
 */
	public function __destruct() {
		$this->close();
	}

/**
 * Wrapper method to call ldap_* function. Hardcoded ldap functions make tests more difficult
 *
 * @return array user function
 * @todo might be true hardcoded ldap function calls are more difficult, but one function to rule them all?
 * @todo what about checking for errors and logging them here?
 */
	protected function _call() {
		$args = func_get_args();
		$funcName = 'ldap_' . array_shift($args);
		$result = call_user_func_array($funcName, $args);
		if (strtolower($funcName) === 'ldap_read') {
			$this->_results[] = $result;
		}
		return $result;
	}

/**
 * Field name
 *
 * This looks weird, but for LDAP we just return the name of the field thats passed as an argument.
 *
 * @param string $field Field name
 * @return string Field name
 * @author Graham Weldon
 * @todo check if still needed/useful
 */
	public function name($field) {
		return $field;
	}

/**
 * connect([$bindDN], [$bindPasswd])  create the actual connection to the ldap server
 * This function supports failover, so if your config['host'] is an array it will try the first one, if it fails,
 * jumps to the next and attempts to connect and so on. If will also check try to setup any special connection options
 * needed like referral chasing and tls support
 *
 * @param string the users dn to bind with
 * @param string the password for the previously state bindDN
 * @return boolean the status of the connection
 * @throws Exception when tls option was specified and starting tls was failed
 * @todo Move failover detection & iteration code to either construct or its own function
 * @todo connect should only what its name says, try to connect (+ bind), return success
 * @todo why not use a $config['failoverHosts'] array instead of all hosts in host?
 */
	public function connect($bindDN = null, $bindPasswd = null) {
		//$config = array_merge($this->_baseConfig, $this->config); // TODO REMOVE
		$config 		= $this->config;
		$hasFailover	= false;
		if (isset($config['host']) && is_array($config['host'])) {
			$config['host'] = $config['host'][$this->_multiMasterUse];
			if (count($this->config['host']) > (1 + $this->_multiMasterUse)) {
				$hasFailOver = true;
			}
		}

		$bindDN = (empty($bindDN)) ? $config['login'] : $bindDN;
		$bindPasswd = (empty($bindPasswd)) ? $config['password'] : $bindPasswd;
		$this->_connection = $this->_call('connect', $config['host']);
		if (!$this->_connection) {
			//Try Next Server Listed
			if ($hasFailover) {
				$this->log('Trying Next LDAP Server in list:' . $this->config['host'][$this->_multiMasterUse], 'ldap.error');
				$this->_multiMasterUse++;
				$this->connect($bindDN, $bindPasswd);
				if ($this->connected) {
					return $this->connected;
				}
			}
		}

		//Set our protocol version usually version 3
		$this->_call('set_option', $this->_connection, LDAP_OPT_PROTOCOL_VERSION, $config['version']);

		if ($config['tls']) {
			if (!$this->_call('start_tls', $this->_connection)) {
				$this->log('Ldap_start_tls failed', 'ldap.error');
				throw new Exception('Ldap_start_tls failed');
			}
		}
		// So little known fact, if your php-ldap lib is built against openldap like pretty much every linux
		// distro out there like Redhat, SUSE etc. The connect doesn't actually happen when you call ldap_connect
		// it happens when you call ldap_bind. So if you are using failover then you have to test here also.
		$bindResult = $this->_call('bind', $this->_connection, $bindDN, $bindPasswd);
		if (!$bindResult) {
			if ($this->_call('errno', $this->_connection) == 49) {
				$this->log("Auth failed for '$bindDN'!", 'ldap.error');
			} elseif ($hasFailover) {
				$this->log('Trying Next LDAP Server in list:' . $this->config['host'][$this->_multiMasterUse], 'ldap.error');
				$this->_multiMasterUse++;
				$this->connect($bindDN, $bindPasswd);
				if ($this->connected) {
					return $this->connected; // TODO isn't this superfluous?
				}
			}
		} else {
			$this->connected = true;
		}
		return $this->connected;
	}

/**
 * Close connection
 *
 * Disconnects and if fullDebug is set, the log for this object is shown.
 */
	public function close() {
		if ($this->fullDebug) {
			$this->showLog();
		}
		$this->disconnect();
	}

/**
 * Disconnect from server
 *
 * Disconnects from server, kills the connection and sets _connection to false,
 * and release any remaining results in the buffer
 *
 * @return bool Always false
 */
	public function disconnect() {
		foreach ($this->_results as $result) {
			if (is_resource($result)) {
				$this->_call('free_result', $result);
			}
		}
		if (is_resource($this->_connection)) {
			$this->_call('unbind', $this->_connection);
		}
		$this->connected = false;
		return $this->connected;
	}

/**
 * The "C" in CRUD
 *
 * @param Model $model
 * @param array $fields containing the field names
 * @param array $values containing the fields' values
 * @return bool true on success, false on error
 * @todo check if still needed/useful
 */
	public function create(Model $model, $fields = null, $values = null) {
		$basedn = $this->config['basedn'];
		$key = $model->primaryKey;
		$table = $model->useTable;
		$fieldsData = array();
		$id = null;
		$objectclasses = null;

		if ($fields == null) {
			unset($fields, $values);
			$fields = array_keys($model->data);
			$values = array_values($model->data);
		}

		$count = count($fields);

		for ($i = 0; $i < $count; $i++) {
			if ($fields[$i] == $key) {
				$id = $values[$i];
			} elseif ($fields[$i] == 'cn') {
				$cn = $values[$i];
			}
			$fieldsData[$fields[$i]] = $values[$i];
		}

		//Lets make our DN, this is made from the useTable & basedn + primary key. Logically this correlate to LDAP

		if (isset($table) && preg_match('/=/', $table)) {
			$table = $table . ', ';
		} else {
			$table = '';
		}
		if (isset($key) && !empty($key)) {
			$key = "$key=$id, ";
		} else {
			//Almost everything has a cn, this is a good fall back.
			$key = "cn=$cn, ";
		}
		$dn = $key . $table . $basedn;

		$res = $this->_call('add', $this->_connection, $dn, $fieldsData);
		// Add the entry
		if ($res) {
			$model->setInsertID($id);
			$model->id = $id;
			return true;
		} else {
			$this->log("Failed to add ldap entry: dn:$dn\nData:" . print_r($fieldsData, true) . "\n" . $this->_call('error', $this->_connection), 'ldap.error');
			$model->onError();
			return false;
		}
	}

/**
 * Returns the query
 *
 * @return string The query
 * @todo check if still needed/useful
 */
	public function query() {
		$args = func_get_args();
		if (count($args) === 0) {
			trigger_error('No Arguments were specified for query()');
			return null;
		}

		$find = array_shift($args);
		if (count($args) > 1) {
			list($query, $model) = $args;
		} else {
			$query = array_shift($args);
		}

		if (isset($query[0]) && is_array($query[0])) {
			$query = $query[0];
		}

		if (isset($find)) {
			switch($find) {
				case 'auth':
					return $this->auth($query['dn'], $query['password']);
				case 'findSchema':
					$query = $this->_getLDAPSchema();
					// $this->findSchema($query);
					break;
				case 'findConfig':
					return $this->config;
				default:
					$query = $this->read($model, $query);
					break;
			}
		}
		return $query;
	}

/**
 * The "R" in CRUD
 *
 * @param Model $model
 * @param array $queryData
 * @param integer $recursive Number of levels of association
 * @return unknown
 * @todo check if still needed/useful
 * @todo Pagination doesn't work because we can't determine the type properly
 */
	public function read(Model $model, $queryData = array(), $recursive = null) {
		$this->model = $model;
		$queryData = $this->_scrubQueryData($queryData);
		if (!is_null($recursive)) {
			$_recursive = $model->recursive;
			$model->recursive = $recursive;
		}

		// Prevent warnings when doing count, e.g. for pagination
		if ($queryData['fields'] === 'count') {
			$queryData['fields'] = array();
		}

		// Prepare query data
		$queryData['conditions'] = $this->_conditions($queryData['conditions'], $model);
		if (empty($queryData['targetDn'])) {
			$queryData['targetDn'] = $model->useTable;
		}
		$queryData['type'] = 'search';

		if (empty($queryData['order']))
				$queryData['order'] = array($model->primaryKey);

		// Associations links
		foreach ($model->_associations as $type) {
			foreach ($model->{$type} as $assoc => $assocData) {
				if ($model->recursive > -1) {
					$linkModel = $model->{$assoc};
					$linkedModels[] = $type . '/' . $assoc;
				}
			}
		}

		// Execute search query
		$res = $this->_executeQuery($queryData);

		if ($this->lastNumRows() === 0) {
			return false;
		}

		// Format results
		$this->_call('sort', $this->_connection, $res, $queryData['order'][0]);
		$resultSet = $this->_call('get_entries', $this->_connection, $res);
		$resultSet = $this->_ldapFormat($model, $resultSet);

		// Query on linked models
		if ($model->recursive > 0 && isset($model->__associations)) {
			foreach ($model->_associations as $type) {

				foreach ($model->{$type} as $assoc => $assocData) {
					$db = null;
					$linkModel = $model->{$assoc};

					if ($model->useDbConfig == $linkModel->useDbConfig) {
						$db = $this;
					} else {
						$db = ConnectionManager::getDataSource($linkModel->useDbConfig);
					}

					if (isset($db) && $db != null) {
						$stack = array($assoc);
						$array = array();
						$db->queryAssociation($model, $linkModel, $type, $assoc, $assocData, $array, true, $resultSet, $model->recursive - 1, $stack);
						unset($db);
					}
				}
			}
		}

		if (!is_null($recursive)) {
			$model->recursive = $_recursive;
		}

		// Add the count field to the resultSet (needed by find() to work out how many entries we got back.. used when $model->exists() is called)
		$resultSet[0][0]['count'] = $this->lastNumRows();
		return $resultSet;
	}

/**
 * The "U" in CRUD
 *
 * @todo check if still needed/useful
 */
	public function update(Model $model, $fields = null, $values = null, $conditions = null) {
		$fieldsData = array();

		if ($fields == null) {
			unset($fields, $values);
			$fields = array_keys($model->data);
			$values = array_values($model->data);
		}

		for ($i = 0, $count = count($fields); $i < $count; $i++) {
			$fieldsData[$fields[$i]] = $values[$i];
		}

		//set our scope
		$queryData['scope'] = 'base';
		if ($model->primaryKey == 'dn') {
			$queryData['targetDn'] = $model->id;
		} elseif (isset($model->useTable) && !empty($model->useTable)) {
			$queryData['targetDn'] = $model->primaryKey . '=' . $model->id . ', ' . $model->useTable;
		}

		// fetch the record
		// Find the user we will update as we need their dn
		$resultSet = $this->read($model, $queryData, $model->recursive);

		//now we need to find out what's different about the old entry and the new one and only changes those parts
		$current = $resultSet[0][$model->alias];
		$update = $model->data[$model->alias];

		foreach ($update as $attr => $value) {
			if (isset($update[$attr]) && !empty($update[$attr])) {
				$entry[$attr] = $update[$attr];
			} elseif (!empty($current[$attr]) && (isset($update[$attr]) && empty($update[$attr]))) {
				$entry[$attr] = array();
			}
		}

		//if this isn't a password reset, then remove the password field to avoid constraint violations...
		if (!in_array('userpassword', array_map('strtolower', $update))) {
			unset($entry['userpassword']);
		}
		unset($entry['count']);
		unset($entry['dn']);

		if ($resultSet) {
			$_dn = $resultSet[0][$model->alias]['dn'];

			if ($this->_call('modify', $this->_connection, $_dn, $entry)) {
				return true;
			} else {
				$this->log("Error updating $_dn: " . $this->_call('error', $this->_connection) . "\nHere is what I sent: " . print_r($entry, true), 'ldap.error');
				return false;
			}
		}

		// If we get this far, something went horribly wrong..
		$model->onError();
		return false;
	}

/**
 * The "D" in CRUD
 *
 * @todo check if still needed/useful
 */
	public function delete(Model $model, $conditions = null) {
		// Boolean to determine if we want to recursively delete or not
		//$recursive = true;
		$recursive = false;

		if (preg_match('/dn/i', $model->primaryKey)) {
			$dn = $model->id;
		} else {
			// Find the user we will update as we need their dn
			if (!empty($model->defaultObjectClass)) {
				$options['conditions'] = sprintf('(&(objectclass=%s)(%s=%s))', $model->defaultObjectClass, $model->primaryKey, $model->id);
			} else {
				$options['conditions'] = sprintf('%s=%s', $model->primaryKey, $model->id);
			}
			$options['targetDn'] = $model->useTable;
			$options['scope'] = 'sub';

			$entry = $this->read($model, $options, $model->recursive);
			$dn = $entry[0][$model->name]['dn'];
		}

		if ($dn) {
			if ($recursive === true) {
				// Recursively delete LDAP entries
				if ($this->_deleteRecursively($dn)) {
					return true;
				}
			} else {
				// Single entry delete
				if ($this->_call('delete', $this->_connection, $dn)) {
					return true;
				}
			}
		}

		$model->onError();
		$errMsg = $this->_call('error', $this->_connection);
		$this->log("Failed Trying to delete: $dn \nLdap Erro:$errMsg", 'ldap.error');
		return false;
	}

/**
 * Courtesy of gabriel at hrz dot uni-marburg dot de @ http://ch1.php.net/ldap_delete
 *
 * @todo check if still needed/useful
 */
	protected function _deleteRecursively($_dn) {
		// Search for sub entries
		$subentries = $this->_call('list', $this->_connection, $_dn, "objectClass=*", array());
		$info = $this->_call('get_entries', $this->_connection, $subentries);
		for ($i = 0; $i < $info['count']; $i++) {
			// deleting recursively sub entries
			$result = $this->_deleteRecursively($info[$i]['dn']);
			if (!$result) {
				return false;
			}
		}

		return $this->_call('delete', $this->_connection, $_dn);
	}

/**
 * Here are the functions that try to do model associations
 *
 * @todo check if still needed/useful
 */
	public function generateAssociationQuery(Model $model, $linkModel, $type, $association, $assocData, &$queryData, $external, &$resultSet) {
		$queryData = $this->_scrubQueryData($queryData);

		switch ($type) {
			case 'hasOne' :
				$id = $resultSet[$model->name][$model->primaryKey];
				$queryData['conditions'] = trim($assocData['foreignKey']) . '=' . trim($id);
				$queryData['targetDn'] = $linkModel->useTable;
				$queryData['type'] = 'search';
				$queryData['limit'] = 1;
				return $queryData;
			case 'belongsTo' :
				$id = $resultSet[$model->name][$assocData['foreignKey']];
				$queryData['conditions'] = trim($linkModel->primaryKey) . '=' . trim($id);
				$queryData['targetDn'] = $linkModel->useTable;
				$queryData['type'] = 'search';
				$queryData['limit'] = 1;
				return $queryData;
			case 'hasMany' :
				$id = $resultSet[$model->name][$model->primaryKey];
				$queryData['conditions'] = trim($assocData['foreignKey']) . '=' . trim($id);
				$queryData['targetDn'] = $linkModel->useTable;
				$queryData['type'] = 'search';
				$queryData['limit'] = $assocData['limit'];
				return $queryData;
			case 'hasAndBelongsToMany' :
				return null;
		}
		return null;
	}

/**
 * Queries associations. Used to fetch results on recursive models.
 *
 * @param Model $model Primary Model object
 * @param Model $linkModel Linked model that
 * @param string $type Association type, one of the model association types ie. hasMany
 * @param string $association
 * @param array $assocData
 * @param array $queryData
 * @param boolean $external Whether or not the association query is on an external datasource.
 * @param array $resultSet Existing results
 * @param integer $recursive Number of levels of association
 * @param array $stack
 * @return mixed
 * @throws CakeException when results cannot be created.
 * @todo check if still needed/useful
 */
	public function queryAssociation(Model $model, &$linkModel, $type, $association, $assocData, &$queryData, $external, &$resultSet, $recursive, $stack) {
		if (!isset($resultSet) || !is_array($resultSet)) {
			if ($this->fullDebug) {
				echo '<div style = "font: Verdana bold 12px; color: #FF0000">SQL Error in model ' . $model->name . ': ';
				if (isset($this->error) && $this->error != null) {
					echo $this->error;
				}
				echo '</div>';
			}
			return null;
		}

		$count = count($resultSet);
		for ($i = 0; $i < $count; $i++) {

			$row = & $resultSet[$i];
			$queryData = $this->generateAssociationQuery($model, $linkModel, $type, $association, $assocData, $queryData, $external, $row);
			$fetch = $this->_executeQuery($queryData);
			$fetch = $this->_call('get_entries', $this->_connection, $fetch);
			$fetch = $this->_ldapFormat($linkModel,$fetch);

			if (!empty($fetch) && is_array($fetch)) {
					if ($recursive > 0) {
						foreach ($linkModel->_associations as $type1) {
							foreach ($linkModel->{$type1} as $assoc1 => $assocData1) {
								$deepModel = $linkModel->{$assocData1['className']};
								if ($deepModel->alias != $model->name) {
									$tmpStack = $stack;
									$tmpStack[] = $assoc1;
									if ($linkModel->useDbConfig == $deepModel->useDbConfig) {
										$db = $this;
									} else {
										$db = ConnectionManager::getDataSource($deepModel->useDbConfig);
									}
									$queryData = array();
									$db->queryAssociation($linkModel, $deepModel, $type1, $assoc1, $assocData1, $queryData, true, $fetch, $recursive - 1, $tmpStack);
								}
							}
						}
					}
				$this->_mergeAssociation($resultSet[$i], $fetch, $association, $type);

			} else {
				$tempArray[0][$association] = false;
				$this->_mergeAssociation($resultSet[$i], $tempArray, $association, $type);
			}
		}
	}

/**
 * Returns a formatted error message from previous database operation.
 *
 * @return string|null Error message with error number or null when no error occured
 */
	public function lastError() {
		$errno = $this->_call('errno', $this->_connection);
		if ($errno) {
			return $errno . ': ' . $this->_call('err2str', $errno);
		}
		return null;
	}

/**
 * Returns number of rows in previous result set.
 *
 * If no previous result set exists, it returns false.
 *
 * @param null $source
 * @return int Number of rows in result set
 * @todo check if still needed/useful
 */
	public function lastNumRows($source = null) {
		if ($this->_result && is_resource($this->_result)) {
			return $this->_call('count_entries', $this->_connection, $this->_result);
		}
		return null;
	}

/**
 * Utility function to convert Active Directory timestamps to unix ones
 *
 * @param $adTimestamp
 * @internal param int $ad_timestamp Active directory timestamp
 * @return integer Unix timestamp
 * @todo move epodDiff to properties
 * @todo link is missing!
 * @todo check if still needed/useful
 */
	public static function convertTimestampADToUnix($adTimestamp) {
		$epochDiff = 11644473600; // difference 1601<>1970 in seconds. see reference URL
		$dateTimestamp = $adTimestamp * 0.0000001;
		$unixTimestamp = $dateTimestamp - $epochDiff;
		return $unixTimestamp;
	}

/**
 * The following was kindly "borrowed" from the excellent phpldapadmin project
 *
 * @todo add copyright/link to phpldapadmin project
 * @todo check for updated version
 * @todo check if still needed/useful
 */
	protected function _getLDAPSchema() {
		$schemaTypes = array('objectclasses', 'attributetypes');
		$results = $this->_call('read', $this->_connection, $this->SchemaDN, $this->SchemaFilter, $schemaTypes, 0, 0, 0, LDAP_DEREF_ALWAYS);
		if (false === $results) {
			trigger_error("LDAP schema filter '$this->SchemaFilter' is invalid!");
			return;
		}

		$schemaEntries = $this->_call('get_entries', $this->_connection, $results);

		if ($schemaEntries) {
			$return = array();
			foreach ($schemaTypes as $n) {
				$schemaTypeEntries = $schemaEntries[0][$n];
				for ($x = 0; $x < $schemaTypeEntries['count']; $x++) {
					$entry = array();
					$strings = preg_split('/[\s,]+/', $schemaTypeEntries[$x], -1, PREG_SPLIT_DELIM_CAPTURE);
					$strCount = count($strings);
					for ($i = 0; $i < $strCount; $i++) {
						switch ($strings[$i]) {
							case '(':
								break;
							case 'NAME':
								if ($strings[$i + 1] != '(') {
									do {
										$i++;
										if (!isset($entry['name'] ) || strlen( $entry['name']) == 0) {
											$entry['name'] = $strings[$i];
										} else {
											$entry['name'] .= ' ' . $strings[$i];
										}
									} while ( !preg_match('/\'$/s', $strings[$i]));
								} else {
									$i++;
									do {
										$i++;
										if (!isset($entry['name']) || strlen( $entry['name']) == 0) {
											$entry['name'] = $strings[$i];
										} else {
											$entry['name'] .= ' ' . $strings[$i];
										}
									} while (!preg_match( '/\'$/s', $strings[$i]));
									do {
										$i++;
									} while (!preg_match( '/\)+\)?/', $strings[$i]));
								}

								$entry['name'] = preg_replace('/^\'/', '', $entry['name'] );
								$entry['name'] = preg_replace('/\'$/', '', $entry['name'] );
								break;
							case 'DESC':
								do {
									$i++;
									if (!isset($entry['description'] ) || strlen( $entry['description']) == 0) {
										$entry['description'] = $strings[$i];
									} else {
										$entry['description'] .= ' ' . $strings[$i];
									}
								} while (!preg_match( '/\'$/s', $strings[$i]));
								break;
							case 'OBSOLETE':
								$entry['is_obsolete'] = true;
								break;
							case 'SUP':
								$entry['sup_classes'] = array();
								if ($strings[$i + 1] != '(') {
									$i++;
									array_push($entry['sup_classes'], preg_replace( "/'/", '', $strings[$i]));
								} else {
									$i++;
									do {
										$i++;
										if ($strings[$i] != '$') {
											array_push($entry['sup_classes'], preg_replace( "/'/", '', $strings[$i]));
										}
									} while (! preg_match('/\)+\)?/',$strings[$i + 1]));
								}
								break;
							case 'ABSTRACT':
								$entry['type'] = 'abstract';
								break;
							case 'STRUCTURAL':
								$entry['type'] = 'structural';
								break;
							case 'SINGLE-VALUE':
								$entry['multiValue'] = 'false';
								break;
							case 'AUXILIARY':
								$entry['type'] = 'auxiliary';
								break;
							case 'MUST':
								$entry['must'] = array();
								$i = $this->_parseList(++$i, $strings, $entry['must']);
								break;
							case 'MAY':
								$entry['may'] = array();
								$i = $this->_parseList(++$i, $strings, $entry['may']);
								break;
							default:
								if (preg_match( '/[\d\ . ]+/i', $strings[$i]) && $i == 1) {
									$entry['oid'] = $strings[$i];
								}
						}
					}
					if (!isset($return[$n]) || !is_array( $return[$n])) {
						$return[$n] = array();
					}
					//make lowercase for consistency
					$return[strtolower($n)][strtolower($entry['name'])] = $entry;
					//array_push($return[$n][$entry['name']], $entry);
				}
			}
		}

		return $return;
	}

/**
 * _parseList
 *
 * @param $i
 * @param $strings
 * @param $attrs
 * @return mixed
 * @todo check if still needed/useful
 */
	protected function _parseList($i, $strings, &$attrs) {
	/**
	 ** A list starts with a (followed by a list of attributes separated by $ terminated by)
	 ** The first token can therefore be a ( or a (NAME or a (NAME)
	 ** The last token can therefore be a ) or NAME)
	 ** The last token may be terminate by more than one bracket
	 */
		$string = $strings[$i];
		if (!preg_match('/^\(/',$string)) {
			// A bareword only - can be terminated by a ) if the last item
			if (preg_match('/\)+$/',$string)) {
				$string = preg_replace('/\)+$/','',$string);
			}

			array_push($attrs, $string);
		} elseif (preg_match('/^\( . *\)$/',$string)) {
			$string = preg_replace('/^\(/','',$string);
			$string = preg_replace('/\)+$/','',$string);
			array_push($attrs, $string);
		} else {
			// Handle the opening cases first
			if ($string == '(') {
				$i++;
			} elseif (preg_match('/^\( . /',$string)) {
				$string = preg_replace('/^\(/','',$string);
				array_push($attrs, $string);
				$i++;
			}

			// Token is either a name, a $ or a ')'
			// NAME can be terminated by one or more ')'
			while (!preg_match('/\)+$/',$strings[$i])) {
				$string = $strings[$i];
				if ($string == '$') {
					$i++;
					continue;
				}

				if (preg_match('/\)$/',$string)) {
						$string = preg_replace('/\)+$/','',$string);
				} else {
						$i++;
				}
				array_push($attrs, $string);
			}
		}
		sort($attrs);

		return $i;
	}

/**
 * Function to actually query LDAP
 *
 * @param $query
 * @param array $options
 * @param array $params
 * @return void null
 * @todo check if still needed/useful
 */
	public function execute($query, $options = array(), $params = array()) {
		$options += array('log' => $this->fullDebug);

		$t = microtime(true);
		$this->_result = $this->_executeQuery($query, $params);

		if ($options['log']) {
			$this->took = round((microtime(true) - $t) * 1000, 0);
			$this->numRows = $this->affected = $this->lastAffected();
			$this->logQuery($query);
		}
		return $this->_result;
	}

/**
 * Execute query, not supported
 *
 * Function not supported by LDAP
 *
 * @param $query
 * @param bool $cache
 * @return array
 * @todo check if still needed/useful
 */
	public function fetchAll($query, $cache = true) {
		return array();
	}

/**
 * Log given LDAP query.
 *
 * Reimplementation of DboSource::logQuery
 *
 * @param string $query LDAP statement
 * @return void
 */
	public function logQuery($query) {
		$this->_queriesCnt++;
		$this->_queriesTime += $this->took;
		$this->_queriesLog[] = array (
			'query' => $query,
			'error' => $this->error,
			'affected' => $this->affected,
			'numRows' => $this->numRows,
			'took' => $this->took
		);
		if (count($this->_queriesLog) > $this->_queriesLogMax) {
			array_pop($this->_queriesLog);
		}
	}

/**
 * Get the query log as an array.
 *
 * @param boolean $sorted Get the queries sorted by time taken, defaults to false.
 * @param boolean $clear If True the existing log will cleared.
 * @return array Array of queries run as an array
 * @todo check if still needed/useful
 */
	public function getLog($sorted = false, $clear = true) {
		if ($sorted) {
			$log = sortByKey($this->_queriesLog, 'took', 'desc', SORT_NUMERIC);
		} else {
			$log = $this->_queriesLog;
		}

		if ($clear) {
			$this->_queriesLog = array();
		}
		return array('log' => $log, 'count' => $this->_queriesCnt, 'time' => $this->_queriesTime);
	}

/**
 * Outputs the contents of the queries log. If in a non-CLI environment the sql_log element
 * will be rendered and output. If in a CLI environment, a plain text log is generated.
 *
 * @param boolean $sorted Get the queries sorted by time taken, defaults to false.
 * @return void
 * @todo check if still needed/useful
 */
	public function showLog($sorted = false) {
		$log = $this->getLog($sorted, false);
		if (empty($log['log'])) {
			return;
		}
		if (PHP_SAPI !== 'cli') {
			$controller = null;
			$View = new View($controller, false);
			$View->set('logs', array($this->configKeyName => $log));
			echo $View->element('sql_dump', array('_forced_from_dbo_' => true));
		} else {
			foreach ($log['log'] as $k => $i) {
				print (($k + 1) . ". {$i['query']}\n");
			}
		}
	}

/**
 * _conditions
 *
 * @param $conditions
 * @param $model
 * @return array|string
 * @todo check if still needed/useful
 */
	protected function _conditions($conditions, $model) {
		$res = '';
		$key = $model->primaryKey;
		$name = $model->name;

		if (is_array($conditions) && count($conditions) == 1) {
			$sqlHack = "$name.$key";
			$conditions = str_ireplace($sqlHack, $key, $conditions);
			foreach ($conditions as $k => $v) {
				if ($k == $name . '.dn') {
					$res = substr($v, 0, strpos($v, ','));
				} elseif ($k == $sqlHack && (empty($v) || $v == '*')) {
					$res = 'objectclass=*';
				} elseif ($k == $sqlHack) {
					$res = "$key=$v";
				} else {
					$res = "$k=$v";
				}
			}
			$conditions = $res;
		}

		if (empty($conditions)) {
			$res = 'objectclass=*';
		} else {
			$res = $conditions;
		}
		return $res;
	}

/**
 * Convert an array into a ldap condition string
 *
 * @param array $conditions condition
 * @return string
 * @todo check if still needed/useful
 */
	protected function _conditionsArrayToString($conditions) {
		$opsRec = array('and' => array('prefix' => '&'), 'or' => array('prefix' => '|'));
		$opsNeg = array('and not' => array() , 'or not' => array(), 'not equals' => array());
		$opsTer = array('equals' => array('null' => '*'));

		$ops = array_merge($opsRec, $opsNeg, $opsTer);

		if (is_array($conditions)) {

			$operand = array_keys($conditions);
			$operand = $operand[0];

			if (!in_array($operand, array_keys($ops))) {
				$this->log("No operators defined in LDAP search conditions . ", 'ldap.error');
				return null;
			}

			$children = $conditions[$operand];

			if (in_array($operand, array_keys($opsRec))) {
				if (!is_array($children))
					return null;

				$tmp = '(' . $opsRec[$operand]['prefix'];
				foreach ($children as $key => $value) {
					$child = array ($key => $value);
					$tmp .= $this->_conditionsArrayToString($child);
				}
				return $tmp . ')';

			} elseif (in_array($operand, array_keys($opsNeg))) {
				if (!is_array($children)) {
					return null;
				}

				$nextOperand = trim(str_replace('not', '', $operand));

				return '(!' . $this->_conditionsArrayToString(array($nextOperand => $children)) . ')';

			} elseif (in_array($operand, array_keys($opsTer))) {
				$tmp = '';
				foreach ($children as $key => $value) {
					if ( !is_array($value))
						$tmp .= '(' . $key . '=' . ((is_null($value))?$opsTer['equals']['null'] : $value) . ')';
					else
						foreach ($value as $subvalue)
							$tmp .= $this->_conditionsArrayToString(array('equals' => array($key => $subvalue)));
				}
				return $tmp;
			}
		}
	}

/**
 * checkBaseDn
 *
 * @param $targetDN
 * @return int
 * @todo check if still needed/useful
 */
	public function checkBaseDn($targetDN) {
		$parts = preg_split('/,\s*/', $this->config['basedn']);
		$pattern = '/' . implode(',\s*', $parts) . '/i';
		return preg_match($pattern, $targetDN);
	}

/**
 * _executeQuery
 *
 * @param array $queryData
 * @param bool $cache
 * @return bool|mixed
 * @todo check if still needed/useful
 */
	protected function _executeQuery($queryData = array(), $cache = true) {
		$t = microtime(true);
		$pattern = '/,[ \t]+(\w+)=/';
		$queryData['targetDn'] = preg_replace($pattern, ',$1=', $queryData['targetDn']);
		if (!$this->checkBaseDn($queryData['targetDn'])) {
			$this->log("Missing BaseDN in " . $queryData['targetDn'], 'debug');

			if ($queryData['targetDn'] != null) {
				$seperator = (substr($queryData['targetDn'], -1) == ',') ? '' : ',';
				if ( (strpos($queryData['targetDn'], '=') === false) && (isset($this->model) && !empty($this->model))) {
					//Fix TargetDN here
					$key = $this->model->primaryKey;
					$table = $this->model->useTable;
					$queryData['targetDn'] = $key . '=' . $queryData['targetDn'] . ',' . $table . $seperator . $this->config['basedn'];
				} else {
					$queryData['targetDn'] = $queryData['targetDn'] . $seperator . $this->config['basedn'];
				}
			} else {
				$queryData['targetDn'] = $this->config['basedn'];
			}
		}

		$query = $this->_queryToString($queryData);
		if ($cache && isset($this->_queryCache[$query])) {
			if (strpos(trim(strtolower($query)), $queryData['type']) !== false) {
				$res = $this->_queryCache[$query];
			}
		} else {

			switch ($queryData['type']) {
				case 'search':
					// TODO pb ldap_search & $queryData['limit']

					if (empty($queryData['fields'])) {
						$queryData['fields'] = $this->defaultNSAttributes();
					}

					//Handle LDAP Scope
					if (isset($queryData['scope']) && $queryData['scope'] == 'base') {
						$res = $this->_call('read', $this->_connection, $queryData['targetDn'], $queryData['conditions'], $queryData['fields']);
					} elseif (isset($queryData['scope']) && $queryData['scope'] == 'one') {
						$res = $this->_call('list', $this->_connection, $queryData['targetDn'], $queryData['conditions'], $queryData['fields']);
					} else {
						if ($queryData['fields'] == 1) $queryData['fields'] = array();
						$res = $this->_call('search', $this->_connection, $queryData['targetDn'], $queryData['conditions'], $queryData['fields'], 0, $queryData['limit']);
					}

					if (!$res) {
						$res = false;
						$errMsg = $this->_call('error', $this->_connection);
						$this->log("Query Params Failed:" . print_r($queryData, true) . ' Error: ' . $errMsg, 'ldap.error');
						$this->count = 0;
					} else {
						$this->count = $this->_call('count_entries', $this->_connection, $res);
					}

					if ($cache) {
						if (strpos(trim(strtolower($query)), $queryData['type']) !== false) {
							$this->_queryCache[$query] = $res;
						}
					}
					break;
				case 'delete':
					$res = $this->_call('delete', $this->_connection, $queryData['targetDn'] . ',' . $this->config['basedn']);
					break;
				default:
					$res = false;
					break;
			}
		}

		$this->_result = $res;
		$this->took = round((microtime(true) - $t) * 1000, 0);
		$this->error = $this->lastError();
		$this->numRows = $this->lastNumRows();
		$this->affected = null;

		if ($this->fullDebug) {
			$this->logQuery($query);
		}

		return $this->_result;
	}

/**
 * _queryToString
 *
 * @param $queryData
 * @return string
 * @todo check if still needed/useful
 */
	protected function _queryToString($queryData) {
		$tmp = '';
		if (!empty($queryData['scope'])) {
			$tmp .= ' | scope: ' . $queryData['scope'] . ' ';
		}

		if (!empty($queryData['conditions'])) {
			$tmp .= ' | cond: ' . $queryData['conditions'] . ' ';
		}

		if (!empty($queryData['targetDn'])) {
			$tmp .= ' | targetDn: ' . $queryData['targetDn'] . ' ';
		}

		$fields = '';
		if (!empty($queryData['fields']) && is_array($queryData['fields'])) {
			$fields = implode(', ', $queryData['fields']);
			$tmp .= ' |fields: ' . $fields . ' ';
		}

		if (!empty($queryData['order'])) {
			$tmp .= ' | order: ' . $queryData['order'][0] . ' ';
		}

		if (!empty($queryData['limit'])) {
			$tmp .= ' | limit: ' . $queryData['limit'];
		}

		return $queryData['type'] . $tmp;
	}

/**
 * _ldapFormat
 *
 * @param Model $model
 * @param $data
 * @return array result formatted
 * @todo check if still needed/useful
 */
	protected function _ldapFormat(Model $model, $data) {
		$resultFormatted = array();


		foreach ($data as $key => $row) {
			if ($key === 'count') {
				continue;
			}

			foreach ($row as $key1 => $param) {
				if ($key1 === 'dn') {
					$resultFormatted[$key][$model->name][$key1] = $param;
					continue;
				}
				if (!is_numeric($key1)) {
					continue;
				}
				if ($row[$param]['count'] === 1) {
					$resultFormatted[$key][$model->name][$param] = $row[$param][0];
				} else {
					foreach ($row[$param] as $key2 => $item) {
						if ($key2 === 'count') {
							continue;
						}
						$resultFormatted[$key][$model->name][$param][] = $item;
					}
				}
			}
		}
		return $resultFormatted;
	}

/**
 * _ldapQuote
 *
 * @param $str
 * @return mixed
 * @todo check if still needed/useful
 */
	protected function _ldapQuote($str) {
		return str_replace(
			array('\\', ' ', '*', '(', ')'),
			array('\\5c', '\\20', '\\2a', '\\28', '\\29'),
			$str
		);
	}

/**
 * Private helper method to remove query metadata in given data array.
 *
 * @param array $data
 * @return array
 * @todo check if still needed/useful
 */
	protected function _scrubQueryData($data) {
		static $base = null;
		if ($base === null) {
			$base = array(
				'type' => 'default',
				'conditions' => array(),
				'targetDn' => null,
				'fields' => array(),
				'order' => array(),
				'limit' => null
			);
		}
		return (array)$data + $base;
	}

/**
 * _getObjectclasses
 *
 * @return null
 * @todo check if still needed/useful
 */
	protected function _getObjectclasses() {
		$cache = null;
		if ($this->cacheSources !== false) {
			if (isset($this->_descriptions['ldap_objectclasses'])) {
				$cache = $this->_descriptions['ldap_objectclasses'];
			} else {
				$cache = $this->__cacheDescription('objectclasses');
			}
		}

		if ($cache != null) {
			return $cache;
		}

		// If we get this far, then we haven't cached the attribute types, yet!
		$ldapschema = $this->_getLDAPschema();
		$objectclasses = $ldapschema['objectclasses'];

		// Cache away
		$this->__cacheDescription('objectclasses', $objectclasses);

		return $objectclasses;
	}

/**
 * boolean
 *
 * Function not supported by LDAP
 *
 * @return null
 * @todo check if still needed/useful
 */
	public function boolean() {
		return null;
	}

/**
 * Returns an calculation, i.e. COUNT() or MAX()
 *
 * @param Model $model
 * @param string $func Lowercase name of SQL function, i.e. 'count' or 'max'
 * @param array $params Function parameters (any values must be quoted manually)
 * @return string An SQL calculation function
 * @todo check if still needed/useful
 */
	public function calculate(Model $model, $func, $params = array()) {
		$params = (array)$params;

		switch (strtolower($func)) {
			case 'count':
				if (empty($params) && $model->id) {
					//quick search to make sure it exsits
					$queryData['targetDn'] = $model->id;
					$queryData['conditions'] = 'objectClass=*';
					$queryData['scope'] = 'base';
					$query = $this->read($model, $queryData);
				}
				return 'count';
			case 'max':
			case 'min':
			break;
		}
	}

/**
 * describe
 *
 * @param Model|string $model
 * @return array
 * @todo check if still needed/useful
 */
	public function describe($model) {
		$args = func_get_args();
		$schemas = $this->_getLDAPSchema();
		$attrs = $schemas['attributetypes'];
		ksort($attrs);
		if (count($args) > 1) {
			$field = $args[1];
			return $attrs[strtolower($field)];
		} else {
			return $attrs;
		}
	}

/**
 * defaultNSAttributes
 *
 * @return array
 * @todo check if still needed/useful
 */
	public function defaultNSAttributes() {
		$fields = '* ' . $this->OperationalAttributes;
		return explode(' ', $fields);
	}

/**
 * debugLDAPConnection debugs the current connection to check the settings
 *
 * @todo check if still needed/useful
 */
	public function debugLDAPConnection() {
		$opts = array(
			'LDAP_OPT_DEREF',
			'LDAP_OPT_SIZELIMIT',
			'LDAP_OPT_TIMELIMIT',
			'LDAP_OPT_NETWORK_TIMEOUT',
			'LDAP_OPT_PROTOCOL_VERSION',
			'LDAP_OPT_ERROR_NUMBER',
			'LDAP_OPT_REFERRALS',
			'LDAP_OPT_RESTART',
			'LDAP_OPT_HOST_NAME',
			'LDAP_OPT_ERROR_STRING',
			'LDAP_OPT_MATCHED_DN',
			'LDAP_OPT_SERVER_CONTROLS',
			'LDAP_OPT_CLIENT_CONTROLS'
		);
		foreach ($opts as $opt) {
			$ve = '';
			$this->_call('get_option', $this->_connection, constant($opt), $ve);
			$this->log("Option={$opt}, Value=" . print_r($ve, true),'debug');
		}
	}

/**
 * If you want to pull everything from a netscape stype ldap server
 * iPlanet, Redhat-DS, Project-389 etc you need to ask for specific
 * attributes like so. Other wise the attributes listed below wont
 * show up
 * @todo check if still needed/useful
 */
	protected function setNetscapeEnv() {
		$this->OperationalAttributes = implode(' ', array(
			'accountUnlockTime',
			'aci',
			'copiedFrom',
			'copyingFrom',
			'createTimestamp',
			'creatorsName',
			'dncomp',
			'entrydn',
			'entryid',
			'hasSubordinates',
			'ldapSchemas',
			'ldapSyntaxes',
			'modifiersName',
			'modifyTimestamp',
			'nsAccountLock',
			'nsAIMStatusGraphic',
			'nsAIMStatusText',
			'nsBackendSuffix',
			'nscpEntryDN',
			'nsds5ReplConflict',
			'nsICQStatusGraphic',
			'nsICQStatusText',
			'nsIdleTimeout',
			'nsLookThroughLimit',
			'nsRole',
			'nsRoleDN',
			'nsSchemaCSN',
			'nsSizeLimit',
			'nsTimeLimit',
			'nsUniqueId',
			'nsYIMStatusGraphic',
			'nsYIMStatusText',
			'numSubordinates',
			'parentid',
			'passwordAllowChangeTime',
			'passwordExpirationTime',
			'passwordExpWarned',
			'passwordGraceUserTime',
			'passwordHistory',
			'passwordRetryCount',
			'pwdExpirationWarned',
			'pwdGraceUserTime',
			'pwdHistory',
			'pwdpolicysubentry',
			'retryCountResetTime',
			'subschemaSubentry'
		));
		$this->SchemaFilter = '(objectClass=subschema)';
		$this->SchemaAttributes = implode(' ', array(
			'objectClasses',
			'attributeTypes',
			'ldapSyntaxes',
			'matchingRules',
			'matchingRuleUse',
			'createTimestamp',
			'modifyTimestamp'
		));
	}

/**
 * setActiveDirectoryEnv
 *
 * @todo check if still needed/useful
 */
	protected function setActiveDirectoryEnv() {
		//Need to disable referrals for AD
		$this->_call('set_option', $this->_connection, LDAP_OPT_REFERRALS, 0);
		$this->OperationalAttributes = ' + ';
		$this->SchemaFilter = '(objectClass=subschema)';
		$this->SchemaAttributes = implode(' ', array(
			'objectClasses',
			'attributeTypes',
			'ldapSyntaxes',
			'matchingRules',
			'matchingRuleUse',
			'createTimestamp',
			'modifyTimestamp',
			'subschemaSubentry'
		));
	}

/**
 * setOpenLDAPEnv
 *
 * @todo check if still needed/useful
 */
	protected function setOpenLDAPEnv() {
		$this->OperationalAttributes = ' + ';
		$this->SchemaFilter = '(objectClass=*)';
	}

/**
 * setSchemaPath
 *
 * @todo Better explanation what this method does, see link
 * @link http://www.analogrithems.com/rant/2010/03/29/find-the-schema-path-in-ldap/
 */
	public function setSchemaPath() {
		$checkDN = $this->_call('read', $this->_connection, '', 'objectClass=*', array('subschemaSubentry'));
		$schemaEntry = $this->_call('get_entries', $this->_connection, $checkDN);
		$this->SchemaDN = $schemaEntry[0]['subschemasubentry'][0];
	}

/**
* Returns an array of sources (tables) in the database.
*
* @param mixed $data
* @return array Array of tablenames in the database
*/
	public function listSources($data = null) {
		$cache = parent::listSources();
		if ($cache !== null) {
			return $cache;
		}
	}
}
