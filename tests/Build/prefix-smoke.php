<?php
/** Verify real generated dependency code and rewritten parent references. */
declare(strict_types=1);

require __DIR__ . '/bootstrap-smoke.php';
if ( class_exists( MozartFixture\Dependency::class ) ) {
	throw new RuntimeException( 'An unprefixed dependency leaked from the build.' );
}
require __DIR__ . '/conflicting-dependency.php';
if ( 'other-plugin' !== MozartFixture\Dependency::value() ) {
	throw new RuntimeException( 'The conflicting fixture did not load.' );
}
if ( 'isolated' !== SW\Securelogin\Dependencies\MozartFixture\Dependency::value() ) {
	throw new RuntimeException( 'The dependency was not prefixed correctly.' );
}
if ( 'isolated' !== ( new SW\Securelogin\BuildConsumer() )->value() ) {
	throw new RuntimeException( 'Parent dependency references were not rewritten.' );
}
echo "Namespace isolation and conflicting dependency smoke test passed.\n";
