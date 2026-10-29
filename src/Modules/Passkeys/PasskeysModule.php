<?php
/**
 * Optional enrollment module and its administration controls.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;


use lbuchs\WebAuthn\WebAuthn;
use RuntimeException;
use SW\Securelogin\Auth\Reauthentication;
use SW\Securelogin\Core\ModuleInterface;
use SW\Securelogin\Core\SettingsRepository;
use SW\Securelogin\Security\RateLimiter;
use SW\Securelogin\Security\TransactionRepository;
use Throwable;
use wpdb;
use WP_REST_Request;
use WP_REST_Response;

/** No login or session issuance is exposed by this enrollment-only milestone. */
final class PasskeysModule implements ModuleInterface {
	/** Compose the module from the WordPress database and validated settings.
	 *
	 * @param wpdb               $database WordPress connection.
	 * @param SettingsRepository $settings Configuration boundary.
	 */
	public function __construct( private wpdb $database, private SettingsRepository $settings ) {}

	/** Module identifier. */
	public function id(): string {
		return 'passkeys';
	}

	/** Enrollment implementation is installed. */
	public function isAvailable(): bool {
		return class_exists( WebAuthn::class ) && extension_loaded( 'openssl' );
	}

	/** Check dependencies and pinned site configuration.
	 *
	 * @return string[] Unmet requirements.
	 */
	public function requirements(): array {
		if ( ! $this->settings->schemaReady() || ! is_ssl() || is_multisite() || ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) ) {
			return array( 'Passkey schema, HTTPS and single-site support are required.' );
		}
		try {
			new WebAuthnService( RelyingParty::configured() );
		} catch ( Throwable $exception ) {
			return array( 'The pinned relying-party origin is unavailable.' );
		}
		return array();
	}

	/** Public configuration schema without any secrets.
	 *
	 * @return array<string, mixed> Schema.
	 */
	public function configurationSchema(): array {
		return array(
			'enabled'          => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'credential_limit' => CredentialRepository::LIMIT,
		);
	}

	/** Wire authenticated enrollment only after registry checks succeed. */
	public function register(): void {
		$transactions = new TransactionRepository( $this->database );
		$limiter      = new RateLimiter( $this->database );
		$rp           = RelyingParty::configured();
		$controller   = new RegistrationController( $rp, new CredentialRepository( $this->database ), $transactions, new Reauthentication( $transactions, $limiter ), $limiter, new WebAuthnService( $rp ) );
		add_action( 'rest_api_init', array( $controller, 'register' ) );
		( new ProfileController() )->register();
	}

	/** Setup and data lifecycle hooks must also exist while enrollment is disabled. */
	public function registerSettings(): void {
		add_action( 'swsl_module_overview', array( $this, 'renderSettings' ) );
		add_action( 'admin_post_swsl_passkeys_settings', array( $this, 'saveSettings' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'noStore' ), 10, 3 );
		if ( $this->settings->schemaReady() ) {
			( new PrivacyController( $this->database, new CredentialRepository( $this->database ) ) )->register();
		}
	}

	/** Add no-store even to rejected authentication requests.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @param mixed            $server REST server, unused.
	 * @param WP_REST_Request  $request Request.
	 * @return WP_REST_Response Response.
	 */
	public function noStore( WP_REST_Response $response, mixed $server, WP_REST_Request $request ): WP_REST_Response {
		if ( str_starts_with( $request->get_route(), '/sw-securelogin/v1/passkeys' ) ) {
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	/** Render a deliberate administrator-only enable/disable control. */
	public function renderSettings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Passkey registration', 'sw-securelogin' ) . '</h2>';
		echo '<p>' . esc_html__( 'Requires HTTPS. Enabling pins the configured site origin; domain changes require a separate migration.', 'sw-securelogin' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="swsl_passkeys_settings">';
		wp_nonce_field( 'swsl_passkeys_settings' );
		echo '<label><input name="enabled" type="checkbox" value="1" ' . checked( in_array( 'passkeys', $this->settings->enabledModules(), true ), true, false ) . '> ' . esc_html__( 'Enable passkey registration', 'sw-securelogin' ) . '</label>';
		submit_button( __( 'Save', 'sw-securelogin' ) );
		echo '</form>';
	}

	/** Apply the explicit capability- and nonce-protected module choice. */
	public function saveSettings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'sw-securelogin' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'swsl_passkeys_settings' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact literal allowlist comparison; no free-form input is stored.
		$enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
		try {
			$this->configure( $enabled );
		} catch ( Throwable $exception ) {
			wp_die( esc_html__( 'Passkey configuration could not be saved. Check HTTPS and the pinned site origin.', 'sw-securelogin' ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=swsl-overview' ) );
	}
	/** Apply a validated module choice.
	 *
	 * @param bool $enabled Explicit administrator choice.
	 * @throws RuntimeException When requirements or origin checks fail.
	 */
	private function configure( bool $enabled ): void {
		if ( $enabled ) {
			$parts = wp_parse_url( site_url() );
			if ( ! $this->isAvailable() || ! $this->settings->schemaReady() || ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) || ! is_ssl() || ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
				throw new RuntimeException( 'Passkey requirements are not satisfied.' );
			}
			$host   = strtolower( $parts['host'] );
			$origin = 'https://' . $host . ( isset( $parts['port'] ) && 443 !== $parts['port'] ? ':' . $parts['port'] : '' );
			add_option(
				'swsl_passkeys_rp',
				array(
					'origin' => $origin,
					'id'     => $host,
				),
				'',
				false
			);
			RelyingParty::configured();
		}
		$settings = array( 'enabled_modules' => $enabled ? array( 'passkeys' ) : array() );
		update_option( 'swsl_settings', $settings, false );
		if ( get_option( 'swsl_settings' ) !== $settings ) {
			throw new RuntimeException( 'Module settings were not persisted.' );
		}
		if ( ! wp_next_scheduled( 'swsl_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'swsl_cleanup' );
		}
	}
}
