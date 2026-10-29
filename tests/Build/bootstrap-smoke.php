<?php
/** Load the actual plugin bootstrap without WordPress or the original vendor tree. */
declare(strict_types=1);

$swsl_build = $argv[1] ?? '';
if ( ! is_file( $swsl_build . '/sw-securelogin.php' ) ) {
	throw new RuntimeException( 'Expected a staged plugin directory.' );
}
define( 'ABSPATH', '/tmp/swsl-build-smoke/' );
$swsl_hooks = array();
function add_action( string $hook, callable $callback ): void {
	$GLOBALS['swsl_hooks'][ $hook ][] = $callback;
}
function is_multisite(): bool {
	return false;
}
class wpdb {}
$GLOBALS['wpdb'] = new wpdb();
function get_option( string $name, mixed $default = false ): mixed {
	return $default;
}
function add_filter( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {}
require $swsl_build . '/sw-securelogin.php';
if ( ! isset( $swsl_hooks['plugins_loaded'][0] ) ) {
	throw new RuntimeException( 'Plugin bootstrap did not register.' );
}
$swsl_hooks['plugins_loaded'][0]();
if ( ! isset( $swsl_hooks['admin_menu'][0], $swsl_hooks['admin_init'][0] ) ) {
	throw new RuntimeException( 'Core classes did not autoload.' );
}
if ( is_file( $swsl_build . '/vendor-prefixed/autoload.php' ) && class_exists( PHPUnit\Framework\TestCase::class ) ) {
	throw new RuntimeException( 'Development dependencies leaked into the build.' );
}
echo "Plugin bootstrap smoke test passed.\n";
