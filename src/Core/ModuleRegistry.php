<?php
/**
 * Optional module lifecycle.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Core;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/** Registers only explicitly enabled, available modules with satisfied dependencies. */
final class ModuleRegistry {
	/**
	 * Known module implementations.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();
	/**
	 * Modules already registered in this request.
	 *
	 * @var array<string, true>
	 */
	private array $registered = array();

	/** Add a module before startup.
	 *
	 * @param ModuleInterface $module Module implementation.
	 * @throws InvalidArgumentException For invalid or duplicate identifiers.
	 */
	public function add( ModuleInterface $module ): void {
		$id = $module->id();
		if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id ) || isset( $this->modules[ $id ] ) ) {
			throw new InvalidArgumentException( 'Invalid or duplicate module identifier.' );
		}
		$this->modules[ $id ] = $module;
	}

	/** Register enabled modules once. Unknown IDs are ignored.
	 *
	 * @param string[] $enabled Explicitly enabled module IDs.
	 */
	public function registerEnabled( array $enabled ): void {
		foreach ( $this->modules as $id => $module ) {
			if ( isset( $this->registered[ $id ] ) || ! in_array( $id, $enabled, true ) ) {
				continue;
			}
			if ( ! $module->isAvailable() || array() !== $module->requirements() ) {
				continue;
			}
			$module->register();
			$this->registered[ $id ] = true;
		}
	}
}
