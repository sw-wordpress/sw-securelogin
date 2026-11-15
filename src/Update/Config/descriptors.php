<?php
/**
 * Local plugin identities. No requests or option reads.
 *
 * @package sw-securelogin
 */

return array(
	// uninstall.php runs without the plugin's constants.
	defined( 'SWSL_FILE' ) ? plugin_basename( SWSL_FILE ) : 'sw-securelogin/sw-securelogin.php' => array(
		'slug'    => 'sw-securelogin',
		'version' => defined( 'SWSL_VERSION' ) ? SWSL_VERSION : '0',
	),
);
