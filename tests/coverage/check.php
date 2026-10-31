<?php
/** Enforce complete original-source PHP line coverage, strictly above 80%. */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) exit;
if ( 2 !== $argc ) throw new RuntimeException( 'Usage: php tests/coverage/check.php CLOVER' );
$root = dirname( __DIR__, 2 );
$expected = array( 'sw-securelogin.php', 'uninstall.php' );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( 'php' === $file->getExtension() ) $expected[] = substr( $file->getPathname(), strlen( $root ) + 1 );
}
$document = new DOMDocument();
if ( ! $document->load( $argv[1], LIBXML_NONET ) || $document->doctype ) throw new RuntimeException( 'Invalid coverage report.' );
$seen = array();
$total = 0;
$covered = 0;
foreach ( $document->getElementsByTagName( 'file' ) as $file ) {
	$name = $file->getAttribute( 'name' );
	if ( ! in_array( $name, $expected, true ) || isset( $seen[$name] ) ) throw new RuntimeException( 'Unexpected or duplicate production path: ' . $name );
	$seen[$name] = true;
	$lines = array();
	foreach ( $file->getElementsByTagName( 'line' ) as $line ) {
		if ( 'stmt' !== $line->getAttribute( 'type' ) ) continue;
		$number = $line->getAttribute( 'num' );
		$count = $line->getAttribute( 'count' );
		if ( ! ctype_digit( $number ) || (int)$number < 1 || ! ctype_digit( $count ) || isset( $lines[$number] ) ) throw new RuntimeException( 'Invalid coverage line.' );
		$lines[$number] = true;
		++$total;
		if ( (int)$count > 0 ) ++$covered;
	}
}
if ( array_diff( $expected, array_keys( $seen ) ) ) throw new RuntimeException( 'Coverage is missing production PHP files.' );
printf( "PHP line coverage: %.2f%% (%d/%d); required: >80%%.\n", $total ? 100 * $covered / $total : 0, $covered, $total );
if ( !$total || 100 * $covered <= 80 * $total ) exit( 1 );
