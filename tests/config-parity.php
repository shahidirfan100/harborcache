<?php
/**
 * Connection-configuration checks: topology constants and socket/host switching.
 *
 * php tests/config-parity.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HARBORCACHE_PLUGIN_PATH', dirname( __DIR__ ) );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'DB_NAME', 'config-fixture' );
define( 'WP_REDIS_CLUSTER', array( 'tcp://10.0.0.1:6379' ) );
define( 'WP_REDIS_PASSWORD', 'wp-config-secret' );

require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-config.php';

$failures = 0;
function hc_config_gate( $name, $pass, $detail = '' ) {
	global $failures;
	if ( ! $pass ) { ++$failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

$loaded = HarborCache\Config::load();
hc_config_gate( 'WP_REDIS_CLUSTER is read into the configuration', array( 'tcp://10.0.0.1:6379' ) === $loaded['cluster'], implode( ',', $loaded['cluster'] ) );
hc_config_gate( 'WP_REDIS_PASSWORD stays available without being copied to a file', 'wp-config-secret' === $loaded['password'] );

$unix = HarborCache\Config::defaults();
$unix['host']   = '/run/redis/redis.sock';
$unix['scheme'] = 'unix';
$unix['path']   = '/run/redis/redis.sock';
$unix = HarborCache\Config::validate( $unix );
$tcp  = HarborCache\Config::validate( HarborCache\Config::apply_host( $unix, '127.0.0.1' ) );
hc_config_gate( 'A typed TCP host replaces a saved Unix socket', 'tcp' === $tcp['scheme'] && '' === $tcp['path'] && '127.0.0.1' === $tcp['host'], $tcp['scheme'] . '/' . $tcp['path'] );
hc_config_gate( 'A typed Unix socket still switches to unix', 'unix' === HarborCache\Config::validate( HarborCache\Config::apply_host( HarborCache\Config::defaults(), '/run/redis/redis.sock' ) )['scheme'] );

$tls = HarborCache\Config::defaults();
$tls['host']   = 'redis.example.com';
$tls['scheme'] = 'tls';
$tls = HarborCache\Config::validate( $tls );
hc_config_gate( 'A plain host does not downgrade an existing TLS connection', 'tls' === HarborCache\Config::apply_host( $tls, 'redis2.example.com' )['scheme'] );

$loop = HarborCache\Config::defaults();
hc_config_gate( 'A loopback endpoint needs no trust warning', array() === HarborCache\Config::endpoint_warnings( $loop ) );
$remote = HarborCache\Config::defaults();
$remote['host'] = 'redis.example.com';
hc_config_gate( 'A remote endpoint reports missing TLS and password', array( 'tls', 'password' ) === HarborCache\Config::endpoint_warnings( $remote ) );
$remote['scheme']   = 'tls';
$remote['password'] = 'secret';
hc_config_gate( 'A remote TLS endpoint with a password is trusted', array() === HarborCache\Config::endpoint_warnings( $remote ) );
$socket = HarborCache\Config::defaults();
$socket['scheme'] = 'unix';
hc_config_gate( 'A Unix socket needs no trust warning', array() === HarborCache\Config::endpoint_warnings( $socket ) );

echo 'RESULT ' . ( $failures ? 'FAILED' : 'PASSED' ) . ' with ' . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
