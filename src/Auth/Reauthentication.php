<?php
/**
 * Fresh password proof for credential management.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Auth;

defined( 'ABSPATH' ) || exit;


use RuntimeException;
use SW\Securelogin\Security\TransactionRepository;
use SW\Securelogin\Security\RateLimiter;

/** Password proof augments the existing session; it never creates a login session. */
final class Reauthentication {
	/** Create the proof service.
	 *
	 * @param TransactionRepository $transactions One-time proof persistence.
	 * @param RateLimiter           $limiter Brute-force protection.
	 */
	public function __construct( private TransactionRepository $transactions, private RateLimiter $limiter ) {}

	/** Bind proof to both the active WordPress token and current password hash.
	 *
	 * @return string Session fingerprint.
	 * @throws RuntimeException When no cookie session exists.
	 */
	public function binding(): string {
		$token = wp_get_session_token();
		if ( '' === $token || 0 === get_current_user_id() ) {
			throw new RuntimeException( 'An authenticated cookie session is required.' );
		}
		return hash_hmac( 'sha256', $token . '|' . wp_get_current_user()->user_pass, wp_salt( 'auth' ) );
	}

	/** Verify a fresh password and issue a single-use, short-lived grant.
	 *
	 * @param string $password Unstored password proof.
	 * @return string Single-use proof token.
	 * @throws RuntimeException On failed proof or rate limit.
	 */
	public function password( string $password ): string {
		$user = wp_get_current_user();
		if ( ! $this->limiter->allow( 'passkeys_reauth', $user->ID, 5, 300 ) || '' === $password || strlen( $password ) > 4096 || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			throw new RuntimeException( 'Reauthentication was not accepted.' );
		}
		return $this->transactions->issue( $user->ID, $this->binding(), 'reauth', array(), time() + 300 );
	}

	/** Consume a proof grant for the current session.
	 *
	 * @param string $grant Opaque proof token.
	 * @return int Expiry, to cap the subsequent ceremony lifetime.
	 * @throws RuntimeException When proof is missing, stale or already consumed.
	 */
	public function consume( string $grant ): int {
		$proof = $this->transactions->consume( $grant, get_current_user_id(), $this->binding(), 'reauth' );
		if ( null === $proof ) {
			throw new RuntimeException( 'Fresh reauthentication is required.' );
		}
		return (int) $proof['expires_at'];
	}
}
