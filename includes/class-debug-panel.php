<?php
/** Compact Debug Bar summary backed by the shared status model. */
defined( 'ABSPATH' ) || exit;
class HarborCache_Debug_Panel extends Debug_Bar_Panel {
	public function init() { $this->title( 'HarborCache' ); }
	public function prerender() { $this->set_visible( \HarborCache\Console::allowed() ); }
	public function render() {
		$status = \HarborCache\Console::status();
		echo '<ul class="ch-debug-summary">';
		foreach ( array(
			__( 'State', 'harborcache-object-cache-with-redis' )       => \HarborCache\Console::state_label( $status ),
			__( 'Transport', 'harborcache-object-cache-with-redis' )  => (string) ( $status['transport'] ?? 'unknown' ),
			__( 'Namespace', 'harborcache-object-cache-with-redis' )  => (string) ( $status['namespace'] ?? 'unknown' ),
			__( 'Codec', 'harborcache-object-cache-with-redis' )      => (string) ( $status['codec'] ?? 'unknown' ),
			__( 'Drop-in', 'harborcache-object-cache-with-redis' )    => (string) ( $status['dropin'] ?? 'unknown' ),
		) as $label => $value ) {
			echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( \HarborCache\Console::url( 'overview' ) ) . '">' . esc_html__( 'Open readiness', 'harborcache-object-cache-with-redis' ) . '</a></p>';
	}
}
