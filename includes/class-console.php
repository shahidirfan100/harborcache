<?php
/** HarborCache administration, protected admin actions and lifecycle controls. */
namespace HarborCache;

defined( 'ABSPATH' ) || exit;

// Enabling and saving settings must also work before the drop-in is installed.
require_once __DIR__ . '/ch-codec.php';
require_once __DIR__ . '/class-analytics.php';
require_once __DIR__ . '/class-dropin-writer.php';

final class Console {
	private static $dropin_owned;
	private static $dropin_matches;
	public static function reset_dropin_state() { self::$dropin_owned = null; self::$dropin_matches = null; }
	public static function capability() { $default = is_multisite() ? 'manage_network_options' : 'manage_options'; return apply_filters( 'harborcache_manager_capability', $default ); }
	public static function allowed() { return current_user_can( self::capability() ); }
	public static function cache() { return isset( $GLOBALS['wp_object_cache'] ) && $GLOBALS['wp_object_cache'] instanceof \HarborCache_Advanced_Cache ? $GLOBALS['wp_object_cache'] : null; }

	public static function boot() {
		$config = Config::load();
		add_action( 'wp_footer', static function () use ( $config ) { if ( $config['analytics']['enabled'] && $config['analytics']['footnote'] && self::cache() ) { $info = self::cache()->info(); echo "\n<!-- HarborCache: hits=" . (int) $info->hits . ' misses=' . (int) $info->misses . " -->\n"; } } );
		add_action( 'admin_post_harborcache_action', array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'adminbar' ), 998 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_update_owned_dropin' ), 30 );
		add_action( 'admin_notices', array( __CLASS__, 'dropin_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'dropin_notice' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'integrations' ), 30 );
		add_action( 'deactivate_plugin', array( __CLASS__, 'deactivate' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'health' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_info' ) );
		add_filter( 'cron_schedules', static function ( $schedules ) { $schedules['harborcache_minute'] = array( 'interval' => 60, 'display' => __( 'Every minute (HarborCache)', 'harborcache' ) ); return $schedules; } );
		add_action( 'init', static function () { if ( Config::load()['query_maintenance'] && self::cache() && ! wp_next_scheduled( 'harborcache_queries' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'harborcache_queries' ); } elseif ( ! Config::load()['query_maintenance'] ) { wp_clear_scheduled_hook( 'harborcache_queries' ); } } );
		add_action( 'harborcache_queries', static function () { try { if ( self::cache() ) { self::cache()->maintain_queries(); } } catch ( \Throwable $error ) { Observer::log( Config::load(), 'warning', 'Optional query maintenance could not reach Redis; entries retain their TTL.' ); } } );
		add_action( 'harborcache_cleanup', static function () { if ( self::cache() ) { self::cache()->cleanup( 3 ); $queue = self::cache()->cleanup_status(); if ( ! $queue['pending'] && ! $queue['inflight'] ) { wp_clear_scheduled_hook( 'harborcache_cleanup' ); } } } );
		if ( defined( 'WP_CLI' ) && WP_CLI ) { add_action( 'plugins_loaded', static function () { \WP_CLI::add_command( 'harborcache', '\HarborCache\Commands' ); }, 40 ); }
	}

	public static function integrations() {
		$plugin = Core\Plugin::instance();
		if ( ! self::cache() || ! self::cache()->redis_status() ) {
			remove_filter( 'perflab_disable_object_cache_dropin', '__return_true' );
			remove_filter( 'w3tc_config_item_objectcache.enabled', '__return_false' );
			remove_action( 'litespeed_init', array( $plugin, 'litespeed_disable_objectcache' ) );
		}
		remove_action( 'load-settings_page_harborcache', array( $plugin, 'do_admin_actions' ) );
		remove_action( 'deactivate_plugin', array( $plugin, 'on_deactivation' ) );
		remove_action( 'admin_init', array( $plugin, 'maybe_update_dropin' ) );
		remove_action( 'admin_notices', array( $plugin, 'show_admin_notices' ) );
		remove_action( 'network_admin_notices', array( $plugin, 'show_admin_notices' ) );
		remove_action( 'admin_init', array( $plugin, 'maybe_redirect' ) );
		remove_action( 'admin_bar_menu', array( $plugin, 'render_admin_bar' ), 998 );
		remove_action( 'wp_ajax_roc_flush_cache', array( $plugin, 'ajax_flush_cache' ) );
		remove_action( 'wp_ajax_roc_dismiss_notice', array( $plugin, 'ajax_dismiss_notice' ) );
		remove_action( 'redis_object_cache_enable', array( $plugin, 'maybe_delete_transients' ) );
		remove_action( 'admin_enqueue_scripts', array( $plugin, 'enqueue_admin_styles' ) );
		remove_action( 'admin_enqueue_scripts', array( $plugin, 'enqueue_admin_scripts' ) );
		remove_action( 'admin_enqueue_scripts', array( $plugin, 'enqueue_redis_metrics' ) );
		remove_action( 'wp_dashboard_setup', array( $plugin, 'setup_dashboard_widget' ) );
		remove_action( 'wp_network_dashboard_setup', array( $plugin, 'setup_dashboard_widget' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'widget' ) );
		add_action( 'wp_network_dashboard_setup', array( __CLASS__, 'widget' ) );
		add_filter( 'redis_cache_manager_capability', static function () { return self::capability(); } );
		if ( class_exists( '\Debug_Bar_Panel' ) ) {
			require_once __DIR__ . '/class-debug-panel.php';
			add_filter( 'debug_bar_panels', static function ( $panels ) { $panels[] = new \HarborCache_Debug_Panel(); return $panels; } );
		}
	}

	public static function assets( $hook = '' ) {
		if ( ! self::allowed() ) { return; }
		if ( ! is_admin() && ! is_admin_bar_showing() ) { return; }
		if ( false !== strpos( $hook, 'harborcache' ) ) { wp_enqueue_style( 'harborcache-console', plugins_url( 'assets/css/console.css', WP_REDIS_FILE ), array(), WP_REDIS_VERSION ); }
		if ( 'index.php' === $hook || false !== strpos( $hook, 'harborcache' ) ) { wp_enqueue_style( 'harborcache-chart', plugins_url( 'assets/css/chart.css', WP_REDIS_FILE ), array(), WP_REDIS_VERSION ); }
		$console_page = 'index.php' === $hook || false !== strpos( $hook, 'harborcache' );
		if ( $console_page ) { wp_enqueue_script( 'harborcache-console', plugins_url( 'assets/js/console.js', WP_REDIS_FILE ), array(), WP_REDIS_VERSION, true ); }
		if ( is_admin_bar_showing() ) { wp_enqueue_script( 'harborcache-adminbar', plugins_url( 'assets/js/adminbar.js', WP_REDIS_FILE ), array(), WP_REDIS_VERSION, true ); }
		wp_localize_script( $console_page ? 'harborcache-console' : 'harborcache-adminbar', 'harborcacheUI', array( 'endpoint' => admin_url( 'admin-post.php' ), 'nonce' => wp_create_nonce( 'harborcache_action' ), 'confirm' => __( 'Invalidate the selected cache scope? Cached entries will be rebuilt by subsequent requests.', 'harborcache' ), 'copied' => __( 'Diagnostics copied.', 'harborcache' ), 'selected' => __( 'Report selected. Press Ctrl+C or Command+C to copy.', 'harborcache' ), 'flushing' => __( 'Invalidating cache…', 'harborcache' ), 'done' => __( 'Cache invalidated.', 'harborcache' ), 'failed' => __( 'Cache action failed. Open Diagnostics for connection details.', 'harborcache' ) ) );
	}

	public static function adminbar( $bar ) {
		if ( ! self::allowed() || ( defined( 'WP_REDIS_DISABLE_ADMINBAR' ) && WP_REDIS_DISABLE_ADMINBAR ) ) { return; }
		$bar->add_node( array( 'id' => 'harborcache', 'title' => 'HarborCache', 'href' => self::url() ) );
		$bar->add_node( array( 'id' => 'harborcache-settings', 'parent' => 'harborcache', 'title' => esc_html__( 'Cache dashboard', 'harborcache' ), 'href' => self::url() ) );
		if ( self::cache() && self::cache()->redis_status() ) { $bar->add_node( array( 'id' => 'harborcache-flush', 'parent' => 'harborcache', 'title' => esc_html__( 'Invalidate cache', 'harborcache' ), 'href' => self::url( 'tools' ) ) ); }
	}

	public static function url( $tab = 'overview' ) { return add_query_arg( array( 'page' => 'harborcache', 'view' => $tab ), network_admin_url( is_multisite() ? 'settings.php' : 'options-general.php' ) ); }

	private static function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! wp_is_file_mod_allowed( 'harborcache' ) || ! WP_Filesystem() ) { throw new \RuntimeException( esc_html__( 'WordPress could not open the filesystem. Configure filesystem access and try again.', 'harborcache' ) ); }
		return $GLOBALS['wp_filesystem'];
	}

	public static function owns_dropin() {
		if ( null !== self::$dropin_owned ) { return self::$dropin_owned; }
		$file = WP_CONTENT_DIR . '/object-cache.php';
		if ( ! is_readable( $file ) || is_link( $file ) ) { return self::$dropin_owned = false; }
		$text = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return self::$dropin_owned = is_string( $text ) && false !== strpos( $text, 'HarborCache_Advanced_Cache' ) && false !== strpos( $text, 'HarborCache Drop-In' );
	}

	public static function dropin_current() {
		if ( null !== self::$dropin_matches ) { return self::$dropin_matches; }
		$bundled = WP_REDIS_PLUGIN_PATH . '/includes/object-cache.php';
		if ( ! self::owns_dropin() || ! is_readable( $bundled ) ) { return self::$dropin_matches = false; }
		$source = hash_file( 'sha256', $bundled ); $active = hash_file( 'sha256', WP_CONTENT_DIR . '/object-cache.php' );
		return self::$dropin_matches = is_string( $source ) && is_string( $active ) && hash_equals( $source, $active );
	}

	public static function maybe_update_owned_dropin() {
		if ( self::allowed() && ! file_exists( WP_CONTENT_DIR . '/object-cache.php' ) && file_exists( WP_CONTENT_DIR . '/object-cache.php.harborcache-backup.php' ) ) {
			try { if ( Dropin_Writer::recover( self::filesystem(), WP_CONTENT_DIR . '/object-cache.php' ) ) { self::reset_dropin_state(); if ( function_exists( 'wp_opcache_invalidate' ) ) { wp_opcache_invalidate( WP_CONTENT_DIR . '/object-cache.php', true ); } } } catch ( \Throwable $error ) { /* Keep the verified backup available for manual recovery. */ }
		}
		if ( ! self::allowed() || ! Config::load()['auto_update_dropin'] || ! self::owns_dropin() || self::dropin_current() ) { return; }
		try { self::dropin( true ); } catch ( \Throwable $error ) { /* The notice below keeps the manual recovery action visible. */ }
	}

	public static function dropin_notice() {
		if ( self::allowed() && ! file_exists( WP_CONTENT_DIR . '/object-cache.php' ) && file_exists( WP_CONTENT_DIR . '/object-cache.php.harborcache-backup.php' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'HarborCache retained the previous drop-in after an interrupted update. Check wp-content permissions and refresh this page to retry recovery. The backup is object-cache.php.harborcache-backup.php.', 'harborcache' ) . '</p></div>';
			return;
		}
		if ( ! self::allowed() || ! self::owns_dropin() || self::dropin_current() ) { return; }
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'HarborCache could not refresh its cache drop-in. Check Redis connectivity and wp-content permissions, then update it below.', 'harborcache' ) . '</p>';
		self::button( 'update', __( 'Update drop-in', 'harborcache' ) );
		echo '</div>';
	}

	public static function dropin( $enable ) {
		$fs = self::filesystem();
		self::reset_dropin_state();
		$file = WP_CONTENT_DIR . '/object-cache.php';
		if ( $fs->exists( $file ) && ! self::owns_dropin() ) { throw new \RuntimeException( esc_html__( 'Another object-cache.php is installed. Disable its owning plugin before enabling HarborCache.', 'harborcache' ) ); }
		if ( $enable ) {
			$config = Config::load();
			new Codec( $config );
			if ( ( 'phpredis' === $config['client'] && ! class_exists( '\Redis' ) ) || ( 'relay' === $config['client'] && ! class_exists( '\Relay\Relay' ) ) ) { throw new \RuntimeException( esc_html__( 'Install the selected PHP Redis client extension before enabling this connection.', 'harborcache' ) ); }
			if ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED ) { throw new \RuntimeException( esc_html__( 'Remove the disabled configuration flag before enabling the cache.', 'harborcache' ) ); }
			$probe = new Core\Predis();
			$probe->connect();
			if ( ! self::owns_dropin() ) { $probe->invalidate_namespace(); }
			$ok = Dropin_Writer::replace( $fs, WP_REDIS_PLUGIN_PATH . '/includes/object-cache.php', $file );
		} else { $ok = Dropin_Writer::remove( $fs, $file ); }
		self::reset_dropin_state();
		if ( ! $ok ) { throw new \RuntimeException( esc_html__( 'The drop-in could not be written. Check wp-content permissions.', 'harborcache' ) ); }
		if ( function_exists( 'wp_opcache_invalidate' ) ) { wp_opcache_invalidate( $file, true ); }
		return true;
	}

