<?php
/** Isolated command-fault fixtures; real transports are covered in Linux acceptance. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HARBORCACHE_PLUGIN_PATH', dirname( __DIR__ ) );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'WP_REDIS_PREFIX', 'hc-safety:' );
define( 'DB_NAME', 'safety-fixture' );
define( 'AUTH_KEY', 'disposable-safety-key' );
define( 'HARBORCACHE_CONFIG', array( 'connector' => 'hc_safety_connector', 'analytics' => array( 'enabled' => false ) ) );
$table_prefix = 'safety_';
function add_filter() {}
function add_action() {}
function do_action() {}
function apply_filters( $name, $value ) { return $value; }
function is_multisite() { return ! empty( $GLOBALS['hc_multisite'] ); }
function hc_safety_connector() { return $GLOBALS['hc_safety_driver']; }
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-config.php';
\HarborCache\Config::apply();
require_once HARBORCACHE_PLUGIN_PATH . '/includes/object-cache.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-advanced-cache.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-predis.php';

class HC_Safety_Driver {
    public $values = array();
    public $info_calls = 0;
    public $deny_info = false;
    public $false_info = false;
    public $fail_root_write = false;
    public $deny_root_read = false;
    public $last_error = '';
    public function ping() { return 'PONG'; }
    public function getConnection() { return null; }
    public function info( ...$args ) {
        ++$this->info_calls;
        if ( $this->deny_info ) { throw new RuntimeException( 'INFO denied' ); }
        if ( $this->false_info ) { $this->last_error = 'NOPERM INFO'; return false; }
        return array( 'redis_version' => '7.0.0' );
    }
    public function getLastError() { return $this->last_error; }
    public function isConnected() { return true; }
    public function clearLastError() { $this->last_error = ''; }
    public function rawCommand( ...$args ) {
        $command = strtoupper( array_shift( $args ) );
        $key = $args[0] ?? '';
        if ( 'GET' === $command ) {
            if ( $this->deny_root_read && 'hc-safety:meta:root' === $key ) { $this->last_error = 'ERR denied'; return false; }
            return $this->values[ $key ] ?? null;
        }
        if ( 'SET' === $command ) {
            if ( $this->fail_root_write && 'hc-safety:meta:root' === $key ) { return 0; }
            if ( in_array( 'NX', $args, true ) && isset( $this->values[ $key ] ) ) { return null; }
            $this->values[ $key ] = $args[1];
            return 'OK';
        }
        if ( 'EXPIRE' === $command || 'SADD' === $command ) { return 1; }
        throw new RuntimeException( 'Unexpected fixture command: ' . $command );
    }
    public function set( ...$args ) { return $this->rawCommand( 'SET', ...$args ); }
}

class HC_Routing_Fixture {
    use HarborCache_Routing;
    public $ch_config = array( 'servers' => array(), 'sentinels' => array() );
    private $ch_relay_bypass = false;
    private $ch_native_nodes = array( 'fixture' );
    private $ch_native_clients = array();
    private $ch_native_primary;
    public function redis_instance() { return new stdClass(); }
    public function native_topology_read( $method, $args, $primary ) { return $primary ? 'primary' : 'replica'; }
    public function read( $keys ) { return $this->routed_read( is_array( $keys ) ? 'mget' : 'get', array( $keys ), static function () { return 'primary'; } ); }
}

class HC_Nested_Bypass_Fixture {
    use HarborCache_Native;
    public $ch_relay_bypass = false;
    public function redis_instance() { return new stdClass(); }
    public function redis_status() { return false; }
    public function read( callable $call ) { return $this->relay_uncached_read( $call ); }
}

class HC_Native_Read_Fixture {
    use HarborCache_Topology;
    public function read( $client ) { return $this->native_data_read( $client, 'get', array( 'key' ) ); }
}

class HC_Failed_Read {
    public $closed = 0;
    public function get( $key ) { return false; }
    public function getLastError() { return 'ERR connection read failed'; }
    public function close() { ++$this->closed; }
}

$log = tempnam( sys_get_temp_dir(), 'hc-safety-' );
$previous_log = ini_set( 'error_log', $log );
$passed = 0;
$failed = 0;
$check = static function ( $name, $ok ) use ( &$passed, &$failed ) {
    if ( $ok ) { ++$passed; } else { ++$failed; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
};
$identity = hash( 'sha256', DB_NAME . ':' . $table_prefix . ':' . AUTH_KEY );
$driver = static function () use ( $identity ) {
    $client = new HC_Safety_Driver();
    $client->values['hc-safety:meta:owner'] = $identity;
    return $GLOBALS['hc_safety_driver'] = $client;
};
try {
    $client = $driver();
    $cache = new HarborCache_Advanced_Cache();
    $check( 'Startup does not fetch optional server information', $cache->redis_status() && 0 === $client->info_calls );

    $client = $driver();
    $client->deny_info = true;
    $cache = new HarborCache_Advanced_Cache();
    $first = $cache->redis_version();
    $second = $cache->redis_version();
    $check( 'Denied INFO is attempted once and leaves data caching connected', null === $first && null === $second && $cache->redis_status() && 1 === $client->info_calls );
    $client = $driver();
    $client->false_info = true;
    $cache = new HarborCache_Advanced_Cache();
    $check( 'False INFO replies cannot poison later cache misses', null === $cache->redis_version() && '' === $client->last_error && $cache->redis_status() );

    $client = $driver();
    $client->values['hc-safety:meta:owner'] = str_repeat( 'f', 64 );
    $client->values['hc-safety:meta:root'] = str_repeat( 'a', 24 );
    $before = $client->values;
    $cache = new HarborCache_Advanced_Cache();
    $check( 'Foreign prefix owner and root remain untouched', ! $cache->redis_status() && $before === $client->values );
    $probe = new \HarborCache\Core\Predis();
    $probe->connect();
    $rejected = false;
    try { $probe->invalidate_namespace(); } catch ( Throwable $error ) { $rejected = true; }
    $check( 'Enable probe refuses to rotate a foreign owner namespace', $rejected && $before === $client->values );

    $client = $driver();
    $client->values['hc-safety:meta:root'] = 'corrupt';
    $cache = new HarborCache_Advanced_Cache();
    $check( 'Corrupt namespace metadata is not accepted or replaced', ! $cache->redis_status() && 'corrupt' === $client->values['hc-safety:meta:root'] );

    $client = $driver();
    $client->fail_root_write = true;
    $cache = new HarborCache_Advanced_Cache();
    $check( 'Malformed namespace SET reply cannot initialize the cache', ! $cache->redis_status() );

    $client = $driver();
    $client->deny_root_read = true;
    $client->values['hc-safety:meta:root'] = str_repeat( 'b', 24 );
    $cache = new HarborCache_Advanced_Cache();
    $check( 'A rejected metadata read cannot masquerade as a cache miss', ! $cache->redis_status() && str_repeat( 'b', 24 ) === $client->values['hc-safety:meta:root'] );

    $routes = new HC_Routing_Fixture();
    $nested = new HC_Nested_Bypass_Fixture();
    $nested_state = $nested->read( static function () use ( $nested ) {
        $inner = $nested->read( static function () use ( $nested ) { return $nested->ch_relay_bypass; } );
        return $inner && $nested->ch_relay_bypass;
    } );
    $check( 'Nested Relay metadata reads preserve the outer forced-read bypass', $nested_state && ! $nested->ch_relay_bypass );
    $check( 'Options reads use the primary before any request write', 'primary' === $routes->read( 'hc:meta:token:site-1:epoch:g:options:version:v:key' ) );
    $check( 'Network option reads use the primary', 'primary' === $routes->read( 'hc:meta:token:global:epoch:g:site-options:version:v:key' ) );
    $check( 'Bulk containing critical options uses the primary', 'primary' === $routes->read( array( 'other', 'hc:meta:token:site-1:epoch:g:options:version:v:key' ) ) );
    $check( 'Ordinary groups retain configured replica routing', 'replica' === $routes->read( 'hc:meta:token:site-1:epoch:g:posts:version:v:key' ) );
    $broken = new HC_Failed_Read();
    $rejected = false;
    try { ( new HC_Native_Read_Fixture() )->read( $broken ); } catch ( Throwable $error ) { $rejected = true; }
    $check( 'Native read failure discards a socket instead of returning a false miss', $rejected && 1 === $broken->closed );
    $GLOBALS['hc_multisite'] = true;
    $wpdb = (object) array( 'base_prefix' => 'network_' );
    $table_prefix = 'network_2_';
    $left = \HarborCache\Namespace_Guard::identity();
    $table_prefix = 'network_3_';
    $check( 'Multisite prefix ownership is stable across network sites', $left === \HarborCache\Namespace_Guard::identity() );
    $GLOBALS['hc_multisite'] = false;
    $table_prefix = 'safety_';
    $text = file_get_contents( $log );
    $check( 'Normal fallback logs are concise and contain no exception traces', false === strpos( $text, 'Stack trace:' ) && substr_count( $text, '[HarborCache]' ) <= 1 );
    $client = $driver();
    $cache = new HarborCache_Advanced_Cache();
    $cache->ch_config['debug'] = true;
    $handler = new ReflectionMethod( $cache, 'handle_exception' );
    if ( PHP_VERSION_ID < 80100 ) { $handler->setAccessible( true ); }
    $handler->invoke( $cache, new RuntimeException( 'Sensitive fixture endpoint must be omitted' ) );
    $text = file_get_contents( $log );
    $check( 'Explicit debug logging retains redacted traces', false !== strpos( $text, 'Stack trace:' ) && false === strpos( $text, 'Sensitive fixture endpoint' ) );
} finally {
    ini_set( 'error_log', $previous_log );
    unlink( $log );
}
echo "RESULT $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
