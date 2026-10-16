<?php
/**
 * Plugin Name: HarborCache – Object Cache with Redis
 * Description: Fast Redis object caching for WordPress with clear insights, analytics and safe cache controls.
 * Version: 1.2.1
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
// A second object cache manager cannot safely share the legacy engine constants.
if ( ( defined( 'WP_REDIS_FILE' ) && WP_REDIS_FILE !== __FILE__ ) || ( defined( 'WP_REDIS_PLUGIN_PATH' ) && realpath( WP_REDIS_PLUGIN_PATH ) !== realpath( __DIR__ ) ) ) {
 add_action( 'admin_notices', static function () { echo '<div class="notice notice-error"><p>' . esc_html__( 'Deactivate the other Redis object cache plugin before activating HarborCache.', 'harborcache-object-cache-with-redis' ) . '</p></div>'; } );
 return;
}
require_once __DIR__ . '/includes/ch-config.php';
try { \HarborCache\Config::apply(); \HarborCache\Config::load(); }
catch ( \Throwable $harborcache_error ) { error_log( 'HarborCache configuration is invalid. Review HARBORCACHE_CONFIG and wp-content/harborcache-config.php.' ); return; } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
require_once __DIR__ . '/includes/class-console.php';
\HarborCache\Console::boot();

define( 'WP_REDIS_FILE', __FILE__ );
define( 'WP_REDIS_BASENAME', plugin_basename( WP_REDIS_FILE ) );
define( 'WP_REDIS_PLUGIN_DIR', plugin_dir_url( WP_REDIS_FILE ) );

if ( ! defined( 'WP_REDIS_PLUGIN_PATH' ) ) {
    define( 'WP_REDIS_PLUGIN_PATH', __DIR__ );
}

$harborcache_meta = get_file_data( WP_REDIS_FILE, [ 'Version' => 'Version' ] );

define( 'WP_REDIS_VERSION', $harborcache_meta['Version'] );

require_once WP_REDIS_PLUGIN_PATH . '/includes/class-autoloader.php';

$harborcache_autoloader = new HarborCache\Core\Autoloader();
$harborcache_autoloader->register();
$harborcache_autoloader->add_namespace( 'HarborCache\Core', WP_REDIS_PLUGIN_PATH . '/includes' );

HarborCache\Core\Plugin::instance();

if ( ! function_exists( 'redis_object_cache' ) ) {
    /**
     * Returns the plugin instance.
     *
     * @return HarborCache\Core\Plugin
     */
    function redis_object_cache() {
        return HarborCache\Core\Plugin::instance();
    }
}
