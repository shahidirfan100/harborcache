<?php
/** Fresh-process checks for renamed paths, early fallback and diagnostic escaping. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-dropin-writer.php';
require_once dirname( __DIR__ ) . '/includes/ch-config.php';
$root = sys_get_temp_dir() . '/hc-review-' . bin2hex( random_bytes( 6 ) );
mkdir( $root );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/object-cache.php' );
$passed = 0;
$assert = static function ( $name, $ok ) use ( &$passed ) {
	if ( ! $ok ) { throw new RuntimeException( $name ); }
	++$passed;
	echo "PASS $name\n";
};
$run = static function ( $code ) use ( $root ) {
	$file = $root . '/probe-' . bin2hex( random_bytes( 4 ) ) . '.php';
	file_put_contents( $file, "<?php\n" . $code );
	$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', $file ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] ); fclose( $pipes[2] );
	$status = proc_close( $process );
	unlink( $file );
	if ( 0 !== $status || '' !== $err ) { throw new RuntimeException( 'Probe failed: ' . $out . $err ); }
	return $out;
};
try {
	$options = array( 'cafile' => '/local/ca.pem', 'verify_peer' => true, 'verify_peer_name' => true );
	$compat = HarborCache\Config::tls_options( $options, 'phpredis', 70433 );
	$assert( 'PHP 7.4 PhpRedis defaults to TLS 1.2 without relaxing certificate verification', isset( $compat['crypto_method'] ) && STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT === $compat['crypto_method'] && true === $compat['verify_peer'] && true === $compat['verify_peer_name'] && $options['cafile'] === $compat['cafile'] );
	$explicit = $options + array( 'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT );
	$assert( 'Explicit TLS policy is preserved on PHP 7.4', $explicit === HarborCache\Config::tls_options( $explicit, 'phpredis', 70433 ) );
	$assert( 'Newer PHP and Predis retain their configured TLS policy', $options === HarborCache\Config::tls_options( $options, 'phpredis', 80200 ) && $options === HarborCache\Config::tls_options( $options, 'predis', 70433 ) );
	// A quote and space exercise safe PHP literals as well as a renamed directory.
	$plugin = $root . "/custom extensions/renamed 'cache";
	mkdir( $plugin . '/includes', 0777, true );
	file_put_contents( $plugin . '/includes/ch-config.php', "<?php\n" );
	$installed = $root . '/object-cache.php';
	$text = HarborCache\Dropin_Writer::render( $source, $plugin );
	file_put_contents( $installed, $text );
	$out = $run( "define('ABSPATH', __DIR__); define('WP_REDIS_DISABLED',true); require " . var_export( $installed, true ) . "; echo HARBORCACHE_PLUGIN_PATH;" );
	$assert( 'Installed drop-in resolves a renamed plugin directory without WordPress helpers', $plugin === $out );
	$assert( 'Installed template comparison is deterministic', $text === HarborCache\Dropin_Writer::render( $source, $plugin ) );
	$assert( 'Path serialization rejects null bytes', false === HarborCache\Dropin_Writer::render( $source, "bad\0path" ) );
	file_put_contents( $installed, HarborCache\Dropin_Writer::render( $source, $root . '/missing' ) );
	$out = $run( "define('ABSPATH', __DIR__); require " . var_export( $installed, true ) . "; echo (!defined('HARBORCACHE_PLUGIN_PATH')&&!function_exists('wp_cache_init'))?'fallback':'failed';" );
	$assert( 'Missing plugin returns to WordPress runtime cache without a stale path constant', 'fallback' === $out );

	// Load the real cache class without making a Redis connection.
	$bootstrap = "define('ABSPATH', __DIR__); define('WP_CONTENT_DIR',__DIR__); define('HARBORCACHE_PLUGIN_PATH'," . var_export( dirname( __DIR__ ), true ) . "); function add_filter(){} function esc_html(\$value){return htmlspecialchars((string)\$value,ENT_QUOTES,'UTF-8');} function number_format_i18n(\$value,\$decimals=0){return number_format(\$value,\$decimals);} require " . var_export( dirname( __DIR__ ) . '/includes/object-cache.php', true ) . "; ";
	$out = $run( $bootstrap . "\$c=(new ReflectionClass('WP_Object_Cache'))->newInstanceWithoutConstructor(); \$c->diagnostics=array('client'=>'<img src=x onerror=alert(1)>'); \$c->stats();" );
	$assert( 'Debug statistics escape an HTML payload from client diagnostics', false === strpos( $out, '<img' ) && false !== strpos( $out, '&lt;img' ) );
	$out = $run( $bootstrap . "\$c=(new ReflectionClass('WP_Object_Cache'))->newInstanceWithoutConstructor(); \$c->diagnostics=array(); \$c->stats();" );
	$assert( 'Debug statistics tolerate a missing client diagnostic without warnings', false !== strpos( $out, 'Unknown' ) );
	echo "RESULT $passed passed, 0 failed\n";
} finally {
	foreach ( glob( $root . '/probe-*.php' ) as $file ) { unlink( $file ); }
	if ( file_exists( $installed ) ) { unlink( $installed ); }
	unlink( $plugin . '/includes/ch-config.php' );
	rmdir( $plugin . '/includes' ); rmdir( $plugin ); rmdir( dirname( $plugin ) ); rmdir( $root );
}
