<?php
/**
 * Main plugin class.
 *
 * @package HarborCache\Core
 */

namespace HarborCache\Core;

defined( 'ABSPATH' ) || exit;

require_once HARBORCACHE_PLUGIN_PATH . '/includes/class-status.php';

/**
 * Keeps the integration hooks and the public status helpers.
 *
 * The dashboard, settings and lifecycle work lives in HarborCache\Console.
 */
class Plugin {
	/** @var string */
	private $page = '';

	/** @var Plugin|null */
	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->page = is_multisite() ? 'settings.php?page=harborcache' : 'options-general.php?page=harborcache';

		$this->add_actions_and_filters();
	}

	public function add_actions_and_filters() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_admin_menu_page' ) );
		add_filter( sprintf( '%splugin_action_links_%s', is_multisite() ? 'network_admin_' : '', HARBORCACHE_BASENAME ), array( $this, 'add_plugin_actions_links' ) );

		add_filter( 'qm/collectors', array( $this, 'register_qm_collector' ), 25 );
		add_filter( 'qm/outputter/html', array( $this, 'register_qm_output' ) );

		add_filter( 'perflab_disable_object_cache_dropin', '__return_true' );
		add_filter( 'w3tc_config_item_objectcache.enabled', '__return_false' );
		add_action( 'litespeed_init', array( $this, 'litespeed_disable_objectcache' ) );
	}

	/** Keep the analytics trim short so the sorted set cannot grow for an hour. */
	public function init() {
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( 'harborcache_metrics_discard' ) : null;
		if ( $event && 'harborcache_minute' !== $event->schedule ) {
			wp_clear_scheduled_hook( 'harborcache_metrics_discard' );
			$event = null;
		}

		if ( Metrics::is_enabled() ) {
			if ( ! $event ) {
				wp_schedule_event( time(), 'harborcache_minute', 'harborcache_metrics_discard' );
			}
		} elseif ( $event ) {
			wp_clear_scheduled_hook( 'harborcache_metrics_discard' );
		}
	}

	public function add_admin_menu_page() {
		add_submenu_page(
			is_multisite() ? 'settings.php' : 'options-general.php',
			__( 'HarborCache – Object Cache with Redis', 'harborcache-object-cache-with-redis' ),
			__( 'HarborCache', 'harborcache-object-cache-with-redis' ),
			$this->manage_redis_capability(),
			'harborcache',
			array( $this, 'show_admin_page' )
		);
	}

	public function show_admin_page() {
		\HarborCache\Console::render();
	}

	public function add_plugin_actions_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			network_admin_url( $this->page ),
			esc_html__( 'Settings', 'harborcache-object-cache-with-redis' )
		);

		return array_merge( array( $settings ), $links );
	}

	public function register_qm_collector( array $collectors ) {
		$collectors['cache'] = new QM_Collector();

		return $collectors;
	}

	public function register_qm_output( $output ) {
		$collector = \QM_Collectors::get( 'cache' );

		if (
			$collector instanceof QM_Collector &&
			method_exists( 'QM_Output_Html', 'before_non_tabular_output' )
		) {
			$output['cache'] = new QM_Output( $collector );
		}

		return $output;
	}

	public function object_cache_dropin_exists() {
		return file_exists( \HarborCache\Paths::content( 'object-cache.php' ) );
	}

	public function validate_object_cache_dropin() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! $this->object_cache_dropin_exists() ) {
			return false;
		}

		$dropin = get_plugin_data( \HarborCache\Paths::content( 'object-cache.php' ) );
		$plugin = get_plugin_data( HARBORCACHE_PLUGIN_PATH . '/includes/object-cache.php' );

		return apply_filters(
			'redis_cache_validate_dropin',
			$dropin['PluginURI'] === $plugin['PluginURI'],
			$dropin['PluginURI'],
			$plugin['PluginURI']
		);
	}

	public function get_status() {
		return \HarborCache\Status::label( \HarborCache\Status::read() );
	}

	/**
	 * True only when the cache can serve persistent reads and writes.
	 *
	 * @return bool|null Null when caching is disabled.
	 */
	public function get_redis_status() {
		$status = \HarborCache\Status::read();
		if ( 'disabled' === ( $status['state'] ?? '' ) ) {
			return null;
		}
		return ! empty( $status['ready'] );
	}

	public function get_diagnostics() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) || ! property_exists( $wp_object_cache, 'diagnostics' ) ) {
			return null;
		}

		return $wp_object_cache->diagnostics;
	}

	public function manage_redis_capability() {
		if ( defined( 'WP_REDIS_MANAGER_CAPABILITY' ) && WP_REDIS_MANAGER_CAPABILITY ) {
			return WP_REDIS_MANAGER_CAPABILITY;
		}

		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';

		return apply_filters( 'redis_cache_manager_capability', $capability );
	}

	public function current_user_can_manage_redis() {
		return current_user_can( $this->manage_redis_capability() );
	}

	/** Prevent LiteSpeed Cache from replacing the active object-cache drop-in. */
	public function litespeed_disable_objectcache() {
		/* phpcs:disable WordPress.Security.NonceVerification.Missing -- This only prevents LiteSpeed from replacing the drop-in, it does not run a HarborCache action. */
		if ( isset( $_POST['LSCWP_CTRL'], $_POST['LSCWP_NONCE'], $_POST['object'] )
			&& is_scalar( $_POST['LSCWP_CTRL'] )
			&& is_scalar( $_POST['LSCWP_NONCE'] )
			&& is_scalar( $_POST['object'] )
			&& '' !== sanitize_text_field( wp_unslash( (string) $_POST['LSCWP_CTRL'] ) )
			&& '' !== sanitize_text_field( wp_unslash( (string) $_POST['LSCWP_NONCE'] ) )
			&& '' !== sanitize_text_field( wp_unslash( (string) $_POST['object'] ) ) ) {
			$_POST['object'] = '0';
		}
		/* phpcs:enable WordPress.Security.NonceVerification.Missing */
	}
}
