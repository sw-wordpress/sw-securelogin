<?php
/**
 * Validated module configuration.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

namespace SW\Securelogin\Core;

defined( 'ABSPATH' ) || exit;


/** Settings fail closed when their shape or schema version is unexpected. */
final class SettingsRepository {
	/** Return explicitly enabled, known module IDs.
	 *
	 * @return string[]
	 */
	public function enabledModules(): array {
		$settings = get_option( 'swsl_settings' );
		if ( ! is_array( $settings ) || ! isset( $settings['enabled_modules'] ) || ! is_array( $settings['enabled_modules'] ) || ! array_is_list( $settings['enabled_modules'] ) ) {
			return array();
		}
		foreach ( $settings['enabled_modules'] as $id ) {
			if ( ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id ) ) {
				return array();
			}
		}
		return in_array( 'passkeys', $settings['enabled_modules'], true ) ? array( 'passkeys' ) : array();
	}

	/** Whether enrollment tables have been migrated by this plugin version. */
	public function schemaReady(): bool {
		return '2' === (string) get_option( 'swsl_schema_version', '0' );
	}
}
