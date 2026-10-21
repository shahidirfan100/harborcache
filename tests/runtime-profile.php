<?php
/** CPU-only engine fixture; transport latency and real WordPress are measured separately in CI. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HARBORCACHE_PLUGIN_PATH', $argv[1] ?? dirname( __DIR__ ) );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'WP_REDIS_PREFIX', 'hc-profile:' );
define( 'DB_NAME', 'hc-profile' );
define( 'AUTH_KEY', 'disposable-profile-key' );
define( 'HARBORCACHE_CONFIG', array( 'connector' => 'hc_profile_connector', 'analytics' => array( 'enabled' => false ) ) );
$table_prefix = 'profile_';
function add_filter() {}
function add_action() {}
function do_action() {}
function has_filter() { return false; }
function apply_filters( $name, $value ) { return $value; }
function is_multisite() { return false; }
function hc_profile_connector() { return $GLOBALS['hc_profile_driver']; }
class HC_Profile_Driver {
    public $values = array();
    public $commands = 0;
    public function ping() { return 'PONG'; }
    public function getConnection() { return null; }
    public function rawCommand( ...$args ) {
        ++$this->commands;
        if ( 'GET' === $args[0] ) { return $this->values[ $args[1] ] ?? null; }
        if ( 'SET' === $args[0] ) {
            if ( in_array( 'NX', $args, true ) && isset( $this->values[ $args[1] ] ) ) { return null; }
            $this->values[ $args[1] ] = $args[2];
            return 'OK';
        }
        if ( in_array( $args[0], array( 'SADD', 'EXPIRE' ), true ) ) { return 1; }
        throw new RuntimeException( 'Unexpected profile command.' );
    }
    public function get( $key ) { ++$this->commands; return $this->values[ $key ] ?? false; }
    public function getLastError() { return null; }
    public function isConnected() { return true; }
}
$GLOBALS['hc_profile_driver'] = new HC_Profile_Driver();
require HARBORCACHE_PLUGIN_PATH . '/includes/ch-config.php';
HarborCache\Config::apply();
require HARBORCACHE_PLUGIN_PATH . '/includes/object-cache.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-advanced-cache.php';
class HC_Profile_Cache extends HarborCache_Advanced_Cache {
    public function seed( $value ) { $this->cache[ $this->build_key( 'profile', 'posts' ) ] = $value; }
}
$cache = new HC_Profile_Cache();
$cache->seed( array( 'value' => 42 ) );
$GLOBALS['hc_profile_driver']->values[ $cache->build_key( 'profile', 'posts' ) ] = ( new HarborCache\Codec( $cache->ch_config ) )->encode( array( 'value' => 42 ) );
$results = array();
foreach ( array( 'memory' => 200000, 'forced' => 20000, 'miss' => 20000, 'keys' => 20000 ) as $case => $iterations ) {
    $times = array();
    for ( $round = 0; $round < 7; ++$round ) {
        $start = hrtime( true );
        for ( $i = 0; $i < $iterations; ++$i ) {
            if ( 'keys' === $case ) { $cache->build_key( $i, 'profile-keys' ); }
            else {
                $value = $cache->get( 'miss' === $case ? 'absent' : 'profile', 'posts', 'memory' !== $case, $found );
                if ( 'miss' === $case ? $found : ( ! $found || array( 'value' => 42 ) !== $value ) ) { throw new RuntimeException( 'Profile value mismatch.' ); }
            }
        }
        $times[] = ( hrtime( true ) - $start ) / $iterations;
    }
    sort( $times );
    $results[ $case ] = array( 'median_ns_per_operation' => $times[3], 'iterations' => $iterations, 'samples' => 7 );
}
echo json_encode( array( 'source' => HARBORCACHE_PLUGIN_PATH, 'php' => PHP_VERSION, 'results' => $results, 'limitations' => 'CPU-only fake transport; not a Redis or WordPress performance result.' ) ) . "\n";
