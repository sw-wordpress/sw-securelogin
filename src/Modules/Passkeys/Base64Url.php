<?php
/**
 * Bounded canonical binary transport encoding.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;


use InvalidArgumentException;

/** Binary transport only; protocol and cryptographic validation belongs to WebAuthn. */
final class Base64Url {
	/** Encode binary without padding.
	 *
	 * @param string $binary Raw bytes.
	 */
	public static function encode( string $binary ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WebAuthn binary transport encoding.
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
	}

	/** Decode a bounded, canonical base64url field.
	 *
	 * @param string $value Encoded bytes.
	 * @param int    $maximum Maximum decoded size.
	 * @return string Raw bytes.
	 * @throws InvalidArgumentException For malformed or oversized input.
	 */
	public static function decode( string $value, int $maximum ): string {
		if ( '' === $value || strlen( $value ) > 4 * $maximum || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) {
			throw new InvalidArgumentException( 'Invalid binary field.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Strict WebAuthn binary transport decoding.
		$binary = base64_decode( strtr( $value, '-_', '+/' ), true );
		if ( false === $binary || strlen( $binary ) > $maximum || self::encode( $binary ) !== $value ) {
			throw new InvalidArgumentException( 'Invalid binary field.' );
		}
		return $binary;
	}
}
