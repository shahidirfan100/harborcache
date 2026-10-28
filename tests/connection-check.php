<?php
/** Connection probe checks. Uses the CI WordPress fixture and its Redis service. */
$root = getenv( 'WP_ROOT' );
if ( $root ) {
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
	require rtrim( $root, '/\\' ) . '/wp-load.php';
} else {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'HARBORCACHE_PLUGIN_PATH', dirname( __DIR__ ) );
	if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }
	if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, $domain = null ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); } }
	if ( ! function_exists( 'esc_html' ) ) { function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); } }
	if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); } }
	if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, (int) $decimals ); } }
	if ( ! function_exists( 'wp_date' ) ) { function wp_date( $format, $timestamp = null ) { return gmdate( $format, $timestamp ?: time() ); } }
}

if ( ! class_exists( 'HarborCache\\Connection_Check' ) ) {
	$hc_plugin = defined( 'HARBORCACHE_PLUGIN_PATH' ) ? HARBORCACHE_PLUGIN_PATH : dirname( __DIR__ );
	require_once $hc_plugin . '/includes/class-connection-check.php';
}

$failures = 0;
function hc_probe_gate( $name, $pass, $detail = '' ) {
	global $failures;
	if ( ! $pass ) { ++$failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

$base = array(
	'client' => 'predis', 'host' => '127.0.0.1', 'port' => 6390, 'database' => 0,
	'username' => '', 'password' => '', 'prefix' => 'harborcache-probe-ci', 'scheme' => 'tcp', 'path' => '',
	'timeout' => 0.2, 'read_timeout' => 0.2, 'persistent' => false,
	'cluster' => array(), 'sentinels' => array(), 'servers' => array(), 'service' => '',
	'tls_options' => array( 'verify_peer' => true, 'verify_peer_name' => true ),
	'connector' => null, 'serializer' => 'php', 'compression' => 'none',
);

$bad = HarborCache\Connection_Check::run( $base, 'test' );
hc_probe_gate( 'Refused endpoint fails', false === $bad['ok'] );
hc_probe_gate( 'Refused endpoint reports a specific code', in_array( $bad['error_code'], array( 'refused', 'timeout' ), true ), $bad['error_code'] );
hc_probe_gate( 'Refused endpoint keeps the endpoint label', false !== strpos( $bad['host'], '6390' ), $bad['host'] );
hc_probe_gate( 'Refused endpoint returns a message', '' !== $bad['message'] );
hc_probe_gate( 'Refused endpoint reports elapsed time', $bad['elapsed_ms'] >= 0 );
hc_probe_gate( 'Rendered failure names the state', false !== strpos( HarborCache\Connection_Check::render( $bad ), 'Not connected' ) );

$secret = 'do-not-print-this-password';
$with_secret = $base;
$with_secret['password'] = $secret;
$rendered = HarborCache\Connection_Check::render( HarborCache\Connection_Check::run( $with_secret, 'test' ) );
hc_probe_gate( 'Rendered result omits the password', false === strpos( $rendered, $secret ) );

if ( $root ) {
	$live = HarborCache\Connection_Check::run( array_replace( $base, array( 'client' => 'phpredis', 'port' => 6379, 'timeout' => 1.0, 'read_timeout' => 1.0 ) ), 'test' );
	hc_probe_gate( 'Live Redis connects through PhpRedis', true === $live['ok'], $live['message'] );
	hc_probe_gate( 'Live Redis reports its version', '' !== $live['redis_version'], $live['redis_version'] );
	hc_probe_gate( 'Live Redis reports the selected client', false !== stripos( $live['client_version'], 'phpredis' ), $live['client_version'] );

	$wrong = HarborCache\Connection_Check::run( array_replace( $base, array( 'port' => 6379, 'password' => 'wrong-password' ) ), 'test' );
	hc_probe_gate( 'Wrong password is classified as auth', false === $wrong['ok'] && 'auth' === $wrong['error_code'], $wrong['error_code'] );

	$database = HarborCache\Connection_Check::run( array_replace( $base, array( 'client' => 'phpredis', 'port' => 6379, 'database' => 9999 ) ), 'test' );
	hc_probe_gate( 'Out-of-range database is classified as database', false === $database['ok'] && 'database' === $database['error_code'], $database['error_code'] );

	$clean = HarborCache\Connection_Check::run( array_replace( $base, array( 'client' => 'phpredis', 'port' => 6379 ) ), 'test' );
	$redis = new Redis();
	$redis->connect( '127.0.0.1', 6379 );
	$pattern = 'ch:' . substr( hash( 'sha256', $base['prefix'] ), 0, 24 ) . ':probe:*';
	$left = array();
	foreach ( $redis->keys( $pattern ) as $key ) { $left[] = $key; }
	hc_probe_gate( 'Probe removes its temporary key', $clean['ok'] && array() === $left, implode( ',', $left ) );

	$defaults = HarborCache\Config::defaults();
	hc_probe_gate( 'Default client matches the available extension', 'phpredis' === $defaults['client'], $defaults['client'] );

	$status = HarborCache\Console::status();
	hc_probe_gate( 'Status exposes the state model', isset( $status['state'], $status['loaded'], $status['installed'], $status['reason'] ) );
	hc_probe_gate( 'Status label is user facing', in_array( $status['state'], array( 'connected', 'not-loaded', 'unreachable', 'not-installed', 'disabled' ), true ), $status['state'] );
	hc_probe_gate( 'Installed drop-in reports connected', 'connected' === $status['state'], $status['state'] );
}

echo "RESULT " . ( $failures ? 'FAILED' : 'PASSED' ) . " with " . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
