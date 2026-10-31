<?php
/** Verify real WordPress UI hooks, asset boundaries, module configuration and lifecycle. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use SW\Securelogin\Core\Plugin;
use SW\Securelogin\Core\MigrationRunner;
use SW\Securelogin\Core\Migrations\InitializeSettings;
use SW\Securelogin\Core\SettingsRepository;
use SW\Securelogin\Modules\Passkeys\LoginPage;
use SW\Securelogin\Modules\Passkeys\ProfileController;
use SW\Securelogin\Modules\Passkeys\PasskeysModule;
use SW\Securelogin\Modules\Passkeys\PasskeysSchema;
use SW\Securelogin\Security\BrowserBinding;

final class PasskeyPresentationTest extends TestCase {
	private array $server;
	private array $post;
	private array $request;
	private array $options;
	private array $hooks;
	private int $userId;

	protected function setUp(): void {
		global $wpdb, $wp_filter;
		$this->server=$_SERVER;
		$this->post=$_POST;
		$this->request=$_REQUEST;
		$this->options=[];
		foreach (array('siteurl','home','swsl_settings','swsl_schema_version','swsl_passkeys_rp') as $name) $this->options[$name]=get_option($name);
		$this->hooks=[];
		foreach ($wp_filter as $name=>$hook) $this->hooks[$name]=clone $hook;
		$_SERVER['HTTPS']='on';
		$this->userId=get_user_by('login','swsl-test-admin')->ID;
		wp_set_current_user($this->userId);
		(new MigrationRunner($wpdb,array(1=>new InitializeSettings(),2=>new PasskeysSchema())))->run();
		update_option('siteurl','https://example.test');
		update_option('home','https://example.test');
		delete_option('swsl_passkeys_rp');
		wp_clear_scheduled_hook('swsl_cleanup');
	}

	protected function tearDown(): void {
		global $wp_filter;
		$wp_filter=$this->hooks;
		foreach ($this->options as $name=>$value) { if (false === $value) delete_option($name); else update_option($name,$value); }
		wp_clear_scheduled_hook('swsl_cleanup');
		wp_set_current_user(0);
		$_SERVER=$this->server;$_POST=$this->post;$_REQUEST=$this->request;
		foreach (array('swsl-passkeys','swsl-assertion','swsl-login') as $handle) { wp_dequeue_script($handle);wp_deregister_script($handle);wp_dequeue_style($handle);wp_deregister_style($handle); }
	}

	private function captureHtml(callable $render): string {
		ob_start();try { $render();return ob_get_contents(); } finally { ob_end_clean(); }
	}

	public function testProfileIsOwnerOnlyAndAssetsAreScopedToProfile(): void {
		$profile=new ProfileController();$profile->register();
		$this->assertNotFalse(has_action('show_user_profile',array($profile,'render')));
		$profile->enqueue('index.php');
		$this->assertFalse(wp_script_is('swsl-passkeys','enqueued'));
		$profile->enqueue('profile.php');
		$this->assertTrue(wp_script_is('swsl-passkeys','enqueued'));
		$this->assertSame(array('swsl-assertion'),wp_scripts()->registered['swsl-passkeys']->deps);
		$this->assertStringContainsString('"nonce"',wp_scripts()->get_data('swsl-passkeys','data'));
		$this->assertStringContainsString('passkeys',wp_scripts()->get_data('swsl-passkeys','data'));
		$html=$this->captureHtml(fn()=>$profile->render(wp_get_current_user()));
		$this->assertStringContainsString('id="swsl-passkey-proof"',$html);
		$this->assertStringContainsString('role="status"',$html);
		$this->assertStringContainsString('autocomplete="current-password"',$html);
		$other=new WP_User();$other->ID=$this->userId+100;
		$this->assertSame('',$this->captureHtml(fn()=>$profile->render($other)));
	}

	public function testLoginControlsPreserveThePasswordFormAndUseLocalAssets(): void {
		$page=new LoginPage();$page->register();$page->enqueue();
		$this->assertNotFalse(has_action('login_form',array($page,'render')));
		$this->assertTrue(wp_script_is('swsl-login','enqueued'));
		$this->assertSame(array('swsl-assertion'),wp_scripts()->registered['swsl-login']->deps);
		$html=$this->captureHtml(fn()=>$page->render());
		$this->assertStringContainsString('type="button"',$html);
		$this->assertStringNotContainsString('type="password"',$html);
		$this->assertStringContainsString('aria-live="polite"',$html);
		// The password switch only appears once script hides the password form.
		$this->assertMatchesRegularExpression('/id="swsl-password-login"[^>]*hidden>/',$html);
	}

	public function testModuleSavePinsOriginEnablesLoginAndCanDisableWithoutRevokingSessions(): void {
		global $wpdb;
		$module=new PasskeysModule($wpdb,new SettingsRepository());
		$module->registerSettings();
		$this->assertNotSame(array(),$module->requirements());
		$this->assertTrue($module->isAvailable());
		$this->assertFalse($module->configurationSchema()['enabled']['default']);
		$redirects=[];
		add_filter('wp_redirect',static function($url) use(&$redirects) { $redirects[]=$url;return false; });
		$_SERVER['REQUEST_METHOD']='POST';
		$_POST=array('enabled'=>'1');$_REQUEST=array('_wpnonce'=>wp_create_nonce('swsl_passkeys_settings'));
		$module->saveSettings();
		$this->assertSame(array('passkeys'),(new SettingsRepository())->enabledModules());
		$this->assertSame(array('origin'=>'https://example.test','id'=>'example.test'),get_option('swsl_passkeys_rp'));
		$this->assertSame(array(),$module->requirements());
		$this->assertNotFalse(wp_next_scheduled('swsl_cleanup'));
		$module->register();
		$this->assertNotFalse(has_action('login_form'));
		$token=WP_Session_Tokens::get_instance($this->userId)->create(time()+300);
		try {
			$_POST=[];$module->saveSettings();
			$this->assertSame(array(),(new SettingsRepository())->enabledModules());
			$this->assertTrue(WP_Session_Tokens::get_instance($this->userId)->verify($token));
		} finally { WP_Session_Tokens::get_instance($this->userId)->destroy($token); }
		$this->assertSame(array(admin_url('admin.php?page=swsl-overview'),admin_url('admin.php?page=swsl-overview')),$redirects);
	}

	public function testOverviewReportsUnreviewedPoliciesAndHidesSettingsFromOtherUsers(): void {
		global $wpdb;
		$module=new PasskeysModule($wpdb,new SettingsRepository());
		add_filter('authenticate',static fn($user)=>$user,50);
		$html=$this->captureHtml(fn()=>$module->renderSettings());
		$this->assertStringContainsString('reviewed passkey adapter',$html);
		$this->assertStringNotContainsString('user_pass',$html);
		wp_set_current_user(0);
		$this->assertSame('',$this->captureHtml(fn()=>$module->renderSettings()));
		$this->assertNotSame(array(),$module->requirements());
	}

	public function testCoreBootstrapRegistersAdministrationAndTranslations(): void {
		global $wpdb,$wp_filter;
		$before=array_keys($wp_filter['plugins_loaded']->callbacks[10] ?? []);
		require dirname(__DIR__,2).'/sw-securelogin.php';
		$added=array_diff(array_keys($wp_filter['plugins_loaded']->callbacks[10]),$before);
		$this->assertCount(1,$added);
		$wp_filter['plugins_loaded']->callbacks[10][array_values($added)[0]]['function']();
		$plugin=new Plugin();$plugin->register();$plugin->loadTranslations();
		$this->assertNotFalse(has_action('admin_menu'));
		$this->assertNotFalse(has_action('admin_init'));
		(new SW\Securelogin\Admin\SettingsPage())->register();
		$this->assertArrayHasKey('toplevel_page_swsl-overview', $GLOBALS['_registered_pages'] ?? []);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testAnonymousBindingIsHostOnlyAndUninstallRetainsConfiguration(): void {
		unset($_COOKIE[BrowserBinding::COOKIE]);
		$binding=(new BrowserBinding())->establish();
		$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$binding);
		wp_schedule_event(time()+100,'hourly','swsl_cleanup');
		$settings=get_option('swsl_settings');
		define('WP_UNINSTALL_PLUGIN','sw-securelogin/sw-securelogin.php');
		require dirname(__DIR__,2).'/uninstall.php';
		$this->assertFalse(wp_next_scheduled('swsl_cleanup'));
		$this->assertSame($settings,get_option('swsl_settings'));
	}
}
