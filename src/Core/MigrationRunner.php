<?php
/**
 * Serialized, resumable database upgrades.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Core;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use wpdb;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/** Advance the schema marker only after each migration verifies its postconditions. */
final class MigrationRunner {
	/**
	 * Ordered migrations, keyed by consecutive version starting at one.
	 *
	 * @var array<int, MigrationInterface>
	 */
	private array $migrations;

	/** Configure the migration sequence.
	 *
	 * @param wpdb                           $database WordPress database connection.
	 * @param array<int, MigrationInterface> $migrations Consecutive migrations.
	 * @throws InvalidArgumentException For a non-consecutive migration sequence.
	 */
	public function __construct( private wpdb $database, array $migrations ) {
		$expected = 1;
		foreach ( $migrations as $version => $migration ) {
			if ( $version !== $expected ) {
				throw new InvalidArgumentException( 'Migrations must be consecutive and start at one.' );
			}
			++$expected;
		}
		$this->migrations = $migrations;
	}

	/** Run pending migrations under a database-scoped connection lock.
	 *
	 * @return true|WP_Error Neutral error, with no SQL or exception details exposed.
	 */
	public function run(): true|WP_Error {
		// Named locks are connection-bound; do not substitute expiring transient locks.
		$lock     = 'swsl_' . hash( 'sha224', (string) $this->database->get_var( 'SELECT DATABASE()' ) . ':' . $this->database->prefix );
		$acquired = $this->database->get_var( $this->database->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
		if ( '1' !== (string) $acquired ) {
			return new WP_Error( 'swsl_migration_busy', __( 'SW Securelogin database upgrade is unavailable. Please retry.', 'sw-securelogin' ) );
		}

		try {
			$this->applyPending();
			return true;
		} catch ( Throwable $exception ) {
			// Never include exception messages: database drivers can include sensitive SQL.
			return new WP_Error( 'swsl_migration_failed', __( 'SW Securelogin database upgrade failed. Authentication modules remain disabled.', 'sw-securelogin' ) );
		} finally {
			$this->database->get_var( $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** Apply and verify each pending version.
	 *
	 * @throws RuntimeException For newer schemas or failed version writes.
	 */
	private function applyPending(): void {
		$version = $this->version();
		if ( $version > count( $this->migrations ) ) {
			throw new RuntimeException( 'Database is newer than this plugin.' );
		}
		foreach ( $this->migrations as $next => $migration ) {
			if ( $next <= $version ) {
				continue;
			}
			$migration->apply();
			update_option( 'swsl_schema_version', $next, false );
			if ( $next !== $this->version() ) {
				throw new RuntimeException( 'Unable to persist migration version.' );
			}
		}
	}

	/** Read authoritative state, avoiding a stale persistent options cache.
	 *
	 * @return int Last completed version.
	 * @throws RuntimeException For invalid state or failed reads.
	 */
	private function version(): int {
		$value = $this->database->get_var(
			$this->database->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->database->options, 'swsl_schema_version' )
		);
		if ( '' !== $this->database->last_error ) {
			throw new RuntimeException( 'Unable to read migration state.' );
		}
		if ( null === $value ) {
			return 0;
		}
		if ( ! preg_match( '/^(0|[1-9][0-9]{0,8})$/D', $value ) ) {
			throw new RuntimeException( 'Invalid migration state.' );
		}
		return (int) $value;
	}
}
