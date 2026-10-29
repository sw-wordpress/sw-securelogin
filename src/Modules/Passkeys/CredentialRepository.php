<?php
/**
 * Owned passkey storage and credential limits.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Modules\Passkeys;

defined( 'ABSPATH' ) || exit;


use RuntimeException;
use wpdb;

/** All mutations include the current user's ID; opaque user handles reveal no WP ID. */
final class CredentialRepository {
	public const LIMIT = 10;

	/** Create the persistence adapter.
	 *
	 * @param wpdb $database WordPress connection.
	 */
	public function __construct( private wpdb $database ) {}

	/** Get or atomically establish the stable opaque handle.
	 *
	 * @param int $user_id Expected local user.
	 * @return string Random 32-byte handle.
	 * @throws RuntimeException On storage failure.
	 */
	public function handle( int $user_id ): string {
		$this->database->query( $this->database->prepare( 'INSERT IGNORE INTO %i (user_id, handle) SELECT ID, %s FROM %i WHERE ID = %d', $this->database->prefix . 'swsl_user_handles', random_bytes( 32 ), $this->database->users, $user_id ) );
		$handle = $this->database->get_var( $this->database->prepare( 'SELECT handle FROM %i WHERE user_id = %d', $this->database->prefix . 'swsl_user_handles', $user_id ) );
		if ( ! is_string( $handle ) || 32 !== strlen( $handle ) ) {
			throw new RuntimeException( 'Unable to persist user handle.' );
		}
		return $handle;
	}

	/** List only public management metadata for one owner.
	 *
	 * @param int $user_id Owner ID.
	 * @return array<int, array<string, mixed>> Credential rows.
	 */
	public function listForUser( int $user_id ): array {
		$rows = $this->database->get_results( $this->database->prepare( 'SELECT id, name, created_at, last_used_at, aaguid, backup_eligible, backup_state FROM %i WHERE user_id = %d ORDER BY id ASC', $this->database->prefix . 'swsl_passkeys', $user_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Return binary credential IDs for exclusion.
	 *
	 * @param int $user_id Owner ID.
	 * @return string[] Credential IDs.
	 */
	public function ids( int $user_id ): array {
		return $this->database->get_col( $this->database->prepare( 'SELECT credential_id FROM %i WHERE user_id = %d ORDER BY id ASC', $this->database->prefix . 'swsl_passkeys', $user_id ) );
	}

	/** Persist a verified credential with uniqueness and a serialized per-user limit.
	 *
	 * @param int                $user_id Owner ID.
	 * @param string             $handle Server-assigned handle.
	 * @param VerifiedCredential $credential Validated library result.
	 * @param string             $name Validated display name.
	 * @param string[]           $transports Browser hints, not trusted authentication evidence.
	 * @throws RuntimeException When persistence, uniqueness or the credential limit fails.
	 */
	public function add( int $user_id, string $handle, VerifiedCredential $credential, string $name, array $transports ): void {
		$lock = $this->userLockName( $user_id );
		if ( '1' !== (string) $this->database->get_var( $this->database->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			throw new RuntimeException( 'Concurrent credential mutation.' );
		}
		try {
			$count  = $this->database->get_var( $this->database->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $this->database->prefix . 'swsl_passkeys', $user_id ) );
			$exists = $this->database->get_var( $this->database->prepare( 'SELECT ID FROM %i WHERE ID = %d', $this->database->users, $user_id ) );
			if ( null === $count || (int) $count >= self::LIMIT || null === $exists || $handle !== $this->handle( $user_id ) ) {
				throw new RuntimeException( 'Credential limit or ownership check failed.' );
			}
			$added = $this->database->insert(
				$this->database->prefix . 'swsl_passkeys',
				array(
					'user_id'         => $user_id,
					'credential_id'   => $credential->id,
					'public_key'      => $credential->public_key,
					'user_handle'     => $handle,
					'sign_count'      => $credential->sign_count,
					'transports'      => wp_json_encode( $transports ),
					'aaguid'          => $credential->aaguid,
					'backup_eligible' => (int) $credential->backup_eligible,
					'backup_state'    => (int) $credential->backup_state,
					'name'            => $name,
					'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			if ( 1 !== $added ) {
				throw new RuntimeException( 'Credential could not be persisted.' );
			}
		} finally {
			$this->database->get_var( $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** Rename only an owned credential.
	 *
	 * @param int    $user_id Owner.
	 * @param int    $id Local row ID.
	 * @param string $name Validated display name.
	 * @return bool Whether a matching row was changed.
	 */
	public function rename( int $user_id, int $id, string $name ): bool {
		return 1 === $this->database->update(
			$this->database->prefix . 'swsl_passkeys',
			array( 'name' => $name ),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			)
		);
	}

	/** Revoke only an owned credential.
	 *
	 * @param int $user_id Owner.
	 * @param int $id Local row ID.
	 * @return bool Whether a matching row was deleted.
	 */
	public function revoke( int $user_id, int $id ): bool {
		return 1 === $this->database->delete(
			$this->database->prefix . 'swsl_passkeys',
			array(
				'id'      => $id,
				'user_id' => $user_id,
			)
		);
	}
	/** Delete the owner's data while excluding in-flight credential inserts.
	 *
	 * @param int $user_id Account or privacy-request owner.
	 * @throws RuntimeException When deletion cannot complete.
	 */
	public function purgeUser( int $user_id ): void {
		$lock = $this->userLockName( $user_id );
		if ( '1' !== (string) $this->database->get_var( $this->database->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			throw new RuntimeException( 'Credential deletion could not acquire its lock.' );
		}
		try {
			foreach ( array( 'swsl_passkeys', 'swsl_user_handles', 'swsl_transactions' ) as $suffix ) {
				if ( false === $this->database->delete( $this->database->prefix . $suffix, array( 'user_id' => $user_id ) ) ) {
					throw new RuntimeException( 'Credential data could not be removed.' );
				}
			}
		} finally {
			$this->database->get_var( $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** Scope mutation locks to one database, site and account.
	 *
	 * @param int $user_id Local account.
	 * @return string MySQL lock name.
	 */
	private function userLockName( int $user_id ): string {
		return 'swsl_user_' . hash( 'sha224', (string) $this->database->get_var( 'SELECT DATABASE()' ) . ':' . $this->database->prefix . ':' . $user_id );
	}
}
