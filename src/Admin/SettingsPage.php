<?php
/**
 * Administration overview.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Admin;

defined( 'ABSPATH' ) || exit;

/** Module overview and removal policy, restricted to administrators. */
final class SettingsPage {
	/** Load the additional confirmation only on this plugin's overview.
	 *
	 * @param string $hook Current administration screen.
	 */
	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_swsl-overview' !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_script( 'swsl-settings', plugins_url( 'assets/js/settings.js', dirname( __DIR__, 2 ) . '/sw-securelogin.php' ), array(), SWSL_VERSION, true );
		wp_localize_script(
			'swsl-settings',
			'swslRemoval',
			array( 'confirm' => __( 'Really delete all SW Securelogin passkeys and settings when this plugin is deleted? Deactivation will still keep all data. Make a backup first.', 'sw-securelogin' ) )
		);
	}

	/** Save the explicit removal choice after capability, method and nonce checks. */
	public function saveRemovalSettings(): void {
		if ( ! current_user_can( 'manage_options' ) || is_multisite() ) {
			wp_die( esc_html__( 'Access denied.', 'sw-securelogin' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact method comparison rejects malformed values.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Access denied.', 'sw-securelogin' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'swsl_uninstall_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the literal checkbox value is accepted; no free-form input is stored.
		if ( isset( $_POST['delete_data'] ) && '1' !== $_POST['delete_data'] ) {
			wp_die( esc_html__( 'Access denied.', 'sw-securelogin' ), '', array( 'response' => 400 ) );
		}
		$delete_data = isset( $_POST['delete_data'] );
		if ( $delete_data ) {
			update_option( 'swsl_delete_data_on_uninstall', '1', false );
		} else {
			delete_option( 'swsl_delete_data_on_uninstall' );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Option caches may mask failed deletion; verify the saved consent against the database.
		$saved = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'swsl_delete_data_on_uninstall' ) );
		if ( '' !== $wpdb->last_error || ( $delete_data ? '1' : null ) !== $saved ) {
			wp_cache_delete( 'swsl_delete_data_on_uninstall', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_die( esc_html__( 'The removal setting could not be saved. Please retry.', 'sw-securelogin' ), '', array( 'response' => 500 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=swsl-overview' ) );
	}

	/** Register the plugin menu. */
	public function register(): void {
		add_menu_page(
			__( 'SW Securelogin', 'sw-securelogin' ),
			__( 'SW Securelogin', 'sw-securelogin' ),
			'manage_options',
			'swsl-overview',
			array( $this, 'render' ),
			'dashicons-lock'
		);
	}

	/** Render only after checking access, including direct callback invocation. */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'SW Securelogin', 'sw-securelogin' ) . '</h1>';
		echo '<p>' . esc_html__( 'Passkey registration and login can be enabled below. Password login remains available.', 'sw-securelogin' ) . '</p>';
		echo '<h2>' . esc_html__( 'Modules', 'sw-securelogin' ) . '</h2><ul>';
		echo '<li>' . esc_html__( 'Passkeys — registration and login', 'sw-securelogin' ) . '</li>';
		echo '<li>' . esc_html__( 'External OpenID Connect login — planned for version 1.1+', 'sw-securelogin' ) . '</li>';
		echo '<li>' . esc_html__( 'Authorization Server — requires a separate security review', 'sw-securelogin' ) . '</li></ul>';
		echo '<h2>' . esc_html__( 'Security status', 'sw-securelogin' ) . '</h2><p>';
		echo is_ssl()
			? esc_html__( 'HTTPS is active for this request.', 'sw-securelogin' )
			: esc_html__( 'HTTPS is required before authentication modules can be used.', 'sw-securelogin' );
		echo '</p>';
		do_action( 'swsl_module_overview' );
		echo '<h2>' . esc_html__( 'Data on plugin deletion', 'sw-securelogin' ) . '</h2>';
		echo '<p id="swsl-removal-help">' . esc_html__( 'By default, deleting this plugin keeps its data. The option below permanently removes all registered passkeys, account handles, pending transactions, rate limits and plugin settings when WordPress deletes the plugin. WordPress users, passwords and sessions are kept. Back up your database first. Deactivation always keeps data.', 'sw-securelogin' ) . '</p>';
		echo '<form id="swsl-removal-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="swsl_uninstall_settings">';
		wp_nonce_field( 'swsl_uninstall_settings' );
		echo '<label><input id="swsl-delete-data" name="delete_data" type="checkbox" value="1" aria-describedby="swsl-removal-help" ' . checked( '1', get_option( 'swsl_delete_data_on_uninstall' ), false ) . '> ' . esc_html__( 'Permanently delete all plugin data when deleting SW Securelogin', 'sw-securelogin' ) . '</label>';
		submit_button( __( 'Save removal setting', 'sw-securelogin' ), 'primary', 'swsl-save-removal' );
		echo '</form>';
		echo '</div>';
	}
}
