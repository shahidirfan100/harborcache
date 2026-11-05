<?php
/**
 * Query maintenance must rotate only groups whose data changed.
 *
 * php tests/query-pruning.php
 */
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['hc_last_changed'] = array( 'posts' => 100.0, 'terms' => 100.0 );
function wp_cache_get_last_changed( $group ) {
	return $GLOBALS['hc_last_changed'][ $group ] ?? 0.0;
}

require_once dirname( __DIR__ ) . '/includes/ch-operations.php';

class HC_Query_Pruning_Fixture {
	use HarborCache_Operations;

	const CH_GROUP_CAP = 1000;
	public $ch_config = array( 'query_maintenance' => true, 'gc_budget_ms' => 1000, 'maxttl' => 86400 );
	public $ch_base = 'ch:meta';
	public $ch_root = 'root';
	public $rotated = array();
	public $state = '';

	public function redis_status() { return true; }
	public function flush_runtime() {}
	public function ch_token( $key ) { return 'token'; }
	public function ch_reclaim( ...$args ) { return 1; }
	public function ch_rotate( $key ) { $this->rotated[] = $key; return 'new'; }

	public function ch_command( array $args ) {
		if ( 'GET' === $args[0] ) { return $this->state; }
		if ( 'SSCAN' === $args[0] ) { return array( '0', array( 'site-1|posts-queries', 'site-1|terms-queries' ) ); }
		if ( 'SET' === $args[0] ) { $this->state = $args[2]; return 'OK'; }
		return 1;
	}
}

$failures = 0;
function hc_query_gate( $name, $pass, $detail = '' ) {
	global $failures;
	if ( ! $pass ) { ++$failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

$fixture = new HC_Query_Pruning_Fixture();
$first = $fixture->maintain_queries();
hc_query_gate( 'First run rotates both query groups', 2 === $first && 2 === count( $fixture->rotated ), (string) $first );

$fixture->rotated = array();
$second = $fixture->maintain_queries();
hc_query_gate( 'Unchanged data rotates nothing', 0 === $second && array() === $fixture->rotated, (string) $second );

$GLOBALS['hc_last_changed']['posts'] = 200.0;
$fixture->rotated = array();
$third = $fixture->maintain_queries();
hc_query_gate( 'Changed data rotates only its query group', 1 === $third && 1 === count( $fixture->rotated ), (string) $third );
hc_query_gate( 'Only the posts query group rotated', 1 === preg_match( '/posts-queries:epoch$/', $fixture->rotated[0] ?? '' ), (string) ( $fixture->rotated[0] ?? '' ) );

echo 'RESULT ' . ( $failures ? 'FAILED' : 'PASSED' ) . ' with ' . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
