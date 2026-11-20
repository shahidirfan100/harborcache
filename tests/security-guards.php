<?php
/**
 * Regression guards for the security fixes: namespace validation, prefetch bounds
 * and metrics trimming without cron.
 *
 * php tests/security-guards.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'HARBORCACHE_PLUGIN_PATH', dirname( __DIR__ ) );

require_once dirname( __DIR__ ) . '/includes/ch-config.php';

$passed = 0;
$assert = static function ( $name, $ok ) use ( &$passed ) {
	if ( ! $ok ) { throw new RuntimeException( $name ); }
	++$passed;
	echo "PASS $name\n";
};

$run = static function ( $code ) {
	$file = tempnam( sys_get_temp_dir(), 'hc-guard-' );
	file_put_contents( $file, "<?php\n" . $code );
	$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', $file ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] );
	$status = proc_close( $process );
	unlink( $file );
	if ( 0 !== $status || '' !== $err ) { throw new RuntimeException( 'Probe failed: ' . $out . $err ); }
	return trim( $out );
};

try {
	// A namespace candidate must be a slug: the prefix is a trust boundary.
	$assert( 'A slug is accepted as a namespace', 'site-one_2' === HarborCache\Config::prefix_candidate( 'site-one_2' ) );
	$assert( 'Glob metacharacters are rejected', '' === HarborCache\Config::prefix_candidate( 'site*' ) );
	$assert( 'Key separators and whitespace are rejected', '' === HarborCache\Config::prefix_candidate( "site:one" ) && '' === HarborCache\Config::prefix_candidate( "site one" ) && '' === HarborCache\Config::prefix_candidate( "site\none" ) );
	$assert( 'Non-strings and empty values are rejected', '' === HarborCache\Config::prefix_candidate( null ) && '' === HarborCache\Config::prefix_candidate( array( 'x' ) ) && '' === HarborCache\Config::prefix_candidate( '' ) );
	$assert( 'Overlong namespaces are rejected', '' === HarborCache\Config::prefix_candidate( str_repeat( 'a', 65 ) ) );

	$dropin = file_get_contents( dirname( __DIR__ ) . '/includes/object-cache.php' );
	$assert( 'The request header is validated before it can define a prefix', false !== strpos( $dropin, "Config::prefix_candidate( getenv( 'HTTP_X_APP_USER' ) )" ) );

	// The prefetch gate must keep anonymous traffic out, and still allow a signed-in visitor.
	$bootstrap = "define('ABSPATH', __DIR__); define('WP_CONTENT_DIR',__DIR__); define('HARBORCACHE_PLUGIN_PATH'," . var_export( dirname( __DIR__ ), true ) . ");"
		. " function add_filter(){} function esc_html(\$value){return htmlspecialchars((string)\$value,ENT_QUOTES,'UTF-8');}"
		. " function number_format_i18n(\$value,\$decimals=0){return number_format(\$value,\$decimals);}"
		. " function sanitize_text_field(\$value){return trim((string)\$value);} function wp_unslash(\$value){return \$value;}"
		. " function get_current_user_id(){return USER_ID;}"
		. " \$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REQUEST_URI']='/sample-page/';"
		. " require " . var_export( dirname( __DIR__ ) . '/includes/object-cache.php', true ) . ";";
	$probe = " \$c=(new ReflectionClass('HarborCache_Advanced_Cache'))->newInstanceWithoutConstructor();"
		. " \$m=new ReflectionMethod('HarborCache_Advanced_Cache','ch_prefetchable_request');"
		. " if ( PHP_VERSION_ID < 80100 ) { \$m->setAccessible(true); }"
		. " echo \$m->invoke(\$c)?'yes':'no';";
	$assert( 'Anonymous requests cannot write a prefetch manifest', 'no' === $run( str_replace( 'USER_ID', '0', $bootstrap ) . $probe ) );
	$assert( 'A signed-in page view can still write a manifest', 'yes' === $run( str_replace( 'USER_ID', '7', $bootstrap ) . $probe ) );

	$cache_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-advanced-cache.php' );
	$assert( 'Manifests expire within the hour', false !== strpos( $cache_source, "json_encode( array_map( 'array_values', \$this->ch_prefetch_keys ) ), 'EX', 3600" ) );
	$assert( 'A manifest is capped at 250 tracked entries', false !== strpos( $cache_source, 'COUNT_RECURSIVE ) < 250' ) );

	$metrics = file_get_contents( dirname( __DIR__ ) . '/includes/class-metrics.php' );
	$assert( 'Metrics trim on a sampled write when cron is absent', false !== strpos( $metrics, "if ( 0 === random_int( 0, 99 ) ) { self::discard(); }" ) );

	echo "RESULT $passed passed, 0 failed\n";
} catch ( Throwable $error ) {
	echo 'FAIL ' . $error->getMessage() . "\n";
	echo "RESULT FAILED with 1 failure(s)\n";
	exit( 1 );
}
