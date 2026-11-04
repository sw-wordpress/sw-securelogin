<?php
/** Package a reviewed production tree with deterministic ZIP metadata. */
declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) exit;

$swsl_stage = realpath( $argv[1] ?? '' );
$swsl_output = $argv[2] ?? '';
if ( false === $swsl_stage || '' === $swsl_output || is_link( $swsl_output ) || file_exists( $swsl_output ) ) {
	throw new RuntimeException( 'Expected a production tree and a new ZIP output path.' );
}
$swsl_required = array( 'sw-securelogin.php', 'uninstall.php', 'readme.txt', 'README.md', 'LICENSE', 'composer.json', 'composer.lock', 'vendor-prefixed/autoload.php', 'vendor-prefixed/licenses/lbuchs-webauthn/LICENSE', 'languages/sw-securelogin-de_DE.mo', 'languages/sw-securelogin-en_US.mo', 'docs/PRIVACY.md', 'docs/OPERATIONS.md', 'docs/RELEASE.md' );
foreach ( $swsl_required as $swsl_file ) {
	if ( ! is_file( $swsl_stage . '/' . $swsl_file ) ) throw new RuntimeException( 'Missing release file: ' . $swsl_file );
}
$swsl_header = file_get_contents( $swsl_stage . '/sw-securelogin.php' );
if ( ! preg_match( '/ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)\R/', $swsl_header, $swsl_match ) ) throw new RuntimeException( 'Invalid plugin version.' );
$swsl_version = $swsl_match[1];
if ( ! str_contains( $swsl_header, "define( 'SWSL_VERSION', '" . $swsl_version . "' )" ) || ! str_contains( file_get_contents( $swsl_stage . '/readme.txt' ), 'Stable tag: ' . $swsl_version . "\n" ) ) {
	throw new RuntimeException( 'Plugin, asset and readme versions must match.' );
}
$swsl_files = array();
$swsl_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $swsl_stage, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
foreach ( $swsl_iterator as $swsl_file ) {
	$swsl_relative = substr( $swsl_file->getPathname(), strlen( $swsl_stage ) + 1 );
	if ( $swsl_file->isLink() ) throw new RuntimeException( 'Release symlinks are forbidden.' );
	if ( $swsl_file->isDir() ) continue;
	if ( ! $swsl_file->isFile() || ! preg_match( '~^(?:sw-securelogin\.php|uninstall\.php|readme\.txt|README\.md|LICENSE|composer\.(?:json|lock)|docs/(?:PRIVACY|OPERATIONS|RELEASE)\.md|(?:src|assets|languages|templates|vendor-prefixed)/[^\\\\]+)$~D', $swsl_relative ) || preg_match( '~(?:^|/)(?:\.[^/]*|tests?|node_modules|vendor|coverage|phpunit|phpstan)(?:/|$)|\.(?:pem|key|log|zip)$~i', $swsl_relative ) ) {
		throw new RuntimeException( 'Unexpected release file: ' . $swsl_relative );
	}
	$swsl_files[ $swsl_relative ] = $swsl_file->getPathname();
}
ksort( $swsl_files, SORT_STRING );
$swsl_zip = new ZipArchive();
if ( true !== $swsl_zip->open( $swsl_output, ZipArchive::CREATE | ZipArchive::EXCL ) ) throw new RuntimeException( 'Cannot create release ZIP.' );
foreach ( $swsl_files as $swsl_relative => $swsl_path ) {
	$swsl_name = 'sw-securelogin/' . $swsl_relative;
	// A fixed DOS-compatible UTC date and uncompressed entries avoid mtime/timezone/zlib differences.
	if ( ! $swsl_zip->addFromString( $swsl_name, file_get_contents( $swsl_path ) ) || ! $swsl_zip->setMtimeName( $swsl_name, 946684800 ) || ! $swsl_zip->setCompressionName( $swsl_name, ZipArchive::CM_STORE ) || ! $swsl_zip->setExternalAttributesName( $swsl_name, ZipArchive::OPSYS_UNIX, 0100644 << 16 ) ) {
		throw new RuntimeException( 'Cannot write release entry.' );
	}
}
if ( ! $swsl_zip->close() ) throw new RuntimeException( 'Cannot finish release ZIP.' );
echo hash_file( 'sha256', $swsl_output ) . '  ' . basename( $swsl_output ) . "\n";
