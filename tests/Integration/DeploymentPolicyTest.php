<?php
/** Deployment boundaries must never silently widen passkey trust. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Modules\Passkeys\RelyingParty;

final class DeploymentPolicyTest extends TestCase {
	public function testOriginPinRejectsDomainAndPortChangesAndIgnoresProxyHeaders(): void {
		$site = get_option('siteurl'); $pin = get_option('swsl_passkeys_rp'); $server = $_SERVER;
		$_SERVER['HTTPS']='on';
		try {
			update_option('siteurl', 'https://admin.example.test/wordpress');
			update_option('swsl_passkeys_rp', array('origin'=>'https://admin.example.test','id'=>'admin.example.test'));
			$_SERVER['HTTP_HOST']='attacker.test';
			$_SERVER['HTTP_X_FORWARDED_HOST']='attacker.test';
			$_SERVER['HTTP_X_FORWARDED_PROTO']='http';
			$this->assertSame('https://admin.example.test', RelyingParty::configured()->origin);
			foreach (array('https://new.example.test/wordpress','https://admin.example.test:8443/wordpress','http://admin.example.test/wordpress') as $changed) {
				update_option('siteurl', $changed);
				try { RelyingParty::configured(); $this->fail('Changed origin must fail closed.'); }
				catch (RuntimeException) { $this->assertTrue(true); }
				$this->assertSame(array('origin'=>'https://admin.example.test','id'=>'admin.example.test'),get_option('swsl_passkeys_rp'));
			}
		} finally { update_option('siteurl',$site); if (false === $pin) delete_option('swsl_passkeys_rp'); else update_option('swsl_passkeys_rp',$pin); $_SERVER=$server; }
	}

}
