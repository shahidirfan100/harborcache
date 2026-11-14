<?php
/**
 * One health model for the cache, the dashboard, Site Health and WP-CLI.
 *
 * Transport health and cache readiness are separate facts. A reachable Redis
 * server does not prove that this installation owns its namespace or that the
 * active drop-in can decode values.
 *
 * @package HarborCache
 */

namespace HarborCache;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/ch-paths.php';
require_once __DIR__ . '/class-dropin-writer.php';

final class Status {
	private static $owned;
	private static $current;
	private static $installed;
	private static $health;

	public static function reset() {
		self::$owned    = null;
		self::$current  = null;
		self::$installed = null;
		self::$health   = null;
	}

	/** The live drop-in instance, or null when WordPress is using request memory. */
	public static function cache() {
		if ( ! isset( $GLOBALS['wp_object_cache'] ) || ! is_object( $GLOBALS['wp_object_cache'] ) ) {
			return null;
		}
		return $GLOBALS['wp_object_cache'] instanceof \HarborCache_Advanced_Cache ? $GLOBALS['wp_object_cache'] : null;
	}

	public static function owns_dropin() {
		if ( null !== self::$owned ) {
			return self::$owned;
		}
		$file = Paths::content( 'object-cache.php' );
		if ( ! is_readable( $file ) || is_link( $file ) ) {
			return self::$owned = false;
		}
		$text = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local drop-in header.
		return self::$owned = is_string( $text ) && false !== strpos( $text, 'HarborCache_Advanced_Cache' ) && false !== strpos( $text, 'HarborCache Drop-In' );
	}

	public static function dropin_current() {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$bundled = HARBORCACHE_PLUGIN_PATH . '/includes/object-cache.php';
		if ( ! self::owns_dropin() || ! is_readable( $bundled ) ) {
			return self::$current = false;
		}
		$text = Dropin_Writer::render( file_get_contents( $bundled ), HARBORCACHE_PLUGIN_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local template.
		$source = is_string( $text ) ? hash( 'sha256', $text ) : false;
		$active = hash_file( 'sha256', Paths::content( 'object-cache.php' ) );
		return self::$current = is_string( $source ) && is_string( $active ) && hash_equals( $source, $active );
	}

	/** One of missing, foreign, outdated or current. */
	public static function dropin_state() {
		if ( ! self::owns_dropin() ) {
			return file_exists( Paths::content( 'object-cache.php' ) ) ? 'foreign' : 'missing';
		}
		return self::dropin_current() ? 'current' : 'outdated';
	}

	public static function installed() {
		if ( null === self::$installed ) {
			self::$installed = self::owns_dropin();
		}
		return self::$installed;
	}

	/**
	 * Read the current health snapshot.
	 *
	 * @param bool $with_bytes Measure exact runtime bytes (Diagnostics only).
	 * @return array
	 */
	public static function read( $with_bytes = false ) {
		$cache   = self::cache();
		$details = array();
		if ( $cache && method_exists( $cache, 'ch_diagnostics' ) ) {
			try {
				$details = (array) $cache->ch_diagnostics( $with_bytes );
			} catch ( \Throwable $error ) {
				$details = array( 'transport_ok' => false, 'ready' => false, 'reason' => 'backend-unavailable' );
			}
		}

		$disabled    = ( defined( 'HARBORCACHE_DISABLED' ) && HARBORCACHE_DISABLED ) || ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED );
		$installed   = self::installed();
		$dropin      = self::dropin_state();
		$transport   = ! empty( $details['transport_ok'] );
		$namespace   = isset( $details['namespace'] ) ? (string) $details['namespace'] : ( ! empty( $details['ready'] ) ? 'owned' : 'unknown' );
		$codec       = isset( $details['codec'] ) ? (string) $details['codec'] : 'unknown';
		$namespace_ready = ! empty( $details['ready'] );
		$ready       = $transport && $namespace_ready && 'ok' === $codec && 'current' === $dropin && ! $disabled;
		$reason      = isset( $details['reason'] ) ? (string) $details['reason'] : '';

		list( $state, $normalized_reason ) = self::state_for( array(
			'installed'   => $installed,
			'loaded'      => (bool) $cache,
			'disabled'    => $disabled,
			'dropin'      => $dropin,
			'transport'   => $transport,
			'namespace'   => $namespace,
			'codec'       => $codec,
			'ready'       => $ready,
			'detail'      => $reason,
		) );

		$snapshot = array_merge( $details, array(
			'version'     => defined( 'HARBORCACHE_VERSION' ) ? HARBORCACHE_VERSION : '',
			'installed'   => $installed,
			'loaded'      => (bool) $cache,
			'dropin'      => $dropin,
			'transport'   => $transport ? 'connected' : 'unreachable',
			'namespace'   => $namespace,
			'codec'       => $codec,
			'ready'       => $ready,
			'state'       => $state,
			'reason'      => $normalized_reason,
			'checked_at'  => 0,
		) );

		if ( $cache && isset( $cache->ch_config ) && is_array( $cache->ch_config ) ) {
			$live = $cache->ch_config;
			$snapshot['endpoint']    = 'unix' === ( $live['scheme'] ?? 'tcp' ) ? (string) ( $live['path'] ?? '' ) : (string) ( $live['host'] ?? '' ) . ':' . (int) ( $live['port'] ?? 0 );
			$snapshot['database']    = (int) ( $live['database'] ?? 0 );
			$snapshot['serializer']  = (string) ( $live['serializer'] ?? 'php' );
			$snapshot['compression'] = (string) ( $live['compression'] ?? 'none' );
			$snapshot['max_ttl']     = (int) ( $live['maxttl'] ?? 86400 );
		}

		// Legacy keys kept for integrations that still read them.
		$snapshot['connected'] = ! empty( $details['connected'] );
		return $snapshot;
	}

