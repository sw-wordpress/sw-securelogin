<?php
/** Isolated unit-test bootstrap; no WordPress installation is modified. */
declare(strict_types=1);

define( 'ABSPATH', '/tmp/swsl-unit/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';
