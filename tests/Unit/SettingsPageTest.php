<?php
/** Access-control regression test. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Admin\SettingsPage;

// This isolated test suite deliberately has no WordPress session.
// Signature matches core so static analysis resolves production calls correctly.
function current_user_can( string $capability, mixed ...$args ): bool {
	return false;
}

final class SettingsPageTest extends TestCase {
	public function testUnauthorizedCallbackProducesNoOutput(): void {
		$this->expectOutputString( '' );
		( new SettingsPage() )->render();
	}
}
