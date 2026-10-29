<?php
/** Integration bootstrap for an explicitly selected disposable WordPress site. */
declare(strict_types=1);

$swsl_test_path = getenv( 'SWSL_TEST_WORDPRESS' );
if ( ! is_string( $swsl_test_path ) || ! is_file( $swsl_test_path . '/wp-load.php' ) ) {
	throw new RuntimeException( 'Set SWSL_TEST_WORDPRESS to a disposable WordPress installation.' );
}
$_SERVER['REQUEST_METHOD'] = 'GET';
require $swsl_test_path . '/wp-load.php';
if ( 'swsl_m0_test' !== DB_NAME || 'swsltest_' !== $GLOBALS['wpdb']->prefix ) {
	throw new RuntimeException( 'Integration tests require database swsl_m0_test and prefix swsltest_.' );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
