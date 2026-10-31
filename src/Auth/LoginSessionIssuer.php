<?php
/**
 * WordPress session issuance after verified alternative authentication.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Auth;

defined( 'ABSPATH' ) || exit;

use RuntimeException;
use WP_Error;
use WP_User;
use WP_Session_Tokens;
use Throwable;

/** Scope the verified identity to one synchronous wp_signon call. */
final class LoginSessionIssuer {
	/** Inject the authentication policy.
	 *
	 * @param AuthPolicy $policy Compatibility and account checks.
	 */
	public function __construct( private AuthPolicy $policy ) {}

	/** Issue a secure core session; honor all authenticate errors and hooks.
	 *
	 * @param WP_User $user Cryptographically verified account.
	 * @param bool    $remember Explicit remember-me choice.
	 * @param int     $expires Proof deadline, rechecked before cookies.
	 * @throws RuntimeException If core or another authentication policy rejects login.
	 */
	public function issue( WP_User $user, bool $remember, int $expires ): void {
		if ( ! is_ssl() || headers_sent() ) {
			throw new RuntimeException( 'Secure session headers are unavailable.' );
		}
		$user    = $this->policy->user( $user );
		$guard   = static fn( $result ): WP_User|WP_Error => $result instanceof WP_User && $result->ID === $user->ID ? $result : new WP_Error( 'swsl_login_denied', 'Passkey authentication was rejected.' );
		$token   = '';
		$sent    = false;
		$capture = static function ( $cookie, $expire, $expiration, $user_id, $scheme, $issued_token ) use ( &$token, $user, $expires ): void {
			if ( $user_id === $user->ID ) {
				$token = $issued_token;
				if ( $expires <= time() ) {
					throw new RuntimeException( 'Passkey proof expired before cookie issuance.' );
				}
			}
		};
		$observe = static function ( $send ) use ( &$sent ): bool {
			$sent = (bool) $send;
			return $sent;
		};
		$secure  = static fn(): bool => true;
		foreach ( array( 'secure_signon_cookie', 'secure_auth_cookie', 'secure_logged_in_cookie' ) as $hook ) {
			add_filter( $hook, $secure, PHP_INT_MAX );
		}
		add_action( 'set_auth_cookie', $capture, PHP_INT_MAX, 6 );
		add_filter( 'send_auth_cookies', $observe, PHP_INT_MAX );
		add_filter( 'authenticate', $guard, PHP_INT_MAX );
		try {
			$result = $this->policy->seeded(
				$user,
				static fn() => wp_signon(
					array(
						'user_login'    => $user->user_login,
						'user_password' => '',
						'remember'      => $remember,
					),
					true
				)
			);
			if ( ! $result instanceof WP_User || $result->ID !== $user->ID || ! $sent || '' === $token ) {
				throw new RuntimeException( 'WordPress rejected passkey login.' );
			}
			wp_set_current_user( $user->ID );
		} catch ( Throwable ) {
			if ( '' !== $token ) {
				WP_Session_Tokens::get_instance( $user->ID )->destroy( $token );
				wp_clear_auth_cookie();
				wp_set_current_user( 0 );
			}
			throw new RuntimeException( 'WordPress session issuance failed.' );
		} finally {
			foreach ( array( 'secure_signon_cookie', 'secure_auth_cookie', 'secure_logged_in_cookie' ) as $hook ) {
				remove_filter( $hook, $secure, PHP_INT_MAX );
			}
			remove_action( 'set_auth_cookie', $capture, PHP_INT_MAX );
			remove_filter( 'send_auth_cookies', $observe, PHP_INT_MAX );
			remove_filter( 'authenticate', $guard, PHP_INT_MAX );
		}
	}

	/** Only same-origin URLs under the admin path are accepted.
	 *
	 * @param string $requested Untrusted requested target.
	 * @return string Validated admin URL.
	 */
	public function redirect( string $requested ): string {
		$fallback = admin_url();
		$target   = wp_validate_redirect( $requested, $fallback );
		$base     = wp_parse_url( $fallback );
		$parts    = wp_parse_url( $target );
		if ( ! is_array( $parts ) || ! is_array( $base ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( $parts['scheme'] ?? '' ) !== ( $base['scheme'] ?? '' ) || ( $parts['host'] ?? '' ) !== ( $base['host'] ?? '' ) || ( $parts['port'] ?? 443 ) !== ( $base['port'] ?? 443 ) ) {
			return $fallback;
		}
		$path = rawurldecode( $parts['path'] ?? '' );
		if ( ! str_starts_with( $path, $base['path'] ) || str_contains( $path, '\\' ) || preg_match( '~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f\x7f]~', $path ) ) {
			return $fallback;
		}
		return $target;
	}
}