	/**
	 * One provider for connection, server, drop-in and cron facts.
	 * Site Health, REST /health and the dashboard read the same facts.
	 *
	 * @return array
	 */
	public static function health_facts() {
		if ( null !== self::$health ) { return self::$health; }
		$status  = self::read();
		$cache   = self::cache();
		$state   = (string) ( $status['state'] ?? 'unknown' );
		$ready   = ! empty( $status['ready'] );
		$dropin  = (string) ( $status['dropin'] ?? 'missing' );
		$issues  = array();
		$checks  = array();
		$server  = array( 'available' => false );

		$connection_ok = 'connected' === $state;
		$checks['connection'] = array(
			'label'       => __( 'HarborCache connection', 'harborcache-object-cache-with-redis' ),
			'status'      => $connection_ok ? 'good' : 'critical',
			'description' => $connection_ok ? __( 'Transport, namespace, codec and drop-in are current.', 'harborcache-object-cache-with-redis' ) : self::label( $status ) . ': ' . self::explain( $status ),
		);
		if ( ! $connection_ok ) {
			$issues[] = array( 'area' => 'connection', 'severity' => 'critical', 'message' => $checks['connection']['description'] );
		}

		$dropin_ok = 'current' === $dropin;
		$checks['dropin'] = array(
			'label'       => __( 'HarborCache drop-in', 'harborcache-object-cache-with-redis' ),
			'status'      => $dropin_ok ? 'good' : 'recommended',
			'description' => $dropin_ok
				? __( 'The owned drop-in matches this plugin version.', 'harborcache-object-cache-with-redis' )
				/* translators: %s: drop-in state. */
				: sprintf( __( 'The drop-in is %s. Install or update the owned drop-in.', 'harborcache-object-cache-with-redis' ), $dropin ),
		);
		if ( ! $dropin_ok ) {
			$issues[] = array( 'area' => 'dropin', 'severity' => 'recommended', 'message' => $checks['dropin']['description'] );
		}

		if ( $cache && $ready ) {
			try { $server = (array) $cache->operational_health() + array( 'available' => true ); } catch ( \Throwable $error ) { $server = array( 'available' => false ); }
		}
		$server_issues = array();
		if ( 'noeviction' === ( $server['maxmemory_policy'] ?? '' ) && (int) ( $server['maxmemory'] ?? 0 ) > 0 ) {
			$server_issues[] = __( 'Redis uses noeviction; exhausted memory can reject cache writes.', 'harborcache-object-cache-with-redis' );
		}
		if ( 'relay' === ( $cache ? ( $cache->ch_config['client'] ?? '' ) : '' ) && $cache ) {
			try {
				$relay  = $cache->relay_diagnostics();
				$memory = $relay['stats']['memory'] ?? null;
				if ( is_array( $memory ) && isset( $memory['used'], $memory['max'] ) && (int) $memory['max'] > 0 && (int) $memory['used'] >= (int) $memory['max'] * 0.9 ) {
					$server_issues[] = __( 'Relay memory is nearly full; its local cache is evicting entries.', 'harborcache-object-cache-with-redis' );
				}
			} catch ( \Throwable $error ) { /* Optional Relay diagnostics. */ }
		}
		$checks['server'] = array(
			'label'       => __( 'HarborCache Redis server', 'harborcache-object-cache-with-redis' ),
			'status'      => $server_issues ? 'recommended' : ( empty( $server['available'] ) ? 'recommended' : 'good' ),
			'description' => $server_issues ? implode( ' ', $server_issues ) : ( empty( $server['available'] ) ? __( 'Redis server details are unavailable.', 'harborcache-object-cache-with-redis' ) : __( 'Redis policy and capacity checks reported no issue.', 'harborcache-object-cache-with-redis' ) ),
		);
		foreach ( $server_issues as $message ) {
			$issues[] = array( 'area' => 'server', 'severity' => 'recommended', 'message' => $message );
		}

		$config  = Config::load();
		$cleanup = ! $config['async_flush'] || (bool) wp_next_scheduled( 'harborcache_cleanup' );
		$queries = ! $config['query_maintenance'] || (bool) wp_next_scheduled( 'harborcache_queries' );
		$cron_issues = array();
		if ( ! $cleanup ) { $cron_issues[] = __( 'The cache cleanup schedule is missing.', 'harborcache-object-cache-with-redis' ); }
		if ( ! $queries ) { $cron_issues[] = __( 'The query maintenance schedule is missing.', 'harborcache-object-cache-with-redis' ); }
		$checks['cron'] = array(
			'label'       => __( 'HarborCache cron schedules', 'harborcache-object-cache-with-redis' ),
			'status'      => $cron_issues ? 'recommended' : 'good',
			'description' => $cron_issues ? implode( ' ', $cron_issues ) : __( 'Enabled cleanup and maintenance events are scheduled.', 'harborcache-object-cache-with-redis' ),
		);
		foreach ( $cron_issues as $message ) {
			$issues[] = array( 'area' => 'cron', 'severity' => 'recommended', 'message' => $message );
		}

		$statuses = array_column( $checks, 'status' );
		$overall  = in_array( 'critical', $statuses, true ) ? 'critical' : ( in_array( 'recommended', $statuses, true ) ? 'recommended' : 'good' );
		self::$health = array(
			'status'  => $overall,
			'state'   => $state,
			'issues'  => $issues,
			'checks'  => $checks,
			'facts'   => array(
				'connection' => array( 'state' => $state, 'transport' => $status['transport'] ?? '', 'namespace' => $status['namespace'] ?? '', 'ready' => $ready ),
				'dropin'     => $dropin,
				'server'     => $server,
				'cron'       => array( 'cleanup' => $cleanup, 'queries' => $queries ),
			),
		);
		return self::$health;
	}

