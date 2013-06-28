## CakePHP LDAP DataSource plugin

This plugin contains the LDAP DatataSource for CakePHP 2.3.x contributed by the core CakePHP team and the community.


### Using the datasources plugin

First download the repository and place it in `app/Plugin/LdapDatasource` or on one of your plugin paths.
You can then import and use the datasource in your App classes.

### Model validation

Datasource plugin datasources can be used either through App::uses of by defining them in your database configuration

	class DATABASE_CONFIG {
		public $mySource = array(
			'datasource' => 'LdapDatasource.LdapSource',
			...
			);
		}
	}

or

	App::uses('LdapSource', 'LdapDatasource.Model/Datasource');

## Contributing to datasources

TODO

## Issues with datasources

TODO
