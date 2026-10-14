<?php
/** Compare cache-group invalidation cost across small and large groups. */
$engine = $argv[1] ?? 'harbor';
$source = $argv[2] ?? dirname( __DIR__ );
$samples = max( 3, min( 20, (int) ( $argv[3] ?? 5 ) ) );

define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$host = getenv( 'HC_REDIS_HOST' ) ?: '127.0.0.1';
$port = max( 1, min( 65535, (int) ( getenv( 'HC_REDIS_PORT' ) ?: 6379 ) ) );
$prefix = ( getenv( 'HC_BENCH_PREFIX' ) ?: 'hc-flush-' ) . preg_replace( '/[^a-z0-9-]/i', '-', $engine ) . ':';
define( 'WP_REDIS_CLIENT', 'phpredis' );
define( 'WP_REDIS_HOST', $host );
define( 'WP_REDIS_PORT', $port );
define( 'WP_REDIS_PREFIX', $prefix );
define( 'WP_REDIS_PLUGIN_PATH', $source );
define( 'WP_REDIS_FILE', rtrim( $source, '/\\' ) . DIRECTORY_SEPARATOR . ( 'free' === $engine ? 'redis-cache.php' : 'harborcache.php' ) );
if ( 'free' !== $engine ) {
	define( 'HARBORCACHE_CONFIG', array( 'client' => 'phpredis', 'analytics' => array( 'enabled' => false ), 'group_flush' => 'atomic', 'async_flush' => false ) );
}

require rtrim( getenv( 'WP_ROOT' ), '/' ) . '/wp-load.php';
if ( 'free' !== $engine ) {
	require rtrim( $source, '/\\' ) . DIRECTORY_SEPARATOR . 'harborcache.php';
}

$cache = $GLOBALS['wp_object_cache'];
if ( ! $cache->redis_status() || ! function_exists( 'wp_cache_flush_group' ) ) {
	throw new RuntimeException( 'Connected object cache with group invalidation is required.' );
}

$probe = new Redis();
$probe->connect( $host, $port );
$results = array();
foreach ( array( 100, 5000 ) as $key_count ) {
	$times = array();
	$command_counts = array();
	for ( $sample = 0; $sample < $samples; ++$sample ) {
		$group = 'hc_flush_' . $key_count . '_' . $sample;
		$data = array();
		for ( $key = 0; $key < $key_count; ++$key ) {
			$data[ 'entry-' . $key ] = 'payload-' . $key;
		}
		$writes = wp_cache_set_multiple( $data, $group, 300 );
		if ( ! is_array( $writes ) || count( $writes ) !== $key_count || in_array( false, $writes, true ) ) {
			throw new RuntimeException( 'Could not seed the benchmark cache group.' );
		}

		$before = $probe->info( 'stats' );
		$started = hrtime( true );
		$flushed = wp_cache_flush_group( $group );
		$times[] = ( hrtime( true ) - $started ) / 1e6;
		$after = $probe->info( 'stats' );
		$command_counts[] = max( 0, (int) $after['total_commands_processed'] - (int) $before['total_commands_processed'] - 1 );
		if ( ! $flushed ) {
			throw new RuntimeException( 'Cache-group invalidation failed.' );
		}
		wp_cache_flush_runtime();
		if ( false !== wp_cache_get( 'entry-0', $group, true ) ) {
			throw new RuntimeException( 'Invalidated cache entry remained visible.' );
		}
	}
	sort( $times, SORT_NUMERIC );
	$index = static function ( $fraction ) use ( $times ) {
		return $times[ min( count( $times ) - 1, (int) ceil( count( $times ) * $fraction ) - 1 ) ];
	};
	$results[ (string) $key_count ] = array(
		'samples' => count( $times ),
		'p50_ms' => round( $index( 0.50 ), 4 ),
		'p95_ms' => round( $index( 0.95 ), 4 ),
		'p99_ms' => round( $index( 0.99 ), 4 ),
		'mean_redis_commands_per_flush' => round( array_sum( $command_counts ) / count( $command_counts ), 2 ),
	);
}

echo json_encode( array(
	'engine' => $engine,
	'php' => PHP_VERSION,
	'wordpress' => get_bloginfo( 'version' ),
	'group_flush' => 'free' === $engine ? 'upstream default' : 'atomic',
	'results' => $results,
	'limitations' => 'Isolated Redis fixture; invalidation-call percentiles after seeding; not concurrent production traffic.',
) ) . "\n";
