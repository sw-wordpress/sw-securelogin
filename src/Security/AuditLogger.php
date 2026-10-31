<?php
/**
 * Identifier-free authentication event counters with daily expiry.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Security;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/** Reuse expiring counters; never persist identities, IPs or ceremony data. */
final class AuditLogger {
	/** Inject the expiring counter store.
	 *
	 * @param RateLimiter $counters Atomic aggregate storage.
	 */
	public function __construct( private RateLimiter $counters ) {}

	/** Count one allowlisted event without a user identifier.
	 *
	 * @param string $event Event category.
	 * @throws InvalidArgumentException For unknown event categories.
	 */
	public function record( string $event ): void {
		if ( ! in_array( $event, array( 'login_verified', 'login_failed', 'reauth_success', 'reauth_failed', 'counter_anomaly', 'rate_limited' ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported authentication event.' );
		}
		$this->counters->allow( 'audit_' . $event, 0, PHP_INT_MAX, DAY_IN_SECONDS );
	}
}
