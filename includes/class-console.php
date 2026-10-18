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
	private static $dropin_recovery_failed = false;
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
		add_action( 'plugins_loaded', array( __CLASS__, 'migrate_legacy_metrics_cron' ), 20 );
		add_filter( 'site_status_tests', array( __CLASS__, 'health' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_info' ) );
		add_filter( 'cron_schedules', static function ( $schedules ) { $schedules['harborcache_minute'] = array( 'interval' => 60, 'display' => __( 'Every minute (HarborCache)', 'harborcache-object-cache-with-redis' ) ); return $schedules; } );
		add_action( 'init', static function () { $config = Config::load(); if ( $config['query_maintenance'] && self::cache() && ! wp_next_scheduled( 'harborcache_queries' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'harborcache_queries' ); } elseif ( ! $config['query_maintenance'] && wp_next_scheduled( 'harborcache_queries' ) ) { wp_clear_scheduled_hook( 'harborcache_queries' ); } } );
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
		remove_action( 'admin_bar_menu', array( $plugin, 'render_admin_bar' ), 998 );
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
		$console_page     = in_array( $hook, array( 'settings_page_harborcache', 'settings_page_harborcache-network' ), true );
		$dashboard_widget = 'index.php' === $hook && self::dashboard_widget_visible();
		$chart_page        = $console_page || $dashboard_widget;
		$ui_data           = array( 'endpoint' => admin_url( 'admin-post.php' ), 'nonce' => wp_create_nonce( 'harborcache_action' ), 'confirm' => __( 'Invalidate the selected cache scope? Cached entries will be rebuilt by subsequent requests.', 'harborcache-object-cache-with-redis' ), 'copied' => __( 'Diagnostics copied.', 'harborcache-object-cache-with-redis' ), 'selected' => __( 'Report selected. Press Ctrl+C or Command+C to copy.', 'harborcache-object-cache-with-redis' ), 'flushing' => __( 'Invalidating cache…', 'harborcache-object-cache-with-redis' ), 'done' => __( 'Cache invalidated.', 'harborcache-object-cache-with-redis' ), 'failed' => __( 'Cache action failed. Open Diagnostics for connection details.', 'harborcache-object-cache-with-redis' ) );
		if ( $console_page ) { wp_enqueue_style( 'harborcache-console', plugins_url( 'assets/css/console.css', HARBORCACHE_FILE ), array(), HARBORCACHE_VERSION ); }
		if ( $chart_page ) {
			wp_enqueue_style( 'harborcache-chart', plugins_url( 'assets/css/chart.css', HARBORCACHE_FILE ), array(), HARBORCACHE_VERSION );
			wp_enqueue_script( 'harborcache-console', plugins_url( 'assets/js/console.js', HARBORCACHE_FILE ), array(), HARBORCACHE_VERSION, true );
			wp_localize_script( 'harborcache-console', 'harborcacheUI', $ui_data );
		}
		if ( is_admin_bar_showing() ) {
			wp_enqueue_script( 'harborcache-adminbar', plugins_url( 'assets/js/adminbar.js', HARBORCACHE_FILE ), array(), HARBORCACHE_VERSION, true );
			wp_localize_script( 'harborcache-adminbar', 'harborcacheUI', $ui_data );
		}
	}

	private static function dashboard_widget_visible() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'dashboard-network' ), true ) ) { return false; }
		$hidden = get_user_option( 'metaboxhidden_' . $screen->id );
		return ! is_array( $hidden ) || ! in_array( 'harborcache_status', $hidden, true );
	}

	public static function migrate_legacy_metrics_cron() {
		$marker = 'harborcache_metrics_cron_migrated';
		if ( get_option( $marker ) ) { return; }
		wp_clear_scheduled_hook( 'rediscache_discard_metrics' );
		add_option( $marker, 1, '', true );
	}

	public static function adminbar( $bar ) {
		if ( ! self::allowed() || ( defined( 'WP_REDIS_DISABLE_ADMINBAR' ) && WP_REDIS_DISABLE_ADMINBAR ) ) { return; }
		$bar->add_node( array( 'id' => 'harborcache', 'title' => 'HarborCache', 'href' => self::url() ) );
		$bar->add_node( array( 'id' => 'harborcache-settings', 'parent' => 'harborcache', 'title' => esc_html__( 'Cache dashboard', 'harborcache-object-cache-with-redis' ), 'href' => self::url() ) );
		if ( self::cache() && self::cache()->redis_status() ) { $bar->add_node( array( 'id' => 'harborcache-flush', 'parent' => 'harborcache', 'title' => esc_html__( 'Invalidate cache', 'harborcache-object-cache-with-redis' ), 'href' => self::url( 'tools' ) ) ); }
	}

	public static function url( $tab = 'overview' ) { return add_query_arg( array( 'page' => 'harborcache', 'view' => $tab ), network_admin_url( is_multisite() ? 'settings.php' : 'options-general.php' ) ); }

	private static function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! wp_is_file_mod_allowed( 'harborcache' ) || ! WP_Filesystem() ) { throw new \RuntimeException( esc_html__( 'WordPress could not open the filesystem. Configure filesystem access and try again.', 'harborcache-object-cache-with-redis' ) ); }
		return $GLOBALS['wp_filesystem'];
	}

	public static function owns_dropin() {
		if ( null !== self::$dropin_owned ) { return self::$dropin_owned; }
		$file = Paths::content( 'object-cache.php' );
		if ( ! is_readable( $file ) || is_link( $file ) ) { return self::$dropin_owned = false; }
		$text = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return self::$dropin_owned = is_string( $text ) && false !== strpos( $text, 'HarborCache_Advanced_Cache' ) && false !== strpos( $text, 'HarborCache Drop-In' );
	}

	public static function dropin_current() {
		if ( null !== self::$dropin_matches ) { return self::$dropin_matches; }
		$bundled = HARBORCACHE_PLUGIN_PATH . '/includes/object-cache.php';
		if ( ! self::owns_dropin() || ! is_readable( $bundled ) ) { return self::$dropin_matches = false; }
		$text = Dropin_Writer::render( file_get_contents( $bundled ), HARBORCACHE_PLUGIN_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the bundled local PHP template.
		$source = is_string( $text ) ? hash( 'sha256', $text ) : false;
		$active = hash_file( 'sha256', Paths::content( 'object-cache.php' ) );
		return self::$dropin_matches = is_string( $source ) && is_string( $active ) && hash_equals( $source, $active );
	}

	public static function maybe_update_owned_dropin() {
		$file = Paths::content( 'object-cache.php' );
		$backup = $file . '.harborcache-backup.php';
		if ( self::allowed() && file_exists( $backup ) ) {
			try {
				if ( Dropin_Writer::recover( self::filesystem(), $file, HARBORCACHE_PLUGIN_PATH . '/includes/object-cache.php' ) ) {
					self::reset_dropin_state();
					if ( function_exists( 'wp_opcache_invalidate' ) ) { wp_opcache_invalidate( $file, true ); }
				} else {
					self::$dropin_recovery_failed = true;
					error_log( '[HarborCache] Drop-in recovery could not verify or restore the saved copy. Preserve both drop-in files and check wp-content filesystem access.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			} catch ( \Throwable $error ) {
				self::$dropin_recovery_failed = true;
				// Do not log connection data or file contents. This is a file-lifecycle failure only.
				error_log( '[HarborCache] Drop-in recovery could not complete. Preserve the recovery backup and check wp-content filesystem access.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		if ( ! self::allowed() || ! Config::load()['auto_update_dropin'] || ! self::owns_dropin() || self::dropin_current() ) { return; }
		try { self::dropin( true ); } catch ( \Throwable $error ) {
			// Keep an actionable notice on the HarborCache screen; no endpoint or credential details are logged.
			self::$dropin_recovery_failed = true;
			error_log( '[HarborCache] Automatic drop-in refresh failed. Check the HarborCache admin notice and wp-content filesystem access.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function dropin_notice() {
		if ( ! self::is_console_screen() ) { return; }
		$backup = Paths::content( 'object-cache.php.harborcache-backup.php' );
		if ( self::allowed() && ( self::$dropin_recovery_failed || ( file_exists( $backup ) && ! self::owns_dropin() ) ) ) {
			$message = Dropin_Writer::error_message();
			if ( ! $message ) { $message = __( 'HarborCache retained a drop-in recovery copy but could not safely complete recovery. Preserve object-cache.php and object-cache.php.harborcache-backup.php, check wp-content permissions, then retry.', 'harborcache-object-cache-with-redis' ); }
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}
		if ( ! self::allowed() || ! self::owns_dropin() || self::dropin_current() ) { return; }
		$message = Dropin_Writer::error_message();
		if ( ! $message ) { $message = __( 'HarborCache could not refresh its cache drop-in. Check Redis connectivity and wp-content permissions, then update it below.', 'harborcache-object-cache-with-redis' ); }
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p>';
		self::button( 'update', __( 'Update drop-in', 'harborcache-object-cache-with-redis' ) );
		echo '</div>';
	}

	public static function is_console_screen( $screen_id = null ) {
		if ( null === $screen_id ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$screen_id = $screen ? $screen->id : '';
		}
		return is_string( $screen_id ) && in_array( $screen_id, array( 'settings_page_harborcache', 'settings_page_harborcache-network' ), true );
	}

	public static function dropin( $enable ) {
		$fs = self::filesystem();
		self::reset_dropin_state();
		$file = Paths::content( 'object-cache.php' );
		if ( $fs->exists( $file ) && ! self::owns_dropin() ) { throw new \RuntimeException( esc_html__( 'Another object-cache.php is installed. Disable its owning plugin before enabling HarborCache.', 'harborcache-object-cache-with-redis' ) ); }
		if ( $enable ) {
			$config = Config::load();
			new Codec( $config );
			if ( ( 'phpredis' === $config['client'] && ! class_exists( '\Redis' ) ) || ( 'relay' === $config['client'] && ! class_exists( '\Relay\Relay' ) ) ) { throw new \RuntimeException( esc_html__( 'Install the selected PHP Redis client extension before enabling this connection.', 'harborcache-object-cache-with-redis' ) ); }
			if ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED ) { throw new \RuntimeException( esc_html__( 'Remove the disabled configuration flag before enabling the cache.', 'harborcache-object-cache-with-redis' ) ); }
			$probe = new Core\Predis();
			$probe->connect();
			if ( ! self::owns_dropin() ) { $probe->invalidate_namespace(); }
			$ok = Dropin_Writer::replace( $fs, HARBORCACHE_PLUGIN_PATH . '/includes/object-cache.php', $file );
		} else { $ok = Dropin_Writer::remove( $fs, $file ); }
		self::reset_dropin_state();
		if ( ! $ok ) {
			error_log( '[HarborCache] Drop-in operation failed (' . sanitize_key( Dropin_Writer::last_error() ) . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			throw new \InvalidArgumentException( esc_html( Dropin_Writer::error_message() ?: __( 'The drop-in could not be written. Check wp-content permissions.', 'harborcache-object-cache-with-redis' ) ) );
		}
		if ( function_exists( 'wp_opcache_invalidate' ) ) { wp_opcache_invalidate( $file, true ); }
		return true;
	}

	public static function deactivate( $plugin ) {
		if ( ! defined( 'HARBORCACHE_BASENAME' ) || HARBORCACHE_BASENAME !== $plugin ) { return; }
		wp_clear_scheduled_hook( 'harborcache_metrics_discard' );
		wp_clear_scheduled_hook( 'rediscache_discard_metrics' ); // Remove the event name used by earlier HarborCache builds.
		wp_clear_scheduled_hook( 'harborcache_cleanup' );
		wp_clear_scheduled_hook( 'harborcache_queries' );
		if ( self::owns_dropin() ) { try { self::dropin( false ); } catch ( \Throwable $error ) { /* The drop-in also checks whether the installation remains readable. */ } }
	}

	public static function status() {
		$cache = self::cache();
		return array_merge( array( 'version' => HARBORCACHE_VERSION, 'installed' => self::owns_dropin(), 'connected' => false ), $cache ? $cache->ch_diagnostics() : array() );
	}

	public static function execute( $operation, array $args = array() ) {
		if ( 'enable' === $operation || 'update' === $operation ) { return self::dropin( true ); }
		if ( 'disable' === $operation ) { return self::dropin( false ); }
		$cache = self::cache();
		if ( ! $cache || ! $cache->redis_status() ) { throw new \RuntimeException( esc_html__( 'Enable a connected HarborCache drop-in first.', 'harborcache-object-cache-with-redis' ) ); }
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
				if ( empty( $cache->ch_config['allow_server_controls'] ) ) { throw new \RuntimeException( esc_html__( 'Server-wide reset controls require allow_server_controls in configuration.', 'harborcache-object-cache-with-redis' ) ); }
				return $cache->ch_command( 'reset-slowlog' === $operation ? array( 'SLOWLOG', 'RESET' ) : array( 'CONFIG', 'RESETSTAT' ) );
			default: throw new \InvalidArgumentException( esc_html__( 'Unknown cache action.', 'harborcache-object-cache-with-redis' ) );
		}
	}

	public static function save_config( array $input ) {
		$old = Config::load();
		$saved = is_readable( Paths::content( 'harborcache-config.php' ) ) ? include Paths::content( 'harborcache-config.php' ) : array();
		$saved = array_replace( Config::defaults(), is_array( $saved ) ? $saved : array() );
		foreach ( $input as $value ) { if ( ! is_scalar( $value ) && null !== $value ) { throw new \InvalidArgumentException( esc_html__( 'Settings must contain scalar values.', 'harborcache-object-cache-with-redis' ) ); } }
		foreach ( array( 'host', 'username', 'client', 'serializer', 'compression', 'network_flush', 'prefix', 'backoff', 'group_flush', 'pattern_flush', 'failure_mode' ) as $key ) { if ( isset( $input[ $key ] ) ) { $saved[ $key ] = sanitize_text_field( $input[ $key ] ); } }
		foreach ( array( 'port', 'database', 'maxttl', 'queryttl', 'retry_interval', 'retries', 'gc_batch_size', 'gc_budget_ms' ) as $key ) { if ( isset( $input[ $key ] ) ) { if ( false === filter_var( $input[ $key ], FILTER_VALIDATE_INT ) ) { throw new \InvalidArgumentException( esc_html__( 'Port, database, retry interval and TTL must be whole numbers.', 'harborcache-object-cache-with-redis' ) ); } $saved[ $key ] = (int) $input[ $key ]; } }
		foreach ( array( 'timeout', 'read_timeout' ) as $key ) { if ( isset( $input[ $key ] ) ) { $saved[ $key ] = (float) $input[ $key ]; } }
		foreach ( array( 'split_alloptions', 'prefetch', 'debug', 'save_commands', 'persistent', 'shared', 'async_flush', 'strict', 'auto_update_dropin', 'query_maintenance' ) as $key ) { $saved[ $key ] = ! empty( $input[ $key ] ); }
		foreach ( array( 'global_groups', 'non_persistent_groups', 'non_prefetchable_groups' ) as $key ) { $saved[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', explode( ',', $input[ $key ] ?? '' ) ) ) ); }
		$saved['password'] = ! empty( $input['clear_password'] ) ? '' : ( ! empty( $input['password'] ) ? (string) $input['password'] : $old['password'] );
		$saved['analytics'] = array_replace( $saved['analytics'], array( 'enabled' => ! empty( $input['analytics'] ), 'retention' => max( 60, min( 86400, absint( $input['retention'] ?? 7200 ) ) ), 'sample_rate' => max( 0, min( 100, (float) ( $input['sample_rate'] ?? 10 ) ) ) ) );
		if ( $saved['port'] < 1 || $saved['port'] > 65535 || $saved['database'] > 65535 || $saved['maxttl'] < 1 || $saved['maxttl'] > 2592000 || $saved['queryttl'] < 1 || $saved['queryttl'] > 2592000 ) { throw new \InvalidArgumentException( esc_html__( 'Check port, database and TTL values.', 'harborcache-object-cache-with-redis' ) ); }
		foreach ( array( 'timeout', 'read_timeout' ) as $key ) { if ( $saved[ $key ] <= 0 || $saved[ $key ] > 30 ) { throw new \InvalidArgumentException( esc_html__( 'Timeouts must be above zero and at most 30 seconds.', 'harborcache-object-cache-with-redis' ) ); } }
		if ( ! in_array( $saved['client'], array( 'predis', 'phpredis', 'relay', 'credis' ), true ) || ! in_array( $saved['network_flush'], array( 'all', 'site', 'global' ), true ) ) { throw new \InvalidArgumentException( esc_html__( 'Unknown connection or flush mode.', 'harborcache-object-cache-with-redis' ) ); }
		$exportable = static function ( $value ) use ( &$exportable ) { if ( is_object( $value ) || is_resource( $value ) ) { return false; } if ( is_array( $value ) ) { foreach ( $value as $entry ) { if ( ! $exportable( $entry ) ) { return false; } } } return true; };
		if ( ! $exportable( $saved ) ) { throw new \InvalidArgumentException( esc_html__( 'Put callback and object configuration in HARBORCACHE_CONFIG; the settings file accepts scalar values and arrays.', 'harborcache-object-cache-with-redis' ) ); }
		Config::validate( $saved );
		new Codec( array_replace( $old, $saved ) );
		$fs = self::filesystem();
		$path = Paths::content( 'harborcache-config.php' );
		if ( is_link( $path ) ) { throw new \RuntimeException( esc_html__( 'Refusing to replace a linked configuration file.', 'harborcache-object-cache-with-redis' ) ); }
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generate the guarded PHP configuration, not debug output.
		$contents = "<?php\n// HarborCache configuration. Credentials are never sent to an external service.\ndefined( 'ABSPATH' ) || exit;\nreturn " . var_export( $saved, true ) . ";\n";
		$temp = $path . '.' . wp_generate_password( 12, false ) . '.php';
		if ( ! $fs->put_contents( $temp, $contents, 0600 ) || ! $fs->move( $temp, $path, true ) ) { $fs->delete( $temp ); throw new \RuntimeException( esc_html__( 'Could not save configuration in wp-content.', 'harborcache-object-cache-with-redis' ) ); }
		if ( function_exists( 'wp_opcache_invalidate' ) ) { wp_opcache_invalidate( $path, true ); }
		return true;
	}

	public static function handle() {
		if ( ! self::allowed() ) { wp_die( esc_html__( 'You do not have permission to manage HarborCache.', 'harborcache-object-cache-with-redis' ), '', array( 'response' => 403 ) ); }
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
			if ( false === $result ) { throw new \RuntimeException( esc_html__( 'The operation did not succeed.', 'harborcache-object-cache-with-redis' ) ); }
			update_user_meta( get_current_user_id(), '_harborcache_notice', array( 'success', __( 'Action completed. Configuration changes apply on the next request.', 'harborcache-object-cache-with-redis' ), time() ) );
		} catch ( \Throwable $error ) {
			update_user_meta( get_current_user_id(), '_harborcache_notice', array( 'error', $error instanceof \InvalidArgumentException ? $error->getMessage() : __( 'Action failed. Check the connection, filesystem access and prerequisites in Diagnostics.', 'harborcache-object-cache-with-redis' ), time() ) );
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
		$tests['direct']['harborcache'] = array( 'label' => __( 'HarborCache health', 'harborcache-object-cache-with-redis' ), 'test' => array( __CLASS__, 'health_result' ) );
		return $tests;
	}

	public static function health_result() {
		$cache = self::cache(); $issues = array();
		if ( ! $cache || ! $cache->redis_status() ) { $issues[] = __( 'Persistent Redis caching is not connected.', 'harborcache-object-cache-with-redis' ); }
		if ( self::owns_dropin() && ! self::dropin_current() ) { $issues[] = __( 'The owned drop-in differs from the installed plugin.', 'harborcache-object-cache-with-redis' ); }
		if ( $cache && $cache->redis_status() ) {
			$health = $cache->operational_health();
			if ( 'noeviction' === ( $health['maxmemory_policy'] ?? '' ) ) { $issues[] = __( 'Redis uses noeviction; exhausted memory can reject cache writes. Review the server policy.', 'harborcache-object-cache-with-redis' ); }
			if ( Config::load()['async_flush'] && ! wp_next_scheduled( 'harborcache_cleanup' ) ) { $issues[] = __( 'The cache cleanup schedule is missing.', 'harborcache-object-cache-with-redis' ); }
			if ( Config::load()['query_maintenance'] && ! wp_next_scheduled( 'harborcache_queries' ) ) { $issues[] = __( 'The query maintenance schedule is missing.', 'harborcache-object-cache-with-redis' ); }
		}
		return array( 'label' => $issues ? __( 'HarborCache needs attention', 'harborcache-object-cache-with-redis' ) : __( 'HarborCache is connected and current', 'harborcache-object-cache-with-redis' ), 'status' => $issues ? 'recommended' : 'good', 'badge' => array( 'label' => __( 'Performance', 'harborcache-object-cache-with-redis' ), 'color' => 'blue' ), 'description' => '<p>' . esc_html( $issues ? implode( ' ', $issues ) : __( 'The connection and owned drop-in checks passed.', 'harborcache-object-cache-with-redis' ) ) . '</p>', 'actions' => '<p><a href="' . esc_url( self::url( 'diagnostics' ) ) . '">' . esc_html__( 'Open HarborCache diagnostics', 'harborcache-object-cache-with-redis' ) . '</a></p>', 'test' => 'harborcache' );
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
		echo '<div class="ch-connection-controls"><strong class="' . ( $status['connected'] ? 'ch-connected' : 'ch-disconnected' ) . '">' . esc_html( $status['connected'] ? __( 'Redis connected', 'harborcache-object-cache-with-redis' ) : __( 'Disconnected', 'harborcache-object-cache-with-redis' ) ) . '</strong>';
		self::button( $status['installed'] ? 'disable' : 'enable', $status['installed'] ? __( 'Disconnect', 'harborcache-object-cache-with-redis' ) : __( 'Connect', 'harborcache-object-cache-with-redis' ) );
		echo '</div><p class="description">' . esc_html( $status['connected'] ? __( 'Disconnect turns off persistent caching for this WordPress installation. Redis stays running; connection settings are kept. Reconnecting starts a fresh cache.', 'harborcache-object-cache-with-redis' ) : __( 'Persistent caching is not active. Check the connection settings and connect to start a fresh cache.', 'harborcache-object-cache-with-redis' ) ) . '</p>';
		if ( $status['connected'] ) {
			$samples = Core\Metrics::get( null, 100 );
			self::chart( $samples );
			echo '<details class="ch-request-details"><summary>' . esc_html__( 'Current dashboard request', 'harborcache-object-cache-with-redis' ) . '</summary><table class="widefat striped"><tbody>';
			foreach ( array( 'ratio' => __( 'Hit ratio (%)', 'harborcache-object-cache-with-redis' ), 'hits' => __( 'Cache hits', 'harborcache-object-cache-with-redis' ), 'misses' => __( 'Cache misses', 'harborcache-object-cache-with-redis' ), 'calls' => __( 'Cache operations', 'harborcache-object-cache-with-redis' ), 'milliseconds' => __( 'Request cache time (ms)', 'harborcache-object-cache-with-redis' ) ) as $key => $label ) {
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( $status[ $key ], 'milliseconds' === $key ? 3 : ( 'ratio' === $key ? 1 : 0 ) ) ) . '</td></tr>';
			}
			echo '</tbody></table></details>';
			if ( $samples ) {
				$hits = 0; $misses = 0; $seconds = 0;
				foreach ( $samples as $sample ) { $hits += $sample->hits; $misses += $sample->misses; $seconds += $sample->time; }
				/* translators: 1: sample count, 2: weighted hit ratio, 3: average Redis time in milliseconds. */
				echo '<p>' . esc_html( sprintf( __( 'Latest %1$s sampled requests: %2$s%% hit ratio; %3$s ms average Redis time.', 'harborcache-object-cache-with-redis' ), number_format_i18n( count( $samples ) ), number_format_i18n( $hits + $misses > 0 ? 100 * $hits / ( $hits + $misses ) : 100, 1 ), number_format_i18n( 1000 * $seconds / count( $samples ), 3 ) ) ) . '</p>';
			} else { echo '<p>' . esc_html__( 'No recent samples. Analytics must be enabled; samples appear after one minute.', 'harborcache-object-cache-with-redis' ) . '</p>'; }
			echo '<p class="description">' . esc_html__( 'Request measurements include runtime cache hits. Samples are local, may span sites sharing this cache namespace, and are not server-wide totals. Refresh the page to update.', 'harborcache-object-cache-with-redis' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open cache dashboard', 'harborcache-object-cache-with-redis' ) . '</a> · <a href="' . esc_url( self::url( 'analytics' ) ) . '">' . esc_html__( 'View analytics', 'harborcache-object-cache-with-redis' ) . '</a></p>';
	}

	public static function chart( array $samples ) {
		if ( ! $samples ) { return; }
		$metrics = array(
			'time' => array( __( 'Time', 'harborcache-object-cache-with-redis' ), __( 'Redis time', 'harborcache-object-cache-with-redis' ), 'ms', 1000 ),
			'bytes' => array( __( 'Bytes', 'harborcache-object-cache-with-redis' ), __( 'Runtime cache size', 'harborcache-object-cache-with-redis' ), 'KiB', 1 / 1024 ),
			'ratio' => array( __( 'Ratio', 'harborcache-object-cache-with-redis' ), __( 'Cache hit ratio', 'harborcache-object-cache-with-redis' ), '%', 1 ),
			'calls' => array( __( 'Calls', 'harborcache-object-cache-with-redis' ), __( 'Cache operations', 'harborcache-object-cache-with-redis' ), '', 1 ),
		);
		$first = (int) $samples[0]->timestamp; $last = (int) end( $samples )->timestamp;
		$id = wp_unique_id( 'ch-chart-' );
		echo '<section class="ch-chart" aria-label="' . esc_attr__( 'Sampled cache performance', 'harborcache-object-cache-with-redis' ) . '"><div class="ch-chart-toolbar"><div class="ch-chart-controls" role="group" aria-label="' . esc_attr__( 'Chart metric', 'harborcache-object-cache-with-redis' ) . '">';
		foreach ( $metrics as $key => $metric ) { echo '<button type="button" data-ch-metric="' . esc_attr( $key ) . '" aria-controls="' . esc_attr( $id . $key ) . '" aria-pressed="' . ( 'time' === $key ? 'true' : 'false' ) . '">' . esc_html( $metric[0] ) . '</button>'; }
		echo '</div><a href="' . esc_url( self::url( 'connection' ) ) . '">' . esc_html__( 'Settings', 'harborcache-object-cache-with-redis' ) . '</a></div>';
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
			foreach ( array( __( 'Latest', 'harborcache-object-cache-with-redis' ) => end( $values ), __( 'Average', 'harborcache-object-cache-with-redis' ) => $average, __( 'Peak', 'harborcache-object-cache-with-redis' ) => $peak ) as $label => $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( number_format_i18n( $value, 'calls' === $key ? 0 : 1 ) . ' ' . $metric[2] ) . '</dd></div>'; }
			echo '</dl></div>';
		}
		echo '<details class="ch-request-details"><summary>' . esc_html__( 'View chart data', 'harborcache-object-cache-with-redis' ) . '</summary><div class="ch-table-scroll"><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Time', 'harborcache-object-cache-with-redis' ) . '</th><th scope="col">ms</th><th scope="col">KiB</th><th scope="col">%</th><th scope="col">' . esc_html__( 'Operations', 'harborcache-object-cache-with-redis' ) . '</th></tr></thead><tbody>';
		foreach ( $samples as $sample ) { echo '<tr><th scope="row">' . esc_html( wp_date( 'H:i:s', $sample->timestamp ) ) . '</th><td>' . esc_html( number_format_i18n( $sample->time * 1000, 2 ) ) . '</td><td>' . esc_html( number_format_i18n( $sample->bytes / 1024, 1 ) ) . '</td><td>' . esc_html( number_format_i18n( $sample->ratio, 1 ) ) . '</td><td>' . esc_html( number_format_i18n( $sample->calls ) ) . '</td></tr>'; }
		echo '</tbody></table></div></details>';
		/* translators: 1: sample count, 2: configured sampling percentage, 3: last sample date and time. */
		echo '<p class="ch-chart-caption">' . esc_html( sprintf( __( '%1$s recent sampled requests · %2$s%% sampling · Last sample: %3$s · Samples are delayed by one minute. Site timezone.', 'harborcache-object-cache-with-redis' ), number_format_i18n( count( $samples ) ), number_format_i18n( Config::load()['analytics']['sample_rate'], 1 ), wp_date( 'M j, H:i:s', $last ) ) ) . '</p><noscript><p>' . esc_html__( 'Enable JavaScript to switch chart metrics. The Time chart and request details remain available.', 'harborcache-object-cache-with-redis' ) . '</p></noscript></section>';
	}

	private static function button( $operation, $label, $primary = false, $group = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ch-action">';
		wp_nonce_field( 'harborcache_action' );
		echo '<input type="hidden" name="action" value="harborcache_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="group" value="' . esc_attr( $group ) . '"><button class="button ' . ( $primary ? 'button-primary' : '' ) . '" type="submit" ' . ( 0 === strpos( $operation, 'flush' ) ? 'data-ch-confirm="1"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	public static function render() {
		if ( ! self::allowed() ) { return; }
		$tabs = array( 'overview' => __( 'Overview', 'harborcache-object-cache-with-redis' ), 'analytics' => __( 'Analytics', 'harborcache-object-cache-with-redis' ), 'connection' => __( 'Connection', 'harborcache-object-cache-with-redis' ), 'groups' => __( 'Cache groups', 'harborcache-object-cache-with-redis' ), 'tools' => __( 'Tools', 'harborcache-object-cache-with-redis' ), 'diagnostics' => __( 'Diagnostics', 'harborcache-object-cache-with-redis' ) );
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		if ( ! isset( $tabs[ $view ] ) ) { $view = 'overview'; }
		$status = self::status();
		$config = Config::load();
		echo '<div id="harborcache" class="wrap ch-console"><h1 class="ch-page-title">' . esc_html__( 'HarborCache – Object Cache with Redis', 'harborcache-object-cache-with-redis' ) . '</h1><div class="ch-notices"></div><header class="ch-header"><div class="ch-identity"><img src="' . esc_url( plugins_url( 'assets/brand/icon.svg', HARBORCACHE_FILE ) ) . '" alt="" width="44" height="44"><div><p class="ch-brand-name" aria-hidden="true">HarborCache</p><p>' . esc_html__( 'Persistent object caching for WordPress', 'harborcache-object-cache-with-redis' ) . '</p></div></div><div class="ch-connection-controls"><span class="ch-status ' . ( $status['connected'] ? 'is-connected' : '' ) . '">' . esc_html( $status['connected'] ? __( 'Connected', 'harborcache-object-cache-with-redis' ) : __( 'Not active', 'harborcache-object-cache-with-redis' ) ) . '</span>';
		self::button( $status['installed'] ? 'disable' : 'enable', $status['installed'] ? __( 'Disconnect', 'harborcache-object-cache-with-redis' ) : __( 'Connect', 'harborcache-object-cache-with-redis' ) );
		echo '</div></header>';
		$notice = get_user_meta( get_current_user_id(), '_harborcache_notice', true );
		if ( is_array( $notice ) ) { delete_user_meta( get_current_user_id(), '_harborcache_notice' ); if ( isset( $notice[2] ) && time() - $notice[2] < 60 ) { echo '<div class="notice notice-' . esc_attr( $notice[0] ) . '"><p>' . esc_html( $notice[1] ) . '</p></div>'; } }
		echo '<nav class="ch-tabs" aria-label="' . esc_attr__( 'Cache dashboard', 'harborcache-object-cache-with-redis' ) . '">';
		foreach ( $tabs as $slug => $label ) { echo '<a href="' . esc_url( self::url( $slug ) ) . '" ' . ( $view === $slug ? 'aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>'; }
		echo '</nav><main class="ch-main"><h2>' . esc_html( $tabs[ $view ] ) . '</h2>';
		if ( 'overview' === $view ) {
			echo '<p class="ch-intro">' . esc_html__( 'Check cache health, inspect the current request and manage persistent storage.', 'harborcache-object-cache-with-redis' ) . '</p><div class="ch-metrics">';
			foreach ( array( 'ratio' => __( 'Combined hit ratio', 'harborcache-object-cache-with-redis' ), 'calls' => __( 'Cache operations', 'harborcache-object-cache-with-redis' ), 'milliseconds' => __( 'Request cache time (ms)', 'harborcache-object-cache-with-redis' ), 'bytes' => __( 'Runtime cache (bytes)', 'harborcache-object-cache-with-redis' ) ) as $key => $label ) {
				$precision = 'ratio' === $key ? 1 : ( 'milliseconds' === $key ? 3 : 0 );
				$value = isset( $status[ $key ] ) ? number_format_i18n( $status[ $key ], $precision ) : '—';
				if ( isset( $status[ $key ] ) && 'ratio' === $key ) { $value .= '%'; }
				echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
			}
			echo '</div><p class="description">' . esc_html__( 'Combined hits include request-memory and Redis reads. Cache time is not full page response time; operation counts include namespace metadata.', 'harborcache-object-cache-with-redis' ) . '</p><p>' . esc_html( sprintf( /* translators: 1: runtime hits, 2: persistent store hits, 3: persistent store misses. */ __( 'Request-memory hits: %1$s · Redis-store hits: %2$s · Redis-store misses: %3$s.', 'harborcache-object-cache-with-redis' ), number_format_i18n( $status['runtime_hits'] ?? 0 ), number_format_i18n( $status['store_hits'] ?? 0 ), number_format_i18n( $status['store_misses'] ?? 0 ) ) ) . '</p>'; self::chart( Core\Metrics::get( null, 100 ) ); echo '<section class="ch-panel"><h3>' . esc_html__( 'Cache lifecycle', 'harborcache-object-cache-with-redis' ) . '</h3><p>' . esc_html__( 'Enable installs the drop-in after a connection check. Flushing invalidates only this installation’s namespace; unrelated Redis data is preserved.', 'harborcache-object-cache-with-redis' ) . '</p><div class="ch-actions">';
			if ( $status['installed'] ) { self::button( 'flush', __( 'Flush cache', 'harborcache-object-cache-with-redis' ) ); self::button( 'update', __( 'Update drop-in', 'harborcache-object-cache-with-redis' ) ); }
			echo '</div></section><section class="ch-panel"><h3>' . esc_html__( 'Connection summary', 'harborcache-object-cache-with-redis' ) . '</h3><dl class="ch-details">';
			foreach ( array( __( 'Transport', 'harborcache-object-cache-with-redis' ) => $status['client'] ?? $config['client'], __( 'Database', 'harborcache-object-cache-with-redis' ) => $config['database'], __( 'Serializer', 'harborcache-object-cache-with-redis' ) => $config['serializer'], __( 'Compression', 'harborcache-object-cache-with-redis' ) => $config['compression'], __( 'Maximum TTL', 'harborcache-object-cache-with-redis' ) => $config['maxttl'] . ' s' ) as $label => $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd></div>'; }
			echo '<div><dt>' . esc_html__( 'Redis allocation', 'harborcache-object-cache-with-redis' ) . '</dt><dd>' . esc_html( $config['shared'] ? __( 'Shared server', 'harborcache-object-cache-with-redis' ) : __( 'Dedicated server', 'harborcache-object-cache-with-redis' ) ) . '</dd></div></dl>';
			if ( $config['shared'] ) { echo '<p>' . esc_html__( 'Server memory and total key counts include other applications and are omitted here.', 'harborcache-object-cache-with-redis' ) . '</p>'; }
			elseif ( $status['connected'] ) {
				try {
					$capacity = self::read( 'capacity' );
					/* translators: 1: memory in bytes across primary nodes, 2: key count in the selected Redis database. */
					$caption = sprintf( __( 'Redis primary nodes: %1$s bytes used, %2$s keys in the selected database.', 'harborcache-object-cache-with-redis' ), number_format_i18n( $capacity['memory_bytes'] ), number_format_i18n( $capacity['keys'] ) );
					echo '<p>' . esc_html( $caption ) . '</p>';
				} catch ( \Throwable $ignored ) { echo '<p>' . esc_html__( 'Server capacity information is unavailable.', 'harborcache-object-cache-with-redis' ) . '</p>'; }
			}
			echo '</section>';
		} elseif ( 'connection' === $view ) { self::connection_form( $config ); }
		elseif ( 'groups' === $view ) {
			self::probe_result( 'inspect-group' );
			echo '<p>' . esc_html__( 'Groups observed in the current namespace. A group can remain listed after its entries expire.', 'harborcache-object-cache-with-redis' ) . '</p><div class="ch-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Group', 'harborcache-object-cache-with-redis' ) . '</th><th>' . esc_html__( 'Scope', 'harborcache-object-cache-with-redis' ) . '</th><th>' . esc_html__( 'Action', 'harborcache-object-cache-with-redis' ) . '</th></tr></thead><tbody>';
			foreach ( self::read( 'groups' ) as $row ) { echo '<tr><td><code>' . esc_html( $row['group'] ) . '</code></td><td>' . esc_html( $row['scope'] ) . '</td><td>'; self::button( 'flush-group', __( 'Flush group on this site', 'harborcache-object-cache-with-redis' ), false, $row['group'] ); self::group_probe_form( $row ); echo '</td></tr>'; }
			echo '</tbody></table></div>';
		} elseif ( 'analytics' === $view ) {
			$window_input = isset( $_GET['window'] ) && is_scalar( $_GET['window'] ) ? absint( wp_unslash( (string) $_GET['window'] ) ) : 7200; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only time filter.
			$window       = Analytics::window( $window_input );
			$rows = Core\Metrics::get( $window, 1000 );
			self::analytics_controls( $window, $rows );
			echo '<p>' . esc_html__( 'Local sampled requests, shown after one minute. This view reads at most 1,000 samples within the configured retention. Percentiles describe sampled requests, not individual commands.', 'harborcache-object-cache-with-redis' ) . '</p>';
			if ( ! $rows ) { echo '<div class="ch-empty"><h3>' . esc_html__( 'No measurements yet', 'harborcache-object-cache-with-redis' ) . '</h3><p>' . esc_html__( 'Enable caching and analytics, then browse the site. Return after one minute.', 'harborcache-object-cache-with-redis' ) . '</p></div>'; }
			else { echo '<div class="ch-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'harborcache-object-cache-with-redis' ) . '</th><th>' . esc_html__( 'Hit ratio', 'harborcache-object-cache-with-redis' ) . '</th><th>' . esc_html__( 'Commands', 'harborcache-object-cache-with-redis' ) . '</th><th>' . esc_html__( 'Time (ms)', 'harborcache-object-cache-with-redis' ) . '</th></tr></thead><tbody>'; foreach ( array_reverse( $rows ) as $row ) { echo '<tr><td>' . esc_html( wp_date( 'H:i:s', $row->timestamp ) ) . '</td><td><span class="ch-bar" style="--ch-ratio:' . esc_attr( (string) max( 0, min( 100, $row->ratio ) ) ) . '%"></span>' . esc_html( $row->ratio . '%' ) . '</td><td>' . esc_html( (string) $row->calls ) . '</td><td>' . esc_html( (string) round( $row->time * 1000, 3 ) ) . '</td></tr>'; } echo '</tbody></table></div>'; }
		} elseif ( 'tools' === $view ) {
			echo '<section class="ch-panel"><h3>' . esc_html__( 'Scoped invalidation', 'harborcache-object-cache-with-redis' ) . '</h3><p>' . esc_html__( 'Invalidation changes namespace tokens immediately. Old entries expire within the configured maximum TTL.', 'harborcache-object-cache-with-redis' ) . '</p><div class="ch-actions">'; self::button( 'flush', __( 'Flush configured scope', 'harborcache-object-cache-with-redis' ) ); self::button( 'flush-global', __( 'Flush global groups', 'harborcache-object-cache-with-redis' ) ); self::button( 'flush-runtime', __( 'Clear this request’s runtime cache', 'harborcache-object-cache-with-redis' ) ); self::button( 'cleanup', __( 'Run a cleanup batch', 'harborcache-object-cache-with-redis' ) ); if ( $config['query_maintenance'] ) { self::button( 'query-maintenance', __( 'Recycle query cache batch', 'harborcache-object-cache-with-redis' ) ); } echo '</div></section>';
			self::probe_controls();
			echo '<section class="ch-panel"><h3>' . esc_html__( 'Server observability', 'harborcache-object-cache-with-redis' ) . '</h3><p>' . esc_html__( 'Command statistics describe the Redis server. Slow-log output omits keys and values.', 'harborcache-object-cache-with-redis' ) . '</p><h4>' . esc_html__( 'Command statistics', 'harborcache-object-cache-with-redis' ) . '</h4><pre>' . esc_html( wp_json_encode( self::read( 'commands' ), JSON_PRETTY_PRINT ) ) . '</pre><h4>' . esc_html__( 'Slow commands', 'harborcache-object-cache-with-redis' ) . '</h4><pre>' . esc_html( wp_json_encode( self::read( 'slowlog' ), JSON_PRETTY_PRINT ) ) . '</pre></section>';
		} else {
			echo '<p>' . esc_html__( 'This report excludes credentials, connection endpoints, cached keys and values.', 'harborcache-object-cache-with-redis' ) . '</p><pre id="ch-diagnostics">' . esc_html( wp_json_encode( array( 'runtime' => self::status(), 'options' => self::read( 'options' ), 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ) ), JSON_PRETTY_PRINT ) ) . '</pre><button type="button" class="button" id="ch-copy">' . esc_html__( 'Copy diagnostics', 'harborcache-object-cache-with-redis' ) . '</button><p id="ch-feedback" role="status"></p><p>' . esc_html__( 'Review the verified feature matrix and deployment requirements in docs/FEATURES.md before production use.', 'harborcache-object-cache-with-redis' ) . '</p>';
		}
		echo '</main><footer class="ch-footer">HarborCache ' . esc_html( HARBORCACHE_VERSION ) . '</footer></div>';
	}

	private static function available_option( $key, $option ) {
		if ( 'client' === $key ) { return 'phpredis' === $option ? class_exists( '\Redis' ) : ( 'relay' !== $option || class_exists( '\Relay\Relay' ) ); }
		if ( 'serializer' === $key && 'igbinary' === $option ) { return function_exists( 'igbinary_serialize' ) && function_exists( 'igbinary_unserialize' ); }
		if ( 'compression' === $key && 'none' !== $option ) { return function_exists( $option . '_compress' ) && function_exists( 'lzf' === $option ? 'lzf_decompress' : $option . '_uncompress' ); }
		return true;
	}

	private static function connection_form( array $config ) {
		echo '<p>' . esc_html__( 'Settings are saved to a guarded PHP file in wp-content. HARBORCACHE_CONFIG and existing WP_REDIS constants take precedence. Change settings on staging before production.', 'harborcache-object-cache-with-redis' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ch-settings">';
		wp_nonce_field( 'harborcache_action' );
		echo '<input type="hidden" name="action" value="harborcache_action"><input type="hidden" name="operation" value="save"><section class="ch-settings-section" aria-labelledby="ch-settings-connection"><h3 id="ch-settings-connection">' . esc_html__( 'Connection and cache limits', 'harborcache-object-cache-with-redis' ) . '</h3><div class="ch-form-grid">';
		$fields = array( 'host' => __( 'Redis host or socket', 'harborcache-object-cache-with-redis' ), 'port' => __( 'Port', 'harborcache-object-cache-with-redis' ), 'database' => __( 'Database', 'harborcache-object-cache-with-redis' ), 'username' => __( 'ACL username', 'harborcache-object-cache-with-redis' ), 'password' => __( 'Password (blank keeps current)', 'harborcache-object-cache-with-redis' ), 'prefix' => __( 'Installation prefix', 'harborcache-object-cache-with-redis' ), 'timeout' => __( 'Connect timeout (seconds)', 'harborcache-object-cache-with-redis' ), 'read_timeout' => __( 'Read timeout (seconds)', 'harborcache-object-cache-with-redis' ), 'maxttl' => __( 'Maximum TTL (seconds)', 'harborcache-object-cache-with-redis' ), 'queryttl' => __( 'Query TTL (seconds)', 'harborcache-object-cache-with-redis' ), 'retry_interval' => __( 'Retry interval (milliseconds)', 'harborcache-object-cache-with-redis' ), 'retries' => __( 'Safe read retries (0–5)', 'harborcache-object-cache-with-redis' ), 'gc_budget_ms' => __( 'Cleanup time budget (milliseconds)', 'harborcache-object-cache-with-redis' ), 'gc_batch_size' => __( 'Cleanup keys per batch (1–1000)', 'harborcache-object-cache-with-redis' ), 'global_groups' => __( 'Extra global groups (comma separated)', 'harborcache-object-cache-with-redis' ), 'non_persistent_groups' => __( 'Non-persistent groups (supports *)', 'harborcache-object-cache-with-redis' ), 'non_prefetchable_groups' => __( 'Non-prefetchable groups', 'harborcache-object-cache-with-redis' ) );
		foreach ( $fields as $key => $label ) { $value = 'password' === $key ? '' : ( is_array( $config[ $key ] ) ? implode( ', ', $config[ $key ] ) : $config[ $key ] ); echo '<label for="ch-' . esc_attr( $key ) . '">' . esc_html( $label ) . '<input id="ch-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="' . ( 'password' === $key ? 'password' : 'text' ) . '" value="' . esc_attr( (string) $value ) . '" autocomplete="' . ( 'password' === $key ? 'new-password' : 'off' ) . '"></label>'; }
		foreach ( array( 'client' => array( 'predis', 'phpredis', 'relay', 'credis' ), 'serializer' => array( 'php', 'igbinary' ), 'compression' => array( 'none', 'lzf', 'lz4', 'zstd' ), 'network_flush' => array( 'all', 'site', 'global' ), 'backoff' => array( 'none', 'constant', 'exponential', 'smart' ), 'group_flush' => array( 'atomic', 'scan', 'incremental', 'keys', 'full' ), 'pattern_flush' => array( 'scan', 'incremental', 'keys' ), 'failure_mode' => array( 'silent', 'strict', 'debug' ) ) as $key => $options ) { echo '<label for="ch-' . esc_attr( $key ) . '">' . esc_html( ucwords( str_replace( '_', ' ', $key ) ) ) . '<select name="' . esc_attr( $key ) . '" id="ch-' . esc_attr( $key ) . '">'; foreach ( $options as $option ) { $available = self::available_option( $key, $option ); echo '<option value="' . esc_attr( $option ) . '" ' . selected( $config[ $key ], $option, false ) . ( $available || $config[ $key ] === $option ? '' : ' disabled' ) . '>' . esc_html( $option . ( $available ? '' : ': ' . __( 'extension unavailable', 'harborcache-object-cache-with-redis' ) ) ) . '</option>'; } echo '</select></label>'; }
		echo '</div><label class="ch-check"><input type="checkbox" name="clear_password" value="1">' . esc_html__( 'Clear saved password', 'harborcache-object-cache-with-redis' ) . '</label></section>';
		$checks = array( 'shared' => __( 'Redis is shared with other applications', 'harborcache-object-cache-with-redis' ), 'auto_update_dropin' => __( 'Automatically refresh HarborCache’s owned drop-in after upgrades', 'harborcache-object-cache-with-redis' ), 'query_maintenance' => __( 'Recycle query cache groups gradually every hour (also expires valid query entries)', 'harborcache-object-cache-with-redis' ), 'strict' => __( 'Protect this installation’s cache prefix ownership', 'harborcache-object-cache-with-redis' ), 'async_flush' => __( 'Reclaim invalidated entries in background batches', 'harborcache-object-cache-with-redis' ), 'persistent' => __( 'Reuse persistent connections where supported by the client', 'harborcache-object-cache-with-redis' ), 'split_alloptions' => __( 'Split autoloaded options (hash or Relay option strings)', 'harborcache-object-cache-with-redis' ), 'prefetch' => __( 'Enable registered key prefetching', 'harborcache-object-cache-with-redis' ), 'debug' => __( 'Enable debug mode', 'harborcache-object-cache-with-redis' ), 'save_commands' => __( 'Keep command names and timings for diagnostics', 'harborcache-object-cache-with-redis' ), 'analytics' => __( 'Collect local request analytics', 'harborcache-object-cache-with-redis' ), 'clear_password' => __( 'Clear saved password', 'harborcache-object-cache-with-redis' ) );
		$sections = array(
			__( 'Cache safety', 'harborcache-object-cache-with-redis' ) => array( 'shared', 'auto_update_dropin', 'strict' ),
			__( 'Performance and maintenance', 'harborcache-object-cache-with-redis' ) => array( 'persistent', 'split_alloptions', 'prefetch', 'async_flush', 'query_maintenance' ),
			__( 'Diagnostics and analytics', 'harborcache-object-cache-with-redis' ) => array( 'debug', 'save_commands', 'analytics' ),
		);
		foreach ( $sections as $heading => $keys ) {
			echo '<fieldset class="ch-settings-section"><legend>' . esc_html( $heading ) . '</legend><div class="ch-check-list">';
			foreach ( $keys as $key ) { $checked = 'analytics' === $key ? $config['analytics']['enabled'] : $config[ $key ]; echo '<label class="ch-check"><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $checked, true, false ) . '>' . esc_html( $checks[ $key ] ) . '</label>'; }
			echo '</div></fieldset>';
		}

		echo '<fieldset class="ch-settings-section"><legend>' . esc_html__( 'Analytics sampling', 'harborcache-object-cache-with-redis' ) . '</legend><div class="ch-form-grid ch-measurement-fields"><label for="ch-sample-rate">' . esc_html__( 'Sample rate (%)', 'harborcache-object-cache-with-redis' ) . ' <input id="ch-sample-rate" name="sample_rate" type="number" min="0" max="100" value="' . esc_attr( (string) $config['analytics']['sample_rate'] ) . '"></label><label for="ch-retention">' . esc_html__( 'Retention (seconds)', 'harborcache-object-cache-with-redis' ) . ' <input id="ch-retention" name="retention" type="number" min="60" max="86400" value="' . esc_attr( (string) $config['analytics']['retention'] ) . '"></label></div></fieldset><button class="button button-primary" type="submit">' . esc_html__( 'Save configuration', 'harborcache-object-cache-with-redis' ) . '</button></form>';
	}

	private static function analytics_controls( $window, array $rows ) {
		echo '<form method="get" class="ch-analytics-filter"><input type="hidden" name="page" value="harborcache"><input type="hidden" name="view" value="analytics"><label for="ch-window">' . esc_html__( 'Time window', 'harborcache-object-cache-with-redis' ) . '</label><select id="ch-window" name="window">';
		foreach ( array( 900 => __( '15 minutes', 'harborcache-object-cache-with-redis' ), 3600 => __( '1 hour', 'harborcache-object-cache-with-redis' ), 7200 => __( '2 hours', 'harborcache-object-cache-with-redis' ), 21600 => __( '6 hours', 'harborcache-object-cache-with-redis' ), 86400 => __( '24 hours', 'harborcache-object-cache-with-redis' ) ) as $seconds => $label ) { echo '<option value="' . esc_attr( (string) $seconds ) . '" ' . selected( $window, $seconds, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select><button class="button" type="submit">' . esc_html__( 'Apply', 'harborcache-object-cache-with-redis' ) . '</button></form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ch-action">';
		wp_nonce_field( 'harborcache_action' );
		echo '<input type="hidden" name="action" value="harborcache_action"><input type="hidden" name="operation" value="export-analytics"><input type="hidden" name="window" value="' . esc_attr( (string) $window ) . '"><button class="button" type="submit">' . esc_html__( 'Export samples (CSV)', 'harborcache-object-cache-with-redis' ) . '</button></form>';
		$summary = Analytics::summarize( $rows );
		if ( $rows ) {
			self::chart( array_slice( $rows, -100 ) );
			echo '<p>' . esc_html( sprintf( /* translators: 1: median time, 2: p95 time. */ __( 'Sampled request Redis time: median %1$s ms; 95th percentile %2$s ms.', 'harborcache-object-cache-with-redis' ), number_format_i18n( $summary['median_ms'], 3 ), number_format_i18n( $summary['p95_ms'], 3 ) ) ) . '</p><details class="ch-request-details"><summary>' . esc_html__( 'Five-minute summaries', 'harborcache-object-cache-with-redis' ) . '</summary><div class="ch-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'harborcache-object-cache-with-redis' ) . '</th><th>' . esc_html__( 'Samples', 'harborcache-object-cache-with-redis' ) . '</th><th>' . esc_html__( 'Hit ratio', 'harborcache-object-cache-with-redis' ) . '</th><th>' . esc_html__( 'Median ms', 'harborcache-object-cache-with-redis' ) . '</th><th>' . esc_html__( 'P95 ms', 'harborcache-object-cache-with-redis' ) . '</th></tr></thead><tbody>';
			foreach ( $summary['buckets'] as $bucket ) { echo '<tr><td>' . esc_html( wp_date( 'H:i', $bucket['timestamp'] ) ) . '</td><td>' . esc_html( (string) $bucket['samples'] ) . '</td><td>' . esc_html( (string) $bucket['ratio'] ) . '%</td><td>' . esc_html( (string) $bucket['median_ms'] ) . '</td><td>' . esc_html( (string) $bucket['p95_ms'] ) . '</td></tr>'; }
			echo '</tbody></table></div></details>';
		}
	}

	private static function group_probe_form( array $row ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ch-action">'; wp_nonce_field( 'harborcache_action' );
		echo '<input type="hidden" name="action" value="harborcache_action"><input type="hidden" name="operation" value="inspect-group"><input type="hidden" name="scope" value="' . esc_attr( $row['scope'] ) . '"><input type="hidden" name="group" value="' . esc_attr( $row['group'] ) . '"><label><input type="checkbox" name="memory" value="1">' . esc_html__( 'Include memory', 'harborcache-object-cache-with-redis' ) . '</label> <button class="button" type="submit">' . esc_html__( 'Inspect group', 'harborcache-object-cache-with-redis' ) . '</button></form>';
	}

	private static function probe_controls() {
		echo '<section class="ch-panel"><h3>' . esc_html__( 'Connection diagnostics', 'harborcache-object-cache-with-redis' ) . '</h3><p>' . esc_html__( 'Run checks on demand. Group inspection stops at 500 physical keys, 20 scan pages or its cooperative time budget; incomplete results are partial, not total counts.', 'harborcache-object-cache-with-redis' ) . '</p><div class="ch-actions">';
		self::button( 'inspect-nodes', __( 'Test Redis nodes', 'harborcache-object-cache-with-redis' ) ); self::button( 'inspect-relay', __( 'Inspect Relay adaptive cache', 'harborcache-object-cache-with-redis' ) );
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
