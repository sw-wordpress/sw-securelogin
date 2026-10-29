<?php
/**
 * Atomic fixed-window request counters.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Security;

defined( 'ABSPATH' ) || exit;


use wpdb;

/** Authenticated limits use a user ID, never a client-supplied IP header. */
final class RateLimiter {
	/** Create a limiter.
	 *
	 * @param wpdb $database WordPress connection.
	 */
	public function __construct( private wpdb $database ) {}

	/** Count every attempt before work and fail closed on database errors.
	 *
	 * @param string $purpose Counter namespace.
	 * @param int    $user_id Current user.
	 * @param int    $limit Requests per window.
	 * @param int    $seconds Fixed window length.
	 */
	public function allow( string $purpose, int $user_id, int $limit, int $seconds ): bool {
		$window = intdiv( time(), $seconds );
		$bucket = hash( 'sha256', $purpose . ':' . $user_id . ':' . $window );
		$query  = $this->database->prepare(
			'INSERT INTO %i (bucket, attempts, expires_at) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE attempts = attempts + 1',
			$this->database->prefix . 'swsl_limits',
			$bucket,
			( $window + 1 ) * $seconds
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared query above.
		if ( false === $this->database->query( $query ) ) {
			return false;
		}
		$count = $this->database->get_var( $this->database->prepare( 'SELECT attempts FROM %i WHERE bucket = %s', $this->database->prefix . 'swsl_limits', $bucket ) );
		return null !== $count && (int) $count <= $limit;
	}
}
