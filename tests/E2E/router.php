<?php
/** HTTPS test adapter for PHP's loopback-only development server. */
declare(strict_types=1);
if ( 'cli-server' !== PHP_SAPI ) exit;
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 8443;
$swsl_path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$swsl_root = realpath( $_SERVER['DOCUMENT_ROOT'] );
$swsl_file = realpath( $swsl_root . $swsl_path );
$swsl_plugin = realpath( $swsl_root . '/wp-content/plugins/sw-securelogin' );
$swsl_allowed = $swsl_file && ( str_starts_with( $swsl_file, $swsl_root . DIRECTORY_SEPARATOR ) || ( $swsl_plugin && str_starts_with( $swsl_file, $swsl_plugin . DIRECTORY_SEPARATOR ) ) );
if ( $swsl_allowed && is_file( $swsl_file ) ) {
	if ( str_ends_with( $swsl_file, '.php' ) ) {
		require $swsl_file;
		return true;
	}
	return false;
}
require $swsl_root . '/index.php';
