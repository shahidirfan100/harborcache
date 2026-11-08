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
		register_rest_route( self::NS, '/groups/(?P<scope>global|site-[1-9][0-9]*)/(?P<group>[a-zA-Z0-9_%.:-]+)', array(
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
			'args'                => array(
				'key'   => array( 'type' => 'string', 'default' => '' ),
				'group' => array( 'type' => 'string', 'default' => 'default' ),
			),
		) );
		register_rest_route( self::NS, '/flush', array(
			'methods'             => $post,
			'callback'            => array( __CLASS__, 'flush' ),
			'permission_callback' => $auth,
			'args'                => array(
				'scope' => array( 'type' => 'string', 'default' => 'site' ),
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

	public static function status( \WP_REST_Request $request ) {
		$status = Console::status();
		unset( $status['endpoint'] );
		return rest_ensure_response( $status );
	}

	public static function health( \WP_REST_Request $request ) {
		$health = Console::health_result();
		$health['description'] = wp_strip_all_tags( (string) ( $health['description'] ?? '' ) );
		$health['actions'] = wp_strip_all_tags( (string) ( $health['actions'] ?? '' ) );
		return rest_ensure_response( $health );
	}

	public static function analytics( \WP_REST_Request $request ) {
		$window   = Analytics::window( (int) $request->get_param( 'window' ) );
		$interval = max( 60, min( 3600, (int) $request->get_param( 'interval' ) ) );
		$samples  = Core\Metrics::get( $window, 1000 );
		return rest_ensure_response( array(
			'window'  => $window,
			'summary' => Analytics::summarize( $samples, $interval ),
		) );
	}

	public static function groups( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
		return rest_ensure_response( array( 'groups' => $cache->group_list() ) );
	}

	public static function group( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
		$scope = (string) $request->get_param( 'scope' );
		$group = (string) $request->get_param( 'group' );
		return rest_ensure_response( $cache->group_diagnostics( $scope, $group, (bool) $request->get_param( 'memory' ) ) );
	}

	public static function slowlog( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
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
		return rest_ensure_response( $log );
	}

	public static function relay( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
		$relay = $cache->relay_diagnostics( (string) $request->get_param( 'key' ), (string) $request->get_param( 'group' ) );
		unset( $relay['stats'] );
		return rest_ensure_response( $relay );
	}

	public static function flush( \WP_REST_Request $request ) {
		$cache = self::cache();
		if ( is_wp_error( $cache ) ) { return $cache; }
		$group = trim( (string) $request->get_param( 'group' ) );
		if ( '' !== $group ) {
			if ( strlen( $group ) > 200 ) {
				return new \WP_Error( 'harborcache_invalid_group', __( 'The cache group is too long.', 'harborcache-object-cache-with-redis' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array( 'flushed' => (bool) $cache->flush_group( $group ), 'group' => $group ) );
		}
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		if ( 'site' === $scope ) { $scope = 'site-' . get_current_blog_id(); }
		if ( 'global' !== $scope && 'all' !== $scope && ! preg_match( '/^site-[1-9][0-9]*$/D', $scope ) ) {
			return new \WP_Error( 'harborcache_invalid_scope', __( 'The flush scope must be site, global, all or a site id.', 'harborcache-object-cache-with-redis' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'flushed' => (bool) $cache->flush_scope( $scope ), 'scope' => $scope ) );
	}
}
