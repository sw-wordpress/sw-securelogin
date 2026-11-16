<?php
/**
 * WordPress test suite configuration. Uses ONLY the private throw-away MariaDB instance (bin/test-db.sh),
 * never the development site's database.
 */

define( 'ABSPATH', __DIR__ . '/vendor/johnpbloch/wordpress-core/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

if ( getenv( 'SWSL_WP_TEST_DB_NAME' ) && 'swsl_wp_tests' !== getenv( 'SWSL_WP_TEST_DB_NAME' ) ) {
	throw new RuntimeException( 'Only the disposable swsl_wp_tests database is allowed.' );
}
define( 'DB_NAME', 'swsl_wp_tests' );
define( 'DB_USER', getenv( 'SWSL_TEST_DB_USER' ) ?: 'swsl' );
define( 'DB_PASSWORD', getenv( 'SWSL_TEST_DB_PASSWORD' ) ?: 'swsl' );
define( 'DB_HOST', ( getenv( 'SWSL_TEST_DB_HOST' ) ?: '127.0.0.1' ) . ':' . ( getenv( 'SWSL_TEST_DB_PORT' ) ?: '33108' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'sw-securelogin Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

$table_prefix = 'wptests_';
