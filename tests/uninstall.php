<?php
/** Disposable uninstall check. Run with: wp eval-file tests/uninstall.php */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$hc_content = WP_CONTENT_DIR;
$hc_config  = $hc_content . '/harborcache-config.php';
$hc_dropin  = $hc_content . '/object-cache.php';
$hc_foreign = $hc_content . '/harborcache-foreign.php';

file_put_contents( $hc_config, "<?php\n// HarborCache configuration. Disposable test.\ndefined( 'ABSPATH' ) || exit;\nreturn array();\n" );
file_put_contents( $hc_dropin, "<?php\n// HarborCache Drop-In\nclass HarborCache_Advanced_Cache {}\n" );
file_put_contents( $hc_foreign, 'keep me' );
update_user_meta( 1, '_harborcache_connection_probe', array( 'checked_at' => time() ) );

define( 'WP_UNINSTALL_PLUGIN', true );
$hc_uninstall = getenv( 'HC_UNINSTALL_FILE' ) ?: dirname( __DIR__ ) . '/uninstall.php';
include $hc_uninstall;

$hc_failures = 0;
$hc_gate = static function ( $name, $pass ) use ( &$hc_failures ) {
	if ( ! $pass ) { ++$hc_failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . "\n";
};

$hc_gate( 'Owned config removed', ! file_exists( $hc_config ) );
$hc_gate( 'Owned drop-in removed', ! file_exists( $hc_dropin ) );
$hc_gate( 'Foreign file preserved', file_exists( $hc_foreign ) );
$hc_gate( 'Probe user meta removed', '' === get_user_meta( 1, '_harborcache_connection_probe', true ) );
$hc_gate( 'Metrics migration option removed', false === get_option( 'harborcache_metrics_cron_migrated' ) );

if ( file_exists( $hc_foreign ) ) {
	unlink( $hc_foreign );
}

echo 'RESULT ' . ( $hc_failures ? 'FAILED' : 'PASSED' ) . ' with ' . $hc_failures . " failure(s)\n";
if ( $hc_failures ) {
	throw new RuntimeException( 'Uninstall checks failed.' );
}
