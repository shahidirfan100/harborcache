<?php
/**
 * Authenticated REST surface for monitoring and scoped cache maintenance.
 *
 * The API intentionally exposes no connection credentials. Destructive routes
 * require the same capability as the HarborCache settings screens.
 *
 * @package HarborCache
 */

namespace HarborCache;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-analytics.php';

final class Rest {
	const NS = 'harborcache/v1';

	public static function register() {
		if ( ! class_exists( '\WP_REST_Server' ) ) { return; }
		$auth = array( __CLASS__, 'permission' );
		$read = \WP_REST_Server::READABLE;
		$post = \WP_REST_Server::CREATABLE;

		register_rest_route( self::NS, '/status', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'status' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/health', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'health' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/analytics', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'analytics' ),
			'permission_callback' => $auth,
			'args'                => array(
				'window'   => array( 'type' => 'integer', 'default' => 7200 ),
				'interval' => array( 'type' => 'integer', 'default' => 300 ),
			),
		) );
		register_rest_route( self::NS, '/groups', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'groups' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/groups/(?P<scope>global|site-[1-9][0-9]*)/(?P<group>[^/]{1,200})', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'group' ),
			'permission_callback' => $auth,
			'args'                => array(
				'memory' => array( 'type' => 'boolean', 'default' => false ),
			),
		) );
		register_rest_route( self::NS, '/slowlog', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'slowlog' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/relay', array(
			'methods'             => $read,
			'callback'            => array( __CLASS__, 'relay' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/flush', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'flush' ),
			'permission_callback' => $auth,
			'args'                => array(
				'scope' => array( 'type' => 'string', 'default' => '' ),
				'group' => array( 'type' => 'string', 'default' => '' ),
			),
		) );
	}

	public static function permission() {
		return current_user_can( Console::capability() );
	}

	private static function cache() {
		$cache = Console::cache();
		if ( ! $cache || ! $cache->redis_status() ) {
			return new \WP_Error( 'harborcache_unavailable', __( 'The cache is disconnected.', 'harborcache-object-cache-with-redis' ), array( 'status' => 503 ) );
		}
		return $cache;
	}

	/** Convert an unexpected backend failure into a REST response, not a PHP fatal. */
	private static function safely( callable $callback ) {
		try {
			return $callback();
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'harborcache_unavailable', __( 'The cache backend did not answer.', 'harborcache-object-cache-with-redis' ), array( 'status' => 503 ) );
		}
	}

	private static function network() {
		return ! is_multisite() || is_super_admin() || current_user_can( 'manage_network_options' );
	}

	public static function status( \WP_REST_Request $request ) {
		return self::safely( static function () {
			$status = Console::status();
			$keep   = array( 'version', 'installed', 'loaded', 'dropin', 'transport', 'namespace', 'codec', 'ready', 'state', 'reason', 'checked_at', 'database', 'serializer', 'compression', 'max_ttl', 'client', 'redis' );
			return rest_ensure_response( array_intersect_key( $status, array_flip( $keep ) ) );
		} );
	}

	public static function health( \WP_REST_Request $request ) {
		return self::safely( static function () {
			$cache  = Console::cache();
			$health = Console::health_result();
			$health['description'] = html_entity_decode( wp_strip_all_tags( (string) ( $health['description'] ?? '' ) ), ENT_QUOTES, 'UTF-8' );
			unset( $health['actions'] );
			$health['operational'] = $cache && $cache->redis_status() ? $cache->operational_health() : array( 'connected' => false );
			return rest_ensure_response( $health );
		} );
	}

	public static function analytics( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
		return self::safely( static function () use ( $request ) {
			$window   = Analytics::window( (int) $request->get_param( 'window' ) );
			$interval = max( 60, min( 3600, (int) $request->get_param( 'interval' ) ) );
			if ( ! Core\Metrics::is_enabled() ) {
				return rest_ensure_response( array( 'enabled' => false, 'window' => $window, 'summary' => null ) );
			}
			$summary = Analytics::summarize( Core\Metrics::get( $window, 1000 ), $interval );
			if ( 0 === (int) $summary['samples'] ) { $summary['ratio'] = null; }
			return rest_ensure_response( array( 'enabled' => true, 'window' => $window, 'summary' => $summary ) );
		} );
	}

	public static function groups( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
		return self::safely( static function () use ( $cache ) {
			$truncated = false;
			$groups    = $cache->group_list( $truncated );
			if ( ! self::network() ) {
				$current = 'site-' . get_current_blog_id();
				$groups  = array_values( array_filter( $groups, static function ( $item ) use ( $current ) {
					return 'global' === $item['scope'] || $current === $item['scope'];
				} ) );
			}
			return rest_ensure_response( array( 'groups' => $groups, 'truncated' => (bool) $truncated ) );
		} );
	}

	public static function group( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
		return self::safely( static function () use ( $request, $cache ) {
			$scope = (string) $request->get_param( 'scope' );
			$group = str_replace( ':', '-', rawurldecode( (string) $request->get_param( 'group' ) ) );
			if ( preg_match( '/^site-([1-9][0-9]*)$/D', $scope, $match ) && (int) $match[1] !== get_current_blog_id() && ! self::network() ) {
				return new \WP_Error( 'harborcache_group_forbidden', __( 'Only a network administrator can inspect another site.', 'harborcache-object-cache-with-redis' ), array( 'status' => 403 ) );
			}
			if ( strlen( $group ) > 200 ) {
				return new \WP_Error( 'harborcache_invalid_group', __( 'The cache group is too long.', 'harborcache-object-cache-with-redis' ), array( 'status' => 400 ) );
			}
			$truncated = false;
			$found     = false;
			foreach ( $cache->group_list( $truncated ) as $item ) {
				if ( $item['scope'] === $scope && $item['group'] === $group ) { $found = true; break; }
			}
			if ( ! $found && ! $truncated ) {
				return new \WP_Error( 'harborcache_unknown_group', __( 'The cache group is not registered.', 'harborcache-object-cache-with-redis' ), array( 'status' => 404 ) );
			}
			return rest_ensure_response( $cache->group_diagnostics( $scope, $group, (bool) $request->get_param( 'memory' ) ) );
		} );
	}

	public static function slowlog( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
		if ( ! empty( $cache->ch_config['shared'] ) ) {
			return new \WP_Error( 'harborcache_slowlog_shared', __( 'The Redis slow log is disabled on a shared server.', 'harborcache-object-cache-with-redis' ), array( 'status' => 403 ) );
		}
		return self::safely( static function () use ( $cache ) {
			try {
				$rows = $cache->ch_command( array( 'SLOWLOG', 'GET', 25 ) );
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'harborcache_slowlog_unavailable', __( 'Redis did not return the slow log.', 'harborcache-object-cache-with-redis' ), array( 'status' => 503 ) );
			}
			$log = array();
			foreach ( (array) $rows as $row ) {
				if ( ! is_array( $row ) || count( $row ) < 4 || ! is_array( $row[3] ) ) { continue; }
				$log[] = array(
					'id'           => (int) ( $row[0] ?? 0 ),
					'timestamp'    => (int) ( $row[1] ?? 0 ),
					'microseconds' => (int) ( $row[2] ?? 0 ),
					'command'      => strtoupper( (string) ( $row[3][0] ?? '' ) ),
				);
			}
			return rest_ensure_response( array( 'entries' => $log ) );
		} );
	}

	public static function relay( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
		return self::safely( static function () use ( $cache ) {
			$relay = $cache->relay_diagnostics();
			unset( $relay['stats'] );
			return rest_ensure_response( $relay );
		} );
	}

	public static function flush( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
		return self::safely( static function () use ( $request, $cache ) {
			$group = trim( (string) $request->get_param( 'group' ) );
			$scope = trim( (string) $request->get_param( 'scope' ) );
			if ( '' !== $group && '' !== $scope ) {
				return new \WP_Error( 'harborcache_flush_ambiguous', __( 'Use either a group or a scope, not both.', 'harborcache-object-cache-with-redis' ), array( 'status' => 400 ) );
			}
			if ( '' === $group && '' === $scope ) {
				return new \WP_Error( 'harborcache_flush_required', __( 'A group or scope is required.', 'harborcache-object-cache-with-redis' ), array( 'status' => 400 ) );
			}
			if ( '' !== $group ) {
				if ( strlen( $group ) > 200 ) {
					return new \WP_Error( 'harborcache_invalid_group', __( 'The cache group is too long.', 'harborcache-object-cache-with-redis' ), array( 'status' => 400 ) );
				}
				$strategy = 'full' === ( $cache->ch_config['group_flush'] ?? 'atomic' ) ? 'atomic' : null;
				if ( ! $cache->flush_group( $group, $strategy ) ) {
					return new \WP_Error( 'harborcache_flush_failed', __( 'The cache did not confirm the flush.', 'harborcache-object-cache-with-redis' ), array( 'status' => $cache->redis_status() ? 500 : 503 ) );
				}
				return rest_ensure_response( array( 'flushed' => true, 'group' => $group ) );
			}
			if ( 'site' === $scope ) { $scope = 'site-' . get_current_blog_id(); }
			$network = self::network();
			$policy  = (string) ( $cache->ch_config['network_flush'] ?? 'all' );
			if ( 'all' === $scope ) {
				if ( ! $network || 'all' !== $policy ) {
					return new \WP_Error( 'harborcache_flush_forbidden', __( 'This scope is not allowed by the connection policy.', 'harborcache-object-cache-with-redis' ), array( 'status' => 403 ) );
				}
			} elseif ( 'global' === $scope ) {
				if ( ! $network || ! in_array( $policy, array( 'all', 'global' ), true ) ) {
					return new \WP_Error( 'harborcache_flush_forbidden', __( 'This scope is not allowed by the connection policy.', 'harborcache-object-cache-with-redis' ), array( 'status' => 403 ) );
				}
			} elseif ( preg_match( '/^site-([1-9][0-9]*)$/D', $scope, $match ) ) {
				if ( (int) $match[1] !== get_current_blog_id() && ! $network ) {
					return new \WP_Error( 'harborcache_flush_forbidden', __( 'Only a network administrator can flush another site.', 'harborcache-object-cache-with-redis' ), array( 'status' => 403 ) );
				}
			} else {
				return new \WP_Error( 'harborcache_invalid_scope', __( 'The flush scope must be site, global, all or a site id.', 'harborcache-object-cache-with-redis' ), array( 'status' => 400 ) );
			}
			if ( ! $cache->flush_scope( $scope ) ) {
				return new \WP_Error( 'harborcache_flush_failed', __( 'The cache did not confirm the flush.', 'harborcache-object-cache-with-redis' ), array( 'status' => $cache->redis_status() ? 500 : 503 ) );
			}
			return rest_ensure_response( array( 'flushed' => true, 'scope' => $scope ) );
		} );
	}
}
