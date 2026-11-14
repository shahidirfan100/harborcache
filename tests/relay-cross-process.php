<?php
/**
 * Relay cross-process invalidation gate.
 *
 * php tests/relay-cross-process.php
 * Requires WP_ROOT and an HC_TEST_CONFIG with client=relay and listeners enabled.
 */
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$mode = $argv[1] ?? 'parent';
$config = json_decode( getenv( 'HC_TEST_CONFIG' ) ?: '{}', true );
if ( ! is_array( $config ) ) { throw new RuntimeException( 'Invalid test configuration' ); }
$config = array_replace( array( 'client' => 'relay', 'prefix' => 'hc-relay-cross', 'database' => 15, 'relay' => array( 'listeners' => true ) ), $config );
define( 'HARBORCACHE_CONFIG', $config );
require rtrim( getenv( 'WP_ROOT' ), '/\\' ) . '/wp-load.php';

$cache = $GLOBALS['wp_object_cache'];
if ( 'relay' !== $config['client'] || ! class_exists( '\Relay\Relay' ) ) {
	echo json_encode( array( 'ok' => true, 'skipped' => 'Relay is unavailable' ) ) . "\n";
	exit( 0 );
}

$barrier = getenv( 'HC_RELAY_BARRIER' );
if ( 'child' === $mode ) {
	if ( ! $barrier ) { throw new RuntimeException( 'Missing barrier' ); }
	$first = $cache->get( 'cross', 'ci-relay', false, $found );
	if ( ! $found || 'v1' !== $first ) { throw new RuntimeException( 'The child did not read the seeded value.' ); }
	file_put_contents( $barrier . '/ready', '1' );
	$deadline = microtime( true ) + 10;
	while ( ! file_exists( $barrier . '/go' ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'The parent did not release the child.' ); }
		usleep( 5000 );
	}
	$second = $cache->get( 'cross', 'ci-relay', true, $found );
	echo json_encode( array( 'first' => $first, 'second' => $second, 'found' => $found ) ) . "\n";
	exit( 0 );
}

$barrier = sys_get_temp_dir() . '/hc-relay-cross-' . getmypid();
if ( ! mkdir( $barrier, 0700 ) ) { throw new RuntimeException( 'Could not create the barrier.' ); }
try {
	$cache->delete( 'cross', 'ci-relay' );
	$cache->set( 'cross', 'v1', 'ci-relay', 60 );
	$env = getenv();
	$env['HC_RELAY_BARRIER'] = $barrier;
	$process = proc_open( array( PHP_BINARY, __FILE__, 'child' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start the child.' ); }
	$deadline = microtime( true ) + 10;
	while ( ! file_exists( $barrier . '/ready' ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'The child did not become ready.' ); }
		usleep( 5000 );
	}
	$cache->set( 'cross', 'v2', 'ci-relay', 60 );
	file_put_contents( $barrier . '/go', '1' );
	$output = stream_get_contents( $pipes[1] );
	$error  = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $process );
	if ( 0 !== $code ) { throw new RuntimeException( 'The child failed: ' . trim( $error ) ); }
	$result = json_decode( trim( $output ), true );
	if ( ! is_array( $result ) || 'v2' !== ( $result['second'] ?? null ) || empty( $result['found'] ) ) {
		throw new RuntimeException( 'The child read a stale value after the cross-process write: ' . trim( $output ) );
	}
	echo json_encode( array( 'ok' => true, 'first' => $result['first'], 'second' => $result['second'] ) ) . "\n";
} finally {
	foreach ( glob( $barrier . '/*' ) as $file ) { @unlink( $file ); }
	@rmdir( $barrier );
}
