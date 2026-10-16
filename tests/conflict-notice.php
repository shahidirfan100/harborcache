<?php
/** Test the bootstrap notice when another Redis cache plugin loads first. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_REDIS_FILE', __DIR__ . '/other-cache.php' );

$GLOBALS['harborcache_test_hooks'] = array();
$GLOBALS['harborcache_test_screen'] = null;
$GLOBALS['harborcache_test_caps'] = array();

function add_action( $hook, $callback ) {
	$GLOBALS['harborcache_test_hooks'][ $hook ][] = $callback;
}

function get_current_screen() {
	return $GLOBALS['harborcache_test_screen'];
}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['harborcache_test_caps'][ $capability ] );
}

function esc_html__( $text, $domain ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

require dirname( __DIR__ ) . '/harborcache.php';

$callbacks = $GLOBALS['harborcache_test_hooks']['admin_notices'] ?? array();
if ( count( $callbacks ) !== 1 ) {
	throw new RuntimeException( 'The Redis cache conflict notice was not registered.' );
}

$render = static function ( $screen_id, array $capabilities ) use ( $callbacks ) {
	$GLOBALS['harborcache_test_screen'] = (object) array( 'id' => $screen_id );
	$GLOBALS['harborcache_test_caps']  = $capabilities;
	ob_start();
	call_user_func( $callbacks[0] );
	return ob_get_clean();
};

if ( '' !== $render( 'dashboard', array( 'activate_plugins' => true ) ) ) {
	throw new RuntimeException( 'The cache conflict notice appeared on the Dashboard.' );
}
if ( '' !== $render( 'plugins', array() ) ) {
	throw new RuntimeException( 'The cache conflict notice appeared without plugin-management capability.' );
}
if ( false === strpos( $render( 'plugins', array( 'activate_plugins' => true ) ), 'Deactivate the other Redis object cache plugin' ) ) {
	throw new RuntimeException( 'The cache conflict notice is missing from the Plugins screen.' );
}
if ( false === strpos( $render( 'plugins-network', array( 'manage_network_plugins' => true ) ), 'Deactivate the other Redis object cache plugin' ) ) {
	throw new RuntimeException( 'The cache conflict notice is missing from Network Plugins.' );
}

echo "PASS Redis cache conflict notice is restricted to plugin-management screens\n";
