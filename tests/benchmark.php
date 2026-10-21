<?php
/** Sequential operations; engine and full-plugin bootstraps are separate lanes. */
$engine = $argv[1];
$source = rtrim( $argv[2], '/\\' );
$round = (int) $argv[3];
$sampling = (int) ( $argv[4] ?? 0 );
$lane = $argv[5] ?? 'full';
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$host = getenv( 'HC_REDIS_HOST' ) ?: '127.0.0.1';
$port = (int) ( getenv( 'HC_REDIS_PORT' ) ?: 6379 );
define( 'WP_REDIS_CLIENT', 'phpredis' );
define( 'WP_REDIS_HOST', $host );
define( 'WP_REDIS_PORT', $port );
define( 'WP_REDIS_PREFIX', 'hc-bench-' . $lane . '-' . $engine . '-' . $round . '-' . $sampling . ':' );
define( 'WP_REDIS_DISABLE_METRICS', 0 === $sampling );
if ( 'free' !== $engine ) {
    define( 'HARBORCACHE_PLUGIN_PATH', $source );
    define( 'HARBORCACHE_CONFIG', array( 'client' => 'phpredis', 'analytics' => array( 'enabled' => $sampling > 0, 'sample_rate' => $sampling ), 'prefetch' => false, 'split_alloptions' => false ) );
} elseif ( 'engine' === $lane ) {
    define( 'WP_REDIS_PLUGIN_PATH', $source );
    define( 'WP_REDIS_FILE', $source . '/redis-cache.php' );
}
if ( 'full' === $lane ) { define( 'HC_BENCH_MAIN', $source . ( 'free' === $engine ? '/redis-cache.php' : '/harborcache.php' ) ); }
function hc_bench_cpu() {
    $r = getrusage();
    return ( $r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec'] ) * 1000 + ( $r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec'] ) / 1000;
}
$bootstrap_start = hrtime( true );
$bootstrap_cpu = hc_bench_cpu();
require rtrim( getenv( 'WP_ROOT' ), '/' ) . '/wp-load.php';
$bootstrap_ms = ( hrtime( true ) - $bootstrap_start ) / 1e6;
$bootstrap_cpu_ms = hc_bench_cpu() - $bootstrap_cpu;
if ( 'full' === $lane && ! in_array( realpath( HC_BENCH_MAIN ), array_map( 'realpath', get_included_files() ), true ) ) { throw new RuntimeException( 'The selected main plugin did not load during bootstrap.' ); }
$cache = $GLOBALS['wp_object_cache'];
if ( ! $cache->redis_status() ) { throw new RuntimeException( 'Backend unavailable.' ); }
$probe = new Redis();
$probe->connect( $host, $port );
$before = $probe->info( 'cpu' );
$stats = $probe->info( 'stats' );
$keys = range( 1, 100 );
$payload = array( 'values' => range( 1, 50 ), 'label' => 'Equal cache workload' );
if ( in_array( false, wp_cache_set_multiple( array_fill_keys( $keys, $payload ), 'hc-bench', 120 ), true ) ) { throw new RuntimeException( 'Seed failed.' ); }
$cold = array();
for ( $i = 0; $i < 1100; ++$i ) { $cold[] = 'cold-' . bin2hex( random_bytes( 8 ) ); }
$cold_index = 0;
$times = array();
$cpu0 = hc_bench_cpu();
$measure = static function ( $label, $call ) use ( &$times ) {
    $samples = array();
    for ( $batch = 0; $batch < 55; ++$batch ) {
        $start = hrtime( true );
        $call();
        if ( $batch >= 5 ) { $samples[] = ( hrtime( true ) - $start ) / 1e6; }
    }
    sort( $samples );
    $times[ $label ] = array( 'p50_ms' => $samples[24], 'p95_ms' => $samples[47], 'p99_ms' => $samples[49] );
};
$measure( 'runtime_1000', static function () { for ( $i = 0; $i < 1000; ++$i ) { $value = wp_cache_get( 1, 'hc-bench', false, $found ); if ( ! $found || 50 !== ( $value['values'][49] ?? null ) ) { throw new RuntimeException( 'Runtime mismatch.' ); } } } );
$measure( 'redis_20', static function () { for ( $i = 0; $i < 20; ++$i ) { $value = wp_cache_get( 1, 'hc-bench', true, $found ); if ( ! $found || 50 !== ( $value['values'][49] ?? null ) ) { throw new RuntimeException( 'Redis mismatch.' ); } } } );
$measure( 'bulk_100', static function () use ( $keys, $payload ) { if ( array_fill_keys( $keys, $payload ) !== wp_cache_get_multiple( $keys, 'hc-bench', true ) ) { throw new RuntimeException( 'Bulk mismatch.' ); } } );
$measure( 'writes_20', static function () use ( $payload ) { for ( $i = 0; $i < 20; ++$i ) { if ( ! wp_cache_set( 1, $payload, 'hc-bench', 120 ) ) { throw new RuntimeException( 'Write failed.' ); } } } );
$miss = static function ( $key ) { wp_cache_get( $key, 'hc-bench', true, $found ); if ( $found ) { throw new RuntimeException( 'Miss was a hit.' ); } };
$measure( 'misses_20', static function () use ( $miss ) { for ( $i = 0; $i < 20; ++$i ) { $miss( 'absent' ); } } );
$measure( 'cold_20', static function () use ( $miss, $cold, &$cold_index ) { for ( $i = 0; $i < 20; ++$i ) { $miss( $cold[ $cold_index++ ] ); } } );
$large = str_repeat( 'x', 16384 );
$measure( 'large_16k', static function () use ( $large ) { if ( ! wp_cache_set( 'large', $large, 'hc-bench', 120 ) || $large !== wp_cache_get( 'large', 'hc-bench', true ) ) { throw new RuntimeException( 'Large value mismatch.' ); } } );
$operation_cpu = hc_bench_cpu() - $cpu0;
$metrics = 0;
if ( 'free' !== $engine && 'full' === $lane ) {
    $start = hrtime( true );
    HarborCache\Core\Metrics::record();
    $metrics = ( hrtime( true ) - $start ) / 1e6;
    remove_action( 'shutdown', array( HarborCache\Core\Metrics::class, 'record' ) );
}
$after = $probe->info( 'cpu' );
$afterstats = $probe->info( 'stats' );
$memory = $probe->info( 'memory' );
echo json_encode( array(
    'engine' => $engine, 'lane' => $lane, 'round' => $round, 'sampling' => $sampling, 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ),
    'timings' => $times, 'bootstrap_wall_ms' => $bootstrap_ms, 'bootstrap_php_cpu_ms' => $bootstrap_cpu_ms,
    'metrics_record_ms' => $metrics, 'php_cpu_ms' => $operation_cpu,
    'redis_cpu_ms' => ( ( $after['used_cpu_sys'] + $after['used_cpu_user'] ) - ( $before['used_cpu_sys'] + $before['used_cpu_user'] ) ) * 1000,
    'redis_commands' => $afterstats['total_commands_processed'] - $stats['total_commands_processed'], 'php_peak_bytes' => memory_get_peak_usage( true ), 'redis_rss_bytes' => $memory['used_memory_rss'],
    'limitations' => 'Sequential checked operations, not HTTP percentiles; Redis CPU covers the isolated daemon; bootstrap and explicit metrics call are separate; full lane loads both main plugins during WordPress bootstrap.'
) ) . "\n";
