<?php
/** Simulate a different plugin loading its own incompatible dependency. */
declare(strict_types=1);

namespace MozartFixture;

final class Dependency {
	public static function value(): string {
		return 'other-plugin';
	}
}
