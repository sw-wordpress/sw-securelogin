<?php
/** Real signed assertions, REST bindings, core hooks and session issuance. */
declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
use SW\Securelogin\Auth\AuthPolicy;
use SW\Securelogin\Auth\LoginSessionIssuer;
use SW\Securelogin\Auth\Reauthentication;
use SW\Securelogin\Core\MigrationRunner;
use SW\Securelogin\Core\Migrations\InitializeSettings;
use SW\Securelogin\Modules\Passkeys\Base64Url;
use SW\Securelogin\Modules\Passkeys\CredentialRepository;
use SW\Securelogin\Modules\Passkeys\LoginController;
use SW\Securelogin\Modules\Passkeys\PasskeysSchema;
use SW\Securelogin\Modules\Passkeys\PrivacyController;
use SW\Securelogin\Modules\Passkeys\RelyingParty;
use SW\Securelogin\Modules\Passkeys\WebAuthnService;
use SW\Securelogin\Security\AuditLogger;
use SW\Securelogin\Security\BrowserBinding;
use SW\Securelogin\Security\RateLimiter;
use SW\Securelogin\Security\TransactionRepository;

require_once dirname( __DIR__ ) . '/Support/AssertionFixture.php';

final class PasskeyLoginTest extends TestCase {
	private AssertionFixture $fixture;
	private CredentialRepository $credentials;
	private LoginController $controller;
	private int $userId;
	private array $server;
	private array $cookies;
	private array $filters;

