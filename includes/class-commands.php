<?php
/** WP-CLI commands. */
namespace HarborCache;

defined( 'ABSPATH' ) || exit;

class Commands {
	public function status() { \WP_CLI::line( wp_json_encode( Console::status(), JSON_PRETTY_PRINT ) ); }
	public function doctor() {
		$status = Console::status( true );
		\WP_CLI::line( wp_json_encode( $status, JSON_PRETTY_PRINT ) );
		if ( empty( $status['ready'] ) ) { \WP_CLI::halt( 1 ); }
	}
	public function self_test() {
		require_once __DIR__ . '/class-self-test.php';
		$result = Self_Test::run();
		\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		if ( empty( $result['ok'] ) ) { \WP_CLI::halt( 1 ); }
	}
	public function reclaim( $args, $assoc ) {
		if ( empty( $assoc['yes'] ) ) { \WP_CLI::error( 'Pass --yes after confirming the old installation no longer uses this prefix.' ); }
		require_once __DIR__ . '/class-connection-check.php';
		$result = Connection_Check::reclaim( Config::load() );
		\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		if ( empty( $result['ok'] ) ) { \WP_CLI::halt( 1 ); }
	}
	public function diagnostics() { \WP_CLI::line( wp_json_encode( array( 'status' => Console::status(), 'options' => Console::read( 'options' ) ), JSON_PRETTY_PRINT ) ); }
	public function enable() { $this->run( 'enable' ); }
	public function disable() { $this->run( 'disable' ); }
	public function update() { $this->run( 'update' ); }
	public function flush( $args, $assoc ) { $this->run( isset( $assoc['group'] ) ? 'flush-group' : ( isset( $assoc['site'] ) ? 'flush-site' : 'flush' ), $assoc ); }
	public function groups( $args, $assoc ) { $cache = Console::cache(); $data = ! empty( $assoc['group'] ) && $cache ? $cache->group_diagnostics( $assoc['scope'] ?? ( 'site-' . get_current_blog_id() ), $assoc['group'], isset( $assoc['memory'] ) ) : Console::read( 'groups' ); \WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) ); }
	public function cleanup() { $this->run( 'cleanup' ); }
	public function slowlog() { \WP_CLI::line( wp_json_encode( Console::read( 'slowlog' ), JSON_PRETTY_PRINT ) ); }
	public function commands() { \WP_CLI::line( wp_json_encode( Console::read( 'commands' ), JSON_PRETTY_PRINT ) ); }
	public function analytics( $args, $assoc ) { $rows = Core\Metrics::get( Analytics::window( $assoc['window'] ?? 7200 ), 1000 ); \WP_CLI::line( isset( $assoc['csv'] ) ? Analytics::csv( $rows ) : wp_json_encode( Analytics::summarize( $rows, $assoc['interval'] ?? 300 ), JSON_PRETTY_PRINT ) ); }
	public function digest( $args, $assoc ) { $this->analytics( $args, $assoc ); }
	public function aggregate( $args, $assoc ) { $this->analytics( $args, $assoc ); }
	public function watch( $args, $assoc ) { $iterations = max( 1, min( 10000, (int) ( $assoc['iterations'] ?? 10 ) ) ); $interval = max( 1, min( 60, (int) ( $assoc['interval'] ?? 2 ) ) ); for ( $i = 0; $i < $iterations; ++$i ) { $summary = Analytics::summarize( Core\Metrics::get( Analytics::window( $assoc['window'] ?? 7200 ), 1000 ) ); unset( $summary['buckets'] ); $summary['observed_at'] = gmdate( 'c' ); \WP_CLI::line( wp_json_encode( $summary ) ); if ( $i + 1 < $iterations ) { sleep( $interval ); } } }
	public function latency() { if ( ! Console::cache() ) { \WP_CLI::error( 'Cache is disconnected.' ); } \WP_CLI::line( wp_json_encode( Console::cache()->node_diagnostics(), JSON_PRETTY_PRINT ) ); }
	public function health() { if ( ! Console::cache() ) { \WP_CLI::error( 'Cache is disconnected.' ); } \WP_CLI::line( wp_json_encode( Console::cache()->operational_health(), JSON_PRETTY_PRINT ) ); }
	public function relay( $args, $assoc ) { if ( ! Console::cache() ) { \WP_CLI::error( 'Cache is disconnected.' ); } \WP_CLI::line( wp_json_encode( Console::cache()->relay_diagnostics( $assoc['key'] ?? '', $assoc['group'] ?? 'default' ), JSON_PRETTY_PRINT ) ); }
	public function queries() { $this->run( 'query-maintenance' ); }
	private function run( $name, $args = array() ) { try { if ( false === Console::execute( $name, $args ) ) { \WP_CLI::error( 'Operation failed.' ); } \WP_CLI::success( 'Operation completed.' ); } catch ( \Throwable $error ) { \WP_CLI::error( 'Operation failed. Check filesystem access and Redis configuration.' ); } }
}
