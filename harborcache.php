<?php
/**
 * Plugin Name: HarborCache – Object Cache with Redis
 * Description: Fast Redis object caching for WordPress with clear insights, analytics and safe cache controls.
 * Version: 1.8.0
 * Text Domain: harborcache-object-cache-with-redis
 * Domain Path: /languages
 * Network: true
 * Requires PHP: 7.4
 * Requires at least: 6.6
 * Author: Shahid Irfan
 * Author URI: https://profiles.wordpress.org/shahidirfan100/
 * Primary Branch: main
 * License: GPL-3.0-only
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package HarborCache/Core
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/includes/class-conflict.php';

// A second object cache manager cannot safely share the legacy engine constants or the drop-in.
if ( \HarborCache\Core\Conflict::is_foreign( defined( 'WP_REDIS_FILE' ) ? WP_REDIS_FILE : null, defined( 'WP_REDIS_PLUGIN_PATH' ) ? WP_REDIS_PLUGIN_PATH : null, __FILE__, __DIR__ ) ) {
	\HarborCache\Core\Conflict::notice();
	return;
}
define( 'HARBORCACHE_FILE', __FILE__ );
if ( ! defined( 'HARBORCACHE_PLUGIN_PATH' ) ) {
    define( 'HARBORCACHE_PLUGIN_PATH', untrailingslashit( plugin_dir_path( HARBORCACHE_FILE ) ) );
}
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-config.php';
try { \HarborCache\Config::apply(); \HarborCache\Config::load(); }
catch ( \Throwable $harborcache_error ) { error_log( 'HarborCache configuration is invalid. Review HARBORCACHE_CONFIG and wp-content/harborcache-config.php.' ); return; } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
define( 'HARBORCACHE_BASENAME', plugin_basename( HARBORCACHE_FILE ) );
define( 'HARBORCACHE_PLUGIN_DIR', plugin_dir_url( HARBORCACHE_FILE ) );

define( 'HARBORCACHE_VERSION', '1.8.0' );

require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-autoloader.php';

$harborcache_autoloader = new HarborCache\Core\Autoloader();
$harborcache_autoloader->register();
$harborcache_autoloader->add_namespace( 'HarborCache\Core', HARBORCACHE_PLUGIN_PATH . '/includes' );

// Load the administration classes on first reference. This keeps anonymous
// front-end requests light while preserving compatibility with scripts and
// integrations that call Console/Status outside an admin request.
spl_autoload_register( static function ( $class ) {
    $map = array(
        'HarborCache\\Console'          => '/includes/class-console.php',
        'HarborCache\\Status'           => '/includes/class-status.php',
        'HarborCache\\Self_Test'        => '/includes/class-self-test.php',
        'HarborCache\\Connection_Check' => '/includes/class-connection-check.php',
    );
    if ( isset( $map[ $class ] ) ) {
        require_once HARBORCACHE_PLUGIN_PATH . $map[ $class ];
    }
} );

// Metrics are the only management feature needed on anonymous front-end
// requests. Administration, WP-CLI, cron and logged-in admin-bar requests
// additionally load the console and the integration layer.
require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-metrics.php';
HarborCache\Core\Metrics::init();

// Authenticated REST surface: load only when WordPress initializes REST.
add_action( 'rest_api_init', static function () {
    if ( \HarborCache\Core\Conflict::is_foreign( defined( 'WP_REDIS_FILE' ) ? WP_REDIS_FILE : null, defined( 'WP_REDIS_PLUGIN_PATH' ) ? WP_REDIS_PLUGIN_PATH : null, __FILE__, __DIR__ ) ) { return; }
    require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-rest.php';
    \HarborCache\Rest::register();
} );

$harborcache_needs_management = is_admin()
    || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
    || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
    || ( defined( 'WP_CLI' ) && WP_CLI )
    || 'cli' === PHP_SAPI;

// Logged-in front-end requests keep only metrics; the console loads for admin,
// AJAX, cron and CLI work instead of on every signed-in page view.

// Wait until every active plugin has loaded, so a late second manager still stands HarborCache down.
add_action( 'plugins_loaded', static function () use ( $harborcache_needs_management ) {
    if ( \HarborCache\Core\Conflict::is_foreign( defined( 'WP_REDIS_FILE' ) ? WP_REDIS_FILE : null, defined( 'WP_REDIS_PLUGIN_PATH' ) ? WP_REDIS_PLUGIN_PATH : null, __FILE__, __DIR__ ) ) {
        \HarborCache\Core\Conflict::notice();
        return;
    }
    if ( ! $harborcache_needs_management ) { return; }

    require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-console.php';
    \HarborCache\Console::boot();

    require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-plugin.php';
    HarborCache\Core\Plugin::instance();
}, 5 );

if ( ! function_exists( 'redis_object_cache' ) ) {
    /**
     * Returns the plugin instance.
     *
     * @return HarborCache\Core\Plugin
     */
    function redis_object_cache() {
        if ( ! class_exists( 'HarborCache\\Core\\Plugin' ) ) {
            require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-plugin.php';
        }
        return HarborCache\Core\Plugin::instance();
    }
}
