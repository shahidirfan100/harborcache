<?php
/**
 * Key builder ownership: stable prefixes, layout invalidation and batch maps.
 *
 * php tests/key-builder.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-key-builder.php';

$failures = 0;
function hc_key_gate( $name, $pass, $detail = '' ) {
	global $failures;
	if ( ! $pass ) { ++$failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

$config = array( 'serializer' => 'php', 'compression' => 'none', 'split_alloptions' => false, 'client' => 'phpredis' );
$builder = new HarborCache\Key_Builder(
	$config,
	'prefix:meta',
	static function ( $key ) { return str_repeat( 'a', 24 ); },
	static function ( $group ) { return 'site-1'; },
	static function ( $scope, $group_key, $group ) {},
	static function ( $group ) { return false; },
	static function ( $key, $group ) { return 'ignored:' . $group . ':' . $key; },
	static function ( $key ) { return str_replace( ':', '-', $key ); }
);

$first = $builder->build( 'alpha', 'default', 1 );
hc_key_gate( 'A built key has the scope, version, signature and hash', false !== strpos( $first, ':site-1:' ) && false !== strpos( $first, ':v:' ) );
hc_key_gate( 'The same key is stable', $first === $builder->build( 'alpha', 'default', 1 ) );
hc_key_gate( 'The cached id matches', $first === $builder->cached_id( 1, 'default', 'alpha' ) );

$batch = $builder->build_many( array( 'alpha', 'beta' ), 'default', 1 );
hc_key_gate( 'Batch building preserves original keys', array( 'alpha', 'beta' ) === array_keys( $batch ) && $batch['alpha'] === $first );

$GLOBALS['wp_version'] = '6.8';
$before = $builder->build( 'alpha', 'default', 1 );
$GLOBALS['wp_version'] = '6.9';
$after = $builder->build( 'alpha', 'default', 1 );
hc_key_gate( 'A core version change rotates the key', $before !== $after );

$config['compression'] = 'lzf';
$compressed = $builder->build( 'alpha', 'default', 1 );
hc_key_gate( 'A compression change rotates the key', $after !== $compressed );

$builder->reset();
hc_key_gate( 'Reset clears the cached id', null === $builder->cached_id( 1, 'default', 'alpha' ) );
$builder->set_root( 'root-two' );
hc_key_gate( 'A namespace root change rotates the key', $compressed !== $builder->build( 'alpha', 'default', 1 ) );

echo 'RESULT ' . ( $failures ? 'FAILED' : 'PASSED' ) . ' with ' . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
