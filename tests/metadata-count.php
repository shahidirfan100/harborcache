<?php
/** Command-count check for a 30-group request. Run with wp eval-file. */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$hc_meta_failures = 0;
function hc_meta_gate( $name, $pass, $detail = '' ) {
	global $hc_meta_failures;
	if ( ! $pass ) { ++$hc_meta_failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

wp_set_current_user( 1 );

$groups = array();
for ( $i = 0; $i < 30; ++$i ) {
	$groups[] = 'hc-meta-' . $i;
	if ( ! wp_cache_set( 'k', $i, $groups[ $i ], 60 ) ) {
		throw new RuntimeException( 'Could not warm group ' . $groups[ $i ] );
	}
}

$cache  = new HarborCache_Advanced_Cache();
$before = $cache->cache_calls;
for ( $i = 0; $i < 30; ++$i ) {
	$value = $cache->get( 'k', $groups[ $i ], false, $found );
	if ( ! $found || $value !== $i ) {
		throw new RuntimeException( 'Group read failed for ' . $groups[ $i ] );
	}
}
$calls    = $cache->cache_calls - $before;
$metadata = $calls - 30;

hc_meta_gate( '30 groups read back', 30 === count( $groups ) );
hc_meta_gate( 'Metadata stays at or below 8 commands', $metadata <= 8, 'metadata=' . $metadata );
hc_meta_gate( 'Total stays at or below 40 commands', $calls <= 40, 'calls=' . $calls );

echo 'RESULT ' . ( $hc_meta_failures ? 'FAILED' : 'PASSED' ) . ' with ' . $hc_meta_failures . " failure(s)\n";
if ( $hc_meta_failures ) {
	throw new RuntimeException( 'Metadata command-count checks failed.' );
}
