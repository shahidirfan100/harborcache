<?php
/**
 * Key derivation for the advanced cache.
 *
 * Owns the layout signature, group/scope prefixes and request-local key caches.
 * The cache supplies token, scope, registration and fallback callbacks; this
 * class never talks to Redis itself.
 *
 * @package HarborCache
 */

namespace HarborCache;

defined( 'ABSPATH' ) || exit;

final class Key_Builder {
	/** @var array */
	private $config;
	/** @var string */
	private $base;
	/** @var string */
	private $root = '';
	/** @var callable */
	private $token;
	/** @var callable */
	private $scope;
	/** @var callable */
	private $register;
	/** @var callable */
	private $ignored;
	/** @var callable */
	private $fallback;
	/** @var callable */
	private $sanitize;
	/** @var array|null */
	private $layout_state;
	/** @var array */
	private $derived = array();
	/** @var array */
	private $prefixes = array();
	/** @var int */
	private $derived_count = 0;
	/** @var array */
	private $ids = array();
	/** @var int */
	private $ids_count = 0;
	/** @var array */
	private $raw = array();
	/** @var int */
	private $raw_count = 0;
	/** @var array */
	private $contexts = array();
	/** @var mixed */
	private $context_blog;
	/** @var array */
	private $registered = array();

	public function __construct( array &$config, $base, callable $token, callable $scope, callable $register, callable $ignored, callable $fallback, callable $sanitize ) {
		$this->config   = &$config;
		$this->base     = (string) $base;
		$this->token    = $token;
		$this->scope    = $scope;
		$this->register = $register;
		$this->ignored  = $ignored;
		$this->fallback = $fallback;
		$this->sanitize = $sanitize;
	}

	public function set_root( $root ) {
		$root = (string) $root;
		if ( $root !== $this->root ) {
			$this->root = $root;
			$this->reset();
		}
	}

	public function reset() {
		$this->layout_state  = null;
		$this->derived       = array();
		$this->prefixes      = array();
		$this->derived_count = 0;
		$this->ids           = array();
		$this->ids_count     = 0;
		$this->raw           = array();
		$this->raw_count     = 0;
		$this->contexts      = array();
		$this->context_blog  = null;
		$this->registered    = array();
	}

	public function cached_id( $bucket, $group, $key ) {
		return $this->ids[ $bucket ][ $group ][ $key ] ?? null;
	}

	public function build( $key, $group, $bucket ) {
		$group = $group ?: 'default';
		if ( ! $this->layout_current() ) { $this->reset_layout_caches(); }
		$raw   = (string) $key;
		if ( isset( $this->raw[ $bucket ][ $group ][ $raw ] ) ) { return $this->raw[ $bucket ][ $group ][ $raw ]; }
		$san_key   = call_user_func( $this->sanitize, $key );
		$san_group = call_user_func( $this->sanitize, $group );
		$id = $this->build_sanitized_internal( $san_key, $san_group, $bucket, true );
		if ( $this->raw_count >= 4096 ) { $this->raw = array(); $this->raw_count = 0; }
		$this->raw[ $bucket ][ $group ][ $raw ] = $id;
		++$this->raw_count;
		return $id;
	}

	public function build_many( array $keys, $group, $bucket ) {
		$group = $group ?: 'default';
		if ( ! $this->layout_current() ) { $this->reset_layout_caches(); }
		$san_group = call_user_func( $this->sanitize, $group );
		$result = array();
		foreach ( $keys as $key ) {
			$raw = (string) $key;
			if ( isset( $this->raw[ $bucket ][ $group ][ $raw ] ) ) { $result[ $key ] = $this->raw[ $bucket ][ $group ][ $raw ]; continue; }
			$san_key = call_user_func( $this->sanitize, $key );
			$id = $this->build_sanitized_internal( $san_key, $san_group, $bucket, true );
			if ( $this->raw_count >= 4096 ) { $this->raw = array(); $this->raw_count = 0; }
			$this->raw[ $bucket ][ $group ][ $raw ] = $id;
			++$this->raw_count;
			$result[ $key ] = $id;
		}
		return $result;
	}

	/** Build from a key and group that are already sanitized for the cache path. */
	public function build_sanitized( $key, $group, $bucket ) {
		return $this->build_sanitized_internal( $key, $group, $bucket, false );
	}

