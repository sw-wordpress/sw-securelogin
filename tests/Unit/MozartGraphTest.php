<?php
/** Build metadata resolution without Docker, network or dependency code loading. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Build\MozartGraph;

require_once dirname(__DIR__, 2) . '/bin/mozart-graph.php';

final class MozartGraphTest extends TestCase {
	public function testVirtualDependenciesIncludeEveryInstalledProviderInDependencyOrder(): void {
		$graph = (new MozartGraph())->resolve([
			['name' => 'test/consumer', 'require' => ['psr/client-implementation' => '^1', 'php' => '^8.2', 'ext-curl' => '*']],
			['name' => 'test/provider-a', 'provide' => ['psr/client-implementation' => '1.0']],
			['name' => 'test/provider-b', 'provide' => ['psr/client-implementation' => '*']],
			['name' => 'test/otherwise-unused'],
		]);
		$this->assertSame(['test/provider-a', 'test/provider-b', 'test/consumer', 'test/otherwise-unused'], array_keys($graph));
		$this->assertSame(['test/provider-a', 'test/provider-b'], $graph['test/consumer']);
	}

	public function testInstalledConcretePackageTakesPrecedenceOverVirtualProviders(): void {
		$graph = (new MozartGraph())->resolve([
			['name' => 'test/consumer', 'require' => ['test/library' => '^1']],
			['name' => 'test/library'],
			['name' => 'test/other', 'provide' => ['test/library' => '*']],
		]);
		$this->assertSame(['test/library'], $graph['test/consumer']);
		$this->assertArrayHasKey('test/other', $graph);
	}

	public function testReplacedPackagesResolveToTheInstalledReplacement(): void {
		$graph = (new MozartGraph())->resolve([
			['name' => 'test/consumer', 'require' => ['test/old' => '^1']],
			['name' => 'test/replacement', 'replace' => ['test/old' => 'self.version']],
		]);
		$this->assertSame(['test/replacement'], $graph['test/consumer']);
	}

	public function testMissingRequirementFailsInsteadOfSilentlyDroppingAPackage(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unresolved installed requirement: test/missing');
		(new MozartGraph())->resolve([['name' => 'test/consumer', 'require' => ['test/missing' => '*']]]);
	}

	public function testCyclesFailBeforeRecursiveMozartOperations(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Cyclic installed requirements');
		(new MozartGraph())->resolve([
			['name' => 'test/a', 'require' => ['test/b' => '*']],
			['name' => 'test/b', 'require' => ['test/a' => '*']],
		]);
	}

	public function testMalformedAndDuplicateNamesFail(): void {
		foreach ([[], [['name' => '../escape']], [['name' => 'test/../../escape']], [['name' => 1]], [['name' => 'test/a'], ['name' => 'test/a']]] as $records) {
			if ([] === $records) {
				$this->assertSame([], (new MozartGraph())->resolve($records));
				continue;
			}
			try {
				(new MozartGraph())->resolve($records);
			} catch (RuntimeException $expected) {
				$this->assertSame('Invalid or duplicate installed package name.', $expected->getMessage());
				continue;
			}
			$this->fail('Malformed package name accepted.');
		}
	}

	public function testMalformedRequirementShapesFail(): void {
		foreach (['require', 'provide', 'replace'] as $field) {
			foreach (['bad', ['test/b' => null], ['test/b']] as $value) {
				try {
					(new MozartGraph())->resolve([['name' => 'test/a', $field => $value]]);
				} catch (RuntimeException $expected) {
					$this->assertStringContainsString('Malformed installed package', $expected->getMessage());
					continue;
				}
				$this->fail('Malformed requirements accepted.');
			}
		}
	}

	public function testDiamondDependenciesAreDeterministicAndNotDuplicated(): void {
		$graph = (new MozartGraph())->resolve([
			['name' => 'test/root', 'require' => ['test/left' => '*', 'test/right' => '*']],
			['name' => 'test/left', 'require' => ['test/shared' => '*']],
			['name' => 'test/right', 'require' => ['test/shared' => '*']],
			['name' => 'test/shared'],
		]);
		$this->assertSame(['test/shared', 'test/left', 'test/right', 'test/root'], array_keys($graph));
	}
}
