<?php
/** Independent process for a simultaneous one-time-consume regression. */
declare(strict_types=1);
if ( 'cli' !== PHP_SAPI ) exit;
require __DIR__ . '/bootstrap.php';
$token = $argv[1];
$user = (int) $argv[2];
$barrier = $argv[3];
$start = microtime( true );
while ( ! file_exists( $barrier ) ) {
	if ( microtime( true ) - $start > 10 ) throw new RuntimeException( 'Test barrier timed out.' );
	usleep( 1000 );
}
// Force both readers to reach DELETE before either may consume the row.
add_filter( 'query', static function ( string $query ) use ( $barrier ): string {
	if ( preg_match( '/^DELETE FROM `?swsltest_swsl_transactions/', $query ) ) {
		touch( $barrier . '.' . getmypid() );
		$deadline = microtime( true ) + 10;
		while ( count( glob( $barrier . '.*' ) ) < 2 ) {
			if ( microtime( true ) > $deadline ) throw new RuntimeException( 'Parallel DELETE barrier timed out.' );
			usleep( 1000 );
		}
	}
	return $query;
} );
$result = ( new SW\Securelogin\Security\TransactionRepository( $wpdb ) )->consume( $token, $user, 'parallel-test-binding', 'test' );
echo null === $result ? '0' : '1';
