<?php
/** Build-only graph of the complete, already resolved Composer installation. */
declare(strict_types=1);

namespace SW\Securelogin\Build;

use RuntimeException;

final class MozartGraph {
	/** Resolve installed providers, preserving every installed package (no solver/scoping exclusions).
	 *
	 * Composer has already selected versions. All installed providers of a virtual
	 * requirement are included, so ambiguous providers cannot silently escape scoping.
	 *
	 * @param list<array<string, mixed>> $records Composer installed package records.
	 * @return array<string, list<string>> Dependency-first map, without platform packages.
	 * @throws RuntimeException For malformed, unresolved or cyclic installed metadata.
	 */
	public function resolve( array $records ): array {
		$packages = array();
		$providers = array();
		foreach ( $records as $record ) {
			$name = $record['name'] ?? null;
			if ( ! is_string( $name ) || ! preg_match( '~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~D', $name ) || isset( $packages[ $name ] ) ) {
				throw new RuntimeException( 'Invalid or duplicate installed package name.' );
			}
			$packages[ $name ] = $record;
			foreach ( array( 'provide', 'replace' ) as $field ) {
				foreach ( $this->requirements( $record, $field ) as $virtual ) {
					$providers[ $virtual ][] = $name;
				}
			}
		}
		$graph = array();
		foreach ( $packages as $name => $record ) {
			$dependencies = array();
			foreach ( $this->requirements( $record, 'require' ) as $requirement ) {
				if ( ! str_contains( $requirement, '/' ) ) {
					continue; // PHP/extensions/Composer APIs are verified by Composer, not scoped.
				}
				$matches = isset( $packages[ $requirement ] ) ? array( $requirement ) : ( $providers[ $requirement ] ?? array() );
				if ( ! $matches ) {
					throw new RuntimeException( 'Unresolved installed requirement: ' . $requirement );
				}
				foreach ( $matches as $match ) {
					$dependencies[ $match ] = true;
				}
			}
			$graph[ $name ] = array_keys( $dependencies );
		}
		$sorted = array();
		$visiting = array();
		foreach ( array_keys( $graph ) as $name ) {
			$this->visit( $name, $graph, $sorted, $visiting );
		}
		return $sorted;
	}

	/** @param array<string, mixed> $record
	 * @return list<string>
	 */
	private function requirements( array $record, string $field ): array {
		$values = $record[ $field ] ?? array();
		if ( ! is_array( $values ) ) {
			throw new RuntimeException( 'Malformed installed package requirements.' );
		}
		foreach ( $values as $name => $constraint ) {
			if ( ! is_string( $name ) || ! is_string( $constraint ) ) {
				throw new RuntimeException( 'Malformed installed package requirement.' );
			}
		}
		return array_keys( $values );
	}

	/** @param array<string, list<string>> $graph
	 * @param array<string, list<string>> $sorted
	 * @param array<string, bool> $visiting
	 */
	private function visit( string $name, array $graph, array &$sorted, array &$visiting ): void {
		if ( isset( $sorted[ $name ] ) ) {
			return;
		}
		if ( isset( $visiting[ $name ] ) ) {
			throw new RuntimeException( 'Cyclic installed requirements need an explicit Mozart review.' );
		}
		$visiting[ $name ] = true;
		foreach ( $graph[ $name ] as $dependency ) {
			$this->visit( $dependency, $graph, $sorted, $visiting );
		}
		unset( $visiting[ $name ] );
		$sorted[ $name ] = $graph[ $name ];
	}
}
