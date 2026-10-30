<?php
/** Merge Clover statement coverage using the highest hit count for each source line. */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) exit;
if ( $argc < 3 ) {
	throw new RuntimeException( 'Usage: php tests/coverage/merge.php OUTPUT INPUT [INPUT ...]' );
}
$root = realpath( dirname( __DIR__, 2 ) ) . DIRECTORY_SEPARATOR;
$files = array();
foreach ( array_slice( $argv, 2 ) as $report ) {
	$xml = new DOMDocument();
	if ( ! $xml->load( $report, LIBXML_NONET ) || 'coverage' !== $xml->documentElement->tagName ) {
		throw new RuntimeException( 'Invalid Clover coverage report.' );
	}
	foreach ( $xml->getElementsByTagName( 'file' ) as $file ) {
		$name = $file->getAttribute( 'name' );
		$absolute = realpath( str_starts_with( $name, DIRECTORY_SEPARATOR ) ? $name : $root . $name );
		if ( false === $absolute || ! str_starts_with( $absolute, $root ) ) {
			throw new RuntimeException( 'Coverage file is outside the source checkout.' );
		}
		$name = substr( $absolute, strlen( $root ) );
		if ( ! str_starts_with( $name, 'src/' ) && ! in_array( $name, array( 'sw-securelogin.php', 'uninstall.php' ), true ) ) {
			throw new RuntimeException( 'Coverage must reference original production source, not tests or compiled builds.' );
		}
		$files[ $name ] ??= array();
		foreach ( $file->getElementsByTagName( 'line' ) as $line ) {
			$attributes = array();
			foreach ( $line->attributes as $attribute ) $attributes[ $attribute->name ] = $attribute->value;
			if ( ! isset( $attributes['num'], $attributes['type'], $attributes['count'] ) || ! ctype_digit( $attributes['num'] ) || (int) $attributes['num'] < 1 || ! ctype_digit( $attributes['count'] ) ) {
				throw new RuntimeException( 'Coverage contains invalid line coordinates or hit counts.' );
			}
			$key = $attributes['num'] . ':' . $attributes['type'];
			$attributes['count'] = (string) max( (int) ( $files[ $name ][ $key ]['count'] ?? 0 ), (int) $attributes['count'] );
			$files[ $name ][ $key ] = $attributes;
		}
	}
}
if ( array() === $files ) throw new RuntimeException( 'No production files in coverage reports.' );
$document = new DOMDocument( '1.0', 'UTF-8' );
$document->formatOutput = true;
$coverage = $document->appendChild( $document->createElement( 'coverage' ) );
$coverage->setAttribute( 'generated', (string) time() );
$project = $coverage->appendChild( $document->createElement( 'project' ) );
$project->setAttribute( 'timestamp', (string) time() );
$executed = 0;
ksort( $files );
foreach ( $files as $name => $lines ) {
	$file = $project->appendChild( $document->createElement( 'file' ) );
	$file->setAttribute( 'name', $name );
	$statements = 0;
	$covered = 0;
	uasort( $lines, static fn( array $a, array $b ): int => (int) $a['num'] <=> (int) $b['num'] );
	foreach ( $lines as $attributes ) {
		$line = $file->appendChild( $document->createElement( 'line' ) );
		foreach ( $attributes as $key => $value ) $line->setAttribute( $key, $value );
		if ( 'stmt' === $attributes['type'] ) {
			++$statements;
			if ( (int) $attributes['count'] > 0 ) { ++$covered; ++$executed; }
		}
	}
	$metrics = $file->appendChild( $document->createElement( 'metrics' ) );
	$metrics->setAttribute( 'statements', (string) $statements );
	$metrics->setAttribute( 'coveredstatements', (string) $covered );
}
if ( 0 === $executed ) throw new RuntimeException( 'No executed production statement lines in coverage reports.' );
$output = $argv[1];
if ( ! is_dir( dirname( $output ) ) && ! mkdir( dirname( $output ), 0755, true ) ) throw new RuntimeException( 'Cannot create coverage output directory.' );
if ( false === $document->save( $output ) ) throw new RuntimeException( 'Cannot write merged Clover report.' );
printf( "Merged %d production files and %d covered statement lines.\n", count( $files ), $executed );
