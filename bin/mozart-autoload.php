<?php
/** Build-only review policy for Composer autoload metadata. */
declare(strict_types=1);

namespace SW\Securelogin\Build;

use RuntimeException;

final class MozartAutoload {
	private const FILES = array(
		'facile-it/php-jose-verifier' => array( 'src/Internal/functions/derived_key.php', 'src/Internal/functions/jose_secret_key.php' ),
		'facile-it/php-openid-client' => array( 'src/functions/base64url_decode.php', 'src/functions/base64url_encode.php', 'src/functions/check_server_response.php', 'src/functions/derived_key.php', 'src/functions/get_endpoint_uri.php', 'src/functions/jose_secret_key.php', 'src/functions/parse_callback_params.php', 'src/functions/parse_metadata_response.php' ),
		'symfony/deprecation-contracts' => array( 'function.php' ),
		'symfony/polyfill-mbstring' => array( 'bootstrap.php' ),
		'symfony/polyfill-php83' => array( 'bootstrap.php' ),
	);

	/** @param array<string, mixed> $installed
	 * @param array<string, mixed> $manifest
	 * @return list<string> Reviewed PSR namespaces.
	 * @throws RuntimeException For mismatched metadata or unreviewed autoload forms.
	 */
	public function validate( string $package, array $installed, array $manifest ): array {
		if ( $this->canonical( $installed ) !== $this->canonical( $manifest ) ) {
			throw new RuntimeException( 'Installed autoload metadata does not match the package manifest: ' . $package );
		}
		$namespaces = array();
		foreach ( $installed as $type => $entries ) {
			if ( 'files' === $type && isset( self::FILES[ $package ] ) && self::FILES[ $package ] === $entries ) {
				continue;
			}
			if ( 'classmap' === $type && 'symfony/polyfill-php83' === $package && array( 'Resources/stubs' ) === $entries ) {
				continue;
			}
			if ( 'exclude-from-classmap' === $type ) {
				continue;
			}
			if ( ! in_array( $type, array( 'psr-4', 'psr-0' ), true ) || ! is_array( $entries ) ) {
				throw new RuntimeException( 'Review Mozart root rewriting for ' . $package . ': ' . $type );
			}
			foreach ( array_keys( $entries ) as $namespace ) {
				if ( ! is_string( $namespace ) || '' === $namespace ) {
					throw new RuntimeException( 'A dependency has an unbounded autoload namespace.' );
				}
				$namespaces[] = $namespace;
			}
		}
		return $namespaces;
	}

	/** Preserve JSON types and list order while ignoring object key order.
	 *
	 * @param array<array-key, mixed> $value
	 * @return array<array-key, mixed>
	 */
	private function canonical( array $value ): array {
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $entry ) {
			if ( is_array( $entry ) ) {
				$value[ $key ] = $this->canonical( $entry );
			}
		}
		return $value;
	}
}
