<?php
/**
 * Retain data unless an administrator explicitly opted into removal on deletion.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'swsl_cleanup' );

// Network data cleanup is unsupported. Never infer consent from truthy/malformed values.
if ( is_multisite() || '1' !== get_option( 'swsl_delete_data_on_uninstall' ) ) {
	return;
}

global $wpdb;
// Fixed allowlist: no wildcard deletion of tables or options belonging to other code.
foreach ( array( 'swsl_passkeys', 'swsl_user_handles', 'swsl_transactions', 'swsl_limits' ) as $swsl_suffix ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- WordPress has no table-removal API; this prepared DROP is restricted to owned tables and explicit uninstall consent.
	if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $swsl_suffix ) ) ) {
		wp_die( esc_html__( 'SW Securelogin data removal failed. Restore your backup or retry deletion before removing the plugin files.', 'sw-securelogin' ) );
	}
}

// Keep the schema marker and consent until table removal has completed.
foreach ( array( 'swsl_settings', 'swsl_passkeys_rp', 'swsl_schema_version', 'swsl_delete_data_on_uninstall' ) as $swsl_option ) {
	delete_option( $swsl_option );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- delete_option() may cache a missing value even after SQL failure; verify actual removal.
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name = %s', $wpdb->options, $swsl_option ) ) || '' !== $wpdb->last_error ) {
		wp_cache_delete( $swsl_option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_die( esc_html__( 'SW Securelogin data removal failed. Restore your backup or retry deletion before removing the plugin files.', 'sw-securelogin' ) );
	}
}
unset( $swsl_suffix, $swsl_option );
