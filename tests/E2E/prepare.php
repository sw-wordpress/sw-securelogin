<?php
/** Prepare only the explicitly named disposable E2E database. */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) exit;
$path = $argv[1] ?? '';
$origin = $argv[2] ?? '';
if ( ! preg_match( '~^https://localhost:[0-9]{2,5}$~D', $origin ) || ! is_file( $path . '/wp-load.php' ) ) {
	throw new RuntimeException( 'Expected a disposable WordPress path and HTTPS localhost origin.' );
}
$_SERVER['REQUEST_METHOD'] = 'GET';
require $path . '/wp-load.php';
if ( 'swsl_m0_test' !== DB_NAME || 'swsltest_' !== $wpdb->prefix ) {
	throw new RuntimeException( 'E2E tests require the disposable integration database and prefix.' );
}
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
$runner = new SW\Securelogin\Core\MigrationRunner( $wpdb, array( 1 => new SW\Securelogin\Core\Migrations\InitializeSettings(), 2 => new SW\Securelogin\Modules\Passkeys\PasskeysSchema() ) );
$result = $runner->run();
if ( is_wp_error( $result ) ) throw new RuntimeException( 'Test database migration failed.' );
$user = get_user_by( 'login', 'swsl-test-admin' );
if ( ! $user ) throw new RuntimeException( 'Expected the disposable test administrator.' );
( new SW\Securelogin\Modules\Passkeys\PrivacyController( $wpdb, new SW\Securelogin\Modules\Passkeys\CredentialRepository( $wpdb ) ) )->deleteUser( $user->ID );
$wpdb->query( 'DELETE FROM swsltest_swsl_limits' );
update_option( 'siteurl', $origin );
update_option( 'home', $origin );
update_option( 'swsl_settings', array( 'enabled_modules' => array() ), false );
delete_option( 'swsl_passkeys_rp' );
echo "Prepared disposable E2E site.\n";
