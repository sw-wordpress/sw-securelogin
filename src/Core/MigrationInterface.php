<?php
/**
 * Versioned migration contract.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Core;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/** Migrations must be idempotent: MySQL DDL cannot be rolled back reliably. */
interface MigrationInterface {
	/** Apply and verify the migration before returning.
	 *
	 * @throws RuntimeException When the postcondition cannot be verified.
	 */
	public function apply(): void;
}
