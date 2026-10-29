<?php
/**
 * Module extension contract.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Core;

defined( 'ABSPATH' ) || exit;

/** Optional modules own their hooks and routes, never the password fallback. */
interface ModuleInterface {
	/** Stable, lowercase module identifier. */
	public function id(): string;

	/** Whether the implementation is ready to be enabled. */
	public function isAvailable(): bool;

	/** Return dependency failures; an empty list means satisfied.
	 *
	 * @return list<string>
	 */
	public function requirements(): array;

	/** Public configuration schema, excluding secret values.
	 *
	 * @return array<string, mixed>
	 */
	public function configurationSchema(): array;

	/** Register hooks, including rest_api_init for routes. Called once per request. */
	public function register(): void;
}
