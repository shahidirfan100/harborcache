<?php
/** Bounded analytics summaries shared by the dashboard, CSV export and CLI. */
namespace HarborCache;
defined( 'ABSPATH' ) || exit;

final class Analytics {
	public static function window( $seconds ) { return in_array( (int) $seconds, array( 900, 3600, 7200, 21600, 86400 ), true ) ? (int) $seconds : 7200; }

	public static function summarize( array $samples, $interval = 300 ) {
		$interval = max( 60, min( 3600, (int) $interval ) ); $buckets = array(); $times = array(); $hits = 0; $misses = 0; $calls = 0;
		foreach ( $samples as $sample ) {
			$t = (int) ( floor( $sample->timestamp / $interval ) * $interval );
			if ( ! isset( $buckets[ $t ] ) ) { $buckets[ $t ] = array( 'timestamp' => $t, 'samples' => 0, 'hits' => 0, 'misses' => 0, 'calls' => 0, 'milliseconds' => array() ); }
			++$buckets[ $t ]['samples']; $buckets[ $t ]['hits'] += $sample->hits; $buckets[ $t ]['misses'] += $sample->misses; $buckets[ $t ]['calls'] += $sample->calls; $buckets[ $t ]['milliseconds'][] = $sample->time * 1000;
			$hits += $sample->hits; $misses += $sample->misses; $calls += $sample->calls; $times[] = $sample->time * 1000;
		}
		foreach ( $buckets as &$bucket ) { $latencies = $bucket['milliseconds']; unset( $bucket['milliseconds'] ); $bucket['median_ms'] = self::percentile( $latencies, .5 ); $bucket['p95_ms'] = self::percentile( $latencies, .95 ); $bucket['ratio'] = $bucket['hits'] + $bucket['misses'] ? round( 100 * $bucket['hits'] / ( $bucket['hits'] + $bucket['misses'] ), 2 ) : 100; }
		unset( $bucket ); ksort( $buckets );
		return array( 'samples' => count( $samples ), 'ratio' => $hits + $misses ? round( 100 * $hits / ( $hits + $misses ), 2 ) : 100, 'commands' => $calls, 'median_ms' => self::percentile( $times, .5 ), 'p95_ms' => self::percentile( $times, .95 ), 'interval' => $interval, 'buckets' => array_values( $buckets ) );
	}

	private static function percentile( array $values, $fraction ) {
		if ( ! $values ) { return null; }
		sort( $values, SORT_NUMERIC ); $position = ( count( $values ) - 1 ) * $fraction; $low = (int) floor( $position ); $high = (int) ceil( $position );
		return round( $values[ $low ] + ( $values[ $high ] - $values[ $low ] ) * ( $position - $low ), 3 );
	}

	public static function csv( array $samples ) {
		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV stream, not a filesystem path; WP_Filesystem has no stream API.
		fputcsv( $stream, array( 'timestamp_utc', 'hits', 'misses', 'hit_ratio_percent', 'commands', 'redis_milliseconds', 'runtime_bytes' ), ',', '"', '' );
		foreach ( $samples as $row ) { fputcsv( $stream, array( gmdate( 'c', $row->timestamp ), (int) $row->hits, (int) $row->misses, (float) $row->ratio, (int) $row->calls, round( $row->time * 1000, 3 ), (int) $row->bytes ), ',', '"', '' ); }
		rewind( $stream ); $csv = stream_get_contents( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV stream opened above.
		return $csv;
	}
}