	public static function deactivate( $plugin ) {
		if ( ! defined( 'WP_REDIS_BASENAME' ) || WP_REDIS_BASENAME !== $plugin ) { return; }
		wp_clear_scheduled_hook( 'rediscache_discard_metrics' );
		wp_clear_scheduled_hook( 'harborcache_cleanup' );
		wp_clear_scheduled_hook( 'harborcache_queries' );
		if ( self::owns_dropin() ) { try { self::dropin( false ); } catch ( \Throwable $error ) { /* The drop-in also checks whether the installation remains readable. */ } }
	}

	public static function status() {
		$cache = self::cache();
		return array_merge( array( 'version' => WP_REDIS_VERSION, 'installed' => self::owns_dropin(), 'connected' => false ), $cache ? $cache->ch_diagnostics() : array() );
	}

	public static function execute( $operation, array $args = array() ) {
		if ( 'enable' === $operation || 'update' === $operation ) { return self::dropin( true ); }
		if ( 'disable' === $operation ) { return self::dropin( false ); }
		$cache = self::cache();
		if ( ! $cache || ! $cache->redis_status() ) { throw new \RuntimeException( esc_html__( 'Enable a connected HarborCache drop-in first.', 'harborcache' ) ); }
		switch ( $operation ) {
			case 'flush': return $cache->flush();
			case 'flush-runtime': return $cache->flush_runtime();
			case 'cleanup': return $cache->cleanup( 3 );
			case 'query-maintenance': return $cache->maintain_queries();
			case 'flush-group': return $cache->flush_group( $args['group'] ?? 'default' );
			case 'flush-pattern': return $cache->flush_pattern( $args['group'] ?? '*' );
			case 'flush-site': return $cache->flush_scope( 'site-' . ( ! empty( $args['site'] ) ? absint( $args['site'] ) : get_current_blog_id() ) );
			case 'flush-global': return $cache->flush_scope( 'global' );
			case 'reset-slowlog':
			case 'reset-commands':
				if ( empty( $cache->ch_config['allow_server_controls'] ) ) { throw new \RuntimeException( esc_html__( 'Server-wide reset controls require allow_server_controls in configuration.', 'harborcache' ) ); }
				return $cache->ch_command( 'reset-slowlog' === $operation ? array( 'SLOWLOG', 'RESET' ) : array( 'CONFIG', 'RESETSTAT' ) );
			default: throw new \InvalidArgumentException( esc_html__( 'Unknown cache action.', 'harborcache' ) );
		}
	}

