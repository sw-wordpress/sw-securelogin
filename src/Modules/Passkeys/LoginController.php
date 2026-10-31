<?php
/**
 * Transaction-bound login and passkey reauthentication endpoints.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;

use RuntimeException;
use SW\Securelogin\Auth\AuthPolicy;
use SW\Securelogin\Auth\LoginSessionIssuer;
use SW\Securelogin\Auth\Reauthentication;
use SW\Securelogin\Security\AuditLogger;
use SW\Securelogin\Security\BrowserBinding;
use SW\Securelogin\Security\RateLimiter;
use SW\Securelogin\Security\TransactionRepository;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/** Public options disclose neither account existence nor credential identifiers. */
final class LoginController {
	/** Compose the assertion boundary.
	 *
	 * @param RelyingParty          $rp Pinned site origin.
	 * @param CredentialRepository  $credentials Owned credential store.
	 * @param TransactionRepository $transactions One-time ceremony store.
	 * @param Reauthentication      $reauthentication Existing-session proof grants.
	 * @param RateLimiter           $limiter Request budgets.
	 * @param WebAuthnService       $webauthn Cryptographic verifier.
	 * @param AuthPolicy            $policy Compatibility and user policies.
	 * @param LoginSessionIssuer    $sessions Core session issuer.
	 * @param BrowserBinding        $browser Anonymous cookie binding.
	 * @param AuditLogger           $audit Identifier-free event counters.
	 */
	public function __construct( private RelyingParty $rp, private CredentialRepository $credentials, private TransactionRepository $transactions, private Reauthentication $reauthentication, private RateLimiter $limiter, private WebAuthnService $webauthn, private AuthPolicy $policy, private LoginSessionIssuer $sessions, private BrowserBinding $browser, private AuditLogger $audit ) {}

