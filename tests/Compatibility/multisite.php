<?php
/** Verify refusal on a real disposable network, without modifying any network data. */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) exit;
$swsl_wordpress = getenv( 'SWSL_TEST_WORDPRESS' );
if ( ! is_string( $swsl_wordpress ) || ! is_file( $swsl_wordpress . '/wp-load.php' ) ) throw new RuntimeException( 'Select the disposable network.' );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'localhost:8443';
require $swsl_wordpress . '/wp-load.php';
if ( 'swsl_m0_test' !== DB_NAME || 'swsltest_' !== $wpdb->prefix || ! is_multisite() ) throw new RuntimeException( 'Only the disposable test network is allowed.' );
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
foreach ( array( 'login_form', 'admin_init', 'admin_menu', 'rest_api_init', 'show_user_profile', 'swsl_module_overview' ) as $swsl_hook ) {
	foreach ( $wp_filter[ $swsl_hook ]->callbacks ?? array() as $swsl_callbacks ) {
		foreach ( $swsl_callbacks as $swsl_callback ) {
			$swsl_function = $swsl_callback['function'];
			if ( is_array( $swsl_function ) && is_object( $swsl_function[0] ) && str_starts_with( get_class( $swsl_function[0] ), 'SW\\Securelogin\\' ) ) throw new RuntimeException( 'Plugin feature hook registered on unsupported multisite.' );
		}
	}
}
wp_set_current_user( get_user_by( 'login', 'swsl-test-admin' )->ID );
ob_start();
( new SW\Securelogin\Core\Plugin() )->multisiteNotice();
$swsl_notice = ob_get_clean();
if ( ! str_contains( $swsl_notice, 'single-site' ) || array() === ( new SW\Securelogin\Modules\Passkeys\PasskeysModule( $wpdb, new SW\Securelogin\Core\SettingsRepository() ) )->requirements() ) throw new RuntimeException( 'Missing multisite refusal.' );
echo "PASS: actual multisite registers no login, management or migration hooks and reports the restriction.\n";