	private function build_sanitized_internal( $key, $group, $bucket, $layout_checked ) {
		$group = $group ?: 'default';
		if ( ! $layout_checked && ! $this->layout_current() ) { $this->reset_layout_caches(); }
		if ( isset( $this->ids[ $bucket ][ $group ][ $key ] ) ) { return $this->ids[ $bucket ][ $group ][ $key ]; }
		if ( call_user_func( $this->ignored, $group ) ) {
			return $this->remember( $bucket, $group, $key, call_user_func( $this->fallback, $key, $group ) );
		}
		$context = $this->context( $group, $bucket );
		if ( isset( $this->derived[ $context ][ $key ] ) ) { return $this->remember( $bucket, $group, $key, $this->derived[ $context ][ $key ] ); }
		if ( isset( $this->prefixes[ $context ] ) ) {
			$prefix = $this->prefixes[ $context ];
		} else {
			$prefix = $this->make_prefix( $group );
			if ( null === $prefix ) { return $this->remember( $bucket, $group, $key, call_user_func( $this->fallback, $key, $group ) ); }
			if ( count( $this->prefixes ) >= 256 ) { $this->prefixes = array(); }
			$this->prefixes[ $context ] = $prefix;
		}
		$derived = $prefix . hash( 'sha256', (string) $key );
		if ( $this->derived_count >= 1024 ) { $this->derived = array(); $this->derived_count = 0; }
		$this->derived[ $context ][ $key ] = $derived;
		++$this->derived_count;
		return $this->remember( $bucket, $group, $key, $derived );
	}

	private function make_prefix( $group ) {
		$layout = $this->config['split_alloptions'] && 'relay' === $this->config['client'] ? ':relay-options-v1' : '';
		$format = $this->config['serializer'] . ':' . $this->config['compression'] . ':' . (int) $this->config['split_alloptions'] . $layout . ':wp-' . ( $GLOBALS['wp_version'] ?? '' );
		$scope  = $this->base . ':' . $this->root . ':' . call_user_func( $this->scope, $group );
		$epoch  = call_user_func( $this->token, $scope . ':epoch' );
		if ( '' === $epoch ) { return null; }
		$group_key = $scope . ':' . $epoch . ':g:' . rawurlencode( $group );
		$version   = call_user_func( $this->token, $group_key . ':epoch' );
		if ( '' === $version ) { return null; }
		if ( ! isset( $this->registered[ $group_key ] ) ) {
			call_user_func( $this->register, $scope, $group_key, $group );
			$this->registered[ $group_key ] = true;
		}
		$signature = substr( hash( 'sha256', $format ), 0, 8 );
		$slot      = defined( 'WP_REDIS_CLUSTER' ) ? ':{hc-' . substr( hash( 'sha256', $group_key ), 0, 24 ) . '}' : '';
		return $group_key . ':' . $version . $slot . ':v:' . $signature . ':';
	}

	private function context( $group, $bucket ) {
		if ( $this->context_blog !== $bucket ) {
			$this->context_blog = $bucket;
			$this->contexts     = array();
		}
		if ( isset( $this->contexts[ $group ] ) ) { return $this->contexts[ $group ]; }
		if ( count( $this->contexts ) >= 256 ) { $this->contexts = array(); }
		return $this->contexts[ $group ] = call_user_func( $this->scope, $group ) . ':' . $group;
	}

	private function remember( $bucket, $group, $key, $id ) {
		if ( $this->ids_count >= 4096 ) {
			$this->ids = array();
			$this->ids_count = 0;
			$this->raw = array();
			$this->raw_count = 0;
		}
		$this->ids[ $bucket ][ $group ][ $key ] = $id;
		++$this->ids_count;
		return $id;
	}

	public function layout_current() {
		return null !== $this->layout_state &&
			$this->layout_state[0] === $this->config['serializer'] &&
			$this->layout_state[1] === $this->config['compression'] &&
			$this->layout_state[2] === $this->config['split_alloptions'] &&
			$this->layout_state[3] === $this->config['client'] &&
			$this->layout_state[4] === ( $GLOBALS['wp_version'] ?? '' );
	}

	private function reset_layout_caches() {
		$this->derived       = array();
		$this->prefixes      = array();
		$this->derived_count = 0;
		$this->ids           = array();
		$this->ids_count     = 0;
		$this->raw           = array();
		$this->raw_count     = 0;
		$this->contexts      = array();
		$this->context_blog  = null;
		$this->layout_state  = array( $this->config['serializer'], $this->config['compression'], $this->config['split_alloptions'], $this->config['client'], ( $GLOBALS['wp_version'] ?? '' ) );
	}
}
