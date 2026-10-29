<?php
/** Access-control regression test. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Admin\SettingsPage;

// This isolated test suite deliberately has no WordPress session.
function current_user_can( string $capability ): bool {
	return false;
}

final class SettingsPageTest extends TestCase {
	public function testUnauthorizedCallbackProducesNoOutput(): void {
		$this->expectOutputString( '' );
		( new SettingsPage() )->render();
	}
}
