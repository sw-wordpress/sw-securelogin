<?php
/**
 * Signed authenticator state after library verification.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;

/** Only constructed by the WebAuthn assertion adapter. */
final class VerifiedAssertion {
	/** Construct signed metadata.
	 *
	 * @param int  $sign_count Signed signature counter.
	 * @param bool $backup_eligible Signed backup eligibility.
	 * @param bool $backup_state Signed backup state.
	 */
	public function __construct( public readonly int $sign_count, public readonly bool $backup_eligible, public readonly bool $backup_state ) {}
}
