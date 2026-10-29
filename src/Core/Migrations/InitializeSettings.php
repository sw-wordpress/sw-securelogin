<?php
/**
 * Initial persistent configuration.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Core\Migrations;

use RuntimeException;
use SW\Securelogin\Core\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/** Initialize settings without overwriting an existing installation. */
final class InitializeSettings implements MigrationInterface {
	/** Create and verify the initial settings option.
	 *
	 * @throws RuntimeException When storage fails or existing settings are malformed.
	 */
	public function apply(): void {
		add_option( 'swsl_settings', array( 'enabled_modules' => array() ), '', false );
		$settings = get_option( 'swsl_settings' );
		if ( ! is_array( $settings ) || ! isset( $settings['enabled_modules'] ) || ! is_array( $settings['enabled_modules'] ) || ! array_is_list( $settings['enabled_modules'] ) ) {
			throw new RuntimeException( 'Unable to initialize module settings.' );
		}
		foreach ( $settings['enabled_modules'] as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id ) ) {
				throw new RuntimeException( 'Invalid enabled module identifier.' );
			}
		}
	}
}