	/**
	 * Pure state mapping so every surface agrees and tests cover every branch.
	 *
	 * @param array $facts Normalized facts.
	 * @return array{0:string,1:string} State and reason.
	 */
	public static function state_for( array $facts ) {
		$installed = ! empty( $facts['installed'] );
		$loaded    = ! empty( $facts['loaded'] );
		$disabled  = ! empty( $facts['disabled'] );
		$dropin    = isset( $facts['dropin'] ) ? (string) $facts['dropin'] : 'missing';
		$transport = ! empty( $facts['transport'] );
		$namespace = isset( $facts['namespace'] ) ? (string) $facts['namespace'] : 'unknown';
		$codec     = isset( $facts['codec'] ) ? (string) $facts['codec'] : 'unknown';
		$ready     = ! empty( $facts['ready'] );
		$detail    = isset( $facts['detail'] ) ? (string) $facts['detail'] : '';

		if ( $disabled ) {
			return array( 'disabled', 'disabled' );
		}
		if ( ! $installed ) {
			return array( 'not-installed', 'foreign' === $dropin ? 'foreign-dropin' : 'drop-in-missing' );
		}
		if ( ! $loaded ) {
			return array( 'not-loaded', 'drop-in-not-loaded' );
		}
		if ( 'conflict' === $namespace ) {
			return array( 'degraded', 'namespace-conflict' );
		}
		if ( 'error' === $codec ) {
			return array( 'degraded', 'codec-error' );
		}
		if ( 'current' !== $dropin ) {
			return array( 'degraded', 'drop-in-outdated' );
		}
		if ( $transport && $ready ) {
			return array( 'connected', '' );
		}
		if ( $transport ) {
			return array( 'degraded', '' !== $detail ? $detail : 'not-ready' );
		}
		return array( 'unreachable', '' !== $detail ? $detail : 'backend-unavailable' );
	}

