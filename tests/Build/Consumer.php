<?php
/** Test-only parent source: Mozart must rewrite its dependency reference. */
declare(strict_types=1);

namespace SW\Securelogin;

use MozartFixture\Dependency;

final class BuildConsumer {
	public function value(): string {
		return Dependency::value();
	}
}
