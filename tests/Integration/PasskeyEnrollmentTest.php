<?php
/** Cookie-session REST boundaries and real database persistence. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Auth\Reauthentication;
use SW\Securelogin\Core\MigrationRunner;
use SW\Securelogin\Core\Migrations\InitializeSettings;
use SW\Securelogin\Core\SettingsRepository;
use SW\Securelogin\Modules\Passkeys\Base64Url;
use SW\Securelogin\Modules\Passkeys\CredentialRepository;
use SW\Securelogin\Modules\Passkeys\PasskeysModule;
use SW\Securelogin\Modules\Passkeys\PasskeysSchema;
use SW\Securelogin\Modules\Passkeys\PrivacyController;
use SW\Securelogin\Modules\Passkeys\RegistrationController;
use SW\Securelogin\Modules\Passkeys\RelyingParty;
use SW\Securelogin\Modules\Passkeys\VerifiedCredential;
use SW\Securelogin\Modules\Passkeys\WebAuthnService;
use SW\Securelogin\Security\RateLimiter;
use SW\Securelogin\Security\TransactionRepository;

final class PasskeyEnrollmentTest extends TestCase {
	private CredentialRepository $credentials;
	private TransactionRepository $transactions;
	private RegistrationController $controller;
	private string $token;
	private int $userId;
	private array $fixture;
	private mixed $oldHttps;

	protected function setUp(): void {
		global $wpdb;
		$this->oldHttps = $_SERVER['HTTPS'] ?? null;
		$_SERVER['HTTPS'] = 'on';
		delete_option( 'swsl_schema_version' );
		$runner = new MigrationRunner( $wpdb, array( 1 => new InitializeSettings(), 2 => new PasskeysSchema() ) );
		$this->assertTrue( $runner->run() );
		foreach ( array( 'swsl_passkeys', 'swsl_transactions', 'swsl_user_handles', 'swsl_limits' ) as $suffix ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . $suffix ) );
		}
		$user = get_user_by( 'login', 'swsl-test-admin' );
		$this->userId = $user->ID;
		wp_set_current_user( $this->userId );
		$this->token = WP_Session_Tokens::get_instance( $this->userId )->create( time() + 600 );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->userId, time() + 600, 'logged_in', $this->token );
		$this->credentials = new CredentialRepository( $wpdb );
		$this->transactions = new TransactionRepository( $wpdb );
		$this->fixture = json_decode( file_get_contents( dirname( __DIR__ ) . '/Fixtures/WebAuthn/chromium.json' ), true, 512, JSON_THROW_ON_ERROR );
		$rp = new RelyingParty( 'https://example.test', 'example.test' );
		$this->controller = new RegistrationController( $rp, $this->credentials, $this->transactions, new Reauthentication( $this->transactions, new RateLimiter( $wpdb ) ), new RateLimiter( $wpdb ), new WebAuthnService( $rp ) );
		rest_get_server();
		$this->controller->register();
	}

	protected function tearDown(): void {
		global $wpdb;
		WP_Session_Tokens::get_instance( $this->userId )->destroy( $this->token );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
		if ( null === $this->oldHttps ) unset( $_SERVER['HTTPS'] );
		else $_SERVER['HTTPS'] = $this->oldHttps;
		foreach ( array( 'swsl_passkeys', 'swsl_transactions', 'swsl_user_handles', 'swsl_limits' ) as $suffix ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . $suffix ) );
		}
		delete_option( 'swsl_passkeys_rp' );
		delete_option( 'swsl_schema_version' );
		delete_option( 'swsl_settings' );
	}

	private function request( string $path, string $method = 'POST', array $body = array(), array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/sw-securelogin/v1/passkeys' . $path );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Origin', 'https://example.test' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		foreach ( $headers as $name => $value ) $request->set_header( $name, $value );
		$request->set_body( json_encode( $body, JSON_THROW_ON_ERROR ) );
		return rest_get_server()->dispatch( $request );
	}

	private function grant(): string {
		$response = $this->request( '/reauthenticate', 'POST', array( 'password' => 'swsl-disposable-test-password' ) );
		$this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
		return $response->get_data()['grant'];
	}

	private function fixtureTransaction( string $name = 'Test key' ): string {
		global $wpdb;
		return $this->transactions->issue( $this->userId, ( new Reauthentication( $this->transactions, new RateLimiter( $wpdb ) ) )->binding(), 'registration', array(
			'challenge' => $this->fixture['challenge'], 'handle' => Base64Url::encode( $this->credentials->handle( $this->userId ) ), 'name' => $name,
		), time() + 120 );
	}

	private function verifyBody( string $transaction ): array {
		return array( 'transaction' => $transaction, 'rawId' => $this->fixture['rawId'], 'clientDataJSON' => $this->fixture['clientDataJSON'], 'attestationObject' => $this->fixture['attestationObject'], 'transports' => array( 'internal' ) );
	}

	public function testFreshPasswordProofOptionsAndOneTimeGrant(): void {
		$this->assertSame( 400, $this->request( '/registration/options', 'POST', array( 'grant' => str_repeat( 'a', 64 ), 'name' => 'test' ) )->get_status() );
		$grant = $this->grant();
		$response = $this->request( '/registration/options', 'POST', array( 'grant' => $grant, 'name' => 'Laptop' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = json_decode( json_encode( $response->get_data() ), true );
		$this->assertSame( 'required', $data['publicKey']['authenticatorSelection']['userVerification'] );
		$this->assertSame( 'none', $data['publicKey']['attestation'] );
		$this->assertSame( 400, $this->request( '/registration/options', 'POST', array( 'grant' => $grant, 'name' => 'Replay' ) )->get_status() );
	}

	public function testRegistrationIsConsumedOnceAndNoSessionIsCreated(): void {
		$before = WP_Session_Tokens::get_instance( $this->userId )->get_all();
		$body = $this->verifyBody( $this->fixtureTransaction() );
		$this->assertSame( 200, $this->request( '/registration/verify', 'POST', $body )->get_status() );
		$this->assertCount( 1, $this->credentials->listForUser( $this->userId ) );
		$this->assertSame( 400, $this->request( '/registration/verify', 'POST', $body )->get_status() );
		$this->assertSame( $before, WP_Session_Tokens::get_instance( $this->userId )->get_all() );
		$this->assertSame( 200, $this->request( '', 'GET' )->get_status() );
	}

	public function testFailedValidationConsumesTransactionAndStoresNothing(): void {
		$body = $this->verifyBody( $this->fixtureTransaction() );
		$wrong = $body;
		$wrong['rawId'] = Base64Url::encode( random_bytes( 32 ) );
		$this->assertSame( 400, $this->request( '/registration/verify', 'POST', $wrong )->get_status() );
		$this->assertSame( 400, $this->request( '/registration/verify', 'POST', $body )->get_status() );
		$this->assertCount( 0, $this->credentials->listForUser( $this->userId ) );
	}

	public function testMissingNonceWrongOriginAndNoCookieAreRejected(): void {
		$this->assertSame( 403, $this->request( '', 'GET', array(), array( 'X-WP-Nonce' => '' ) )->get_status() );
		$this->assertSame( 403, $this->request( '', 'GET', array(), array( 'Origin' => 'https://evil.example.test' ) )->get_status() );
		$cookie = $_COOKIE[ LOGGED_IN_COOKIE ];
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$this->assertSame( 403, $this->request( '', 'GET' )->get_status() );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	}

	public function testProofCannotBeUsedFromAnotherSessionOrAfterExpiry(): void {
		global $wpdb;
		$grant = $this->grant();
		$original = $_COOKIE[ LOGGED_IN_COOKIE ];
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->userId, time() + 600, 'logged_in', 'another-test-session' );
		$this->assertSame( 400, $this->request( '/registration/options', 'POST', array( 'grant' => $grant, 'name' => 'wrong session' ) )->get_status() );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $original;
		$wpdb->update( $wpdb->prefix . 'swsl_transactions', array( 'expires_at' => time() - 1 ), array( 'token_hash' => hash( 'sha256', $grant ) ) );
		$this->assertSame( 400, $this->request( '/registration/options', 'POST', array( 'grant' => $grant, 'name' => 'expired' ) )->get_status() );
	}

	public function testFailedPasswordsAreRateLimited(): void {
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$this->assertSame( 400, $this->request( '/reauthenticate', 'POST', array( 'password' => 'wrong' ) )->get_status() );
		}
		$this->assertSame( 400, $this->request( '/reauthenticate', 'POST', array( 'password' => 'swsl-disposable-test-password' ) )->get_status() );
		$this->assertSame( $this->token, wp_get_session_token() );
	}

	public function testRenameAndRevokeRequireFreshProofAndOwnership(): void {
		$this->assertSame( 200, $this->request( '/registration/verify', 'POST', $this->verifyBody( $this->fixtureTransaction() ) )->get_status() );
		$id = $this->credentials->listForUser( $this->userId )[0]['id'];
		$this->assertSame( 400, $this->request( '/' . $id, 'DELETE', array( 'grant' => str_repeat( 'a', 64 ) ) )->get_status() );
		$this->assertSame( 200, $this->request( '/' . $id, 'PATCH', array( 'grant' => $this->grant(), 'name' => 'New name' ) )->get_status() );
		$this->assertSame( 'New name', $this->credentials->listForUser( $this->userId )[0]['name'] );
		$this->assertFalse( $this->credentials->revoke( $this->userId + 999, (int) $id ) );
		$this->assertFalse( $this->credentials->rename( $this->userId + 999, (int) $id, 'stolen' ) );
		$this->assertSame( 200, $this->request( '/' . $id, 'DELETE', array( 'grant' => $this->grant() ) )->get_status() );
		$this->assertCount( 0, $this->credentials->listForUser( $this->userId ) );
	}

	public function testCredentialLimitAndGlobalUniqueness(): void {
		global $wpdb;
		$handle = $this->credentials->handle( $this->userId );
		for ( $i = 0; $i < CredentialRepository::LIMIT; ++$i ) {
			$this->credentials->add( $this->userId, $handle, new VerifiedCredential( random_bytes( 32 ), 'test public key', 0, '', false, false ), 'Test', array() );
		}
		$this->expectException( RuntimeException::class );
		$this->credentials->add( $this->userId, $handle, new VerifiedCredential( random_bytes( 32 ), 'test public key', 0, '', false, false ), 'Excess', array() );
	}

	public function testSingleWinnerOnIndependentConnections(): void {
		global $wpdb;
		$token = $this->transactions->issue( $this->userId, 'test-binding', 'test', array( 'safe' => true ), time() + 30 );
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $wpdb->prefix );
		try {
			$independent = new TransactionRepository( $other );
			$this->assertNull( $independent->consume( $token, $this->userId + 1, 'test-binding', 'test' ) );
			$this->assertNotNull( $independent->consume( $token, $this->userId, 'test-binding', 'test' ) );
			$this->assertNull( $this->transactions->consume( $token, $this->userId, 'test-binding', 'test' ) );
		} finally { $other->close(); }
	}

	public function testPrivacyErasureAndExpiryCleanup(): void {
		global $wpdb;
		$this->assertSame( 200, $this->request( '/registration/verify', 'POST', $this->verifyBody( $this->fixtureTransaction() ) )->get_status() );
		$privacy = new PrivacyController( $wpdb, $this->credentials );
		$user = get_userdata( $this->userId );
		$this->assertCount( 1, $privacy->export( $user->user_email )['data'] );
		$this->assertTrue( $privacy->erase( $user->user_email )['items_removed'] );
		$this->assertCount( 0, $this->credentials->listForUser( $this->userId ) );
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM swsltest_swsl_transactions' ) );
		$this->transactions->issue( $this->userId, 'expired', 'test', array(), time() - 1 );
		$privacy->cleanup();
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM swsltest_swsl_transactions' ) );
	}
	public function testParallelWorkersHaveExactlyOneWinner(): void {
		$token = $this->transactions->issue( $this->userId, 'parallel-test-binding', 'test', array(), time() + 30 );
		$barrier = tempnam( sys_get_temp_dir(), 'swsl-barrier-' );
		unlink( $barrier );
		$workers = array();
		try {
			for ( $i = 0; $i < 2; ++$i ) {
				$process = proc_open( array( PHP_BINARY, __DIR__ . '/consume-worker.php', $token, (string) $this->userId, $barrier ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
				$this->assertIsResource( $process );
				$workers[] = array( $process, $pipes );
			}
			touch( $barrier );
			$wins = 0;
			foreach ( $workers as [ $process, $pipes ] ) {
				$output = stream_get_contents( $pipes[1] );
				$error = stream_get_contents( $pipes[2] );
				fclose( $pipes[1] ); fclose( $pipes[2] );
				$this->assertSame( 0, proc_close( $process ), $error );
				$this->assertContains( $output, array( '0', '1' ) );
				$wins += (int) $output;
			}
			$this->assertSame( 1, $wins );
		} finally {
			if ( file_exists( $barrier ) ) unlink( $barrier );
			foreach ( glob( $barrier . '.*' ) as $marker ) unlink( $marker );
		}
	}

	public function testDuplicateCredentialIsRejectedAcrossOwners(): void {
		global $wpdb;
		$this->assertSame( 200, $this->request( '/registration/verify', 'POST', $this->verifyBody( $this->fixtureTransaction() ) )->get_status() );
		$other = wp_insert_user( array( 'user_login' => 'swsl-duplicate-fixture', 'user_pass' => wp_generate_password(), 'user_email' => 'swsl-duplicate@example.test', 'role' => 'subscriber' ) );
		$this->assertIsInt( $other );
		$old = $wpdb->suppress_errors( true );
		try {
			$rejected = false;
			try {
				$this->credentials->add( $other, $this->credentials->handle( $other ), new VerifiedCredential( Base64Url::decode( $this->fixture['rawId'], 1023 ), 'public fixture', 0, '', false, false ), 'Duplicate', array() );
			} catch ( RuntimeException $expected ) { $rejected = true; }
			$this->assertTrue( $rejected );
			$this->assertCount( 0, $this->credentials->listForUser( $other ) );
			$this->assertCount( 1, $this->credentials->listForUser( $this->userId ) );
		} finally {
			$wpdb->suppress_errors( $old );
			$this->credentials->purgeUser( $other );
			wp_delete_user( $other );
		}
	}

	public function testUserDeletionCleansDataEvenWhenModuleIsDisabled(): void {
		global $wpdb;
		$owner = wp_insert_user( array( 'user_login' => 'swsl-delete-fixture', 'user_pass' => wp_generate_password(), 'user_email' => 'swsl-delete@example.test', 'role' => 'subscriber' ) );
		$this->assertIsInt( $owner );
		$handle = $this->credentials->handle( $owner );
		$this->credentials->add( $owner, $handle, new VerifiedCredential( random_bytes( 32 ), 'public fixture', 0, '', false, false ), 'Remove me', array() );
		$this->transactions->issue( $owner, 'test', 'test', array(), time() + 30 );
		update_option( 'swsl_settings', array( 'enabled_modules' => array() ), false );
		( new PasskeysModule( $wpdb, new SettingsRepository() ) )->registerSettings();
		$this->assertTrue( wp_delete_user( $owner ) );
		$this->assertCount( 0, $this->credentials->listForUser( $owner ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT handle FROM %i WHERE user_id = %d', $wpdb->prefix . 'swsl_user_handles', $owner ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', $wpdb->prefix . 'swsl_transactions', $owner ) ) );
	}

	public function testPinnedOriginChangesAndMalformedSettingsFailClosed(): void {
		global $wpdb;
		$parts = wp_parse_url( site_url() );
		$origin = 'https://' . $parts['host'] . ( isset( $parts['port'] ) && 443 !== $parts['port'] ? ':' . $parts['port'] : '' );
		update_option( 'swsl_passkeys_rp', array( 'origin' => $origin, 'id' => $parts['host'] ) );
		$settings = new SettingsRepository();
		$module = new PasskeysModule( $wpdb, $settings );
		$this->assertSame( array(), $module->requirements() );
		$change = static fn() => 'https://changed.example.test';
		add_filter( 'pre_option_siteurl', $change );
		try { $this->assertNotEmpty( $module->requirements() ); }
		finally { remove_filter( 'pre_option_siteurl', $change ); }
		$this->assertSame( $origin, get_option( 'swsl_passkeys_rp' )['origin'] );
		update_option( 'swsl_settings', array( 'enabled_modules' => array( 'passkeys', 123 ) ) );
		$this->assertSame( array(), $settings->enabledModules() );
	}

}
