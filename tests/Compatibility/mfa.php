<?php
/** Confirm an actual, unreviewed MFA extension cannot be bypassed by session issuance. */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) exit;
$swsl_wordpress = getenv( 'SWSL_TEST_WORDPRESS' );
if ( ! is_string( $swsl_wordpress ) || ! is_file( $swsl_wordpress . '/wp-load.php' ) ) throw new RuntimeException( 'Select the disposable WordPress site.' );
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_METHOD'] = 'GET';
require $swsl_wordpress . '/wp-load.php';
if ( 'swsl_m0_test' !== DB_NAME || 'swsltest_' !== $wpdb->prefix ) throw new RuntimeException( 'Only the disposable integration database is allowed.' );
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
$swsl_mfa = WP_PLUGIN_DIR . '/two-factor/two-factor.php';
if ( ! is_file( $swsl_mfa ) ) throw new RuntimeException( 'Install the real Two Factor plugin first.' );
require_once $swsl_mfa;
if ( ! has_action( 'wp_login', array( Two_Factor_Core::class, 'wp_login' ) ) || ! has_filter( 'authenticate', array( Two_Factor_Core::class, 'filter_authenticate' ) ) ) throw new RuntimeException( 'Actual MFA hooks are required.' );
$swsl_user = get_user_by( 'login', 'swsl-test-admin' );
if ( ! $swsl_user instanceof WP_User ) throw new RuntimeException( 'Missing fixture administrator.' );
wp_set_current_user( 0 );
$swsl_sessions = WP_Session_Tokens::get_instance( $swsl_user->ID );
$swsl_before = $swsl_sessions->get_all();
$swsl_policy = new SW\Securelogin\Auth\AuthPolicy();
$swsl_denied = 0;
try { $swsl_policy->assertCompatible(); } catch ( RuntimeException ) { ++$swsl_denied; }
try { ( new SW\Securelogin\Auth\LoginSessionIssuer( $swsl_policy ) )->issue( $swsl_user, false, time() + 120 ); } catch ( RuntimeException ) { ++$swsl_denied; }
if ( 2 !== $swsl_denied || 0 !== get_current_user_id() || $swsl_before !== $swsl_sessions->get_all() ) throw new RuntimeException( 'MFA boundary allowed a passkey session.' );
echo 'PASS: Two Factor ' . TWO_FACTOR_VERSION . ' blocks unreviewed passkey authentication and creates no session.' . "\n";
