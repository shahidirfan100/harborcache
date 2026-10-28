<?php
/** Dashboard rendering checks. Run with: wp eval-file tests/console-render.php */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$hc_failures = 0;
function hc_console_gate( $name, $pass, $detail = '' ) {
	global $hc_failures;
	if ( ! $pass ) { ++$hc_failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

wp_set_current_user( 1 );

$_GET['view'] = 'overview';
ob_start();
HarborCache\Console::render();
$overview = ob_get_clean();

$_GET['view'] = 'connection';
ob_start();
HarborCache\Console::render();
$settings = ob_get_clean();

$_GET['view'] = 'diagnostics';
ob_start();
HarborCache\Console::render();
$diagnostics = ob_get_clean();

$all = $overview . $settings . $diagnostics;

hc_console_gate( 'No em dash is rendered', false === strpos( $all, "\xE2\x80\x94" ) );
hc_console_gate( 'The brand name keeps its registered spelling', false !== strpos( $overview, 'HarborCache – Object Cache with Redis' ) );
hc_console_gate( 'No bare "Not active" state', false === strpos( $all, 'Not active' ) );
hc_console_gate( 'No duplicate Overview heading', false === strpos( $overview, '>Overview<' ) );
hc_console_gate( 'Five tabs, no Tools drawer', false === strpos( $all, '>Tools<' ) && false !== strpos( $all, '>Status<' ) && false !== strpos( $all, '>Settings<' ) && false !== strpos( $all, '>Cache<' ) && false !== strpos( $all, '>Diagnostics<' ) );
hc_console_gate( 'The state has a live region', false !== strpos( $overview, 'class="ch-status' ) && false !== strpos( $overview, 'role="status"' ) );
hc_console_gate( 'Old composite metric labels are gone', false === strpos( $overview, 'Combined hit ratio' ) && false === strpos( $overview, 'Runtime cache (bytes)' ) );
hc_console_gate( 'Clear buttons carry the action-specific confirmation', false === strpos( $all, 'data-ch-confirm="1"' ) );
hc_console_gate( 'Settings exposes a saved-connection test', false !== strpos( $settings, 'id="ch-test-form"' ) && false !== strpos( $settings, 'Test saved connection' ) );
hc_console_gate( 'Advanced settings are collapsed', false !== strpos( $settings, 'Advanced settings' ) && false !== strpos( $settings, '<details' ) );
hc_console_gate( 'The probe result is announced', false !== strpos( $settings, 'id="ch-probe-result"' ) && false !== strpos( $settings, 'aria-live="polite"' ) );
hc_console_gate( 'Diagnostics keeps the raw report behind a summary', false !== strpos( $diagnostics, 'Show raw report' ) );
hc_console_gate( 'Diagnostics keeps the copy control', false !== strpos( $diagnostics, 'id="ch-copy"' ) );
hc_console_gate( 'Empty dead notice container is gone', false === strpos( $all, 'class="ch-notices"' ) );

echo 'RESULT ' . ( $hc_failures ? 'FAILED' : 'PASSED' ) . ' with ' . $hc_failures . " failure(s)\n";
if ( $hc_failures ) {
	throw new RuntimeException( 'Console rendering checks failed.' );
}
