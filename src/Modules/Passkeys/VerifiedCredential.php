<?php
/**
 * Validated enrollment result.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;


/** Only the WebAuthn adapter creates this result after complete validation. */
final class VerifiedCredential {
	/** Capture the validated public credential metadata.
	 *
	 * @param string $id Binary credential ID.
	 * @param string $public_key PEM public key.
	 * @param int    $sign_count Initial counter.
	 * @param string $aaguid Authenticator model identifier.
	 * @param bool   $backup_eligible Backup eligibility flag.
	 * @param bool   $backup_state Current backup state.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $public_key,
		public readonly int $sign_count,
		public readonly string $aaguid,
		public readonly bool $backup_eligible,
		public readonly bool $backup_state
	) {}
}
