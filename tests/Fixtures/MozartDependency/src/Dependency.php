<?php
/** Test-only dependency for namespace isolation. */
declare(strict_types=1);

namespace MozartFixture;

final class Dependency {
	public static function value(): string {
		return 'isolated';
	}
}
