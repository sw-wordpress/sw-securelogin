<?php
/** Coverage must combine hits honestly and reject generated or foreign source paths. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CoverageMergeTest extends TestCase {
	private string $temporary;

	protected function setUp(): void {
		$this->temporary = sys_get_temp_dir() . '/swsl-coverage-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->temporary );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->temporary . '/*' ) as $file ) unlink( $file );
		rmdir( $this->temporary );
	}

	private function report( string $name, string $source, array $counts ): string {
		$xml = new DOMDocument();
		$coverage = $xml->appendChild( $xml->createElement( 'coverage' ) );
		$project = $coverage->appendChild( $xml->createElement( 'project' ) );
		$file = $project->appendChild( $xml->createElement( 'file' ) );
		$file->setAttribute( 'name', $source );
		foreach ( $counts as $number => $hits ) {
			$line = $file->appendChild( $xml->createElement( 'line' ) );
			$line->setAttribute( 'num', (string) $number );
			$line->setAttribute( 'type', 'stmt' );
			$line->setAttribute( 'count', (string) $hits );
		}
		$path = $this->temporary . '/' . $name . '.xml';
		$xml->save( $path );
		return $path;
	}

	private function merge( array $reports ): array {
		$process = proc_open( array_merge( array( PHP_BINARY, dirname( __DIR__ ) . '/coverage/merge.php', $this->temporary . '/merged.xml' ), $reports ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		return array( proc_close( $process ), $output, $error );
	}

	public function testMergeNormalizesPathsAndUsesMaximumOverlappingHits(): void {
		$source = dirname( __DIR__, 2 ) . '/src/Core/ModuleRegistry.php';
		$unit = $this->report( 'unit', $source, array( 20 => 2, 30 => 0 ) );
		$wordpress = $this->report( 'wordpress', 'src/Core/ModuleRegistry.php', array( 20 => 3, 30 => 1, 40 => 0 ) );
		$result = $this->merge( array( $unit, $wordpress ) );
		$this->assertSame( 0, $result[0], $result[2] );
		$xml = new DOMDocument(); $xml->load( $this->temporary . '/merged.xml' );
		$xpath = new DOMXPath( $xml );
		$this->assertSame( 'src/Core/ModuleRegistry.php', $xpath->evaluate( 'string(//file/@name)' ) );
		$this->assertSame( '3', $xpath->evaluate( 'string(//line[@num="20"]/@count)' ) );
		$this->assertSame( '1', $xpath->evaluate( 'string(//line[@num="30"]/@count)' ) );
		$this->assertSame( '3', $xpath->evaluate( 'string(//metrics/@statements)' ) );
		$this->assertSame( '2', $xpath->evaluate( 'string(//metrics/@coveredstatements)' ) );
	}

	public function testForeignSourcePathIsRejected(): void {
		$foreign = $this->temporary . '/foreign.php'; file_put_contents( $foreign, '<?php' );
		$result = $this->merge( array( $this->report( 'foreign', $foreign, array( 1 => 1 ) ) ) );
		$this->assertNotSame( 0, $result[0] );
		$this->assertStringContainsString( 'outside the source checkout', $result[2] );
	}

	public function testTestFilesAndZeroExecutedStatementsAreRejected(): void {
		$result = $this->merge( array( $this->report( 'test', __FILE__, array( 20 => 1 ) ) ) );
		$this->assertNotSame( 0, $result[0] );
		$this->assertStringContainsString( 'original production source', $result[2] );
		$result = $this->merge( array( $this->report( 'empty', 'src/Core/ModuleRegistry.php', array( 20 => 0 ) ) ) );
		$this->assertNotSame( 0, $result[0] );
		$this->assertStringContainsString( 'No executed production statement', $result[2] );
	}
}
