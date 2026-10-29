<?php
/** Module lifecycle regression tests. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SW\Securelogin\Core\ModuleInterface;
use SW\Securelogin\Core\ModuleRegistry;

final class ModuleRegistryTest extends TestCase {
	public function testDisabledAndUnknownModulesDoNotRegister(): void {
		$module = $this->createMock( ModuleInterface::class );
		$module->method( 'id' )->willReturn( 'passkeys' );
		$module->expects( $this->never() )->method( 'register' );
		$registry = new ModuleRegistry();
		$registry->add( $module );
		$registry->registerEnabled( array( 'unknown' ) );
	}

	public function testUnavailableModuleDoesNotRegister(): void {
		$module = $this->createMock( ModuleInterface::class );
		$module->method( 'id' )->willReturn( 'passkeys' );
		$module->method( 'isAvailable' )->willReturn( false );
		$module->expects( $this->never() )->method( 'register' );
		$registry = new ModuleRegistry();
		$registry->add( $module );
		$registry->registerEnabled( array( 'passkeys' ) );
	}

	public function testMissingDependencyDoesNotRegister(): void {
		$module = $this->createMock( ModuleInterface::class );
		$module->method( 'id' )->willReturn( 'passkeys' );
		$module->method( 'isAvailable' )->willReturn( true );
		$module->method( 'requirements' )->willReturn( array( 'Missing library.' ) );
		$module->expects( $this->never() )->method( 'register' );
		$registry = new ModuleRegistry();
		$registry->add( $module );
		$registry->registerEnabled( array( 'passkeys' ) );
	}

	public function testEnabledModuleRegistersOnce(): void {
		$module = $this->createMock( ModuleInterface::class );
		$module->method( 'id' )->willReturn( 'passkeys' );
		$module->method( 'isAvailable' )->willReturn( true );
		$module->method( 'requirements' )->willReturn( array() );
		$module->expects( $this->once() )->method( 'register' );
		$registry = new ModuleRegistry();
		$registry->add( $module );
		$registry->registerEnabled( array( 'passkeys' ) );
		$registry->registerEnabled( array( 'passkeys' ) );
	}

	public function testDuplicateIdentifierIsRejected(): void {
		$module = $this->createMock( ModuleInterface::class );
		$module->method( 'id' )->willReturn( 'passkeys' );
		$registry = new ModuleRegistry();
		$registry->add( $module );
		$this->expectException( InvalidArgumentException::class );
		$registry->add( $module );
	}

	public function testInvalidIdentifierIsRejected(): void {
		$module = $this->createMock( ModuleInterface::class );
		$module->method( 'id' )->willReturn( '../invalid' );
		$this->expectException( InvalidArgumentException::class );
		( new ModuleRegistry() )->add( $module );
	}
}
