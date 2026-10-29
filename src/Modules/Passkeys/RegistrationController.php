<?php
/**
 * Authenticated passkey management REST routes.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;


use RuntimeException;
use SW\Securelogin\Auth\Reauthentication;
use SW\Securelogin\Security\RateLimiter;
use SW\Securelogin\Security\TransactionRepository;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** All management endpoints operate on the current cookie-session owner only. */
final class RegistrationController {
	/** Inject persistence, proof and protocol services.
	 *
	 * @param RelyingParty          $rp Pinned origin.
	 * @param CredentialRepository  $credentials Credential persistence.
	 * @param TransactionRepository $transactions One-time transactions.
	 * @param Reauthentication      $reauthentication Fresh proof.
	 * @param RateLimiter           $limiter Request counters.
	 * @param WebAuthnService       $webauthn Protocol adapter.
	 */
	public function __construct( private RelyingParty $rp, private CredentialRepository $credentials, private TransactionRepository $transactions, private Reauthentication $reauthentication, private RateLimiter $limiter, private WebAuthnService $webauthn ) {}

	/** Register explicit permission callbacks and request schemas. */
	public function register(): void {
		$routes = array(
			array( '/passkeys', 'GET', 'list', array() ),
			array( '/passkeys/reauthenticate', 'POST', 'reauthenticate', array( 'password' ) ),
			array( '/passkeys/registration/options', 'POST', 'options', array( 'grant', 'name' ) ),
			array( '/passkeys/registration/verify', 'POST', 'verify', array( 'transaction', 'clientDataJSON', 'attestationObject', 'rawId', 'transports' ) ),
			array( '/passkeys/(?P<id>[1-9][0-9]{0,9})', 'PATCH', 'rename', array( 'grant', 'name' ) ),
			array( '/passkeys/(?P<id>[1-9][0-9]{0,9})', 'DELETE', 'revoke', array( 'grant' ) ),
		);
		foreach ( $routes as $route ) {
			$args = array();
			foreach ( $route[3] as $field ) {
				$args[ $field ] = array(
					'required'          => true,
					'type'              => 'transports' === $field ? 'array' : 'string',
					'validate_callback' => static fn( $value ): bool => 'transports' === $field ? is_array( $value ) : is_string( $value ),
				);
			}
			register_rest_route(
				'sw-securelogin/v1',
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, 'dispatch' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => $args,
					'swsl_operation'      => $route[2],
					'swsl_fields'         => $route[3],
				)
			);
		}
	}

	/** Require the existing cookie session, same-site request and REST nonce.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error Authorization result.
	 */
	public function permission( WP_REST_Request $request ): true|WP_Error {
		$user_id = get_current_user_id();
		$origin  = $request->get_header( 'origin' );
		if ( ! is_ssl() || ! $user_id || ! current_user_can( 'read' ) || ! current_user_can( 'edit_user', $user_id ) || '' === wp_get_session_token() || ! wp_verify_nonce( $request->get_header( 'x_wp_nonce' ), 'wp_rest' ) ) {
			return $this->error( 403 );
		}
		if ( ( null !== $origin && $origin !== $this->rp->origin ) || ( 'GET' !== $request->get_method() && $origin !== $this->rp->origin ) ) {
			return $this->error( 403 );
		}
		if ( strlen( $request->get_body() ) > 32768 || ( 'GET' !== $request->get_method() && 'application/json' !== strtolower( trim( explode( ';', (string) $request->get_header( 'content_type' ) )[0] ) ) ) ) {
			return $this->error( 400 );
		}
		return true;
	}

	/** Handle validated request data with neutral public failures.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Management result.
	 */
	public function dispatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Keep the boundary safe even if another caller invokes the callback directly.
		$permission = $this->permission( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		if ( ! $this->limiter->allow( 'passkeys_management', get_current_user_id(), 60, 300 ) ) {
			return $this->error( 429 );
		}
		try {
			$result = $this->perform( $request );
			return new WP_REST_Response( $result, 200, array( 'Cache-Control' => 'no-store' ) );
		} catch ( Throwable $exception ) {
			return $this->error( 400 );
		}
	}

	/** Validate and perform one management operation.
	 *
	 * @param WP_REST_Request $request Authenticated request.
	 * @return array<string, mixed> Operation result.
	 * @throws RuntimeException For invalid fields, limits or operation names.
	 */
	private function perform( WP_REST_Request $request ): array {
		$attributes = $request->get_attributes();
		$operation  = $attributes['swsl_operation'] ?? '';
		$body       = 'GET' === $request->get_method() ? array() : json_decode( $request->get_body(), true, 32, JSON_THROW_ON_ERROR );
		if ( ! is_array( $body ) || array_diff( array_keys( $body ), $attributes['swsl_fields'] ?? array() ) ) {
			throw new RuntimeException( 'Unexpected request fields.' );
		}
		return match ( $operation ) {
			'list' => array( 'credentials' => $this->credentials->listForUser( get_current_user_id() ) ),
			'reauthenticate' => array( 'grant' => $this->reauthentication->password( $this->text( $body, 'password', 4096 ) ) ),
			'options' => $this->options( $body ),
			'verify' => $this->verify( $body ),
			'rename', 'revoke' => $this->mutate( $operation, $request, $body ),
			default => throw new RuntimeException( 'Unknown management operation.' ),
		};
	}

	/** Start an enrollment after consuming fresh proof.
	 *
	 * @param array<string, mixed> $body Validated transport object.
	 * @return array<string, mixed> Browser options and transaction token.
	 * @throws RuntimeException At the credential limit.
	 */
	private function options( array $body ): array {
		$name     = $this->text( $body, 'name', 160 );
		$deadline = $this->reauthentication->consume( $this->text( $body, 'grant', 64 ) );
		$user     = wp_get_current_user();
		$ids      = $this->credentials->ids( $user->ID );
		if ( count( $ids ) >= CredentialRepository::LIMIT ) {
			throw new RuntimeException( 'Credential limit reached.' );
		}
		$handle  = $this->credentials->handle( $user->ID );
		$options = $this->webauthn->options( $handle, $user->user_login, $user->display_name, $ids );
		$token   = $this->transactions->issue(
			$user->ID,
			$this->reauthentication->binding(),
			'registration',
			array(
				'challenge' => Base64Url::encode( $this->webauthn->challenge() ),
				'handle'    => Base64Url::encode( $handle ),
				'name'      => $name,
			),
			min( $deadline, time() + 120 )
		);
		return array(
			'transaction' => $token,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Browser option field from the library.
			'publicKey'   => $options->publicKey,
		);
	}

	/** Consume before checking the library response or writing any credential.
	 *
	 * @param array<string, mixed> $body Credential transport fields.
	 * @return array<string, bool> Success marker.
	 * @throws RuntimeException For expired, unbound or replayed transactions.
	 */
	private function verify( array $body ): array {
		$payload = $this->transactions->consume( $this->text( $body, 'transaction', 64 ), get_current_user_id(), $this->reauthentication->binding(), 'registration' );
		if ( null === $payload ) {
			throw new RuntimeException( 'Invalid registration transaction.' );
		}
		$transports = $body['transports'] ?? null;
		if ( ! is_array( $transports ) || ! array_is_list( $transports ) || count( $transports ) > 5 ) {
			throw new RuntimeException( 'Invalid transport hints.' );
		}
		foreach ( $transports as $transport ) {
			if ( ! is_string( $transport ) || ! in_array( $transport, array( 'usb', 'nfc', 'ble', 'hybrid', 'internal' ), true ) ) {
				throw new RuntimeException( 'Invalid transport hint type.' );
			}
		}
		$verified = $this->webauthn->verify( $this->text( $body, 'clientDataJSON', 6000 ), $this->text( $body, 'attestationObject', 22000 ), $this->text( $body, 'rawId', 1400 ), Base64Url::decode( $payload['challenge'], 32 ) );
		if ( (int) $payload['expires_at'] <= time() ) {
			throw new RuntimeException( 'Enrollment proof expired during verification.' );
		}
		$this->credentials->add( get_current_user_id(), Base64Url::decode( $payload['handle'], 32 ), $verified, $payload['name'], $transports );
		return array( 'success' => true );
	}

	/** Rename or revoke only the current owner's credential with fresh proof.
	 *
	 * @param string               $operation Internal operation.
	 * @param WP_REST_Request      $request Incoming request.
	 * @param array<string, mixed> $body Proof and optional name.
	 * @return array<string, bool> Success marker.
	 * @throws RuntimeException If the credential cannot be changed.
	 */
	private function mutate( string $operation, WP_REST_Request $request, array $body ): array {
		$this->reauthentication->consume( $this->text( $body, 'grant', 64 ) );
		$id = filter_var( $request->get_param( 'id' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $id || ( 'rename' === $operation
			? ! $this->credentials->rename( get_current_user_id(), $id, $this->text( $body, 'name', 160 ) )
			: ! $this->credentials->revoke( get_current_user_id(), $id ) ) ) {
			throw new RuntimeException( 'Credential mutation was not accepted.' );
		}
		return array( 'success' => true );
	}

	/** Validate strings without silently coercing or sanitizing protocol bytes.
	 *
	 * @param array<string, mixed> $body Input object.
	 * @param string               $key Field name.
	 * @param int                  $maximum Byte limit.
	 * @return string Validated string.
	 * @throws RuntimeException For invalid types or lengths.
	 */
	private function text( array $body, string $key, int $maximum ): string {
		$value = $body[ $key ] ?? null;
		if ( ! is_string( $value ) || '' === $value || ( 'name' === $key && '' === trim( $value ) ) || strlen( $value ) > $maximum || str_contains( $value, "\0" ) ) {
			throw new RuntimeException( 'Invalid request field.' );
		}
		return $value;
	}

	/** Produce the same public message for all management failures.
	 *
	 * @param int $status HTTP status.
	 * @return WP_Error Neutral failure.
	 */
	private function error( int $status ): WP_Error {
		return new WP_Error( 'swsl_passkey_failed', __( 'The passkey operation could not be completed. Please confirm your password again and retry.', 'sw-securelogin' ), array( 'status' => $status ) );
	}
}
