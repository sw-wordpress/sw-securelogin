<?php
/**
 * Fail-closed compatibility boundary for alternative authentication.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Auth;

defined( 'ABSPATH' ) || exit;

use RuntimeException;
use WP_User;

/** Unknown authentication callbacks require an explicitly reviewed adapter. */
final class AuthPolicy {
	/**
	 * Reviewed third-party callbacks. WP Armour's honeypot inspects only form `$_POST`
	 * fields, which JSON passkey requests never populate; it vetoes with WP_Error or
	 * returns null, so `seeded()` restores the verified identity after it.
	 */
	private const REVIEWED = array( 'wpae_wplogin_extra_validation' );

	/** Check compatibility without attempting password authentication.
	 *
	 * @throws RuntimeException When additional authentication policies are unreviewed.
	 */
	public function assertCompatible(): void {
		global $wp_filter;
		$core    = array( 'wp_authenticate_username_password', 'wp_authenticate_email_password', 'wp_authenticate_application_password', 'wp_authenticate_spam_check', 'wp_authenticate_cookie', ...self::REVIEWED );
		$unknown = array();
		foreach ( array( 'authenticate', 'wp_authenticate_user', 'wp_authenticate', 'wp_login' ) as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( ! is_string( $callback['function'] ) || ! in_array( $callback['function'], $core, true ) ) {
						$unknown[] = $hook;
					}
				}
			}
		}
		if ( $unknown && true !== apply_filters( 'swsl_passkey_auth_compatible', false, array_unique( $unknown ) ) ) {
			throw new RuntimeException( 'Additional authentication policies require a reviewed passkey adapter.' );
		}
	}

	/** Run user-level WordPress policy after cryptographic verification.
	 *
	 * @param WP_User $user Verified account.
	 * @return WP_User Same, eligible account.
	 * @throws RuntimeException When a policy rejects or substitutes the account.
	 */
	public function user( WP_User $user ): WP_User {
		$this->assertCompatible();
		$result = apply_filters( 'wp_authenticate_user', $user, '' );
		if ( ! $result instanceof WP_User || $result->ID !== $user->ID || ! user_can( $result, 'read' ) ) {
			throw new RuntimeException( 'Account policy rejected passkey authentication.' );
		}
		return $result;
	}
	/** Apply the authentication filter chain to fresh proof without issuing a session.
	 *
	 * @param WP_User $user Verified session owner.
	 * @throws RuntimeException If any reviewed policy rejects or substitutes the owner.
	 */
	public function reauthenticate( WP_User $user ): void {
		$verified = $this->user( $user );
		$result   = $this->seeded( $verified, static fn() => apply_filters( 'authenticate', $verified, $verified->user_login, '' ) );
		if ( ! $result instanceof WP_User || $result->ID !== $user->ID ) {
			throw new RuntimeException( 'Reauthentication policy rejected the account.' );
		}
	}

	/** Run an authenticate chain in which empty results fall back to the verified identity.
	 *
	 * Core callbacks run at priority 20; the second seed restores the identity after
	 * reviewed callbacks that return null instead of passing the user through.
	 *
	 * @param WP_User  $user Cryptographically verified account.
	 * @param callable $operation Code applying the authenticate filter.
	 * @return mixed Operation result.
	 */
	public function seeded( WP_User $user, callable $operation ): mixed {
		$seed = static fn( $result ): mixed => $result instanceof WP_User || is_wp_error( $result ) ? $result : $user;
		add_filter( 'authenticate', $seed, 1 );
		add_filter( 'authenticate', $seed, 19 );
		try {
			return $operation();
		} finally {
			remove_filter( 'authenticate', $seed, 1 );
			remove_filter( 'authenticate', $seed, 19 );
		}
	}
}
