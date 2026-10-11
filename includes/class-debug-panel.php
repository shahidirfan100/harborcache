<?php
/** Local Debug Bar summary. */
defined( 'ABSPATH' ) || exit;
class HarborCache_Debug_Panel extends Debug_Bar_Panel {
	public function init() { $this->title( 'HarborCache' ); }
	public function prerender() { $this->set_visible( \HarborCache\Console::allowed() ); }
	public function render() { echo '<pre>' . esc_html( wp_json_encode( \HarborCache\Console::status(), JSON_PRETTY_PRINT ) ) . '</pre>'; }
}