	public static function label( array $status ) {
		switch ( $status['state'] ?? '' ) {
			case 'connected':
				return __( 'Connected', 'harborcache-object-cache-with-redis' );
			case 'degraded':
				if ( 'namespace-conflict' === ( $status['reason'] ?? '' ) ) {
					return __( 'Namespace conflict', 'harborcache-object-cache-with-redis' );
				}
				if ( 'drop-in-outdated' === ( $status['reason'] ?? '' ) ) {
					return __( 'Drop-in update needed', 'harborcache-object-cache-with-redis' );
				}
				if ( 'codec-error' === ( $status['reason'] ?? '' ) ) {
					return __( 'Value decoding error', 'harborcache-object-cache-with-redis' );
				}
				return __( 'Cache degraded', 'harborcache-object-cache-with-redis' );
			case 'unreachable':
				return __( 'Not connected', 'harborcache-object-cache-with-redis' );
			case 'not-loaded':
				return __( 'Installed, not loaded', 'harborcache-object-cache-with-redis' );
			case 'disabled':
				return __( 'Disabled by configuration', 'harborcache-object-cache-with-redis' );
			default:
				return __( 'Not enabled', 'harborcache-object-cache-with-redis' );
		}
	}

	public static function explain( array $status ) {
		switch ( $status['state'] ?? '' ) {
			case 'connected':
				return __( 'Persistent caching is handling requests for this site.', 'harborcache-object-cache-with-redis' );
			case 'degraded':
				if ( 'namespace-conflict' === ( $status['reason'] ?? '' ) ) {
					return __( 'Redis answers, but this cache prefix belongs to another installation. Choose a distinct prefix or reclaim the namespace after confirming the old owner is gone.', 'harborcache-object-cache-with-redis' );
				}
				if ( 'drop-in-outdated' === ( $status['reason'] ?? '' ) ) {
					return __( 'The installed drop-in differs from this plugin version. Update it so cache reads and invalidation use the current engine.', 'harborcache-object-cache-with-redis' );
				}
				if ( 'codec-error' === ( $status['reason'] ?? '' ) ) {
					return __( 'Stored values could not be decoded with the configured serializer or compression. Old entries will be rebuilt.', 'harborcache-object-cache-with-redis' );
				}
				return __( 'The drop-in is loaded, but the cache is not ready for persistent reads and writes. Review the readiness checks below.', 'harborcache-object-cache-with-redis' );
			case 'unreachable':
				return __( 'The drop-in is loaded, but Redis did not answer with the configured connection. Check the endpoint, credentials and server health.', 'harborcache-object-cache-with-redis' );
			case 'not-loaded':
				return __( 'The drop-in is installed, but WordPress did not load it for this request. Check that the plugin directory is readable, then reload.', 'harborcache-object-cache-with-redis' );
			case 'disabled':
				return __( 'HARBORCACHE_DISABLED or WP_REDIS_DISABLED is set in wp-config.php. Remove the flag to use persistent caching.', 'harborcache-object-cache-with-redis' );
			default:
				if ( 'foreign-dropin' === ( $status['reason'] ?? '' ) ) {
					return __( 'Another object-cache.php is active. Disable its owning plugin before enabling HarborCache.', 'harborcache-object-cache-with-redis' );
				}
				return __( 'WordPress is using its request-only cache. Connect to Redis to start persistent caching.', 'harborcache-object-cache-with-redis' );
		}
	}

	public static function css_class( array $status ) {
		switch ( $status['state'] ?? '' ) {
			case 'connected':
				return 'is-connected';
			case 'degraded':
				return 'is-degraded';
			default:
				return 'is-disconnected';
		}
	}

	public static function is_ready( array $status ) {
		return ! empty( $status['ready'] );
	}
}
