<?php
/**
 * Connection-check parity with the live cache. Runs without Redis.
 *
 * php tests/probe-parity.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HARBORCACHE_PLUGIN_PATH', dirname( __DIR__ ) );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'DB_NAME', 'probe-fixture' );
define( 'AUTH_KEY', 'disposable-probe-key' );
define( 'HARBORCACHE_CONFIG', array( 'connector' => 'hc_probe_connector', 'analytics' => array( 'enabled' => false ) ) );
$GLOBALS['table_prefix'] = 'probe_';

if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ) { return $text; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $text ) { return $text; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $format, $timestamp = null ) { return gmdate( $format, $timestamp ?: time() ); } }
if ( ! function_exists( 'is_multisite' ) ) { function is_multisite() { return false; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'do_action' ) ) { function do_action() {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $name, $value ) { return $value; } }

require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-config.php';
\HarborCache\Config::apply();
require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-connection-check.php';

$failures = 0;
function hc_probe_gate( $name, $pass, $detail = '' ) {
	global $failures;
	if ( ! $pass ) { ++$failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

class HC_Probe_Driver {
	public $values = array();
	public $deleted = array();
	public function ping() { return 'PONG'; }
	public function info( ...$args ) { return array( 'redis_version' => '7.0.0' ); }
	public function rawCommand( ...$args ) {
		$command = strtoupper( array_shift( $args ) );
		$key     = $args[0] ?? '';
		if ( 'PING' === $command ) { return 'PONG'; }
		if ( 'GET' === $command ) { return $this->values[ $key ] ?? null; }
		if ( 'SET' === $command ) {
			if ( in_array( 'NX', $args, true ) && isset( $this->values[ $key ] ) ) { return null; }
			$this->values[ $key ] = $args[1];
			return 'OK';
		}
		if ( 'DEL' === $command ) { unset( $this->values[ $key ] ); $this->deleted[] = $key; return 1; }
		if ( 'INFO' === $command ) { return "redis_version:7.0.0\r\n"; }
		throw new RuntimeException( 'Unexpected probe command: ' . $command );
	}
	public function close() {}
}

function hc_probe_connector() {
	return $GLOBALS['hc_probe_driver'];
}

$config = \HarborCache\Config::load();
$config['connector'] = 'hc_probe_connector';
$config['timeout']   = 0.2;

$driver = new HC_Probe_Driver();
$GLOBALS['hc_probe_driver'] = $driver;
$result = \HarborCache\Connection_Check::run( $config, 'test' );
hc_probe_gate( 'Probe uses the configured timeout', 0.2 === (float) $result['timeout'], (string) $result['timeout'] );
hc_probe_gate( 'Probe reports transport', true === $result['transport_ok'] );
hc_probe_gate( 'Unclaimed namespace passes', 'unclaimed' === $result['namespace'] && true === $result['ok'], $result['namespace'] . '/' . $result['error_code'] );
hc_probe_gate( 'Codec round trip passes', 'ok' === $result['codec'] );
hc_probe_gate( 'Probe key is removed', array() === array_filter( array_keys( $driver->values ), static function ( $key ) { return false !== strpos( $key, ':probe:' ); } ) );

$prefix = 'ch:' . substr( hash( 'sha256', $config['prefix'] ), 0, 24 ) . ':';
$driver = new HC_Probe_Driver();
$driver->values[ $prefix . 'meta:owner' ] = 'another-installation';
$GLOBALS['hc_probe_driver'] = $driver;
$result = \HarborCache\Connection_Check::run( $config, 'test' );
hc_probe_gate( 'Foreign namespace blocks readiness', false === $result['ok'] );
hc_probe_gate( 'Foreign namespace keeps transport visible', true === $result['transport_ok'] );
hc_probe_gate( 'Foreign namespace is classified', 'conflict' === $result['namespace'] && 'namespace-conflict' === $result['error_code'], $result['namespace'] . '/' . $result['error_code'] );
hc_probe_gate( 'Foreign namespace is not called disconnected', false === strpos( \HarborCache\Connection_Check::render( $result ), '>Not connected<' ) );

$driver = new HC_Probe_Driver();
$driver->values[ $prefix . 'meta:owner' ] = \HarborCache\Namespace_Guard::legacy_identity();
$GLOBALS['hc_probe_driver'] = $driver;
$result = \HarborCache\Connection_Check::run( $config, 'test' );
hc_probe_gate( 'Legacy namespace passes', 'legacy' === $result['namespace'] && true === $result['ok'], $result['namespace'] );

$driver = new HC_Probe_Driver();
$driver->values[ $prefix . 'meta:owner' ] = 'another-installation';
$GLOBALS['hc_probe_driver'] = $driver;
$reclaimed = \HarborCache\Connection_Check::reclaim( $config );
hc_probe_gate( 'Reclaim replaces the owner', true === $reclaimed['ok'] && hash_equals( \HarborCache\Namespace_Guard::identity(), $driver->values[ $prefix . 'meta:owner' ] ), $reclaimed['message'] );

echo 'RESULT ' . ( $failures ? 'FAILED' : 'PASSED' ) . ' with ' . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
