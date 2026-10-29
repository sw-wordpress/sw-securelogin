<?php
/**
 * Short-lived, session-bound one-time transactions.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Security;

defined( 'ABSPATH' ) || exit;


use RuntimeException;
use wpdb;

/** Consume before verification: only the winning DELETE may proceed. */
final class TransactionRepository {
	/** Create a repository.
	 *
	 * @param wpdb $database WordPress connection.
	 */
	public function __construct( private wpdb $database ) {}

	/** Issue an opaque token, storing its hash only.
	 *
	 * @param int                  $user_id Expected user.
	 * @param string               $binding Session fingerprint.
	 * @param string               $purpose Transaction purpose.
	 * @param array<string, mixed> $payload Server-controlled context.
	 * @param int                  $expires Absolute expiry.
	 * @return string Opaque browser token.
	 * @throws RuntimeException On failed persistence.
	 */
	public function issue( int $user_id, string $binding, string $purpose, array $payload, int $expires ): string {
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			throw new RuntimeException( 'Transaction storage requires SQL query logging to be disabled.' );
		}
		$token = bin2hex( random_bytes( 32 ) );
		// Avoid recording challenge payloads in WordPress SQL debug/error logs.
		$previous_errors = $this->database->suppress_errors( true );
		try {
			$result = $this->database->insert(
				$this->database->prefix . 'swsl_transactions',
				array(
					'token_hash' => hash( 'sha256', $token ),
					'user_id'    => $user_id,
					'binding'    => $binding,
					'purpose'    => $purpose,
					'payload'    => wp_json_encode( $payload, JSON_THROW_ON_ERROR ),
					'expires_at' => $expires,
				)
			);
		} finally {
			$this->database->suppress_errors( $previous_errors );
			$this->database->last_query = '';
		}

		if ( 1 !== $result ) {
			throw new RuntimeException( 'Unable to persist transaction.' );
		}
		return $token;
	}

	/** Consume exactly once with all server-side bindings in the DELETE predicate.
	 *
	 * @param string $token Opaque browser token.
	 * @param int    $user_id Expected user.
	 * @param string $binding Expected session fingerprint.
	 * @param string $purpose Expected purpose.
	 * @return array<string, mixed>|null Consumed context, or failure.
	 */
	public function consume( string $token, int $user_id, string $binding, string $purpose ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			return null;
		}
		$table     = $this->database->prefix . 'swsl_transactions';
		$predicate = $this->database->prepare( 'token_hash = %s AND user_id = %d AND binding = %s AND purpose = %s AND expires_at > %d', hash( 'sha256', $token ), $user_id, $binding, $purpose, time() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Predicate is prepared above; table is trusted.
		$row = $this->database->get_row( "SELECT payload, expires_at FROM $table WHERE $predicate", ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$delete = $this->database->prepare( 'DELETE FROM %i WHERE token_hash = %s AND user_id = %d AND binding = %s AND purpose = %s AND expires_at > %d', $table, hash( 'sha256', $token ), $user_id, $binding, $purpose, time() );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared DELETE; affected rows arbitrate parallel readers.
		if ( 1 !== $this->database->query( $delete ) || (int) $row['expires_at'] <= time() ) {
			return null;
		}

		$payload = json_decode( $row['payload'], true );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		$payload['expires_at'] = (int) $row['expires_at'];
		return $payload;
	}
}
