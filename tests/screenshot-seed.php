<?php
/**
 * Seeds a disposable screenshot fixture with cache groups and demonstration samples.
 * Run with: wp eval-file tests/screenshot-seed.php
 *
 * The analytics chart needs history that spans more than the two minute sample
 * delay. These samples are synthetic and are disclosed as demonstration data in
 * media/README.md; the rest of the UI is driven by the real Redis connection.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wp_object_cache;

if ( ! $wp_object_cache instanceof HarborCache_Advanced_Cache || ! $wp_object_cache->redis_status() ) {
	WP_CLI::error( 'HarborCache is not connected; screenshots need a live Redis connection.' );
}

$client = $wp_object_cache->redis_instance();
$metrics_only = '1' === getenv( 'HC_METRICS_ONLY' );

// Start from a clean namespace so the capture is deterministic.
if ( ! $metrics_only ) {
	$wp_object_cache->flush();
}

$groups = array(
	'posts'          => array( 'last_changed', 'posts:published', 'posts:front' ),
	'post_meta'      => array( 'post_meta:12', 'post_meta:18' ),
	'terms'          => array( 'terms:category', 'terms:post_tag' ),
	'term_meta'      => array( 'term_meta:3' ),
	'users'          => array( 'users:1', 'users:2' ),
	'user_meta'      => array( 'user_meta:1' ),
	'comments'       => array( 'comments:recent', 'comments:approved' ),
	'comment_meta'   => array( 'comment_meta:7' ),
	'options'        => array( 'options:site', 'options:widgets' ),
	'themes'         => array( 'themes:installed' ),
	'counts'         => array( 'counts:posts', 'counts:comments' ),
	'site-transient' => array( 'site-transient:update_core' ),
);

$written = 0;
if ( ! $metrics_only ) {
	foreach ( $groups as $group => $keys ) {
		foreach ( $keys as $index => $key ) {
			$value = array(
				'group'   => $group,
				'key'     => $key,
				'records' => 8 + ( $index * 3 ),
				'built'   => time() - ( $index * 90 ),
			);
			if ( wp_cache_set( $key, $value, $group, HOUR_IN_SECONDS ) ) {
				++$written;
			}
		}
	}

	// Read the seeded values back so the request memory also holds entries.
	foreach ( $groups as $group => $keys ) {
		foreach ( $keys as $key ) {
			wp_cache_get( $key, $group );
		}
	}

	// Representative traffic so the Status request counters are not near zero.
	for ( $i = 0; $i < 240; ++$i ) {
		$post_id = 1 + ( $i % 12 );
		wp_cache_get( 'post_' . $post_id, 'posts' );
		wp_cache_get( 'post_meta_' . $post_id, 'post_meta' );
		wp_cache_get( 'user_' . ( 1 + ( $i % 3 ) ), 'users' );
		wp_cache_get( 'missing_' . $i, 'terms' );
	}
}

$key = $wp_object_cache->build_key( 'metrics', 'harborcache' );
$now = time();

// A synthetic score would collide with the real samples, so clear first.
if ( $client instanceof \Redis ) {
	$client->del( $key );
} else {
	$client->del( array( $key ) );
}

$samples = 36;
$span    = 6600; // 110 minutes, inside the two hour retention window.
for ( $i = 1; $i <= $samples; ++$i ) {
	$timestamp = $now - 60 - (int) round( ( $samples - $i ) * ( $span / $samples ) );
	$wave      = sin( $i / 9 );
	$hits      = 58 + (int) round( 26 * $wave ) + ( $i % 7 );
	$misses    = 1 + ( $i % 4 );
	$sample    = new \HarborCache\Core\Metrics();

	$sample->id        = substr( md5( 'harborcache-screenshot-' . $i ), 12 );
	$sample->hits      = $hits;
	$sample->misses    = $misses;
	$sample->ratio     = round( 100 * $hits / ( $hits + $misses ), 2 );
	$sample->bytes     = 96000 + ( $i * 733 ) % 84000;
	$sample->time      = round( 0.006 + ( abs( $wave ) * 0.011 ) + ( $i % 5 ) * 0.0007, 5 );
	$sample->calls     = 34 + (int) round( 22 * $wave ) + ( $i % 9 );
	$sample->timestamp = $timestamp;

	$payload = serialize( $sample ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Matches HarborCache\Core\Metrics::save().

	if ( $client instanceof \Redis ) {
		$saved = $client->zadd( $key, $timestamp, $payload );
	} else {
		$saved = $client->zadd( $key, array( $payload => $timestamp ) );
	}

	if ( false === $saved ) {
		WP_CLI::error( 'Could not seed analytics samples.' );
	}
}

if ( $client instanceof \Redis ) {
	$client->expire( $key, 7200 );
} else {
	$client->expire( array( $key, 7200 ) );
}

$count = \HarborCache\Core\Metrics::count();

WP_CLI::line( 'Seeded ' . $written . ' cache entries in ' . count( $groups ) . ' groups.' );
WP_CLI::line( 'Seeded analytics samples: ' . $count . '.' );

if ( ( ! $metrics_only && $written < 20 ) || $count < $samples ) {
	WP_CLI::error( 'Screenshot fixture is incomplete.' );
}
