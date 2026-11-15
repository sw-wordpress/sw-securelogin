<?php
/**
 * PHPStan-only constants defined at runtime by sw-securelogin.php.
 *
 * @package SW\Securelogin
 */

define( 'SWSL_MIN_PHP', '8.2' );

define( 'SWSL_VERSION', '0.3.0' );

// WordPress defines this from wp-config.php before the provider can acquire a lease.
define( 'DB_NAME', 'swsl_static_analysis' );
