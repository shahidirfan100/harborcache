<?php
/**
 * Management capability parity between the menu and the dashboard gate.
 *
 * php tests/capability.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HARBORCACHE_PLUGIN_PATH', dirname( __DIR__ ) );
define( 'HARBORCACHE_FILE', dirname( __DIR__ ) . '/harborcache.php' );
define( 'HARBORCACHE_BASENAME', 'harborcache/harborcache.php' );
define( 'HARBORCACHE_VERSION', 'test' );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'DB_NAME', 'capability-fixture' );
define( 'WP_REDIS_MANAGER_CAPABILITY', 'manage_shop' );

if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ) { return $text; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $text ) { return $text; } }
if ( ! function_exists( 'is_multisite' ) ) { function is_multisite() { return false; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $name, $value ) { return $value; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $capability ) { return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-console.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-plugin.php';

$failures = 0;
function hc_capability_gate( $name, $pass, $detail = '' ) {
	global $failures;
	if ( ! $pass ) { ++$failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

$console = HarborCache\Console::capability();
$menu    = HarborCache\Core\Plugin::instance()->manage_redis_capability();
hc_capability_gate( 'The dashboard gate honors WP_REDIS_MANAGER_CAPABILITY', 'manage_shop' === $console, $console );
hc_capability_gate( 'The menu and the dashboard gate agree', $console === $menu, $menu );
hc_capability_gate( 'A single-site installation can manage every scope', true === HarborCache\Console::can_manage_network() );

echo 'RESULT ' . ( $failures ? 'FAILED' : 'PASSED' ) . ' with ' . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
