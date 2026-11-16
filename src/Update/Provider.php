<?php
/**
 * Thin WordPress policy adapter. No state reads or network activity on frontend requests.
 * Offers come from the locally cached, signed manifest only; installation stays in WordPress core.
 *
 * @package sw-securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Update;

use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Integrates manual verified updates with WordPress. */
final class Provider {

	public const AJAX_ACTION = 'swsl_check_github_updates';
	public const NONCE       = 'swsl_verified_updates';
	public const HANDLE      = 'swsl-verified-updates';
	public const MIGRATED    = 'swsl_verified_updates_migrated';

	/** Register local guards in every context; defer HTTP to authenticated AJAX. */
	public static function init(): void {
		add_filter( 'auto_update_plugin', array( self::class, 'automatic' ), PHP_INT_MAX, 2 );
		add_filter( 'plugin_auto_update_setting_html', array( self::class, 'automatic_html' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( self::class, 'download_guard' ), PHP_INT_MAX, 4 );
		add_filter( 'site_transient_update_plugins', array( self::class, 'offers' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'offers' ) );
		add_filter( 'update_plugins_github.com', array( self::class, 'update_uri' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'details' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( self::class, 'row_meta' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'migrate' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'ajax_check' ) );
	}

	/**
	 * Plugins managed by this provider (basename => identity).
	 *
	 * @return array<string,array{slug:string,version:string}>
	 */
	public static function descriptors(): array {
		/**
		 * Filters the plugin identities managed by the verified GitHub provider (tests only).
		 *
		 * @param array<string,array{slug:string,version:string}> $descriptors Basename => identity.
		 */
		return (array) apply_filters( 'swsl_verified_update_descriptors', require __DIR__ . '/Config/descriptors.php' );
	}

	/** Return the locally pinned update URI. */
	private static function uri(): string {
		return 'https://github.com/' . Manifest::source()['repository'];
	}

	/**
	 * Automatic installation of our plugin is never allowed, even for stale cached offers.
	 *
	 * @param mixed  $allowed Previous decision.
	 * @param object $item    Update offer.
	 */
	public static function automatic( mixed $allowed, object $item ): mixed {
		$plugin = isset( $item->plugin ) && is_string( $item->plugin ) ? $item->plugin : '';
		return isset( self::descriptors()[ $plugin ] ) ? false : $allowed;
	}

	/**
	 * Explain the manual installation policy.
	 *
	 * @param string $html Previous HTML.
	 * @param string $file Plugin basename.
	 */
	public static function automatic_html( string $html, string $file ): string {
		return isset( self::descriptors()[ $file ] ) ? esc_html__( 'Manual updates only', 'sw-securelogin' ) : $html;
	}

	/**
	 * Gate in front of core's download: only a manually triggered, nonce-protected native update gets the verified ZIP.
	 *
	 * @param mixed               $reply    Previous reply.
	 * @param string              $url      Expected package URL.
	 * @param mixed               $upgrader Upgrader instance.
	 * @param array<string,mixed> $extra    Hook extra (plugin basename).
	 */
	public static function download_guard( mixed $reply, string $url, mixed $upgrader, array $extra = array() ): mixed {
		$plugin     = isset( $extra['plugin'] ) && is_string( $extra['plugin'] ) ? $extra['plugin'] : '';
		$descriptor = self::descriptors()[ $plugin ] ?? null;
		// Also catch a known provider URL when hook_extra is missing or was tampered with.
		$provider_url = str_starts_with( $url, self::uri() . '/releases/download/' );
		if ( null === $descriptor && ! $provider_url ) {
			return $reply;
		}
		if ( null === $descriptor || ! is_admin() || wp_doing_cron() || ! current_user_can( 'update_plugins' ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return new WP_Error( 'manual_update_required', __( 'GitHub installation is only allowed manually in the WordPress backend.', 'sw-securelogin' ) );
		}
		$skin = is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) ? $upgrader->skin : null;
		if ( ! Service::interactive() || ( null !== $skin && is_a( $skin, 'Automatic_Upgrader_Skin' ) && ! is_a( $skin, 'WP_Ajax_Upgrader_Skin' ) ) ) {
			return new WP_Error( 'manual_update_required', __( 'Automatic GitHub installation is blocked.', 'sw-securelogin' ) );
		}
		// Core authenticated the native single/bulk/updates-AJAX action before invoking its upgrader; check it again.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is verified below against the per-action nonce name.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
		$nonce  = '';
		if ( isset( $_REQUEST['_ajax_nonce'] ) && is_string( $_REQUEST['_ajax_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_ajax_nonce'] ) );
		} elseif ( isset( $_REQUEST['_wpnonce'] ) && is_string( $_REQUEST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$nonce_actions = array(
			'upgrade-plugin'    => 'upgrade-plugin_' . $plugin,
			'update-plugin'     => 'updates',
			'update-selected'   => 'bulk-update-plugins',
			'do-plugin-upgrade' => 'upgrade-core',
		);
		if ( ! isset( $nonce_actions[ $action ] ) || ! wp_verify_nonce( $nonce, $nonce_actions[ $action ] ) ) {
			return new WP_Error( 'manual_update_required', __( 'No valid manual WordPress update request.', 'sw-securelogin' ) );
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = WP_PLUGIN_DIR . '/' . $plugin;
		if ( is_file( $installed ) ) {
			$descriptor['version'] = (string) get_plugin_data( $installed, false, false )['Version'];
		}
		return Service::download( $url, $descriptor );
	}

	/**
	 * Injects the cached, verified offer for our own plugin; foreign entries stay untouched.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 */
	public static function offers( mixed $transient ): mixed {
		if ( ! is_admin() || wp_doing_cron() || ! current_user_can( 'update_plugins' ) || ! $transient instanceof stdClass ) {
			return $transient;
		}
		$checked = isset( $transient->checked ) && is_array( $transient->checked ) ? $transient->checked : array();
		$state   = Store::read();
		foreach ( self::descriptors() as $file => $descriptor ) {
			if ( ! isset( $checked[ $file ] ) ) {
				continue;
			}
			unset( $transient->response[ $file ], $transient->no_update[ $file ] );
			$manifest = is_array( $state['manifest'] ?? null ) ? $state['manifest'] : array();
			if ( empty( $state['last_success'] ) || time() - (int) $state['last_success'] > 7 * DAY_IN_SECONDS || ! $manifest || ! Service::keys() ) {
				continue;
			}
			$package = Manifest::package( $manifest, $descriptor['slug'] );
			if ( null === $package || ! Service::compatible( $package ) ) {
				continue;
			}
			$offer  = (object) array(
				'id'           => self::uri(),
				'slug'         => $descriptor['slug'],
				'plugin'       => $file,
				'new_version'  => $package['version'],
				'version'      => $package['version'],
				'url'          => self::uri() . '/releases',
				'package'      => Manifest::url( $manifest, $package ),
				'requires'     => $package['requires_wp'],
				'requires_php' => $package['requires_php'],
				'autoupdate'   => false,
			);
			$bucket = version_compare( (string) $package['version'], (string) $checked[ $file ], '>' ) ? 'response' : 'no_update';
			if ( ! isset( $transient->{$bucket} ) || ! is_array( $transient->{$bucket} ) ) {
				$transient->{$bucket} = array();
			}
			$transient->{$bucket}[ $file ] = $offer;
		}
		return $transient;
	}

	/**
	 * Update URI hook for github.com: answers only for our own plugin and URI, from the local cache.
	 *
	 * @param mixed               $reply Previous reply.
	 * @param array<string,mixed> $data  Plugin header data.
	 * @param string              $file  Plugin basename.
	 */
	public static function update_uri( mixed $reply, array $data, string $file ): mixed {
		if ( ! isset( self::descriptors()[ $file ] ) || ( $data['UpdateURI'] ?? '' ) !== self::uri() ) {
			return $reply;
		}
		$transient = self::offers(
			(object) array(
				'checked'   => array( $file => $data['Version'] ?? '0' ),
				'response'  => array(),
				'no_update' => array(),
			)
		);
		return is_object( $transient ) ? ( $transient->response[ $file ] ?? $transient->no_update[ $file ] ?? false ) : false;
	}

	/**
	 * Plugin details dialog from cached data only.
	 *
	 * @param mixed  $reply  Previous reply.
	 * @param string $action API action.
	 * @param mixed  $args   API arguments.
	 */
	public static function details( mixed $reply, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
			return $reply;
		}
		foreach ( self::descriptors() as $file => $descriptor ) {
			if ( ( $args->slug ?? '' ) !== $descriptor['slug'] ) {
				continue;
			}
			$offers = self::offers( (object) array( 'checked' => array( $file => $descriptor['version'] ) ) );
			$offer  = is_object( $offers ) ? ( $offers->response[ $file ] ?? $offers->no_update[ $file ] ?? null ) : null;
			if ( $offer instanceof stdClass ) {
				return (object) array(
					'name'          => $descriptor['slug'],
					'slug'          => $descriptor['slug'],
					'version'       => $offer->new_version,
					'requires'      => $offer->requires,
					'requires_php'  => $offer->requires_php,
					'download_link' => $offer->package,
					'sections'      => array( 'description' => esc_html__( 'Signed GitHub release. Install only manually through WordPress.', 'sw-securelogin' ) ),
				);
			}
		}
		return $reply;
	}

	/**
	 * Check button and status in the plugin's own row of the native plugin list.
	 *
	 * @param array<int|string,string> $meta Row meta links.
	 * @param string                   $file Plugin basename.
	 * @return array<int|string,string>
	 */
	public static function row_meta( array $meta, string $file ): array {
		if ( ! isset( self::descriptors()[ $file ] ) || ! Service::interactive() ) {
			return $meta;
		}
		$meta[] = '<button type="button" class="button-link" id="swsl-verified-updates-check">' . esc_html__( 'Check for updates now', 'sw-securelogin' ) . '</button>'
			. ' <span id="swsl-verified-updates-result" role="status" aria-live="polite" hidden></span>'
			. ' <span id="swsl-verified-updates-status">' . esc_html( self::status_text() ) . '</span>';
		return $meta;
	}

	/**
	 * Human readable state from the local cache (no request).
	 */
	public static function status_text(): string {
		if ( ! Service::keys() ) {
			return __( 'GitHub updates are blocked: no public release key is configured.', 'sw-securelogin' );
		}
		$state = Store::read();
		if ( empty( $state['last_success'] ) ) {
			$text = __( 'No verified update check yet.', 'sw-securelogin' );
		} else {
			/* translators: %s: time span, e.g. "2 hours" */
			$text = sprintf( __( 'Last verified check: %s ago.', 'sw-securelogin' ), human_time_diff( (int) $state['last_success'] ) );
		}
		if ( ! empty( $state['error'] ) && is_string( $state['error'] ) ) {
			/* translators: %s: error message */
			$text .= ' ' . sprintf( __( 'Last error: %s', 'sw-securelogin' ), $state['error'] );
		}
		return $text;
	}

	/**
	 * AJAX endpoint: nonce + update_plugins; GitHub is only contacted by Service::check().
	 */
	public static function ajax_check(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$manual = isset( $_POST['manual'] ) && '1' === $_POST['manual'];
		$result = Service::check( $manual );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Loads the deferred check script for authorised interactive sessions only.
	 */
	public static function enqueue(): void {
		if ( ! Service::interactive() ) {
			return;
		}
		$state = Store::read();
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/js/swsl-updates.js', SWSL_FILE ), array(), SWSL_VERSION, true );
		wp_add_inline_script(
			self::HANDLE,
			'window.swslVerifiedUpdates=' . wp_json_encode(
				array(
					'url'      => admin_url( 'admin-ajax.php' ),
					'action'   => self::AJAX_ACTION,
					'nonce'    => wp_create_nonce( self::NONCE ),
					'due'      => (bool) Service::keys() && time() - (int) ( $state['last_attempt'] ?? 0 ) >= DAY_IN_SECONDS,
					'messages' => array(
						'checking' => __( 'Checking signed releases …', 'sw-securelogin' ),
						'done'     => __( 'Check finished.', 'sw-securelogin' ),
						'failed'   => __( 'GitHub could not be reached. Please try again later.', 'sw-securelogin' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * One-time cleanup of automatic-update selections and cached offers of this plugin.
	 */
	public static function migrate(): void {
		if ( ! Service::interactive() || get_site_option( self::MIGRATED, false ) ) {
			return;
		}
		self::clear_automatic_selection();
		self::clear_offers();
		if ( is_multisite() ) {
			update_site_option( self::MIGRATED, 1 );
		} else {
			update_option( self::MIGRATED, 1, false );
		}
	}

	/** Remove only our own automatic installation selection. */
	private static function clear_automatic_selection(): void {
		$selected = get_site_option( 'auto_update_plugins', array() );
		if ( is_array( $selected ) ) {
			update_site_option( 'auto_update_plugins', array_values( array_diff( $selected, array_keys( self::descriptors() ) ) ) );
		}
	}

	/** Remove only our own cached executable update offers. */
	private static function clear_offers(): void {
		$transient = get_site_transient( 'update_plugins' );
		if ( $transient instanceof stdClass ) {
			foreach ( array_keys( self::descriptors() ) as $file ) {
				unset( $transient->response[ $file ], $transient->no_update[ $file ] );
			}
			set_site_transient( 'update_plugins', $transient );
		}
	}

	/**
	 * Removes executable cached offers before this plugin's guards stop loading; foreign offers stay.
	 */
	public static function deactivate(): void {
		self::clear_automatic_selection();
		remove_filter( 'site_transient_update_plugins', array( self::class, 'offers' ) );
		remove_filter( 'pre_set_site_transient_update_plugins', array( self::class, 'offers' ) );
		self::clear_offers();
	}

	/**
	 * Removes every option this component created (uninstall).
	 */
	public static function uninstall(): void {
		self::deactivate();
		delete_site_option( Store::STATE );
		delete_site_option( Store::LOCK );
		delete_option( Store::STATE );
		delete_option( Store::LOCK );
		delete_option( self::MIGRATED );
		delete_site_option( self::MIGRATED );
	}
}
