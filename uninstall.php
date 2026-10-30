<?php
/**
 * Data is retained on uninstall until an explicit deletion policy is implemented.
 *
 * @package SW\Securelogin
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove an orphaned recurring job while retaining all user data.
wp_clear_scheduled_hook( 'swsl_cleanup' );
