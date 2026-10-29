<?php
/** Build-only Mozart entry point, executed inside the pinned container. */
declare(strict_types=1);

use CoenJacobs\Mozart\Commands\Compose;
use CoenJacobs\Mozart\Replace\Namespace\NamespaceReplacer;

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require '/mozart/vendor/autoload.php';

$swsl_project = '/project';
$swsl_config = json_decode( file_get_contents( $swsl_project . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
$swsl_installed = json_decode( file_get_contents( $swsl_project . '/vendor/composer/installed.json' ), true, 512, JSON_THROW_ON_ERROR );
if ( ! empty( $swsl_config['extra']['mozart']['packages'] ) || ! empty( $swsl_config['extra']['mozart']['excluded_packages'] ) ) {
	throw new RuntimeException( 'Selective scoping requires a review of root reference rewriting.' );
}
$swsl_namespaces = array();
$swsl_notices = array();
foreach ( $swsl_installed['packages'] as $swsl_package ) {
	foreach ( glob( $swsl_project . '/vendor/' . $swsl_package['name'] . '/{LICENSE*,LICENCE*,COPYING*,NOTICE*}', GLOB_BRACE ) as $swsl_notice ) {
		if ( is_file( $swsl_notice ) ) {
			$swsl_notices[ str_replace( '/', '-', $swsl_package['name'] ) . '/' . basename( $swsl_notice ) ] = file_get_contents( $swsl_notice );
		}
	}
	foreach ( $swsl_package['autoload'] ?? array() as $swsl_type => $swsl_entries ) {
		// New autoload forms need an explicit review of root reference rewriting.
		if ( ! in_array( $swsl_type, array( 'psr-4', 'psr-0', 'exclude-from-classmap' ), true ) ) {
			throw new RuntimeException( 'Review Mozart root rewriting for ' . $swsl_package['name'] . ': ' . $swsl_type );
		}
		if ( 'exclude-from-classmap' !== $swsl_type ) {
			foreach ( array_keys( $swsl_entries ) as $swsl_namespace ) {
				if ( '' === $swsl_namespace ) {
					throw new RuntimeException( 'A dependency has an unbounded autoload namespace.' );
				}
				$swsl_namespaces[ $swsl_namespace ] = true;
			}
		}
	}
}

( new Compose( $swsl_project ) )->execute();
foreach ( $swsl_notices as $swsl_relative => $swsl_contents ) {
	$swsl_target = $swsl_project . '/vendor-prefixed/licenses/' . $swsl_relative;
	if ( ! is_dir( dirname( $swsl_target ) ) && ! mkdir( dirname( $swsl_target ), 0755, true ) ) {
		throw new RuntimeException( 'Unable to retain dependency license notices.' );
	}
	if ( false === $swsl_contents || false === file_put_contents( $swsl_target, $swsl_contents ) ) {
		throw new RuntimeException( 'Unable to retain dependency license notices.' );
	}
}

// Mozart's parent pass handles dependency-to-dependency references. Apply its
// AST rewriter to our source imports too; keep all source checkout files intact.
$swsl_rewriter = new NamespaceReplacer( $swsl_config['extra']['mozart']['dep_namespace'] );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $swsl_project . '/src', FilesystemIterator::SKIP_DOTS ) ) as $swsl_file ) {
	if ( ! $swsl_file->isFile() || 'php' !== $swsl_file->getExtension() ) {
		continue;
	}
	$swsl_contents = file_get_contents( $swsl_file->getPathname() );
	foreach ( array_keys( $swsl_namespaces ) as $swsl_namespace ) {
		$swsl_rewriter->setSearchNamespace( rtrim( $swsl_namespace, '\\' ) );
		$swsl_contents = $swsl_rewriter->replace( $swsl_contents );
	}
	if ( false === file_put_contents( $swsl_file->getPathname(), $swsl_contents ) ) {
		throw new RuntimeException( 'Unable to rewrite plugin dependency references.' );
	}
}