	/** Register public login and authenticated reauthentication separately. */
	public function register(): void {
		foreach ( array( 'login', 'reauthentication' ) as $purpose ) {
			foreach ( array( 'options', 'verify' ) as $operation ) {
				register_rest_route(
					'sw-securelogin/v1',
					'/passkeys/' . $purpose . '/' . $operation,
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'dispatch' ),
						'permission_callback' => array( $this, 'permission' ),
						'swsl_purpose'        => $purpose,
						'swsl_operation'      => $operation,
					)
				);
			}
		}
	}

	/** Require HTTPS, exact origin and bounded JSON; reauthentication needs nonce/session.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error Boundary result.
	 */
	public function permission( WP_REST_Request $request ): true|WP_Error {
		if ( ! is_ssl() || 'POST' !== $request->get_method() || $request->get_header( 'origin' ) !== $this->rp->origin || strlen( $request->get_body() ) > 16384 || 'application/json' !== strtolower( trim( explode( ';', (string) $request->get_header( 'content_type' ) )[0] ) ) ) {
			return $this->error( 403 );
		}
		$reauth = 'reauthentication' === ( $request->get_attributes()['swsl_purpose'] ?? '' );
		if ( $reauth ) {
			$user_id = get_current_user_id();
			if ( ! $user_id || '' === wp_get_session_token() || ! current_user_can( 'read' ) || ! current_user_can( 'edit_user', $user_id ) || ! wp_verify_nonce( $request->get_header( 'x_wp_nonce' ), 'wp_rest' ) ) {
				return $this->error( 403 );
			}
		} elseif ( is_user_logged_in() || wp_validate_auth_cookie( '', 'logged_in' ) ) {
			return $this->error( 403 );
		}
		return true;
	}

	/** Recheck the boundary even when invoked directly, then count before work.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Neutral operation result.
	 * @throws RuntimeException For an invalid internal operation, translated into a neutral error.
	 */
	public function dispatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$permission = $this->permission( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$purpose = $request->get_attributes()['swsl_purpose'] ?? '';
		try {
			if ( ! $this->budget( $purpose ) ) {
				$this->audit->record( 'rate_limited' );
				return $this->error( 429 );
			}
			$body = json_decode( $request->get_body(), true, 16, JSON_THROW_ON_ERROR );
			if ( ! is_array( $body ) ) {
				return $this->error( 400 );
			}
			$result = match ( $request->get_attributes()['swsl_operation'] ?? '' ) {
				'options' => $this->options( $body, $purpose ),
				'verify' => $this->verify( $body, $purpose ),
				default => throw new RuntimeException( 'Invalid assertion operation.' ),
			};
			return new WP_REST_Response( $result, 200, array( 'Cache-Control' => 'no-store' ) );
		} catch ( Throwable $exception ) {
			$this->audit->record( 'login' === $purpose ? 'login_failed' : 'reauth_failed' );
			return $this->error( 400 );
		}
	}

	/** Limit by server-observed peer, never forwarded headers, plus a site-wide budget.
	 *
	 * @param string $purpose Ceremony namespace.
	 * @return bool Budget result.
	 */
	private function budget( string $purpose ): bool {
		if ( 'reauthentication' === $purpose ) {
			return $this->limiter->allow( 'assertion_reauth', get_current_user_id(), 20, 300 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Validate canonical peer address before hashing; do not trust proxy headers.
		$peer = $_SERVER['REMOTE_ADDR'] ?? '';
		if ( ! is_string( $peer ) || ! filter_var( $peer, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$key = hash_hmac( 'sha256', (string) inet_pton( $peer ), wp_salt( 'auth' ) );
		return $this->limiter->allow( 'login_site', 0, 1000, 300 ) && $this->limiter->allow( 'login_peer_' . $key, 0, 40, 300 );
	}

	/** Issue identically shaped options for real, empty and nonexistent accounts.
	 *
	 * @param array<string, mixed> $body Ceremony start fields.
	 * @param string               $purpose Login or reauthentication.
	 * @return array<string, mixed> Public ceremony data.
	 * @throws RuntimeException For invalid transport or exhausted budget.
	 */
	private function options( array $body, string $purpose ): array {
		$reauth = 'reauthentication' === $purpose;
		$this->fields( $body, $reauth ? array() : array( 'username', 'remember', 'redirect' ) );
		$this->policy->assertCompatible();
		$user = wp_get_current_user();
		if ( ! $reauth ) {
			$name = $this->text( $body, 'username', 100 );
			if ( ! is_bool( $body['remember'] ?? null ) || ! is_string( $body['redirect'] ?? null ) || strlen( $body['redirect'] ) > 2048 ) {
				throw new RuntimeException( 'Invalid login preferences.' );
			}
			$key = hash_hmac( 'sha256', strtolower( $name ), wp_salt( 'auth' ) );
			if ( ! $this->limiter->allow( 'login_name_' . $key, 0, 10, 300 ) ) {
				throw new RuntimeException( 'Account attempt budget exhausted.' );
			}
			// Same lookup order as core: login name first, then email address.
			$user = get_user_by( 'login', $name );
			if ( ! $user instanceof WP_User && is_email( $name ) ) {
				$user = get_user_by( 'email', $name );
			}
		}
		$options     = $this->webauthn->assertionOptions();
		$binding     = $reauth ? $this->reauthentication->binding() : $this->browser->establish();
		$payload     = array(
			'user_id'   => $user instanceof WP_User ? $user->ID : 0,
			'stamp'     => $user instanceof WP_User ? $this->stamp( $user ) : bin2hex( random_bytes( 32 ) ),
			'challenge' => Base64Url::encode( $this->webauthn->challenge() ),
			'remember'  => $body['remember'] ?? false,
			'redirect'  => $this->sessions->redirect( $body['redirect'] ?? '' ),
		);
		$transaction = $this->transactions->issue( $reauth ? get_current_user_id() : 0, $binding, $purpose, $payload, time() + 120 );
		return array(
			'transaction' => $transaction,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Library browser field.
			'publicKey'   => $options->publicKey,
		);
	}

	/** Consume before verification; any failure burns the ceremony without issuing a session.
	 *
	 * @param array<string, mixed> $body Assertion transport.
	 * @param string               $purpose Login or reauthentication.
	 * @return array<string, mixed> Redirect or management grant.
	 * @throws RuntimeException On any failed proof or binding.
	 */
	private function verify( array $body, string $purpose ): array {
		$this->fields( $body, array( 'transaction', 'rawId', 'userHandle', 'clientDataJSON', 'authenticatorData', 'signature' ) );
		$reauth  = 'reauthentication' === $purpose;
		$binding = $reauth ? $this->reauthentication->binding() : $this->browser->read();
		$payload = '' === $binding ? null : $this->transactions->consume( $this->text( $body, 'transaction', 64 ), $reauth ? get_current_user_id() : 0, $binding, $purpose );
		if ( null === $payload ) {
			throw new RuntimeException( 'Unbound or expired assertion.' );
		}
		foreach ( array(
			'rawId'             => 1400,
			'userHandle'        => 44,
			'clientDataJSON'    => 5500,
			'authenticatorData' => 5500,
			'signature'         => 350,
		) as $key => $maximum ) {
			$this->text( $body, $key, $maximum );
		}
		$user_id = (int) $payload['user_id'];
		return $this->credentials->authenticate(
			$user_id,
			Base64Url::decode( $body['rawId'], 1023 ),
			function ( array $row ) use ( $body, $payload ): VerifiedAssertion {
				$verified = $this->webauthn->assertion( $body, $row, Base64Url::decode( $payload['challenge'], 32 ) );
				$previous = (int) $row['sign_count'];
				if ( ( 0 < $previous || 0 < $verified->sign_count ) && $verified->sign_count <= $previous ) {
					$this->audit->record( 'counter_anomaly' );
					if ( ! $verified->backup_eligible ) {
						throw new RuntimeException( 'Device-bound counter regression.' );
					}
				}
				return $verified;
			},
			function () use ( $payload, $reauth, $user_id ): array {
				clean_user_cache( $user_id );
				$user = get_user_by( 'id', $user_id );
				if ( ! $user instanceof WP_User || ! hash_equals( $payload['stamp'], $this->stamp( $user ) ) || (int) $payload['expires_at'] <= time() || ( $reauth && get_current_user_id() !== $user->ID ) ) {
					throw new RuntimeException( 'Account or proof changed during authentication.' );
				}
				if ( $reauth ) {
					$this->policy->reauthenticate( $user );
					if ( (int) $payload['expires_at'] <= time() ) {
						throw new RuntimeException( 'Reauthentication proof expired during policy checks.' );
					}
					$grant = $this->reauthentication->verifiedPasskey();
					$this->audit->record( 'reauth_success' );
					return array( 'grant' => $grant );
				}
				$target = $this->sessions->redirect( $payload['redirect'] );
				$this->audit->record( 'login_verified' );
				$this->sessions->issue( $user, $payload['remember'], (int) $payload['expires_at'] );
				return array( 'redirect' => $target );
			}
		);
	}

	/** Fingerprint password changes without persisting an additional password hash.
	 *
	 * @param WP_User $user Selected user.
	 * @return string Keyed fingerprint.
	 */
	private function stamp( WP_User $user ): string {
		return hash_hmac( 'sha256', $user->user_pass, wp_salt( 'auth' ) );
	}

	/** Reject missing and unexpected fields.
	 *
	 * @param array<string, mixed> $body Transport object.
	 * @param string[]             $allowed Exact field set.
	 * @throws RuntimeException On a field mismatch.
	 */
	private function fields( array $body, array $allowed ): void {
		if ( array_diff( array_keys( $body ), $allowed ) || array_diff( $allowed, array_keys( $body ) ) ) {
			throw new RuntimeException( 'Unexpected assertion fields.' );
		}
	}

	/** Validate transport text without coercion.
	 *
	 * @param array<string, mixed> $body Transport object.
	 * @param string               $key Field name.
	 * @param int                  $maximum Byte limit.
	 * @return string Bounded value.
	 * @throws RuntimeException On invalid input.
	 */
	private function text( array $body, string $key, int $maximum ): string {
		$value = $body[ $key ] ?? null;
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $maximum || str_contains( $value, "\0" ) ) {
			throw new RuntimeException( 'Invalid assertion field.' );
		}
		return $value;
	}

	/** Return one neutral public error for all identities and assertion failures.
	 *
	 * @param int $status Transport status.
	 * @return WP_Error Failure without account details.
	 */
	private function error( int $status ): WP_Error {
		return new WP_Error( 'swsl_login_failed', __( 'Passkey authentication could not be completed. Retry or sign in with your password.', 'sw-securelogin' ), array( 'status' => $status ) );
	}
}
