<?php
/**
 * Pinned HTTPS origin and relying-party policy.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;


use RuntimeException;

/** Derive the admin origin from the configured site URL, never forwarded headers. */
final class RelyingParty {
	/** Construct a canonical HTTPS policy.
	 *
	 * @param string $origin Exact browser origin.
	 * @param string $id RP host.
	 */
	public function __construct( public readonly string $origin, public readonly string $id ) {}

	/** Validate configuration and refuse implicit domain migrations.
	 *
	 * @return self Current, pinned policy.
	 * @throws RuntimeException When HTTPS or domain policy is unavailable.
	 */
	public static function configured(): self {
		$parts = wp_parse_url( site_url() );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) ) {
			throw new RuntimeException( 'A configured HTTPS site URL is required.' );
		}
		$host = strtolower( $parts['host'] );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) || ! filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) || str_ends_with( $host, '.' ) ) {
			throw new RuntimeException( 'A DNS host is required.' );
		}
		$origin = 'https://' . $host;
		if ( isset( $parts['port'] ) && 443 !== $parts['port'] ) {
			$origin .= ':' . $parts['port'];
		}
		$pinned = get_option( 'swsl_passkeys_rp' );
		if ( ! is_array( $pinned ) || ( $pinned['origin'] ?? null ) !== $origin || ( $pinned['id'] ?? null ) !== $host ) {
			throw new RuntimeException( 'The passkey origin must be explicitly configured.' );
		}
		return new self( $origin, $host );
	}
}
