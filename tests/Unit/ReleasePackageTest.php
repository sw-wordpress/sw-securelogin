<?php
/** Verify deterministic packaging and rejection of accidental distribution data. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleasePackageTest extends TestCase {
	private string $directory;
	private string $stage;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/swsl-package-' . bin2hex( random_bytes( 8 ) );
		$this->stage = $this->directory . '/stage';
		mkdir( $this->stage, 0700, true );
		foreach ( array( 'sw-securelogin.php', 'uninstall.php', 'readme.txt', 'README.md', 'LICENSE', 'composer.json', 'composer.lock', 'vendor-prefixed/autoload.php', 'vendor-prefixed/licenses/lbuchs-webauthn/LICENSE', 'languages/sw-securelogin-de_DE.mo', 'languages/sw-securelogin-en_US.mo', 'docs/PRIVACY.md', 'docs/OPERATIONS.md', 'docs/RELEASE.md' ) as $file ) {
			if ( ! is_dir( dirname( $this->stage . '/' . $file ) ) ) mkdir( dirname( $this->stage . '/' . $file ), 0700, true );
			file_put_contents( $this->stage . '/' . $file, 'fixture' );
		}
		copy( dirname( __DIR__, 2 ) . '/sw-securelogin.php', $this->stage . '/sw-securelogin.php' );
		copy( dirname( __DIR__, 2 ) . '/readme.txt', $this->stage . '/readme.txt' );
	}

	protected function tearDown(): void {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) rmdir( $file->getPathname() );
			else unlink( $file->getPathname() );
		}
		rmdir( $this->directory );
	}

	private function package( string $output ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/bin/package-release.php' ) . ' ' . escapeshellarg( $this->stage ) . ' ' . escapeshellarg( $output ) . ' 2>&1';
		exec( $command, $lines, $status );
		return array( $status, implode( "\n", $lines ) );
	}

	public function testArchiveIsDeterministicAcrossFileTimesAndContainsOnlyPluginFolder(): void {
		$first = $this->directory . '/first.zip';
		$second = $this->directory . '/second.zip';
		$this->assertSame( 0, $this->package( $first )[0] );
		touch( $this->stage . '/README.md', time() - 123456 );
		chmod( $this->stage . '/README.md', 0755 );
		$this->assertSame( 0, $this->package( $second )[0] );
		$this->assertSame( hash_file( 'sha256', $first ), hash_file( 'sha256', $second ) );
		$zip = new ZipArchive();
		$zip->open( $first );
		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$this->assertStringStartsWith( 'sw-securelogin/', $zip->getNameIndex( $index ) );
		}
		$this->assertNotFalse( $zip->locateName( 'sw-securelogin/vendor-prefixed/autoload.php' ) );
		$zip->close();
	}

	public function testDevelopmentFilesSymlinksMissingFilesAndVersionMismatchAreRejected(): void {
		$output = $this->directory . '/invalid.zip';
		foreach ( array( '.env', 'tests/secret.php', 'vendor/autoload.php', 'assets/private.key' ) as $file ) {
			$path = $this->stage . '/' . $file;
			if ( ! is_dir( dirname( $path ) ) ) mkdir( dirname( $path ), 0700, true );
			file_put_contents( $path, 'must not ship' );
			$this->assertNotSame( 0, $this->package( $output )[0], $file );
			$this->assertFileDoesNotExist( $output );
			unlink( $path );
		}
		symlink( $this->stage . '/README.md', $this->stage . '/assets/link' );
		$this->assertNotSame( 0, $this->package( $output )[0] );
		unlink( $this->stage . '/assets/link' );
		file_put_contents( $this->stage . '/readme.txt', "Stable tag: 9.9.9\n" );
		$this->assertNotSame( 0, $this->package( $output )[0] );
		unlink( $this->stage . '/uninstall.php' );
		$this->assertNotSame( 0, $this->package( $output )[0] );
	}

	public function testExistingOutputIsNeverOverwritten(): void {
		$output = $this->directory . '/existing.zip';
		file_put_contents( $output, 'keep' );
		$this->assertNotSame( 0, $this->package( $output )[0] );
		$this->assertSame( 'keep', file_get_contents( $output ) );
	}
}