	public static function save_config( array $input ) {
		$old = Config::load();
		$saved = is_readable( WP_CONTENT_DIR . '/harborcache-config.php' ) ? include WP_CONTENT_DIR . '/harborcache-config.php' : array();
		$saved = array_replace( Config::defaults(), is_array( $saved ) ? $saved : array() );
		foreach ( $input as $value ) { if ( ! is_scalar( $value ) && null !== $value ) { throw new \InvalidArgumentException( esc_html__( 'Settings must contain scalar values.', 'harborcache' ) ); } }
		foreach ( array( 'host', 'username', 'client', 'serializer', 'compression', 'network_flush', 'prefix', 'backoff', 'group_flush', 'pattern_flush', 'failure_mode' ) as $key ) { if ( isset( $input[ $key ] ) ) { $saved[ $key ] = sanitize_text_field( $input[ $key ] ); } }
		foreach ( array( 'port', 'database', 'maxttl', 'queryttl', 'retry_interval', 'retries', 'gc_batch_size', 'gc_budget_ms' ) as $key ) { if ( isset( $input[ $key ] ) ) { if ( false === filter_var( $input[ $key ], FILTER_VALIDATE_INT ) ) { throw new \InvalidArgumentException( esc_html__( 'Port, database, retry interval and TTL must be whole numbers.', 'harborcache' ) ); } $saved[ $key ] = (int) $input[ $key ]; } }
		foreach ( array( 'timeout', 'read_timeout' ) as $key ) { if ( isset( $input[ $key ] ) ) { $saved[ $key ] = (float) $input[ $key ]; } }
		foreach ( array( 'split_alloptions', 'prefetch', 'debug', 'save_commands', 'persistent', 'shared', 'async_flush', 'strict', 'auto_update_dropin', 'query_maintenance' ) as $key ) { $saved[ $key ] = ! empty( $input[ $key ] ); }
		foreach ( array( 'global_groups', 'non_persistent_groups', 'non_prefetchable_groups' ) as $key ) { $saved[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', explode( ',', $input[ $key ] ?? '' ) ) ) ); }
		$saved['password'] = ! empty( $input['clear_password'] ) ? '' : ( ! empty( $input['password'] ) ? (string) $input['password'] : $old['password'] );
		$saved['analytics'] = array_replace( $saved['analytics'], array( 'enabled' => ! empty( $input['analytics'] ), 'retention' => max( 60, min( 86400, absint( $input['retention'] ?? 7200 ) ) ), 'sample_rate' => max( 0, min( 100, (float) ( $input['sample_rate'] ?? 10 ) ) ) ) );
		if ( $saved['port'] < 1 || $saved['port'] > 65535 || $saved['database'] > 65535 || $saved['maxttl'] < 1 || $saved['maxttl'] > 2592000 || $saved['queryttl'] < 1 || $saved['queryttl'] > 2592000 ) { throw new \InvalidArgumentException( esc_html__( 'Check port, database and TTL values.', 'harborcache' ) ); }
		foreach ( array( 'timeout', 'read_timeout' ) as $key ) { if ( $saved[ $key ] <= 0 || $saved[ $key ] > 30 ) { throw new \InvalidArgumentException( esc_html__( 'Timeouts must be above zero and at most 30 seconds.', 'harborcache' ) ); } }
		if ( ! in_array( $saved['client'], array( 'predis', 'phpredis', 'relay', 'credis' ), true ) || ! in_array( $saved['network_flush'], array( 'all', 'site', 'global' ), true ) ) { throw new \InvalidArgumentException( esc_html__( 'Unknown connection or flush mode.', 'harborcache' ) ); }
		$exportable = static function ( $value ) use ( &$exportable ) { if ( is_object( $value ) || is_resource( $value ) ) { return false; } if ( is_array( $value ) ) { foreach ( $value as $entry ) { if ( ! $exportable( $entry ) ) { return false; } } } return true; };
		if ( ! $exportable( $saved ) ) { throw new \InvalidArgumentException( esc_html__( 'Put callback and object configuration in HARBORCACHE_CONFIG; the settings file accepts scalar values and arrays.', 'harborcache' ) ); }
		Config::validate( $saved );
		new Codec( array_replace( $old, $saved ) );
		$fs = self::filesystem();
		$path = WP_CONTENT_DIR . '/harborcache-config.php';
		if ( is_link( $path ) ) { throw new \RuntimeException( esc_html__( 'Refusing to replace a linked configuration file.', 'harborcache' ) ); }
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generate the guarded PHP configuration, not debug output.
		$contents = "<?php\n// HarborCache configuration. Credentials are never sent to an external service.\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $saved, true ) . ";\n";
		$temp = $path . '.' . wp_generate_password( 12, false ) . '.php';
		if ( ! $fs->put_contents( $temp, $contents, 0600 ) || ! $fs->move( $temp, $path, true ) ) { $fs->delete( $temp ); throw new \RuntimeException( esc_html__( 'Could not save configuration in wp-content.', 'harborcache' ) ); }
		if ( function_exists( 'wp_opcache_invalidate' ) ) { wp_opcache_invalidate( $path, true ); }
		return true;
	}

	public static function handle() {
		if ( ! self::allowed() ) { wp_die( esc_html__( 'You do not have permission to manage HarborCache.', 'harborcache' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'harborcache_action' );
		$input = wp_unslash( $_POST );
		$operation = sanitize_key( $input['operation'] ?? '' );
		try {
			if ( 'export-analytics' === $operation ) { nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename=harborcache-analytics.csv' ); echo Analytics::csv( Core\Metrics::get( Analytics::window( $input['window'] ?? 7200 ), 1000 ) ); exit; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Numeric CSV generated internally.
			if ( in_array( $operation, array( 'inspect-group', 'inspect-nodes', 'inspect-relay' ), true ) ) {
				$cache = self::cache(); if ( ! $cache || ! $cache->redis_status() ) { throw new \RuntimeException( 'Cache is disconnected.' ); }
				$report = 'inspect-group' === $operation ? $cache->group_diagnostics( sanitize_text_field( $input['scope'] ?? '' ), sanitize_text_field( $input['group'] ?? '' ), ! empty( $input['memory'] ) ) : ( 'inspect-nodes' === $operation ? $cache->node_diagnostics() : $cache->relay_diagnostics( sanitize_text_field( $input['key'] ?? '' ), sanitize_text_field( $input['group'] ?? 'default' ) ) );
				update_user_meta( get_current_user_id(), '_harborcache_probe', array( 'operation' => $operation, 'created' => time(), 'data' => $report ) );
				wp_safe_redirect( self::url( 'inspect-group' === $operation ? 'groups' : 'tools' ) ); exit;
			}
			$result = 'save' === $operation ? self::save_config( $input ) : self::execute( $operation, array( 'group' => sanitize_text_field( $input['group'] ?? '' ), 'site' => absint( $input['site'] ?? 0 ) ) );
			if ( false === $result ) { throw new \RuntimeException( esc_html__( 'The operation did not succeed.', 'harborcache' ) ); }
			update_user_meta( get_current_user_id(), '_harborcache_notice', array( 'success', __( 'Action completed. Configuration changes apply on the next request.', 'harborcache' ), time() ) );
		} catch ( \Throwable $error ) {
			update_user_meta( get_current_user_id(), '_harborcache_notice', array( 'error', $error instanceof \InvalidArgumentException ? $error->getMessage() : __( 'Action failed. Check the connection, filesystem access and prerequisites in Diagnostics.', 'harborcache' ), time() ) );
		}
		wp_safe_redirect( self::url( 'save' === $operation ? 'connection' : 'overview' ) );
		exit;
	}

	public static function read( $name ) {
		if ( 'status' === $name ) { return self::status(); }
		$config = Config::load();
		if ( 'options' === $name ) { foreach ( array( 'password', 'username', 'host', 'path', 'cluster', 'servers', 'sentinels', 'tls_options', 'logger', 'tracer', 'connector', 'cache' ) as $private ) { unset( $config[ $private ] ); } return $config; }
		$cache = self::cache();
		if ( ! $cache || ! $cache->redis_status() ) { return array(); }
		if ( 'groups' === $name ) { return $cache->ch_groups(); }
		if ( 'capacity' === $name ) { return $cache->storage_summary(); }
		if ( 'analytics' === $name ) { return Core\Metrics::get( $config['analytics']['retention'], 1000 ); }
		if ( 'latency' === $name ) { $start = microtime( true ); $cache->ch_command( array( 'PING' ) ); return array( 'milliseconds' => round( ( microtime( true ) - $start ) * 1000, 3 ) ); }
		if ( 'commands' === $name ) { return array( 'scope' => $config['shared'] ? 'shared-server' : 'dedicated-server', 'info' => $cache->ch_command( array( 'INFO', 'commandstats' ) ) ); }
		if ( 'slowlog' === $name ) {
			$rows = $cache->ch_command( array( 'SLOWLOG', 'GET', 25 ) );
			return array_map( static function ( $row ) { return array( 'id' => $row[0], 'timestamp' => $row[1], 'microseconds' => $row[2], 'command' => strtoupper( $row[3][0] ?? '' ) ); }, (array) $rows );
		}
		return array();
	}

	public static function health( $tests ) {
		$tests['direct']['harborcache'] = array( 'label' => __( 'HarborCache health', 'harborcache' ), 'test' => array( __CLASS__, 'health_result' ) );
		return $tests;
	}

	public static function health_result() {
		$cache = self::cache(); $issues = array();
		if ( ! $cache || ! $cache->redis_status() ) { $issues[] = __( 'Persistent Redis caching is not connected.', 'harborcache' ); }
		if ( self::owns_dropin() && ! self::dropin_current() ) { $issues[] = __( 'The owned drop-in differs from the installed plugin.', 'harborcache' ); }
		if ( $cache && $cache->redis_status() ) {
			$health = $cache->operational_health();
			if ( 'noeviction' === ( $health['maxmemory_policy'] ?? '' ) ) { $issues[] = __( 'Redis uses noeviction; exhausted memory can reject cache writes. Review the server policy.', 'harborcache' ); }
			if ( Config::load()['async_flush'] && ! wp_next_scheduled( 'harborcache_cleanup' ) ) { $issues[] = __( 'The cache cleanup schedule is missing.', 'harborcache' ); }
			if ( Config::load()['query_maintenance'] && ! wp_next_scheduled( 'harborcache_queries' ) ) { $issues[] = __( 'The query maintenance schedule is missing.', 'harborcache' ); }
		}
		return array( 'label' => $issues ? __( 'HarborCache needs attention', 'harborcache' ) : __( 'HarborCache is connected and current', 'harborcache' ), 'status' => $issues ? 'recommended' : 'good', 'badge' => array( 'label' => __( 'Performance', 'harborcache' ), 'color' => 'blue' ), 'description' => '<p>' . esc_html( $issues ? implode( ' ', $issues ) : __( 'The connection and owned drop-in checks passed.', 'harborcache' ) ) . '</p>', 'actions' => '<p><a href="' . esc_url( self::url( 'diagnostics' ) ) . '">' . esc_html__( 'Open HarborCache diagnostics', 'harborcache' ) . '</a></p>', 'test' => 'harborcache' );
	}

	public static function debug_info( $info ) {
		$fields = array(); foreach ( self::status() as $key => $value ) { $fields[ $key ] = array( 'label' => $key, 'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : $value ); }
		$info['harborcache'] = array( 'label' => 'HarborCache', 'fields' => $fields ); return $info;
	}

	public static function widget() {
		if ( self::allowed() ) { wp_add_dashboard_widget( 'harborcache_status', 'HarborCache', array( __CLASS__, 'render_widget' ) ); }
	}

	public static function render_widget() {
		if ( ! self::allowed() ) { return; }
		$status = self::status();
		echo '<div class="ch-connection-controls"><strong class="' . ( $status['connected'] ? 'ch-connected' : 'ch-disconnected' ) . '">' . esc_html( $status['connected'] ? __( 'Redis connected', 'harborcache' ) : __( 'Disconnected', 'harborcache' ) ) . '</strong>';
		self::button( $status['installed'] ? 'disable' : 'enable', $status['installed'] ? __( 'Disconnect', 'harborcache' ) : __( 'Connect', 'harborcache' ) );
		echo '</div><p class="description">' . esc_html( $status['connected'] ? __( 'Disconnect turns off persistent caching for this WordPress installation. Redis stays running; connection settings are kept. Reconnecting starts a fresh cache.', 'harborcache' ) : __( 'Persistent caching is not active. Check the connection settings and connect to start a fresh cache.', 'harborcache' ) ) . '</p>';
		if ( $status['connected'] ) {
			$samples = Core\Metrics::get( null, 100 );
			self::chart( $samples );
			echo '<details class="ch-request-details"><summary>' . esc_html__( 'Current dashboard request', 'harborcache' ) . '</summary><table class="widefat striped"><tbody>';
			foreach ( array( 'ratio' => __( 'Hit ratio (%)', 'harborcache' ), 'hits' => __( 'Cache hits', 'harborcache' ), 'misses' => __( 'Cache misses', 'harborcache' ), 'calls' => __( 'Cache operations', 'harborcache' ), 'milliseconds' => __( 'Request cache time (ms)', 'harborcache' ) ) as $key => $label ) {
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( $status[ $key ], 'milliseconds' === $key ? 3 : ( 'ratio' === $key ? 1 : 0 ) ) ) . '</td></tr>';
			}
			echo '</tbody></table></details>';
			if ( $samples ) {
				$hits = 0; $misses = 0; $seconds = 0;
				foreach ( $samples as $sample ) { $hits += $sample->hits; $misses += $sample->misses; $seconds += $sample->time; }
				/* translators: 1: sample count, 2: weighted hit ratio, 3: average Redis time in milliseconds. */
				echo '<p>' . esc_html( sprintf( __( 'Latest %1$s sampled requests: %2$s%% hit ratio; %3$s ms average Redis time.', 'harborcache' ), number_format_i18n( count( $samples ) ), number_format_i18n( $hits + $misses > 0 ? 100 * $hits / ( $hits + $misses ) : 100, 1 ), number_format_i18n( 1000 * $seconds / count( $samples ), 3 ) ) ) . '</p>';
			} else { echo '<p>' . esc_html__( 'No recent samples. Analytics must be enabled; samples appear after one minute.', 'harborcache' ) . '</p>'; }
			echo '<p class="description">' . esc_html__( 'Request measurements include runtime cache hits. Samples are local, may span sites sharing this cache namespace, and are not server-wide totals. Refresh the page to update.', 'harborcache' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open cache dashboard', 'harborcache' ) . '</a> · <a href="' . esc_url( self::url( 'analytics' ) ) . '">' . esc_html__( 'View analytics', 'harborcache' ) . '</a></p>';
	}

	public static function chart( array $samples ) {
		if ( ! $samples ) { return; }
		$metrics = array(
			'time' => array( __( 'Time', 'harborcache' ), __( 'Redis time', 'harborcache' ), 'ms', 1000 ),
			'bytes' => array( __( 'Bytes', 'harborcache' ), __( 'Runtime cache size', 'harborcache' ), 'KiB', 1 / 1024 ),
			'ratio' => array( __( 'Ratio', 'harborcache' ), __( 'Cache hit ratio', 'harborcache' ), '%', 1 ),
			'calls' => array( __( 'Calls', 'harborcache' ), __( 'Cache operations', 'harborcache' ), '', 1 ),
		);
		$first = (int) $samples[0]->timestamp; $last = (int) end( $samples )->timestamp;
		$id = wp_unique_id( 'ch-chart-' );
		echo '<section class="ch-chart" aria-label="' . esc_attr__( 'Sampled cache performance', 'harborcache' ) . '"><div class="ch-chart-toolbar"><div class="ch-chart-controls" role="group" aria-label="' . esc_attr__( 'Chart metric', 'harborcache' ) . '">';
		foreach ( $metrics as $key => $metric ) { echo '<button type="button" data-ch-metric="' . esc_attr( $key ) . '" aria-controls="' . esc_attr( $id . $key ) . '" aria-pressed="' . ( 'time' === $key ? 'true' : 'false' ) . '">' . esc_html( $metric[0] ) . '</button>'; }
		echo '</div><a href="' . esc_url( self::url( 'connection' ) ) . '">' . esc_html__( 'Settings', 'harborcache' ) . '</a></div>';
		foreach ( $metrics as $key => $metric ) {
			$values = array_map( static function ( $sample ) use ( $key, $metric ) { return max( 0, (float) $sample->$key * $metric[3] ); }, $samples );
			$peak = max( $values ); $average = array_sum( $values ) / count( $values );
			$max = 'ratio' === $key ? 100 : max( 1, ceil( $peak * 1.15 / pow( 10, floor( log10( max( 1, $peak ) ) ) ) ) * pow( 10, floor( log10( max( 1, $peak ) ) ) ) );
			$points = array();
			foreach ( $samples as $i => $sample ) { $points[] = array( round( 100 + 486 * ( $last > $first ? ( $sample->timestamp - $first ) / ( $last - $first ) : .5 ), 2 ), round( 212 - 176 * min( 1, $values[ $i ] / $max ), 2 ) ); }
			$line = implode( ' ', array_map( static function ( $point ) { return implode( ',', $point ); }, $points ) );
			echo '<div id="' . esc_attr( $id . $key ) . '" class="ch-chart-panel" data-ch-panel="' . esc_attr( $key ) . '"' . ( 'time' === $key ? '' : ' hidden' ) . '><svg viewBox="0 0 610 250" data-single="' . ( 1 === count( $samples ) ? 'true' : 'false' ) . '" role="img" aria-labelledby="' . esc_attr( $id . $key . '-title' ) . '"><title id="' . esc_attr( $id . $key . '-title' ) . '">' . esc_html( $metric[1] . ' (' . $metric[2] . ')' ) . '</title>';
			echo '<text x="100" y="22" text-anchor="start">' . esc_html( $metric[2] ) . '</text>';
			for ( $tick = 0; $tick <= 4; ++$tick ) { $y = 212 - 44 * $tick; echo '<line class="ch-chart-grid" x1="100" x2="586" y1="' . esc_attr( (string) $y ) . '" y2="' . esc_attr( (string) $y ) . '"/><text x="92" y="' . esc_attr( (string) ( $y + 4 ) ) . '" text-anchor="end">' . esc_html( ( $max * $tick / 4 >= 10000 ? number_format_i18n( $max * $tick / 4000, 0 ) . 'k' : number_format_i18n( $max * $tick / 4, $max < 10 ? 1 : 0 ) ) ) . '</text>'; }
			echo '<polygon class="ch-chart-area" points="' . esc_attr( $points[0][0] . ',212 ' . $line . ' ' . end( $points )[0] . ',212' ) . '"/><polyline class="ch-chart-line" points="' . esc_attr( $line ) . '"/>';
			foreach ( $points as $i => $point ) { echo '<circle class="ch-chart-point" cx="' . esc_attr( (string) $point[0] ) . '" cy="' . esc_attr( (string) $point[1] ) . '" r="3"><title>' . esc_html( wp_date( 'H:i:s', $samples[ $i ]->timestamp ) . ': ' . number_format_i18n( $values[ $i ], 2 ) . ' ' . $metric[2] ) . '</title></circle>'; }
			foreach ( array( 0, .5, 1 ) as $position ) { echo '<text x="' . esc_attr( (string) ( 100 + 486 * $position ) ) . '" y="238" text-anchor="' . ( 0 === $position ? 'start' : ( 1 === $position ? 'end' : 'middle' ) ) . '">' . esc_html( wp_date( 'H:i', (int) ( $first + ( $last - $first ) * $position ) ) ) . '</text>'; }
			echo '</svg><dl class="ch-chart-summary">';
			foreach ( array( __( 'Latest', 'harborcache' ) => end( $values ), __( 'Average', 'harborcache' ) => $average, __( 'Peak', 'harborcache' ) => $peak ) as $label => $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( number_format_i18n( $value, 'calls' === $key ? 0 : 1 ) . ' ' . $metric[2] ) . '</dd></div>'; }
			echo '</dl></div>';
		}
		echo '<details class="ch-request-details"><summary>' . esc_html__( 'View chart data', 'harborcache' ) . '</summary><div class="ch-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Time', 'harborcache' ) . '</th><th scope="col">ms</th><th scope="col">KiB</th><th scope="col">%</th><th scope="col">' . esc_html__( 'Operations', 'harborcache' ) . '</th></tr></thead><tbody>';
		foreach ( $samples as $sample ) { echo '<tr><th scope="row">' . esc_html( wp_date( 'H:i:s', $sample->timestamp ) ) . '</th><td>' . esc_html( number_format_i18n( $sample->time * 1000, 2 ) ) . '</td><td>' . esc_html( number_format_i18n( $sample->bytes / 1024, 1 ) ) . '</td><td>' . esc_html( number_format_i18n( $sample->ratio, 1 ) ) . '</td><td>' . esc_html( number_format_i18n( $sample->calls ) ) . '</td></tr>'; }
		echo '</tbody></table></div></details>';
		/* translators: 1: sample count, 2: configured sampling percentage, 3: last sample date and time. */
		echo '<p class="ch-chart-caption">' . esc_html( sprintf( __( '%1$s recent sampled requests · %2$s%% sampling · Last sample: %3$s · Samples are delayed by one minute. Site timezone.', 'harborcache' ), number_format_i18n( count( $samples ) ), number_format_i18n( Config::load()['analytics']['sample_rate'], 1 ), wp_date( 'M j, H:i:s', $last ) ) ) . '</p><noscript><p>' . esc_html__( 'Enable JavaScript to switch chart metrics. The Time chart and request details remain available.', 'harborcache' ) . '</p></noscript></section>';
	}

	private static function button( $operation, $label, $primary = false, $group = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ch-action">';
		wp_nonce_field( 'harborcache_action' );
		echo '<input type="hidden" name="action" value="harborcache_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="group" value="' . esc_attr( $group ) . '"><button class="button ' . ( $primary ? 'button-primary' : '' ) . '" type="submit" ' . ( 0 === strpos( $operation, 'flush' ) ? 'data-ch-confirm="1"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	public static function render() {
		if ( ! self::allowed() ) { return; }
		$tabs = array( 'overview' => __( 'Overview', 'harborcache' ), 'analytics' => __( 'Analytics', 'harborcache' ), 'connection' => __( 'Connection', 'harborcache' ), 'groups' => __( 'Cache groups', 'harborcache' ), 'tools' => __( 'Tools', 'harborcache' ), 'diagnostics' => __( 'Diagnostics', 'harborcache' ) );
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		if ( ! isset( $tabs[ $view ] ) ) { $view = 'overview'; }
		$status = self::status();
		$config = Config::load();
		echo '<div class="wrap ch-console"><h1 class="ch-page-title">HarborCache</h1><div class="ch-notices"></div><header class="ch-header"><div class="ch-identity"><img src="' . esc_url( plugins_url( 'assets/brand/icon.svg', WP_REDIS_FILE ) ) . '" alt="" width="44" height="44"><div><p class="ch-brand-name" aria-hidden="true">HarborCache</p><p>' . esc_html__( 'Redis object cache', 'harborcache' ) . '</p></div></div><div class="ch-connection-controls"><span class="ch-status ' . ( $status['connected'] ? 'is-connected' : '' ) . '">' . esc_html( $status['connected'] ? __( 'Connected', 'harborcache' ) : __( 'Not active', 'harborcache' ) ) . '</span>';
		self::button( $status['installed'] ? 'disable' : 'enable', $status['installed'] ? __( 'Disconnect', 'harborcache' ) : __( 'Connect', 'harborcache' ) );
		echo '</div></header>';
		$notice = get_user_meta( get_current_user_id(), '_harborcache_notice', true );
		if ( is_array( $notice ) ) { delete_user_meta( get_current_user_id(), '_harborcache_notice' ); if ( isset( $notice[2] ) && time() - $notice[2] < 60 ) { echo '<div class="notice notice-' . esc_attr( $notice[0] ) . '"><p>' . esc_html( $notice[1] ) . '</p></div>'; } }
		echo '<nav class="ch-tabs" aria-label="' . esc_attr__( 'Cache dashboard', 'harborcache' ) . '">';
		foreach ( $tabs as $slug => $label ) { echo '<a href="' . esc_url( self::url( $slug ) ) . '" ' . ( $view === $slug ? 'aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>'; }
		echo '</nav><main class="ch-main"><h2>' . esc_html( $tabs[ $view ] ) . '</h2>';
		if ( 'overview' === $view ) {
			echo '<p class="ch-intro">' . esc_html__( 'Check cache health, inspect the current request and manage persistent storage.', 'harborcache' ) . '</p><div class="ch-metrics">';
			foreach ( array( 'ratio' => __( 'Combined hit ratio', 'harborcache' ), 'calls' => __( 'Cache operations', 'harborcache' ), 'milliseconds' => __( 'Request cache time (ms)', 'harborcache' ), 'bytes' => __( 'Runtime cache (bytes)', 'harborcache' ) ) as $key => $label ) { echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( isset( $status[ $key ] ) ? (string) $status[ $key ] . ( 'ratio' === $key ? '%' : '' ) : '—' ) . '</strong></div>'; }
			echo '</div><p class="description">' . esc_html__( 'Combined hits include request-memory and Redis reads. Cache time is not full page response time; operation counts include namespace metadata.', 'harborcache' ) . '</p><p>' . esc_html( sprintf( /* translators: 1: runtime hits, 2: persistent store hits, 3: persistent store misses. */ __( 'Request-memory hits: %1$s · Redis-store hits: %2$s · Redis-store misses: %3$s.', 'harborcache' ), number_format_i18n( $status['runtime_hits'] ?? 0 ), number_format_i18n( $status['store_hits'] ?? 0 ), number_format_i18n( $status['store_misses'] ?? 0 ) ) ) . '</p>'; self::chart( Core\Metrics::get( null, 100 ) ); echo '<section class="ch-panel"><h3>' . esc_html__( 'Cache lifecycle', 'harborcache' ) . '</h3><p>' . esc_html__( 'Enable installs the drop-in after a connection check. Flushing invalidates only this installation’s namespace; unrelated Redis data is preserved.', 'harborcache' ) . '</p><div class="ch-actions">';
			if ( $status['installed'] ) { self::button( 'flush', __( 'Flush cache', 'harborcache' ) ); self::button( 'update', __( 'Update drop-in', 'harborcache' ) ); }
			echo '</div></section><section class="ch-panel"><h3>' . esc_html__( 'Connection summary', 'harborcache' ) . '</h3><dl class="ch-details">';
			foreach ( array( __( 'Transport', 'harborcache' ) => $status['client'] ?? $config['client'], __( 'Database', 'harborcache' ) => $config['database'], __( 'Serializer', 'harborcache' ) => $config['serializer'], __( 'Compression', 'harborcache' ) => $config['compression'], __( 'Maximum TTL', 'harborcache' ) => $config['maxttl'] . ' s' ) as $label => $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>'; }
			echo '<div><dt>' . esc_html__( 'Redis allocation', 'harborcache' ) . '</dt><dd>' . esc_html( $config['shared'] ? __( 'Shared server', 'harborcache' ) : __( 'Dedicated server', 'harborcache' ) ) . '</dd></div></dl>';
			if ( $config['shared'] ) { echo '<p>' . esc_html__( 'Server memory and total key counts include other applications and are omitted here.', 'harborcache' ) . '</p>'; }
			elseif ( $status['connected'] ) {
				try {
					$capacity = self::read( 'capacity' );
					/* translators: 1: memory in bytes across primary nodes, 2: key count in the selected Redis database. */
					$caption = sprintf( __( 'Redis primary nodes: %1$s bytes used, %2$s keys in the selected database.', 'harborcache' ), number_format_i18n( $capacity['memory_bytes'] ), number_format_i18n( $capacity['keys'] ) );
					echo '<p>' . esc_html( $caption ) . '</p>';
				} catch ( \Throwable $ignored ) { echo '<p>' . esc_html__( 'Server capacity information is unavailable.', 'harborcache' ) . '</p>'; }
			}
			echo '</section>';
		} elseif ( 'connection' === $view ) { self::connection_form( $config ); }
		elseif ( 'groups' === $view ) {
			self::probe_result( 'inspect-group' );
			echo '<p>' . esc_html__( 'Groups observed in the current namespace. A group can remain listed after its entries expire.', 'harborcache' ) . '</p><div class="ch-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Group', 'harborcache' ) . '</th><th>' . esc_html__( 'Scope', 'harborcache' ) . '</th><th>' . esc_html__( 'Action', 'harborcache' ) . '</th></tr></thead><tbody>';
			foreach ( self::read( 'groups' ) as $row ) { echo '<tr><td><code>' . esc_html( $row['group'] ) . '</code></td><td>' . esc_html( $row['scope'] ) . '</td><td>'; self::button( 'flush-group', __( 'Flush group on this site', 'harborcache' ), false, $row['group'] ); self::group_probe_form( $row ); echo '</td></tr>'; }
			echo '</tbody></table></div>';
		} elseif ( 'analytics' === $view ) {
			$window = Analytics::window( isset( $_GET['window'] ) ? absint( $_GET['window'] ) : 7200 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only time filter.
			$rows = Core\Metrics::get( $window, 1000 );
			self::analytics_controls( $window, $rows );
			echo '<p>' . esc_html__( 'Local sampled requests, shown after one minute. This view reads at most 1,000 samples within the configured retention. Percentiles describe sampled requests, not individual commands.', 'harborcache' ) . '</p>';
			if ( ! $rows ) { echo '<div class="ch-empty"><h3>' . esc_html__( 'No measurements yet', 'harborcache' ) . '</h3><p>' . esc_html__( 'Enable caching and analytics, then browse the site. Return after one minute.', 'harborcache' ) . '</p></div>'; }
			else { echo '<div class="ch-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'harborcache' ) . '</th><th>' . esc_html__( 'Hit ratio', 'harborcache' ) . '</th><th>' . esc_html__( 'Commands', 'harborcache' ) . '</th><th>' . esc_html__( 'Time (ms)', 'harborcache' ) . '</th></tr></thead><tbody>'; foreach ( array_reverse( $rows ) as $row ) { echo '<tr><td>' . esc_html( wp_date( 'H:i:s', $row->timestamp ) ) . '</td><td><span class="ch-bar" style="--ch-ratio:' . esc_attr( (string) max( 0, min( 100, $row->ratio ) ) ) . '%"></span>' . esc_html( $row->ratio . '%' ) . '</td><td>' . esc_html( (string) $row->calls ) . '</td><td>' . esc_html( (string) round( $row->time * 1000, 3 ) ) . '</td></tr>'; } echo '</tbody></table></div>'; }
		} elseif ( 'tools' === $view ) {
			echo '<section class="ch-panel"><h3>' . esc_html__( 'Scoped invalidation', 'harborcache' ) . '</h3><p>' . esc_html__( 'Invalidation changes namespace tokens immediately. Old entries expire within the configured maximum TTL.', 'harborcache' ) . '</p><div class="ch-actions">'; self::button( 'flush', __( 'Flush configured scope', 'harborcache' ) ); self::button( 'flush-global', __( 'Flush global groups', 'harborcache' ) ); self::button( 'flush-runtime', __( 'Clear this request’s runtime cache', 'harborcache' ) ); self::button( 'cleanup', __( 'Run a cleanup batch', 'harborcache' ) ); if ( $config['query_maintenance'] ) { self::button( 'query-maintenance', __( 'Recycle query cache batch', 'harborcache' ) ); } echo '</div></section>';
			self::probe_controls();
			echo '<section class="ch-panel"><h3>' . esc_html__( 'Server observability', 'harborcache' ) . '</h3><p>' . esc_html__( 'Command statistics describe the Redis server. Slow-log output omits keys and values.', 'harborcache' ) . '</p><h4>' . esc_html__( 'Command statistics', 'harborcache' ) . '</h4><pre>' . esc_html( wp_json_encode( self::read( 'commands' ), JSON_PRETTY_PRINT ) ) . '</pre><h4>' . esc_html__( 'Slow commands', 'harborcache' ) . '</h4><pre>' . esc_html( wp_json_encode( self::read( 'slowlog' ), JSON_PRETTY_PRINT ) ) . '</pre></section>';
		} else {
			echo '<p>' . esc_html__( 'This report excludes credentials, connection endpoints, cached keys and values.', 'harborcache' ) . '</p><pre id="ch-diagnostics">' . esc_html( wp_json_encode( array( 'runtime' => self::status(), 'options' => self::read( 'options' ), 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ) ), JSON_PRETTY_PRINT ) ) . '</pre><button type="button" class="button" id="ch-copy">' . esc_html__( 'Copy diagnostics', 'harborcache' ) . '</button><p id="ch-feedback" role="status"></p><p>' . esc_html__( 'Review the verified feature matrix and deployment requirements in docs/FEATURES.md before production use.', 'harborcache' ) . '</p>';
		}
		echo '</main><footer class="ch-footer">HarborCache ' . esc_html( WP_REDIS_VERSION ) . '</footer></div>';
	}

	private static function available_option( $key, $option ) {
		if ( 'client' === $key ) { return 'phpredis' === $option ? class_exists( '\Redis' ) : ( 'relay' !== $option || class_exists( '\Relay\Relay' ) ); }
		if ( 'serializer' === $key && 'igbinary' === $option ) { return function_exists( 'igbinary_serialize' ) && function_exists( 'igbinary_unserialize' ); }
		if ( 'compression' === $key && 'none' !== $option ) { return function_exists( $option . '_compress' ) && function_exists( 'lzf' === $option ? 'lzf_decompress' : $option . '_uncompress' ); }
		return true;
	}

	private static function connection_form( array $config ) {
		echo '<p>' . esc_html__( 'Settings are saved to a guarded PHP file in wp-content. HARBORCACHE_CONFIG and existing WP_REDIS constants take precedence. Change settings on staging before production.', 'harborcache' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ch-settings">';
		wp_nonce_field( 'harborcache_action' );
		echo '<input type="hidden" name="action" value="harborcache_action"><input type="hidden" name="operation" value="save"><section class="ch-settings-section" aria-labelledby="ch-settings-connection"><h3 id="ch-settings-connection">' . esc_html__( 'Connection and cache limits', 'harborcache' ) . '</h3><div class="ch-form-grid">';
		$fields = array( 'host' => __( 'Redis host or socket', 'harborcache' ), 'port' => __( 'Port', 'harborcache' ), 'database' => __( 'Database', 'harborcache' ), 'username' => __( 'ACL username', 'harborcache' ), 'password' => __( 'Password (blank keeps current)', 'harborcache' ), 'prefix' => __( 'Installation prefix', 'harborcache' ), 'timeout' => __( 'Connect timeout (seconds)', 'harborcache' ), 'read_timeout' => __( 'Read timeout (seconds)', 'harborcache' ), 'maxttl' => __( 'Maximum TTL (seconds)', 'harborcache' ), 'queryttl' => __( 'Query TTL (seconds)', 'harborcache' ), 'retry_interval' => __( 'Retry interval (milliseconds)', 'harborcache' ), 'retries' => __( 'Safe read retries (0–5)', 'harborcache' ), 'gc_budget_ms' => __( 'Cleanup time budget (milliseconds)', 'harborcache' ), 'gc_batch_size' => __( 'Cleanup keys per batch (1–1000)', 'harborcache' ), 'global_groups' => __( 'Extra global groups (comma separated)', 'harborcache' ), 'non_persistent_groups' => __( 'Non-persistent groups (supports *)', 'harborcache' ), 'non_prefetchable_groups' => __( 'Non-prefetchable groups', 'harborcache' ) );
		foreach ( $fields as $key => $label ) { $value = 'password' === $key ? '' : ( is_array( $config[ $key ] ) ? implode( ', ', $config[ $key ] ) : $config[ $key ] ); echo '<label for="ch-' . esc_attr( $key ) . '">' . esc_html( $label ) . '<input id="ch-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . ( 'password' === $key ? 'password' : 'text' ) . '" value="' . esc_attr( (string) $value ) . '" autocomplete="' . ( 'password' === $key ? 'new-password' : 'off' ) . '"></label>'; }
		foreach ( array( 'client' => array( 'predis', 'phpredis', 'relay', 'credis' ), 'serializer' => array( 'php', 'igbinary' ), 'compression' => array( 'none', 'lzf', 'lz4', 'zstd' ), 'network_flush' => array( 'all', 'site', 'global' ), 'backoff' => array( 'none', 'constant', 'exponential', 'smart' ), 'group_flush' => array( 'atomic', 'scan', 'incremental', 'keys', 'full' ), 'pattern_flush' => array( 'scan', 'incremental', 'keys' ), 'failure_mode' => array( 'silent', 'strict', 'debug' ) ) as $key => $options ) { echo '<label for="ch-' . esc_attr( $key ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . '<select name="' . esc_attr( $key ) . '" id="ch-' . esc_attr( $key ) . '">'; foreach ( $options as $option ) { $available = self::available_option( $key, $option ); echo '<option value="' . esc_attr( $option ) . '" ' . selected( $config[ $key ], $option, false ) . ( $available || $config[ $key ] === $option ? '' : ' disabled' ) . '>' . esc_html( $option . ( $available ? '' : ': ' . __( 'extension unavailable', 'harborcache' ) ) ) . '</option>'; } echo '</select></label>'; }
		echo '</div><label class="ch-check"><input type="checkbox" name="clear_password" value="1">' . esc_html__( 'Clear saved password', 'harborcache' ) . '</label></section>';
		$checks = array( 'shared' => __( 'Redis is shared with other applications', 'harborcache' ), 'auto_update_dropin' => __( 'Automatically refresh HarborCache’s owned drop-in after upgrades', 'harborcache' ), 'query_maintenance' => __( 'Recycle query cache groups gradually every hour (also expires valid query entries)', 'harborcache' ), 'strict' => __( 'Protect this installation’s cache prefix ownership', 'harborcache' ), 'async_flush' => __( 'Reclaim invalidated entries in background batches', 'harborcache' ), 'persistent' => __( 'Reuse persistent connections where supported by the client', 'harborcache' ), 'split_alloptions' => __( 'Split autoloaded options (hash or Relay option strings)', 'harborcache' ), 'prefetch' => __( 'Enable registered key prefetching', 'harborcache' ), 'debug' => __( 'Enable debug mode', 'harborcache' ), 'save_commands' => __( 'Keep command names and timings for diagnostics', 'harborcache' ), 'analytics' => __( 'Collect local request analytics', 'harborcache' ), 'clear_password' => __( 'Clear saved password', 'harborcache' ) );
		$sections = array(
			__( 'Cache safety', 'harborcache' ) => array( 'shared', 'auto_update_dropin', 'strict' ),
			__( 'Performance and maintenance', 'harborcache' ) => array( 'persistent', 'split_alloptions', 'prefetch', 'async_flush', 'query_maintenance' ),
			__( 'Diagnostics and analytics', 'harborcache' ) => array( 'debug', 'save_commands', 'analytics' ),
		);
		foreach ( $sections as $heading => $keys ) {
			echo '<fieldset class="ch-settings-section"><legend>' . esc_html( $heading ) . '</legend><div class="ch-check-list">';
			foreach ( $keys as $key ) { $checked = 'analytics' === $key ? $config['analytics']['enabled'] : $config[ $key ]; echo '<label class="ch-check"><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $checked, true, false ) . '>' . esc_html( $checks[ $key ] ) . '</label>'; }
			echo '</div></fieldset>';
		}

		echo '<fieldset class="ch-settings-section"><legend>' . esc_html__( 'Analytics sampling', 'harborcache' ) . '</legend><div class="ch-form-grid ch-measurement-fields"><label for="ch-sample-rate">' . esc_html__( 'Sample rate (%)', 'harborcache' ) . ' <input id="ch-sample-rate" name="sample_rate" type="number" min="0" max="100" value="' . esc_attr( (string) $config['analytics']['sample_rate'] ) . '"></label><label for="ch-retention">' . esc_html__( 'Retention (seconds)', 'harborcache' ) . ' <input id="ch-retention" name="retention" type="number" min="60" max="86400" value="' . esc_attr( (string) $config['analytics']['retention'] ) . '"></label></div></fieldset><button class="button button-primary" type="submit">' . esc_html__( 'Save configuration', 'harborcache' ) . '</button></form>';
	}

	private static function analytics_controls( $window, array $rows ) {
		echo '<form method="get" class="ch-analytics-filter"><input type="hidden" name="page" value="harborcache"><input type="hidden" name="view" value="analytics"><label for="ch-window">' . esc_html__( 'Time window', 'harborcache' ) . '</label><select id="ch-window" name="window">';
		foreach ( array( 900 => __( '15 minutes', 'harborcache' ), 3600 => __( '1 hour', 'harborcache' ), 7200 => __( '2 hours', 'harborcache' ), 21600 => __( '6 hours', 'harborcache' ), 86400 => __( '24 hours', 'harborcache' ) ) as $seconds => $label ) { echo '<option value="' . esc_attr( (string) $seconds ) . '" ' . selected( $window, $seconds, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select><button class="button" type="submit">' . esc_html__( 'Apply', 'harborcache' ) . '</button></form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ch-action">';
		wp_nonce_field( 'harborcache_action' );
		echo '<input type="hidden" name="action" value="harborcache_action"><input type="hidden" name="operation" value="export-analytics"><input type="hidden" name="window" value="' . esc_attr( (string) $window ) . '"><button class="button" type="submit">' . esc_html__( 'Export samples (CSV)', 'harborcache' ) . '</button></form>';
		$summary = Analytics::summarize( $rows );
		if ( $rows ) {
			self::chart( array_slice( $rows, -100 ) );
			echo '<p>' . esc_html( sprintf( /* translators: 1: median time, 2: p95 time. */ __( 'Sampled request Redis time: median %1$s ms; 95th percentile %2$s ms.', 'harborcache' ), number_format_i18n( $summary['median_ms'], 3 ), number_format_i18n( $summary['p95_ms'], 3 ) ) ) . '</p><details class="ch-request-details"><summary>' . esc_html__( 'Five-minute summaries', 'harborcache' ) . '</summary><div class="ch-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'harborcache' ) . '</th><th>' . esc_html__( 'Samples', 'harborcache' ) . '</th><th>' . esc_html__( 'Hit ratio', 'harborcache' ) . '</th><th>' . esc_html__( 'Median ms', 'harborcache' ) . '</th><th>' . esc_html__( 'P95 ms', 'harborcache' ) . '</th></tr></thead><tbody>';
			foreach ( $summary['buckets'] as $bucket ) { echo '<tr><td>' . esc_html( wp_date( 'H:i', $bucket['timestamp'] ) ) . '</td><td>' . esc_html( (string) $bucket['samples'] ) . '</td><td>' . esc_html( (string) $bucket['ratio'] ) . '%</td><td>' . esc_html( (string) $bucket['median_ms'] ) . '</td><td>' . esc_html( (string) $bucket['p95_ms'] ) . '</td></tr>'; }
			echo '</tbody></table></div></details>';
		}
	}

	private static function group_probe_form( array $row ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ch-action">'; wp_nonce_field( 'harborcache_action' );
		echo '<input type="hidden" name="action" value="harborcache_action"><input type="hidden" name="operation" value="inspect-group"><input type="hidden" name="scope" value="' . esc_attr( $row['scope'] ) . '"><input type="hidden" name="group" value="' . esc_attr( $row['group'] ) . '"><label><input type="checkbox" name="memory" value="1">' . esc_html__( 'Include memory', 'harborcache' ) . '</label> <button class="button" type="submit">' . esc_html__( 'Inspect group', 'harborcache' ) . '</button></form>';
	}

	private static function probe_controls() {
		echo '<section class="ch-panel"><h3>' . esc_html__( 'Connection diagnostics', 'harborcache' ) . '</h3><p>' . esc_html__( 'Run checks on demand. Group inspection stops at 500 physical keys, 20 scan pages or its cooperative time budget; incomplete results are partial, not total counts.', 'harborcache' ) . '</p><div class="ch-actions">';
		self::button( 'inspect-nodes', __( 'Test Redis nodes', 'harborcache' ) ); self::button( 'inspect-relay', __( 'Inspect Relay adaptive cache', 'harborcache' ) );
		echo '</div>'; self::probe_result( 'inspect-nodes' ); self::probe_result( 'inspect-relay' ); echo '</section>';
	}

	private static function probe_result( $operation ) {
		$probe = get_user_meta( get_current_user_id(), '_harborcache_probe', true );
		if ( is_array( $probe ) && $operation === ( $probe['operation'] ?? '' ) && time() - ( $probe['created'] ?? 0 ) < 300 ) { echo '<pre class="ch-probe-result">' . esc_html( wp_json_encode( $probe['data'], JSON_PRETTY_PRINT ) ) . '</pre>'; }
	}
}

class Commands {
	/** Display cache status. */
	public function status() { \WP_CLI::line( wp_json_encode( Console::status(), JSON_PRETTY_PRINT ) ); }
	/** Display redacted diagnostics. */
	public function diagnostics() { \WP_CLI::line( wp_json_encode( array( 'status' => Console::status(), 'options' => Console::read( 'options' ) ), JSON_PRETTY_PRINT ) ); }
	/** Install the owned object-cache drop-in. */
	public function enable() { $this->run( 'enable' ); }
	/** Remove the owned object-cache drop-in. */
	public function disable() { $this->run( 'disable' ); }
	/** Refresh the owned drop-in. */
	public function update() { $this->run( 'update' ); }
	/** Flush the configured scope, or a selected group/site. */
	public function flush( $args, $assoc ) { $this->run( isset( $assoc['group'] ) ? 'flush-group' : ( isset( $assoc['site'] ) ? 'flush-site' : 'flush' ), $assoc ); }
	/** Show registered groups. */
	public function groups( $args, $assoc ) { $cache = Console::cache(); $data = ! empty( $assoc['group'] ) && $cache ? $cache->group_diagnostics( $assoc['scope'] ?? ( 'site-' . get_current_blog_id() ), $assoc['group'], isset( $assoc['memory'] ) ) : Console::read( 'groups' ); \WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) ); }
	/** Reclaim invalidated entries in bounded batches. */
	public function cleanup() { $this->run( 'cleanup' ); }
	/** Show redacted slow commands. */
	public function slowlog() { \WP_CLI::line( wp_json_encode( Console::read( 'slowlog' ), JSON_PRETTY_PRINT ) ); }
	/** Show server command statistics. */
	public function commands() { \WP_CLI::line( wp_json_encode( Console::read( 'commands' ), JSON_PRETTY_PRINT ) ); }
	/** Summarize up to 1000 samples. Options: --window=7200 --interval=300 --csv. */
	public function analytics( $args, $assoc ) { $rows = Core\Metrics::get( Analytics::window( $assoc['window'] ?? 7200 ), 1000 ); \WP_CLI::line( isset( $assoc['csv'] ) ? Analytics::csv( $rows ) : wp_json_encode( Analytics::summarize( $rows, $assoc['interval'] ?? 300 ), JSON_PRETTY_PRINT ) ); }
	/** Display a sampled request digest. */
	public function digest( $args, $assoc ) { $this->analytics( $args, $assoc ); }
	/** Display interval aggregates. */
	public function aggregate( $args, $assoc ) { $this->analytics( $args, $assoc ); }
	/** Watch sampled analytics. Options: --iterations=10 --interval=2 --window=7200. */
	public function watch( $args, $assoc ) { $iterations = max( 1, min( 10000, (int) ( $assoc['iterations'] ?? 10 ) ) ); $interval = max( 1, min( 60, (int) ( $assoc['interval'] ?? 2 ) ) ); for ( $i = 0; $i < $iterations; ++$i ) { $summary = Analytics::summarize( Core\Metrics::get( Analytics::window( $assoc['window'] ?? 7200 ), 1000 ) ); unset( $summary['buckets'] ); $summary['observed_at'] = gmdate( 'c' ); \WP_CLI::line( wp_json_encode( $summary ) ); if ( $i + 1 < $iterations ) { sleep( $interval ); } } }
	/** Test connected Redis nodes without exposing connection endpoints. */
	public function latency() { if ( ! Console::cache() ) { \WP_CLI::error( 'Cache is disconnected.' ); } \WP_CLI::line( wp_json_encode( Console::cache()->node_diagnostics(), JSON_PRETTY_PRINT ) ); }
	/** Show operational Redis health. */
	public function health() { if ( ! Console::cache() ) { \WP_CLI::error( 'Cache is disconnected.' ); } \WP_CLI::line( wp_json_encode( Console::cache()->operational_health(), JSON_PRETTY_PRINT ) ); }
	/** Show Relay adaptive status; optional --key=... --group=.... */
	public function relay( $args, $assoc ) { if ( ! Console::cache() ) { \WP_CLI::error( 'Cache is disconnected.' ); } \WP_CLI::line( wp_json_encode( Console::cache()->relay_diagnostics( $assoc['key'] ?? '', $assoc['group'] ?? 'default' ), JSON_PRETTY_PRINT ) ); }
	/** Run an opt-in periodic query recycling batch. */
	public function queries() { $this->run( 'query-maintenance' ); }
	private function run( $name, $args = array() ) { try { if ( false === Console::execute( $name, $args ) ) { \WP_CLI::error( 'Operation failed.' ); } \WP_CLI::success( 'Operation completed.' ); } catch ( \Throwable $error ) { \WP_CLI::error( 'Operation failed. Check filesystem access and Redis configuration.' ); } }
}
