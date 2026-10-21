<?php
namespace HarborCache {
    class Config {
        public static $sample = 100;
        public static function load() { return array( 'analytics' => array( 'enabled' => true, 'sample_rate' => self::$sample, 'persist_limit' => 1000, 'retention' => 7200 ) ); }
    }
}
namespace HarborCache\Core {
    class Plugin {
        public static $checks = 0;
        public static function instance() { return new self(); }
        public function get_redis_status() { ++self::$checks; return true; }
    }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    define( 'MINUTE_IN_SECONDS', 60 );
    require dirname( __DIR__ ) . '/includes/class-metrics.php';
    $wp_object_cache = new class {
        public function info() { throw new Error( 'Sensitive fixture value must not be logged' ); }
        public function redis_instance() { return $this; }
        public function zremrangebyscore( ...$args ) { throw new Error( 'Sensitive fixture endpoint must not be logged' ); }
        public function build_key( ...$args ) { return 'fixture-metrics'; }
        public function zrangebyscore( ...$args ) { return false; }
        public function zrevrangebyscore( ...$args ) { return false; }
        public function zcount( ...$args ) { return false; }
    };
    $log = tempnam( sys_get_temp_dir(), 'hc-metrics-' );
    $previous = ini_get( 'error_log' );
    ini_set( 'error_log', $log );
    try {
        \HarborCache\Core\Metrics::record();
        \HarborCache\Core\Metrics::discard();
        if ( array() !== \HarborCache\Core\Metrics::get() || array() !== \HarborCache\Core\Metrics::get( null, 10 ) || 0 !== \HarborCache\Core\Metrics::count() ) { throw new RuntimeException( 'False metrics replies were accepted' ); }
        \HarborCache\Config::$sample = 0;
        $before = \HarborCache\Core\Plugin::$checks;
        \HarborCache\Core\Metrics::record();
        if ( $before !== \HarborCache\Core\Plugin::$checks ) { throw new RuntimeException( 'Unsampled requests still inspect the drop-in' ); }
        $text = file_get_contents( $log );
        if ( 1 !== substr_count( $text, '[HarborCache]' ) || false !== strpos( $text, 'Sensitive fixture' ) || false !== strpos( $text, 'Stack trace' ) ) { throw new RuntimeException( 'Metrics logging is not bounded and redacted' ); }
        echo "PASS Metrics collection and cleanup contain Throwable failures\nPASS False sorted-set reads and counts return empty metrics\nPASS Unsampled requests skip drop-in status inspection\nPASS Metrics failure logs are redacted and bounded\n";
    } finally {
        ini_set( 'error_log', $previous );
        unlink( $log );
    }
}
