<?php
/** Run through wp eval-file after installing the rendered drop-in. */
$assert = static function ( $name, $condition ) {
	if ( ! $condition ) { throw new RuntimeException( $name ); }
	echo "PASS $name\n";
};
$cache = $GLOBALS['wp_object_cache'];
$assert( 'Rendered drop-in loads the HarborCache engine', $cache instanceof HarborCache_Advanced_Cache );
$assert( 'Installed drop-in matches the currently located template', HarborCache\Console::dropin_current() );
$assert( 'Canonical drop-in location respects the configured content directory', WP_CONTENT_DIR . '/object-cache.php' === HarborCache\Paths::content( 'object-cache.php' ) );
$default = HarborCache\Config::validate( HarborCache\Config::defaults() );
$assert( 'Default prefix identity is unchanged by path centralization', substr( hash( 'sha256', WP_CONTENT_DIR ), 0, 16 ) === $default['prefix'] );
$client = new ReflectionClass( 'Predis\Client' );
$assert( 'Predis loads from the actual plugin location', realpath( HARBORCACHE_PLUGIN_PATH . '/vendor/predis/predis/src/Client.php' ) === realpath( $client->getFileName() ) );
$diagnostics = $cache->diagnostics;
$cache->diagnostics['client'] = '<img src=x onerror=alert(1)>';
ob_start(); $cache->stats(); $stats = ob_get_clean();
$cache->diagnostics = $diagnostics;
$assert( 'Diagnostic output is escaped by real WordPress functions', false === strpos( $stats, '<img' ) && false !== strpos( $stats, '&lt;img' ) );
$value = array( 'typed' => false, 'nested' => array( 1, 'two' ) );
$assert( 'Object cache preserves typed values through the installed engine', wp_cache_set( 'review-typed', $value, 'harborcache-review', 60 ) && $value === wp_cache_get( 'review-typed', 'harborcache-review', false, $found ) && $found );
wp_cache_delete( 'review-typed', 'harborcache-review' );
echo "RESULT 7 passed, 0 failed\n";
