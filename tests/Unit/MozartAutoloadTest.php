<?php
/** Exact review boundaries for eagerly loaded dependency code. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Build\MozartAutoload;

require_once dirname(__DIR__, 2) . '/bin/mozart-autoload.php';

final class MozartAutoloadTest extends TestCase {
	public function testObjectKeyOrderDoesNotChangeMetadataButListOrderDoes(): void {
		$installed = ['psr-4' => ['Fixture\\B\\' => 'b/', 'Fixture\\A\\' => 'a/'], 'exclude-from-classmap' => ['tests']];
		$manifest = ['exclude-from-classmap' => ['tests'], 'psr-4' => ['Fixture\\A\\' => 'a/', 'Fixture\\B\\' => 'b/']];
		$this->assertSame(['Fixture\\B\\', 'Fixture\\A\\'], (new MozartAutoload())->validate('test/package', $installed, $manifest));
		$this->expectException(RuntimeException::class);
		(new MozartAutoload())->validate('test/package', ['psr-4' => ['Fixture\\' => ['first', 'second']]], ['psr-4' => ['Fixture\\' => ['second', 'first']]]);
	}

	public function testMetadataComparisonDoesNotCoerceTypes(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Installed autoload metadata does not match');
		(new MozartAutoload())->validate('test/package', ['psr-4' => ['Fixture\\' => '0']], ['psr-4' => ['Fixture\\' => 0]]);
	}

	public function testReviewedFilesAndConditionalPhp83ClassmapAreAcceptedExactly(): void {
		$metadata = ['psr-4' => ['Symfony\\Polyfill\\Php83\\' => ''], 'files' => ['bootstrap.php'], 'classmap' => ['Resources/stubs']];
		$this->assertSame(['Symfony\\Polyfill\\Php83\\'], (new MozartAutoload())->validate('symfony/polyfill-php83', $metadata, $metadata));
		$metadata = ['files' => ['function.php']];
		$this->assertSame([], (new MozartAutoload())->validate('symfony/deprecation-contracts', $metadata, $metadata));
	}

	public function testUnknownOrChangedFilesAndClassmapsStillRequireReview(): void {
		foreach ([
			['test/package', ['files' => ['bootstrap.php']]],
			['symfony/polyfill-php83', ['files' => ['bootstrap.php', 'unreviewed.php']]],
			['symfony/polyfill-php83', ['classmap' => ['Resources']]],
			['test/package', ['classmap' => ['src']]],
			['test/package', ['unknown' => ['src']]],
		] as [$package, $metadata]) {
			try {
				(new MozartAutoload())->validate($package, $metadata, $metadata);
			} catch (RuntimeException $expected) {
				$this->assertStringContainsString('Review Mozart root rewriting', $expected->getMessage());
				continue;
			}
			$this->fail('An unreviewed autoload entry was accepted.');
		}
	}

	public function testEmptyOrMalformedNamespaceMapsFail(): void {
		foreach ([['psr-4' => ['' => 'src/']], ['psr-4' => 'src/'], ['psr-4' => ['src/']]] as $metadata) {
			try {
				(new MozartAutoload())->validate('test/package', $metadata, $metadata);
			} catch (RuntimeException $expected) {
				$this->addToAssertionCount(1);
				continue;
			}
			$this->fail('An unbounded or malformed namespace map was accepted.');
		}
	}
}
