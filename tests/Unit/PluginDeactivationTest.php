<?php
/** Source installations must remain deactivatable without Composer dependencies. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PluginDeactivationTest extends TestCase {

	public function testMissingAutoloadersDoNotPreventMaintenanceCleanup(): void {
		$directory = sys_get_temp_dir() . '/swsl-no-autoload-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory, 0700 );
		$plugin = $directory . '/sw-securelogin.php';
		copy( dirname( __DIR__, 2 ) . '/sw-securelogin.php', $plugin );
		// A fresh PHP process must not inherit PHPUnit's Composer loader or loaded classes.
		$code = <<<'PHP'
define( 'ABSPATH', '/swsl-no-autoload/' );
$deactivate = null;
$actions = array();
$cleared = array();
function register_deactivation_hook( string $file, callable $callback ): void {
	$GLOBALS['deactivate'] = $callback;
}
function add_action( string $hook, callable $callback ): void {
	$GLOBALS['actions'][] = $hook;
}
function wp_clear_scheduled_hook( string $hook ): void {
	$GLOBALS['cleared'][] = $hook;
}
require $argv[1];
if ( class_exists( 'SW\\Securelogin\\Update\\Provider', false ) || ! is_callable( $deactivate ) || array( 'admin_notices' ) !== $actions ) {
	throw new RuntimeException( 'Expected the missing-dependencies bootstrap path.' );
}
$deactivate();
echo json_encode( $cleared, JSON_THROW_ON_ERROR );
PHP;
		try {
			$process = proc_open(
				array( PHP_BINARY, '-n', '-r', $code, $plugin ),
				array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
				$pipes
			);
			$this->assertIsResource( $process );
			$output = stream_get_contents( $pipes[1] );
			$errors = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$this->assertSame( 0, proc_close( $process ), $output . $errors );
			$this->assertSame( array( 'swsl_cleanup' ), json_decode( $output, true, 512, JSON_THROW_ON_ERROR ) );
		} finally {
			unlink( $plugin );
			rmdir( $directory );
		}
	}
}
