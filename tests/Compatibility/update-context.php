<?php
/** Verify immutable REST/CLI constants in independent processes, only in the disposable site. */
declare(strict_types=1);
$path = getenv('SWSL_TEST_WORDPRESS');
if (!$path || !is_file($path . '/wp-load.php')) throw new RuntimeException('Select the disposable site.');
require $path . '/wp-load.php';
if (DB_NAME !== 'swsl_m0_test' || $GLOBALS['wpdb']->prefix !== 'swsltest_') throw new RuntimeException('Unsafe test database.');
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
set_current_screen('plugins');
wp_set_current_user(get_user_by('login', 'swsl-test-admin')->ID);
$mode = $argv[1] ?? '';
if (!in_array($mode, ['WP_CLI', 'REST_REQUEST'], true)) throw new RuntimeException('Unknown context.');
define($mode, true);
add_filter('pre_http_request', static function () { throw new RuntimeException('Forbidden HTTP attempt.'); });
if (SW\Securelogin\Update\Service::interactive()) throw new RuntimeException('Context should be blocked.');
$result = SW\Securelogin\Update\Service::check(true);
if (!is_wp_error($result) || $result->get_error_code() !== 'updates_forbidden') throw new RuntimeException('Check not blocked.');
$_REQUEST = ['action' => 'upgrade-plugin', '_wpnonce' => wp_create_nonce('upgrade-plugin_sw-securelogin/sw-securelogin.php')];
$download = SW\Securelogin\Update\Provider::download_guard(false, 'https://github.com/sw-wordpress/sw-securelogin/releases/download/1.0.0/a.zip', new stdClass(), ['plugin' => 'sw-securelogin/sw-securelogin.php']);
if (!is_wp_error($download) || $download->get_error_code() !== 'manual_update_required') throw new RuntimeException('Installation not blocked.');
echo "PASS: $mode check/download blocked without HTTP.\n";
