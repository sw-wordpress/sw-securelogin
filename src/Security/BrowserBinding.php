<?php
/**
 * Secure anonymous browser binding for login-CSRF protection.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Security;

defined( 'ABSPATH' ) || exit;

use RuntimeException;

/** Host-only, HttpOnly cookie is separate from WordPress authentication cookies. */
final class BrowserBinding {
	public const COOKIE = '__Host-swsl_login';

	/** Read a valid browser secret without creating one.
	 *
	 * @return string HMAC fingerprint, empty when absent.
	 */
	public function read(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Canonical opaque token; reject instead of transforming.
		$value = $_COOKIE[ self::COOKIE ] ?? null;
		return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/D', $value ) ? hash_hmac( 'sha256', $value, wp_salt( 'auth' ) ) : '';
	}

	/** Establish or reuse a short-lived, host-only browser binding.
	 *
	 * @return string HMAC fingerprint.
	 * @throws RuntimeException When secure cookie headers cannot be sent.
	 */
	public function establish(): string {
		$binding = $this->read();
		if ( '' !== $binding ) {
			return $binding;
		}
		if ( ! is_ssl() || headers_sent() ) {
			throw new RuntimeException( 'Secure login binding is unavailable.' );
		}
		$value = bin2hex( random_bytes( 32 ) );
		if ( ! setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => time() + 300,
				'path'     => '/',
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		) ) {
			throw new RuntimeException( 'Login binding cookie could not be sent.' );
		}
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}
}
