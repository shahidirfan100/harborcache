<?php
/**
 * Status model and namespace-conflict checks. Runs without Redis.
 *
 * php tests/status-matrix.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HARBORCACHE_PLUGIN_PATH', dirname( __DIR__ ) );
define( 'HARBORCACHE_VERSION', 'test' );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'DB_NAME', 'status-fixture' );
define( 'AUTH_KEY', 'disposable-status-key' );
define( 'HARBORCACHE_CONFIG', array( 'connector' => 'hc_status_connector', 'analytics' => array( 'enabled' => false ), 'failure_mode' => 'silent' ) );
$GLOBALS['table_prefix'] = 'status_';

if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ) { return $text; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $text ) { return $text; } }
if ( ! function_exists( 'is_multisite' ) ) { function is_multisite() { return false; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'do_action' ) ) { function do_action() {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $name, $value ) { return $value; } }

require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-config.php';
\HarborCache\Config::apply();
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-namespace.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/object-cache.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-advanced-cache.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-status.php';

$failures = 0;
function hc_status_gate( $name, $pass, $detail = '' ) {
	global $failures;
	if ( ! $pass ) { ++$failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

class HC_Status_Driver {
	public $values = array();
	public $hashes = array();
	public $sets = array();
	public $last_error = '';
	public $commands = 0;
	public function ping() { return 'PONG'; }
	public function getConnection() { return null; }
	public function info( ...$args ) { return array( 'redis_version' => '7.0.0' ); }
	public function getLastError() { return $this->last_error; }
	public function isConnected() { return true; }
	public function clearLastError() { $this->last_error = ''; }
	public function rawCommand( ...$args ) {
		++$this->commands;
		$command = strtoupper( array_shift( $args ) );
		$key     = $args[0] ?? '';
		if ( 'PING' === $command ) { return 'PONG'; }
		if ( 'GET' === $command ) { return $this->values[ $key ] ?? null; }
		if ( 'SET' === $command ) {
			if ( in_array( 'NX', $args, true ) && isset( $this->values[ $key ] ) ) { return null; }
			$this->values[ $key ] = $args[1];
			return true;
		}
		if ( 'SETEX' === $command ) { $this->values[ $key ] = $args[2]; return true; }
		if ( 'HGETALL' === $command ) { return $this->hashes[ $key ] ?? array(); }
		if ( 'HGET' === $command ) { return $this->hashes[ $key ][ $args[1] ?? '' ] ?? null; }
		if ( 'HSET' === $command ) { $this->hashes[ $key ][ $args[1] ] = $args[2]; return 1; }
		if ( 'SMEMBERS' === $command ) { return array_keys( $this->sets[ $key ] ?? array() ); }
		if ( 'SADD' === $command ) { $this->sets[ $key ][ $args[1] ] = true; return 1; }
		if ( 'EXPIRE' === $command ) { return 1; }
		if ( 'EVAL' === $command ) {
			$script = $args[0];
			$num    = (int) $args[1];
			$keys   = array_slice( $args, 2, $num );
			$argv   = array_slice( $args, 2 + $num );
			if ( false !== strpos( $script, 'SADD' ) ) { $this->sets[ $keys[0] ][ $argv[0] ] = true; return 1; }
			if ( false !== strpos( $script, 'HSET' ) ) {
				if ( ! isset( $this->hashes[ $keys[0] ][ $argv[0] ] ) ) { $this->hashes[ $keys[0] ][ $argv[0] ] = $argv[1]; }
				return $this->hashes[ $keys[0] ][ $argv[0] ];
			}
			return 1;
		}
		throw new RuntimeException( 'Unexpected fixture command: ' . $command );
	}
	public function set( ...$args ) { return $this->rawCommand( 'SET', ...$args ); }
	public function setex( ...$args ) { return $this->rawCommand( 'SETEX', ...$args ); }
	public function get( ...$args ) { return $this->rawCommand( 'GET', ...$args ); }
}

function hc_status_connector() {
	return $GLOBALS['hc_status_driver'];
}

$cases = array(
	'healthy'              => array( array( 'installed' => true, 'loaded' => true, 'dropin' => 'current', 'transport' => true, 'namespace' => 'owned', 'codec' => 'ok', 'ready' => true ), 'connected', '' ),
	'namespace conflict'   => array( array( 'installed' => true, 'loaded' => true, 'dropin' => 'current', 'transport' => true, 'namespace' => 'conflict', 'codec' => 'ok', 'ready' => false ), 'degraded', 'namespace-conflict' ),
	'redis unreachable'    => array( array( 'installed' => true, 'loaded' => true, 'dropin' => 'current', 'transport' => false, 'namespace' => 'unknown', 'codec' => 'ok', 'ready' => false ), 'unreachable', 'backend-unavailable' ),
	'codec failure'        => array( array( 'installed' => true, 'loaded' => true, 'dropin' => 'current', 'transport' => true, 'namespace' => 'owned', 'codec' => 'error', 'ready' => false ), 'degraded', 'codec-error' ),
	'drop-in outdated'     => array( array( 'installed' => true, 'loaded' => true, 'dropin' => 'outdated', 'transport' => true, 'namespace' => 'owned', 'codec' => 'ok', 'ready' => true ), 'degraded', 'drop-in-outdated' ),
	'not loaded'           => array( array( 'installed' => true, 'loaded' => false, 'dropin' => 'current', 'transport' => false, 'namespace' => 'unknown', 'codec' => 'unknown', 'ready' => false ), 'not-loaded', 'drop-in-not-loaded' ),
	'disabled'             => array( array( 'installed' => true, 'loaded' => true, 'dropin' => 'current', 'transport' => false, 'namespace' => 'unknown', 'codec' => 'unknown', 'ready' => false, 'disabled' => true ), 'disabled', 'disabled' ),
	'foreign drop-in'      => array( array( 'installed' => false, 'loaded' => false, 'dropin' => 'foreign', 'transport' => false, 'namespace' => 'unknown', 'codec' => 'unknown', 'ready' => false ), 'not-installed', 'foreign-dropin' ),
);

foreach ( $cases as $name => $case ) {
	list( $state, $reason ) = \HarborCache\Status::state_for( $case[0] );
	hc_status_gate( 'State: ' . $name, $state === $case[1] && $reason === $case[2], $state . '/' . $reason );
}

hc_status_gate( 'Connected label', 'Connected' === \HarborCache\Status::label( array( 'state' => 'connected' ) ) );
hc_status_gate( 'Conflict label is specific', 'Namespace conflict' === \HarborCache\Status::label( array( 'state' => 'degraded', 'reason' => 'namespace-conflict' ) ) );
hc_status_gate( 'Conflict explanation mentions Redis', false !== strpos( \HarborCache\Status::explain( array( 'state' => 'degraded', 'reason' => 'namespace-conflict' ) ), 'Redis answers' ) );
hc_status_gate( 'Disabled explanation names both constants', false !== strpos( \HarborCache\Status::explain( array( 'state' => 'disabled' ) ), 'WP_REDIS_DISABLED' ) );
hc_status_gate( 'CSS class for degraded is amber', 'is-degraded' === \HarborCache\Status::css_class( array( 'state' => 'degraded' ) ) );

$identity = \HarborCache\Namespace_Guard::identity();
$expected = hash( 'sha256', DB_NAME . ':' . $GLOBALS['table_prefix'] . ':' . WP_CONTENT_DIR );
hc_status_gate( 'Identity excludes AUTH_KEY', hash_equals( $expected, $identity ), $identity );
hc_status_gate( 'Legacy identity still available', hash_equals( hash( 'sha256', DB_NAME . ':' . $GLOBALS['table_prefix'] . ':' . AUTH_KEY ), \HarborCache\Namespace_Guard::legacy_identity() ) );

$base = 'ch:' . substr( hash( 'sha256', \HarborCache\Config::load()['prefix'] ), 0, 24 ) . ':meta';
$driver = new HC_Status_Driver();
$GLOBALS['hc_status_driver'] = $driver;
$driver->values[ $base . ':owner' ] = 'foreign-owner';
$cache = new HarborCache_Advanced_Cache( true );
$diag  = $cache->ch_diagnostics( false );
hc_status_gate( 'Conflict keeps the runtime fallback', false === $cache->redis_status() );
hc_status_gate( 'Conflict preserves transport health', true === $diag['transport_ok'] );
hc_status_gate( 'Conflict is reported as namespace conflict', 'conflict' === $diag['namespace'] && 'prefix-conflict' === $diag['reason'] );
hc_status_gate( 'Conflict does not report not-ready as the only fact', 'conflict' === $diag['namespace'] );

$driver = new HC_Status_Driver();
$GLOBALS['hc_status_driver'] = $driver;
$driver->values[ $base . ':owner' ] = \HarborCache\Namespace_Guard::legacy_identity();
$cache = new HarborCache_Advanced_Cache( true );
hc_status_gate( 'Legacy owner migrates without a conflict', $cache->redis_status() && hash_equals( \HarborCache\Namespace_Guard::identity(), $driver->values[ $base . ':owner' ] ) );

$driver = new HC_Status_Driver();
$GLOBALS['hc_status_driver'] = $driver;
$driver->values[ $base . ':owner' ] = \HarborCache\Namespace_Guard::identity();
$write = new HarborCache_Advanced_Cache( true );
$set   = $write->set( 'probe', 'value', 'audit-group' );
$root  = (string) ( $driver->values[ $base . ':root' ] ?? '' );
hc_status_gate( 'A first group write succeeds', $set );
hc_status_gate( 'A first group write registers discovery', isset( $driver->sets[ $base . ':' . $root . ':groups' ]['site-1|audit-group'] ) );
hc_status_gate( 'A first group write stores its epoch', isset( $driver->hashes[ $base . ':' . $root . ':epochs' ]['site-1|audit-group'] ) );

$driver->commands = 0;
$value = $write->get( 'probe', 'audit-group', false, $found );
hc_status_gate( 'A runtime hit returns the stored value', $found && 'value' === $value );
hc_status_gate( 'A runtime hit sends no Redis command', 0 === $driver->commands, (string) $driver->commands );
$driver->commands = 0;
$first_id  = $write->build_key( 'probe', 'audit-group' );
$second_id = $write->build_key( 'probe', 'audit-group' );
hc_status_gate( 'A repeated key build is memoized', $first_id === $second_id && 0 === $driver->commands, (string) $driver->commands );

$original_compression = $write->ch_config['compression'];
$write->ch_config['compression'] = 'none' === $original_compression ? 'lzf' : 'none';
$layout_value = $write->get( 'probe', 'audit-group', false, $layout_found );
hc_status_gate( 'Layout changes bypass the runtime hit', false === $layout_value && ! $layout_found );
$write->ch_config['compression'] = $original_compression;

$write->flush_group( 'audit-group' );
$driver->commands = 0;
$value = $write->get( 'probe', 'audit-group', false, $found );
hc_status_gate( 'Group rotation drops the runtime hit', ! $found && false === $value );

$prefetchable = new ReflectionMethod( $write, 'ch_prefetchable_request' );
$incomplete = new ReflectionMethod( $write, 'ch_prefetch_incomplete' );
if ( PHP_VERSION_ID < 80100 ) { $prefetchable->setAccessible( true ); $incomplete->setAccessible( true ); }
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/prefetch-fixture';
hc_status_gate( 'Prefetch accepts GET', true === $prefetchable->invoke( $write ) );
$_SERVER['REQUEST_METHOD'] = 'POST';
hc_status_gate( 'Prefetch skips POST', false === $prefetchable->invoke( $write ) );
$missing = @unserialize( 'O:22:"Missing_Prefetch_Class":0:{}' );
hc_status_gate( 'Prefetch detects an incomplete class', true === $incomplete->invoke( $write, $missing ) );
hc_status_gate( 'Prefetch detects a nested incomplete class', true === $incomplete->invoke( $write, array( 'x' => array( $missing ) ) ) );
hc_status_gate( 'Prefetch accepts plain data', false === $incomplete->invoke( $write, array( 'x' => 1 ) ) );
$driver->commands = 0;
$write->prefetch( array( 'audit-group' => array( 'probe' ) ) );
hc_status_gate( 'Prefetch skips a single-key group', 0 === $driver->commands, (string) $driver->commands );

$GLOBALS['wp_object_cache'] = new class extends HarborCache_Advanced_Cache {
	public $ch_config = array( 'scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379, 'database' => 2, 'serializer' => 'igbinary', 'compression' => 'lzf', 'maxttl' => 3600 );
	public function __construct() {}
	public function ch_diagnostics( $with_bytes = false ) {
		return array( 'connected' => true, 'transport_ok' => true, 'ready' => true, 'namespace' => 'owned', 'codec' => 'ok', 'client' => 'PhpRedis', 'redis' => '7.4.0', 'reason' => '', 'bytes_estimate' => 0 );
	}
};
$live = \HarborCache\Status::read();
hc_status_gate( 'Status reads the live endpoint', '127.0.0.1:6379' === ( $live['endpoint'] ?? '' ), (string) ( $live['endpoint'] ?? '' ) );
hc_status_gate( 'Status reads the live database', 2 === ( $live['database'] ?? -1 ) );
hc_status_gate( 'Status reads the live codec', 'igbinary' === ( $live['serializer'] ?? '' ) && 'lzf' === ( $live['compression'] ?? '' ) );
hc_status_gate( 'Status keeps transport and namespace facts', 'connected' === ( $live['transport'] ?? '' ) && 'owned' === ( $live['namespace'] ?? '' ) && 'ok' === ( $live['codec'] ?? '' ) );
hc_status_gate( 'Missing drop-in is still reported', 'not-installed' === ( $live['state'] ?? '' ), (string) ( $live['state'] ?? '' ) );

echo 'RESULT ' . ( $failures ? 'FAILED' : 'PASSED' ) . ' with ' . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
