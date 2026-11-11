<?php
/**
 * Arbitration predicate: either activation order must identify one owner.
 *
 * php tests/conflict-arbitration.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-conflict.php';

$failures = 0;
function hc_conflict_gate( $name, $pass ) {
	global $failures;
	if ( ! $pass ) { ++$failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . "\n";
}

$own_file = dirname( __DIR__ ) . '/harborcache.php';
$own_path = dirname( __DIR__ );
$foreign  = __DIR__ . '/fixtures/other-cache/other-cache.php';

hc_conflict_gate( 'No manager constants and no drop-in is not a conflict', false === HarborCache\Core\Conflict::is_foreign( null, null, $own_file, $own_path, '' ) );
hc_conflict_gate( 'Our own drop-in is not a conflict', false === HarborCache\Core\Conflict::is_foreign( null, null, $own_file, $own_path, "<?php\n * Drop-In Name: HarborCache Drop-In\n" ) );
hc_conflict_gate( 'A foreign drop-in is a conflict', true === HarborCache\Core\Conflict::is_foreign( null, null, $own_file, $own_path, "<?php\n * Drop-In Name: Another Cache\n" ) );
hc_conflict_gate( 'A manager loaded first is a conflict', true === HarborCache\Core\Conflict::is_foreign( $foreign, $own_path, $own_file, $own_path, '' ) );
hc_conflict_gate( 'A manager loaded after HarborCache is a conflict', true === HarborCache\Core\Conflict::is_foreign( $own_file, dirname( $foreign ), $own_file, $own_path, "<?php\n * Drop-In Name: HarborCache Drop-In\n" ) );

echo 'RESULT ' . ( $failures ? 'FAILED' : 'PASSED' ) . ' with ' . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
