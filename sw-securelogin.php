<?php
/**
 * Plugin Name: SW Securelogin
 * Description: Modular authentication platform. Passkey registration and login preview; password login remains available.
 * Version: 0.3.0
 * Requires at least: 6.7
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sw-securelogin
 * Domain Path: /languages
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Keep this entry point parseable on older PHP installations.
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	return;
}

// Deactivation removes only this plugin's maintenance job, never credential data.
register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( 'swsl_cleanup' );
	}
);

// Built distributions use isolated dependencies; source checkouts use Composer.
$swsl_autoload = __DIR__ . '/vendor-prefixed/autoload.php';
if ( ! is_readable( $swsl_autoload ) ) {
	$swsl_autoload = __DIR__ . '/vendor/autoload.php';
}
if ( ! is_readable( $swsl_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'SW Securelogin requires its Composer dependencies. Run composer install in the plugin directory.', 'sw-securelogin' ) . '</p></div>';
			}
		}
	);
	return;
}
$swsl_loader = require $swsl_autoload;
// Mozart generates a dependency loader; register the plugin's own PSR-4 classes.
$swsl_loader->addPsr4( 'SW\\Securelogin\\', __DIR__ . '/src/' );
unset( $swsl_autoload, $swsl_loader );

add_action(
	'plugins_loaded',
	static function (): void {
		global $wpdb;
		( new \SW\Securelogin\Core\Plugin( array( 2 => new \SW\Securelogin\Modules\Passkeys\PasskeysSchema() ) ) )->register();
		if ( ! is_multisite() ) {
			$swsl_settings = new \SW\Securelogin\Core\SettingsRepository();
			$swsl_module   = new \SW\Securelogin\Modules\Passkeys\PasskeysModule( $wpdb, $swsl_settings );
			$swsl_module->registerSettings();
			add_action(
				'init',
				static function () use ( $swsl_module, $swsl_settings ): void {
					$swsl_registry = new \SW\Securelogin\Core\ModuleRegistry();
					$swsl_registry->add( $swsl_module );
					$swsl_registry->registerEnabled( $swsl_settings->enabledModules() );
				},
				20
			);
		}
	}
);