	protected function setUp(): void {
		global $wpdb, $wp_filter;
		$this->server = $_SERVER;
		$this->cookies = $_COOKIE;
		$this->filters = array();
		foreach ( array( 'authenticate', 'wp_authenticate_user', 'wp_authenticate', 'wp_login', 'set_current_user', 'send_auth_cookies', 'swsl_passkey_auth_compatible' ) as $hook ) {
			$this->filters[$hook] = isset($wp_filter[$hook]) ? clone $wp_filter[$hook] : null;
		}
		$_SERVER['HTTPS'] = 'on';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$_COOKIE = array( BrowserBinding::COOKIE => bin2hex( random_bytes( 32 ) ) );
		wp_set_current_user( 0 );
		(new MigrationRunner( $wpdb, array( 1 => new InitializeSettings(), 2 => new PasskeysSchema() ) ))->run();
		foreach ( array( 'swsl_passkeys', 'swsl_transactions', 'swsl_user_handles', 'swsl_limits' ) as $suffix ) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . $suffix ) );
		$this->userId = get_user_by( 'login', 'swsl-test-admin' )->ID;
		WP_Session_Tokens::get_instance( $this->userId )->destroy_all();
		$this->fixture = new AssertionFixture();
		$this->credentials = new CredentialRepository( $wpdb );
		$this->fixture->handle = $this->credentials->handle( $this->userId );
		$this->credentials->add( $this->userId, $this->fixture->handle, $this->fixture->credential(), 'Test key', array( 'internal' ) );
		$transactions = new TransactionRepository( $wpdb );
		$limiter = new RateLimiter( $wpdb );
		$policy = new AuthPolicy();
		$rp = new RelyingParty( 'https://example.test', 'example.test' );
		$this->controller = new LoginController( $rp, $this->credentials, $transactions, new Reauthentication( $transactions, $limiter ), $limiter, new WebAuthnService( $rp ), $policy, new LoginSessionIssuer( $policy ), new BrowserBinding(), new AuditLogger( $limiter ) );
		rest_get_server();
		$this->controller->register();
	}

	protected function tearDown(): void {
		global $wpdb, $wp_filter;
		foreach ( $this->filters as $hook => $filter ) {
			if ( $filter ) $wp_filter[$hook] = $filter;
			else unset($wp_filter[$hook]);
		}
		WP_Session_Tokens::get_instance( $this->userId )->destroy_all();
		foreach ( array( 'swsl_passkeys', 'swsl_transactions', 'swsl_user_handles', 'swsl_limits' ) as $suffix ) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . $suffix ) );
		wp_set_current_user( 0 );
		$_COOKIE = $this->cookies;
		$_SERVER = $this->server;
	}

	private function request( string $path, array $body, array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/sw-securelogin/v1/passkeys/' . $path );
		$request->set_header( 'Origin', 'https://example.test' );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $key => $value ) $request->set_header( $key, $value );
		$request->set_body( json_encode( $body, JSON_THROW_ON_ERROR ) );
		return rest_get_server()->dispatch( $request );
	}

	private function start( string $name = 'swsl-test-admin' ): array {
		$response = $this->request( 'login/options', array( 'username' => $name, 'remember' => false, 'redirect' => '' ) );
		$this->assertSame( 200, $response->get_status(), json_encode($response->get_data()) );
		return json_decode( json_encode( $response->get_data() ), true );
	}

	private function assertNoSession(): void {
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( array(), WP_Session_Tokens::get_instance( $this->userId )->get_all() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testLoginUsesCoreHooksAndEachOfTwoCredentials(): void {
		add_filter('swsl_passkey_auth_compatible','__return_true');
		$logins = 0;
		add_action( 'wp_login', static function( $name, $user ) use (&$logins): void { ++$logins; }, 10, 2 );
		foreach ( array( $this->fixture, new AssertionFixture() ) as $index => $fixture ) {
			if ( $index ) {
				$fixture->handle = $this->credentials->handle( $this->userId );
				$this->credentials->add( $this->userId, $fixture->handle, $fixture->credential(), 'Second key', array() );
			}
			$body = $fixture->body( $this->start() );
			$response = $this->request( 'login/verify', $body );
			$this->assertSame( 200, $response->get_status(), json_encode($response->get_data()) );
			$this->assertSame( $this->userId, get_current_user_id() );
			$this->assertSame( admin_url(), $response->get_data()['redirect'] );
			$this->assertCount( $index + 1, WP_Session_Tokens::get_instance( $this->userId )->get_all() );
			$this->assertNotNull( $this->credentials->listForUser( $this->userId )[$index]['last_used_at'] );
			wp_set_current_user( 0 );
			$this->assertSame( 400, $this->request( 'login/verify', $body )->get_status() );
		}
		$this->assertSame( 2, $logins );
		$this->assertInstanceOf( WP_Error::class, wp_authenticate( 'swsl-test-admin', 'wrong' ) );
		$this->assertInstanceOf( WP_User::class, wp_authenticate( 'swsl-test-admin', 'swsl-disposable-test-password' ) );
	}

	public function testOptionsDoNotDistinguishKnownMissingAndCredentiallessAccounts(): void {
		global $wpdb;
		$known = $this->start();
		$missing = $this->start( 'swsl-no-such-user' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'swsl_passkeys' ) );
		$empty = $this->start();
		foreach ( array( &$known, &$missing, &$empty ) as &$options ) {
			$this->assertArrayNotHasKey( 'allowCredentials', $options['publicKey'] );
			unset( $options['transaction'], $options['publicKey']['challenge'] );
		}
		$this->assertSame( $known, $missing );
		$this->assertSame( $known, $empty );
		$this->assertNoSession();
	}

	public function testUnknownOrDifferentUserCannotUseAValidCredential(): void {
		foreach ( array( 'swsl-no-such-user', 'swsl-test-admin' ) as $name ) {
			$body = $this->fixture->body( $this->start( $name ) );
			if ( 'swsl-test-admin' === $name ) $body['userHandle'] = Base64Url::encode( random_bytes( 32 ) );
			$response = $this->request( 'login/verify', $body );
			$this->assertSame( 400, $response->get_status() );
			$this->assertNoSession();
		}
	}

	public function testAnotherExistingAccountCannotUseTheOwnersCredential(): void {
		$name='swsl-login-'.bin2hex(random_bytes(4));
		$other=wp_insert_user(array('user_login'=>$name,'user_pass'=>'disposable-other-account','role'=>'subscriber'));
		$this->assertIsInt($other);
		try {
			$body=$this->fixture->body($this->start($name));
			$this->assertSame(400,$this->request('login/verify',$body)->get_status());
			$this->assertNoSession();
		} finally {
			require_once ABSPATH.'wp-admin/includes/user.php';
			wp_delete_user($other);
		}
	}

	public function testInvalidSignatureConsumesTransactionWithoutCreatingSession(): void {
		$body = $this->fixture->body( $this->start() );
		$bad = $body;
		$bad['signature'] = Base64Url::encode( random_bytes( 70 ) );
		$this->assertSame( 400, $this->request( 'login/verify', $bad )->get_status() );
		$this->assertSame( 400, $this->request( 'login/verify', $body )->get_status() );
		$this->assertNoSession();
	}

	public static function invalidAssertions(): array {
		return array('challenge'=>array('challenge'),'origin'=>array('origin'),'rp'=>array('rp'),'uv'=>array('uv'),'up'=>array('up'),'type'=>array('type'),'credential'=>array('credential'),'backup'=>array('backup'));
	}

	#[DataProvider('invalidAssertions')]
	public function testProtocolFailuresCannotIssueSessions( string $case ): void {
		$context = match($case) {
			'challenge' => array('challenge'=>Base64Url::encode(random_bytes(32))),
			'origin' => array('origin'=>'https://evil.example.test'),
			'type' => array('type'=>'webauthn.create'),
			default => array(),
		};
		$flags = match($case) { 'uv'=>1,'up'=>4,'backup'=>13,default=>5 };
		$body = $this->fixture->body($this->start(),$context,$flags,1,'rp' === $case ? 'evil.test' : 'example.test');
		if ('credential' === $case) $body['rawId'] = Base64Url::encode(random_bytes(32));
		$this->assertSame(400,$this->request('login/verify',$body)->get_status());
		$this->assertNoSession();
	}

	public function testExpiredAndRevokedCredentialsCannotCreateSession(): void {
		global $wpdb;
		$body = $this->fixture->body( $this->start() );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %d', $wpdb->prefix . 'swsl_transactions', time() - 1 ) );
		$this->assertSame( 400, $this->request( 'login/verify', $body )->get_status() );
		$body = $this->fixture->body( $this->start() );
		$this->assertTrue( $this->credentials->revoke( $this->userId, (int)$this->credentials->listForUser($this->userId)[0]['id'] ) );
		$this->assertSame( 400, $this->request( 'login/verify', $body )->get_status() );
		$this->assertNoSession();
	}

	public function testBrowserBindingAndOriginPreventLoginCsrf(): void {
		$body = $this->fixture->body( $this->start() );
		$original = $_COOKIE[BrowserBinding::COOKIE];
		$_COOKIE[BrowserBinding::COOKIE] = bin2hex(random_bytes(32));
		$this->assertSame( 400, $this->request( 'login/verify', $body )->get_status() );
		unset($_COOKIE[BrowserBinding::COOKIE]);
		$this->assertSame( 400, $this->request( 'login/verify', $body )->get_status() );
		$_COOKIE[BrowserBinding::COOKIE] = $original;
		$this->assertSame( 403, $this->request( 'login/verify', $body, array( 'Origin' => 'https://evil.test' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'login/verify', $body, array( 'Origin' => '' ) )->get_status() );
		$this->assertNoSession();
	}

	public function testUnreviewedAuthenticationPluginBlocksAndReviewedPolicyCanStillReject(): void {
		$body = $this->fixture->body( $this->start() );
		$reject = static fn($user) => new WP_Error('test_mfa_required', 'Additional authentication required.');
		add_filter('authenticate', $reject, 99);
		$this->assertSame(400, $this->request('login/verify',$body)->get_status());
		$this->assertNoSession();
		add_filter('swsl_passkey_auth_compatible','__return_true');
		$body = $this->fixture->body( $this->start() );
		$this->assertSame(400, $this->request('login/verify',$body)->get_status());
		$this->assertNoSession();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testReviewedHoneypotNeitherBlocksNorDropsTheVerifiedIdentityAndEmailSelectsTheAccount(): void {
		if ( ! function_exists( 'wpae_wplogin_extra_validation' ) ) {
			// Mirrors WP Armour: form-field check, veto on spam, otherwise null instead of $user.
			function wpae_wplogin_extra_validation( $user, $username, $password ) {
				return empty( $_POST['swsl_spam'] ) ? null : new WP_Error( 'error', 'Spam.' );
			}
		}
		add_filter( 'authenticate', 'wpae_wplogin_extra_validation', 10, 3 );
		$email = get_userdata( $this->userId )->user_email;
		$_POST = array( 'swsl_spam' => '1' );
		$this->assertSame( 400, $this->request( 'login/verify', $this->fixture->body( $this->start( $email ) ) )->get_status() );
		$this->assertNoSession();
		$_POST = array();
		$response = $this->request( 'login/verify', $this->fixture->body( $this->start( $email ), array(), 5, 2 ) );
		$this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
		$this->assertSame( $this->userId, get_current_user_id() );
		$this->assertArrayNotHasKey( 19, $GLOBALS['wp_filter']['authenticate']->callbacks );
	}

	public function testUnreviewedPostLoginHookBlocksBeforeSessionIssuance(): void {
		$body=$this->fixture->body($this->start());
		$called=false;
		add_action('wp_login',static function() use(&$called): void {$called=true;});
		$this->assertSame(400,$this->request('login/verify',$body)->get_status());
		$this->assertFalse($called);
		$this->assertNoSession();
	}

	public function testUserPolicyAndIdentitySubstitutionAreRejected(): void {
		foreach (array('wp_authenticate_user','authenticate') as $counter => $hook) {
			add_filter('swsl_passkey_auth_compatible','__return_true');
			$reject = static fn($user) => new WP_User(0);
			add_filter($hook,$reject,50);
			$body = $this->fixture->body( $this->start(), array(), 5, $counter + 1 );
			$this->assertSame(400,$this->request('login/verify',$body)->get_status());
			$this->assertNoSession();
			remove_filter($hook,$reject,50);
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCookieVetoAndLoginHookExceptionRollBackNewSessions(): void {
		add_filter('swsl_passkey_auth_compatible','__return_true');
		foreach (array('send_auth_cookies','wp_login','set_current_user') as $counter => $hook) {
			$called = false;
			$reject = static function() use($hook,&$called) {
				if ('set_current_user' === $hook && !get_current_user_id()) return false;
				$called = true;
				if ('send_auth_cookies' !== $hook) throw new RuntimeException('Test login hook failure.');
				return false;
			};
			add_filter($hook,$reject);
			$body = $this->fixture->body( $this->start(), array(), 5, $counter + 1 );
			$this->assertSame(400,$this->request('login/verify',$body)->get_status());
			$this->assertTrue($called);
			$this->assertNoSession();
			remove_filter($hook,$reject);
		}
	}

	public function testReauthenticationRequiresCookieNonceAndIssuesOnlyOneTimeGrant(): void {
		$this->assertSame(403,$this->request('reauthentication/options',array())->get_status());
		wp_set_current_user($this->userId);
		$token = WP_Session_Tokens::get_instance($this->userId)->create(time()+600);
		$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($this->userId,time()+600,'logged_in',$token);
		$this->assertSame(403,$this->request('reauthentication/options',array())->get_status());
		$headers = array('X-WP-Nonce'=>wp_create_nonce('wp_rest'));
		$options = $this->request('reauthentication/options',array(),$headers);
		$this->assertSame(200,$options->get_status());
		$body = $this->fixture->body(json_decode(json_encode($options->get_data()),true));
		$this->assertSame(403,$this->request('login/verify',$body)->get_status());
		$response = $this->request('reauthentication/verify',$body,$headers);
		$this->assertSame(200,$response->get_status(),json_encode($response->get_data()));
		$this->assertCount(1,WP_Session_Tokens::get_instance($this->userId)->get_all());
		global $wpdb;
		$proof = new Reauthentication(new TransactionRepository($wpdb),new RateLimiter($wpdb));
		$this->assertGreaterThan(time(),$proof->consume($response->get_data()['grant']));
		$this->expectException(RuntimeException::class);
		$proof->consume($response->get_data()['grant']);
	}

	public function testRateLimitsApplyToUnknownNamesAndIgnoreForwardedAddresses(): void {
		for ($i=0;$i<10;++$i) $this->start('swsl-no-such-user');
		$this->assertSame(400,$this->request('login/options',array('username'=>'swsl-no-such-user','remember'=>false,'redirect'=>''))->get_status());
		for ($i=0;$i<30;++$i) $this->request('login/verify',array(),array('X-Forwarded-For'=>'192.0.2.'.$i));
		$this->assertSame(429,$this->request('login/verify',array())->get_status());
		$this->assertNoSession();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testProofExpiryAtCookieIssuanceRollsBackSessionAndTemporaryHooks(): void {
		$issuer = new LoginSessionIssuer(new AuthPolicy());
		try {
			$issuer->issue(get_user_by('id',$this->userId),false,time()-1);
			$this->fail('Expired proof created a session.');
		} catch (RuntimeException $expected) {
			$this->assertNoSession();
		}
		$this->assertInstanceOf(WP_Error::class,wp_authenticate('swsl-test-admin','wrong'));
	}

	public function testDeviceCounterRegressionIsRejectedWithoutSession(): void {
		global $wpdb;
		$wpdb->query($wpdb->prepare('UPDATE %i SET sign_count = 5',$wpdb->prefix.'swsl_passkeys'));
		$body = $this->fixture->body($this->start(),array(),5,4);
		$this->assertSame(400,$this->request('login/verify',$body)->get_status());
		$this->assertNoSession();
		$this->assertSame('5',(string)$wpdb->get_var('SELECT sign_count FROM '.$wpdb->prefix.'swsl_passkeys'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testSyncedCounterRegressionIsAuditedAndPreservesHighWaterMark(): void {
		global $wpdb;
		$wpdb->query($wpdb->prepare('UPDATE %i SET sign_count = 5, backup_eligible = 1',$wpdb->prefix.'swsl_passkeys'));
		$body = $this->fixture->body($this->start(),array(),29,0);
		$this->assertSame(200,$this->request('login/verify',$body)->get_status());
		$row = $wpdb->get_row('SELECT sign_count, backup_state FROM '.$wpdb->prefix.'swsl_passkeys',ARRAY_A);
		$this->assertSame('5',(string)$row['sign_count']);
		$this->assertSame('1',(string)$row['backup_state']);
		$bucket = hash('sha256','audit_counter_anomaly:0:'.intdiv(time(),DAY_IN_SECONDS));
		$this->assertSame('1',(string)$wpdb->get_var($wpdb->prepare('SELECT attempts FROM %i WHERE bucket = %s',$wpdb->prefix.'swsl_limits',$bucket)));
	}

	public function testPasswordChangeInvalidatesOutstandingCeremony(): void {
		$body = $this->fixture->body($this->start());
		try {
			wp_set_password('changed-disposable-password',$this->userId);
			$this->assertSame(400,$this->request('login/verify',$body)->get_status());
			$this->assertNoSession();
		} finally {
			wp_set_password('swsl-disposable-test-password',$this->userId);
		}
	}

	public function testUserLockExcludesParallelRevocationDuringAuthentication(): void {
		$other = new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
		global $wpdb;
		$other->set_prefix($wpdb->prefix);
		$id = (int)$this->credentials->listForUser($this->userId)[0]['id'];
		try {
			$this->credentials->authenticate($this->userId,$this->fixture->id,
				static fn($row) => new SW\Securelogin\Modules\Passkeys\VerifiedAssertion(1,false,false),
				function() use($other,$id): void {
					try {
						(new CredentialRepository($other))->revoke($this->userId,$id);
						$this->fail('Parallel revocation entered a locked authentication.');
					} catch (RuntimeException $expected) {
						$this->assertSame('Concurrent credential mutation.',$expected->getMessage());
					}
				});
		} finally { $other->close(); }
		$this->assertTrue($this->credentials->revoke($this->userId,$id));
	}

	public function testRedirectsStayInTheSameAdminTree(): void {
		$issuer = new LoginSessionIssuer(new AuthPolicy());
		foreach (array('https://evil.test/wp-admin/','//evil.test/wp-admin/','javascript:alert(1)',home_url('/'),admin_url('../outside'),admin_url('%2e%2e/outside'),admin_url('%5c..%5coutside')) as $target) $this->assertSame(admin_url(),$issuer->redirect($target));
		$this->assertSame(admin_url('profile.php'),$issuer->redirect(admin_url('profile.php')));
	}

	public function testAnonymousTransactionsSurviveOrphanCleanupUntilTheirExpiry(): void {
		global $wpdb;
		$this->start();
		(new PrivacyController($wpdb,$this->credentials))->cleanup();
		$this->assertSame('1',(string)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'swsl_transactions'));
	}
}
