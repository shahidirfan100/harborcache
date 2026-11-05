<?php
/**
 * Connection probe routing for Cluster and replica topologies.
 *
 * php tests/probe-topology.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HARBORCACHE_PLUGIN_PATH', dirname( __DIR__ ) );
define( 'WP_CONTENT_DIR', __DIR__ );

require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-config.php';
require_once HARBORCACHE_PLUGIN_PATH . '/vendor/predis/predis/autoload.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-connection-check.php';

if ( ! function_exists( '__' ) ) { function __( $text, $domain = null ) { return $text; } }

/** Stands in for a Predis client so the probe's node selection is observable. */
class HC_Probe_Topology_Client extends \Predis\Client {
	public $label;
	public $calls = array();
	public $node;
	public function __construct( $label ) { $this->label = $label; }
	public function connect() {}
	public function disconnect() {}
	public function getClientBy( $selector, $value ) {
		$this->calls[] = 'select:' . strtolower( $selector ) . ':' . $value;
		return $this->node ?: $this;
	}
	public function executeRaw( array $arguments, &$error = null ) {
		$command = strtoupper( (string) $arguments[0] );
		$key     = (string) ( $arguments[1] ?? '' );
		$this->calls[] = $command;
		if ( 'PING' === $command ) { return 'PONG'; }
		if ( 'INFO' === $command ) { return "redis_version:7.4.0\r\n"; }
		if ( 'GET' === $command && false !== strpos( $key, ':probe:' ) ) { return '1'; }
		if ( 'GET' === $command ) { return null; }
		if ( 'DEL' === $command ) { return 1; }
		return 'OK';
	}
}

$failures = 0;
function hc_topology_gate( $name, $pass, $detail = '' ) {
	global $failures;
	if ( ! $pass ) { ++$failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

$base = HarborCache\Config::defaults();
$base['timeout'] = 0.2;

$cluster = new HC_Probe_Topology_Client( 'cluster' );
$node    = new HC_Probe_Topology_Client( 'node' );
$cluster->node = $node;
$config = array_replace( $base, array( 'cluster' => array( 'tcp://10.0.0.1:6379' ), 'connector' => static function () use ( $cluster ) { return $cluster; } ) );
$result = HarborCache\Connection_Check::run( $config, 'test' );
hc_topology_gate( 'Cluster probe selects a slot node', in_array( 'select:slot:0', $cluster->calls, true ), implode( ',', $cluster->calls ) );
hc_topology_gate( 'Cluster keyless commands use the node', in_array( 'PING', $node->calls, true ) && in_array( 'INFO', $node->calls, true ) );
hc_topology_gate( 'Cluster keyed commands use the cluster client', in_array( 'SET', $cluster->calls, true ) && in_array( 'GET', $cluster->calls, true ) && in_array( 'DEL', $cluster->calls, true ) );
hc_topology_gate( 'Cluster probe passes', true === $result['ok'], $result['message'] );

$replica = new HC_Probe_Topology_Client( 'replica' );
$primary = new HC_Probe_Topology_Client( 'primary' );
$replica->node = $primary;
$config = array_replace( $base, array( 'sentinels' => array( 'tcp://10.0.0.2:26379' ), 'service' => 'hc', 'connector' => static function () use ( $replica ) { return $replica; } ) );
$result = HarborCache\Connection_Check::run( $config, 'test' );
hc_topology_gate( 'Replica probe selects the primary', in_array( 'select:role:master', $replica->calls, true ), implode( ',', $replica->calls ) );
hc_topology_gate( 'Replica probe runs every check on the primary', in_array( 'PING', $primary->calls, true ) && in_array( 'GET', $primary->calls, true ) && in_array( 'SET', $primary->calls, true ) && in_array( 'DEL', $primary->calls, true ) );
hc_topology_gate( 'Replica probe does not use a slot node', false === in_array( 'select:slot:0', $replica->calls, true ) );
hc_topology_gate( 'Replica probe passes', true === $result['ok'], $result['message'] );

echo 'RESULT ' . ( $failures ? 'FAILED' : 'PASSED' ) . ' with ' . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
