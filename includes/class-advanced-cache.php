<?php
/** Original extensions to the GPL Redis Object Cache engine. */
defined( 'ABSPATH' ) || exit;
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-codec.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-observer.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-cleanup.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-routing.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-native.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-topology.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-operations.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-relay-options.php';
require_once HARBORCACHE_PLUGIN_PATH . '/includes/ch-namespace.php';

class HarborCache_Advanced_Cache extends WP_Object_Cache {
	use HarborCache_Cleanup;
	use HarborCache_Routing;
	use HarborCache_Native;
	use HarborCache_Topology;
	use HarborCache_Operations;
	use HarborCache_Relay_Options;
	// shortcut: above 1000 distinct groups, discovery and epoch metadata evict an arbitrary group; old values stay TTL-bound. Move to per-group expiring token keys if dynamic group names are expected.
	const CH_GROUP_CAP = 1000;
	public $ch_config;
	// Also declare counters here so an older owned drop-in can load the upgraded plugin until refresh.
	public $runtime_hits = 0;
	public $store_hits = 0;
	public $store_misses = 0;
	private $ch_codec;
	private $ch_tokens = array();
	private $ch_epochs = null;
	private $ch_epochs_key;
	private $ch_registry = null;
	private $ch_writing = false;
	private $ch_touched = array();
	private $ch_root;
	private $ch_base;
	private $ch_ready = false;
	private $ch_registered = array();
	public $ch_commands = array();
	private $ch_prefetch_keys = array();
	private $ch_prefetch_skip = array();
	private $ch_prefetching = false;
	private $ch_option_snapshots = array();
	private $ch_reconnecting = false;
	private $ch_initializing = true;
	private $ch_derived = array();
	private $ch_prefixes = array();
	private $ch_derived_count = 0;
	private $ch_ids = array();
	private $ch_ids_count = 0;
	private $ch_single;
	private $ch_observe;
	private $ch_readonly = array( 'get' => true, 'mget' => true, 'pttl' => true );
	private $ch_layout_state;
	private $ch_relay_bypass = false;
	private $ch_ignored_source;
	private $ch_ignored_exact = array();
	private $ch_ignored_patterns = array();
	private $ch_ignored_results = array();
	public $ch_retries = 0;
	public $ch_transport_ok = false;
	private $ch_codec_ok = true;
	private $ch_failure_reason = '';
	private $ch_contexts = array();
	private $ch_context_blog;
	private $ch_default_sanitizer = false;

	public function __construct( $fail_gracefully = false ) {
		$this->ch_default_sanitizer = __CLASS__ === get_class( $this );
		$this->ch_config = \HarborCache\Config::load();
		$this->ch_single  = ! defined( 'WP_REDIS_CLUSTER' ) && ! $this->ch_config['servers'] && ! $this->ch_config['sentinels'];
		$this->ch_observe = $this->ch_config['save_commands'] || $this->ch_config['debug'] || $this->ch_config['retries'] > 0 || ( null !== $this->ch_config['tracer'] && 'none' !== $this->ch_config['tracer'] );
		$codec_error = null;
		try { $this->ch_codec = new \HarborCache\Codec( $this->ch_config ); }
		catch ( Throwable $error ) { $codec_error = $error; $this->ch_codec_ok = false; $this->ch_codec = new \HarborCache\Codec( array( 'serializer' => 'php', 'compression' => 'none' ) ); }
		parent::__construct( true );
		$this->ch_transport_ok = (bool) $this->redis_status();
		if ( $codec_error ) { $this->handle_exception( $codec_error ); }
		$this->ch_base = WP_REDIS_PREFIX . 'meta';
		if ( $this->redis_status() ) {
			try {
				if ( $this->ch_config['strict'] ) {
					\HarborCache\Namespace_Guard::claim( function ( $args ) {
						return 'GET' === $args[0] ? $this->ch_metadata_get( $args[1] ) : $this->ch_command( $args );
					}, $this->ch_base );
				}
				$this->ch_root = $this->ch_token( $this->ch_base . ':root' );
				$this->ch_ready = '' !== $this->ch_root && $this->redis_status();
			}
			catch ( Throwable $error ) { $this->handle_exception( $error ); }
		}
		if ( $this->ch_config['prefetch'] ) {
			add_action( 'redis_object_cache_get', array( $this, 'track_prefetch' ), 10, 6 );
			add_action( 'wp_loaded', array( $this, 'prefetch_request' ) );
			add_action( 'shutdown', array( $this, 'save_prefetch' ) );
		}
		$this->ch_initializing = false;
	}

	protected function handle_exception( $exception ) {
		$this->ch_ready = false;
		$conflict = $exception instanceof \HarborCache\Prefix_Conflict;
		$this->ch_failure_reason = $conflict ? 'prefix-conflict' : 'backend-unavailable';
		// A namespace conflict is not a transport failure. Remember that Redis answered
		// so the dashboard can report "Namespace conflict" instead of "Not connected".
		$this->ch_transport_ok = $conflict ? true : (bool) $this->redis_status();
		$message = $conflict ? 'Cache prefix belongs to another installation. Configure a distinct prefix or restore this installation identity.' : 'HarborCache backend unavailable. Check connection settings and Redis service.';
		// Discard native sockets with potentially unread replies before a persistent pool can reuse them.
		if ( $this->redis instanceof \Redis || $this->redis instanceof \RedisCluster || $this->redis instanceof \Relay\Relay || $this->redis instanceof \Relay\Cluster ) {
			try { $this->redis->close(); } catch ( Throwable $ignored ) {}
		}
		// Only explicit debug mode may terminate the request. Strict ownership still
		// falls back to request memory so a stale owner can never take down the site.
		if ( 'debug' === $this->ch_config['failure_mode'] ) {
			if ( $conflict ) { throw new RuntimeException( 'Cache prefix belongs to another installation. Configure a distinct prefix or restore this installation identity.' ); }
			throw new RuntimeException( 'HarborCache backend unavailable. Check connection settings and Redis service.' );
		}
		parent::handle_exception( new RuntimeException( $message ) );
	}

	protected function log_exception( $exception ) {
		static $logged = false;
		$debug = $this->ch_config['debug'] || 'debug' === $this->ch_config['failure_mode'];
		if ( ! $debug && $logged ) { return; }
		$logged = true;
		\HarborCache\Observer::log( $this->ch_config, 'error', $exception->getMessage(), array( 'reason' => $this->ch_failure_reason ) );
		error_log( $debug ? $exception : '[HarborCache] ' . $exception->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	protected function redis_pipeline() {
		if ( ! $this->redis_connected ) { throw new RuntimeException( 'Redis is unavailable.' ); }
		$client = $this->redis_instance();
		$pipeline = $client instanceof \Predis\Client && ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) ? $client->getClientBy( 'role', 'master' )->pipeline() : parent::redis_pipeline();
		return new \HarborCache\Pipeline( $pipeline, $this );
	}
	protected function redis_call( $method, ...$args ) {
		if ( ! $this->redis_connected ) { throw new RuntimeException( 'Redis is unavailable.' ); }
		$readonly = isset( $this->ch_readonly[ $method ] );
		if ( $this->ch_single ) {
			if ( $readonly ) { ++$this->ch_routes['primary']; }
			if ( ! $this->ch_observe ) {
				$result = parent::redis_call( $method, ...$args );
			} else {
				$result = $this->observe_command( strtoupper( $method ), function () use ( $method, $args ) { return parent::redis_call( $method, ...$args ); }, $readonly );
			}
			if ( false === $result ) { \HarborCache\Namespace_Guard::checked_reply( $this->redis_instance(), $result ); }
			return $result;
		}
		if ( ! $readonly ) { $this->mark_write( $args[0] ?? '' ); }
		return $this->observe_command( strtoupper( $method ), function () use ( $method, $args, $readonly ) {
			$call = function () use ( $method, $args ) {
				$result = parent::redis_call( $method, ...$args );
				if ( false === $result ) { \HarborCache\Namespace_Guard::checked_reply( $this->redis_instance(), $result ); }
				return $result;
			};
			$client = $this->redis_instance();
			if ( ! $readonly && $client instanceof \Predis\Client && ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) ) { return $client->getClientBy( 'role', 'master' )->$method( ...$args ); }
			return $readonly ? $this->routed_read( $method, $args, $call ) : $call();
		}, $readonly );
	}

	public function observe_command( $command, callable $call, $readonly = false, array $batch = array() ) {
		if ( ! $this->ch_observe ) { return $call(); }
		$start = microtime( true );
		$run = function () use ( $call, $readonly ) {
			$attempt = 0; $delay = $this->ch_config['retry_interval'];
			while ( true ) {
				try { return $call(); }
				catch ( Throwable $error ) {
					// A write may have succeeded before its reply was lost. Never replay it.
					if ( ! $readonly || $this->ch_reconnecting || $attempt >= $this->ch_config['retries'] || ! $this->redis_status() ) { throw $error; }
					++$attempt; ++$this->ch_retries;
					$cap = min( 1000, (int) ( $this->ch_config['read_timeout'] * 1000 ) );
					switch ( $this->ch_config['backoff'] ) {
						case 'none': $delay = 0; break;
						case 'exponential': $delay = min( $cap, $this->ch_config['retry_interval'] * ( 2 ** ( $attempt - 1 ) ) ); break;
						case 'smart': $delay = min( $cap, random_int( $this->ch_config['retry_interval'], max( $this->ch_config['retry_interval'], $delay * 3 ) ) ); break;
						default: $delay = min( $cap, $delay );
					}
					if ( $delay > 0 ) { usleep( $delay * 1000 ); }
					$this->ch_reconnecting = true;
					$this->reset_read_topology();
					if ( method_exists( $this, 'reset_server_info' ) ) { $this->reset_server_info(); }
					try {
						if ( is_callable( $this->ch_config['connector'] ) ) { $this->redis = call_user_func( $this->ch_config['connector'], $this->ch_config ); }
						elseif ( $this->redis instanceof \Predis\Client ) { $this->redis->disconnect(); $this->redis->connect(); }
						else { $method = 'connect_using_' . $this->ch_config['client']; $this->$method( $this->build_parameters() ); }
					} catch ( Throwable $ignored ) { /* The next bounded attempt reports the connection result. */ }
					finally { $this->ch_reconnecting = false; }
				}
			}
		};
		try { return \HarborCache\Observer::trace( $this->ch_config['tracer'], $command, $run ); }
		finally {
			if ( $this->ch_config['save_commands'] || $this->ch_config['debug'] ) {
				$names = $batch ?: array( $command );
				foreach ( $names as $name ) { if ( count( $this->ch_commands ) >= 200 ) { break; } $this->ch_commands[] = array( 'command' => $name, 'milliseconds' => round( ( microtime( true ) - $start ) * 1000, 3 ), 'batch' => ! empty( $batch ) ); }
			}
		}
	}

	public function ch_command( array $args ) {
		if ( ! $this->redis_status() ) { throw new RuntimeException( 'Redis is unavailable.' ); }
		if ( ! $this->ch_single ) {
			$command = strtoupper( $args[0] );
			if ( in_array( $command, array( 'DEL', 'UNLINK' ), true ) ) { $this->mark_write( array_slice( $args, 1 ) ); }
			elseif ( in_array( $command, array( 'SET', 'SETEX', 'HSET', 'HDEL' ), true ) ) { $this->mark_write( $args[1] ?? '' ); }
			elseif ( in_array( $command, array( 'EVAL', 'EVALSHA' ), true ) ) { $this->mark_write( array_slice( $args, 3, max( 0, (int) ( $args[2] ?? 0 ) ) ) ); }
		}
		$client = $this->redis_instance();
		$start = microtime( true );
		$call = function () use ( $args ) {
			$client = $this->redis_instance();
			if ( $client instanceof \Predis\Client ) {
				if ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) { return \HarborCache\Namespace_Guard::command( $client->getClientBy( 'role', 'master' ), $args ); }
				if ( defined( 'WP_REDIS_CLUSTER' ) && in_array( strtoupper( $args[0] ), array( 'PING', 'INFO', 'SLOWLOG', 'CONFIG' ), true ) ) { return \HarborCache\Namespace_Guard::command( $client->getClientBy( 'slot', 0 ), $args ); }
				return \HarborCache\Namespace_Guard::command( $client, $args );
			}
			if ( $client instanceof \RedisCluster || ( class_exists( '\Relay\Cluster' ) && $client instanceof \Relay\Cluster ) ) {
				$route = in_array( strtoupper( $args[0] ), array( 'EVAL', 'EVALSHA' ), true ) ? $args[3] : ( $args[1] ?? 'harborcache' );
				return $client->rawCommand( $route, ...$args );
			}
			if ( ! ( $client instanceof \Credis_Client ) && method_exists( $client, 'rawCommand' ) ) { return $client->rawCommand( ...$args ); }
			$method = strtolower( array_shift( $args ) );
			if ( $client instanceof \Credis_Client && 'sscan' === $method ) {
				$cursor = '0' === (string) $args[1] ? null : (int) $args[1];
				$values = $client->sscan( $cursor, $args[0], null, (int) ( $args[3] ?? 10 ) );
				return array( (string) $cursor, is_array( $values ) ? $values : array() );
			}
			if ( $client instanceof \Credis_Client && in_array( $method, array( 'eval', 'evalsha' ), true ) ) {
				$script = array_shift( $args ); $count = (int) array_shift( $args );
				return $client->$method( $script, array_slice( $args, 0, $count ), array_slice( $args, $count ) );
			}
			if ( $client instanceof \Credis_Client && 'mget' === $method ) { return $client->mget( $args ); }
			$result = $client->$method( ...$args );
			if ( $client instanceof \Credis_Client && 'hgetall' === $method && is_array( $result ) ) { $flat = array(); foreach ( $result as $field => $value ) { $flat[] = $field; $flat[] = $value; } return $flat; }
			return $result;
		};
		try {
			$result = $this->observe_command( strtoupper( $args[0] ), $call, in_array( strtoupper( $args[0] ), array( 'GET', 'HGETALL', 'SMEMBERS', 'INFO', 'PING', 'TTL', 'PTTL', 'EXISTS' ), true ) );
			return \HarborCache\Namespace_Guard::checked_reply( $client, $result );
		} finally {
			++$this->cache_calls;
			$this->cache_time += microtime( true ) - $start;
		}
	}

	private function ch_metadata_get( $key ) {
		$read = function () use ( $key ) { return $this->ch_command( array( 'GET', $key ) ); };
		return 'relay' === $this->ch_config['client'] ? $this->relay_uncached_read( $read ) : $read();
	}

	private function ch_token( $key ) {
		if ( isset( $this->ch_tokens[ $key ] ) ) { return $this->ch_tokens[ $key ]; }
		try {
		$field = $this->ch_epoch_field( $key );
		if ( null !== $field ) { $value = $this->ch_epoch_token( $field, $key ); }
		else {
			$value = $this->ch_metadata_get( $key );
			if ( null === $value ) {
				$value = bin2hex( random_bytes( 12 ) );
				$reply = $this->ch_command( array( 'SET', $key, $value, 'NX' ) );
				if ( null === $reply ) { $value = $this->ch_metadata_get( $key ); }
				elseif ( ! \HarborCache\Namespace_Guard::is_ok( $reply ) ) { throw new RuntimeException( 'Cache namespace initialization failed.' ); }
			}
		}
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{24}$/D', $value ) ) { throw new RuntimeException( 'Cache namespace metadata is invalid.' ); }
		return $this->ch_tokens[ $key ] = $value;
		} catch ( Throwable $error ) { $this->handle_exception( $error ); return ''; }
	}

	/** The epoch key suffix a field in the shared hash represents, or null for the root and other keys. */
	private function ch_epoch_field( $key ) {
		$base = $this->ch_base . ':' . $this->ch_root . ':';
		if ( 0 !== strpos( $key, $base ) ) { return null; }
		$rest = substr( $key, strlen( $base ) );
		if ( preg_match( '/^(global|site-[0-9]+):epoch$/D', $rest, $match ) ) { return $match[1]; }
		if ( preg_match( '/^(global|site-[0-9]+):[a-f0-9]{24}:g:([^:]+):epoch$/D', $rest, $match ) ) { return $match[1] . '|' . $match[2]; }
		return null;
	}

	private function ch_epochs_load() {
		if ( null !== $this->ch_epochs ) { return; }
		$this->ch_epochs_key = $this->ch_base . ':' . $this->ch_root . ':epochs';
		$this->ch_epochs = $this->ch_pairs( $this->ch_command( array( 'HGETALL', $this->ch_epochs_key ) ) );
	}

	/** HGETALL is flat for raw clients and associative for Predis. Normalize both. */
	private function ch_pairs( $raw ) {
		if ( ! is_array( $raw ) ) { return array(); }
		$pairs = array();
		if ( array_keys( $raw ) === range( 0, count( $raw ) - 1 ) ) {
			for ( $i = 0; $i + 1 < count( $raw ); $i += 2 ) { $pairs[ (string) $raw[ $i ] ] = (string) $raw[ $i + 1 ]; }
		} else {
			foreach ( $raw as $field => $value ) { $pairs[ (string) $field ] = (string) $value; }
		}
		return $pairs;
	}

	private function ch_epoch_token( $field, $legacy_key ) {
		$this->ch_epochs_load();
		if ( isset( $this->ch_epochs[ $field ] ) && preg_match( '/^[a-f0-9]{24}$/D', $this->ch_epochs[ $field ] ) ) { return $this->ch_epochs[ $field ]; }
		$legacy = $this->ch_metadata_get( $legacy_key );
		if ( is_string( $legacy ) && preg_match( '/^[a-f0-9]{24}$/D', $legacy ) ) {
			$script = "redis.call('HSET',KEYS[1],ARGV[1],ARGV[2]); redis.call('EXPIRE',KEYS[1],ARGV[3]); return ARGV[2]";
			$value  = $this->ch_command( array( 'EVAL', $script, 1, $this->ch_epochs_key, $field, $legacy, $this->ch_config['maxttl'] * 2 ) );
			if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{24}$/D', $value ) ) { $value = $legacy; }
			$this->ch_epochs[ $field ] = $value;
			return $value;
		}
		$candidate = bin2hex( random_bytes( 12 ) );
		$script    = "local v=redis.call('HGET',KEYS[1],ARGV[1]); if not v then redis.call('HSET',KEYS[1],ARGV[1],ARGV[2]); redis.call('EXPIRE',KEYS[1],ARGV[3]); v=ARGV[2]; local n=redis.call('HLEN',KEYS[1]); local cap=tonumber(ARGV[4]); if n>cap then local fields=redis.call('HKEYS',KEYS[1]); for i=1,n-cap do redis.call('HDEL',KEYS[1],fields[i]) end end end; return v";
		$value     = $this->ch_command( array( 'EVAL', $script, 1, $this->ch_epochs_key, $field, $candidate, $this->ch_config['maxttl'] * 2, self::CH_GROUP_CAP ) );
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{24}$/D', $value ) ) { $value = $this->ch_command( array( 'HGET', $this->ch_epochs_key, $field ) ); }
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{24}$/D', $value ) ) { throw new RuntimeException( 'Cache namespace metadata is invalid.' ); }
		$this->ch_epochs[ $field ] = $value;
		return $value;
	}

	private function ch_registry_load() {
		if ( null !== $this->ch_registry ) { return; }
		$this->ch_registry = array();
		foreach ( (array) $this->ch_command( array( 'SMEMBERS', $this->ch_base . ':' . $this->ch_root . ':groups' ) ) as $member ) {
			if ( is_string( $member ) ) { $this->ch_registry[ $member ] = true; }
		}
	}

	private function ch_scope( $group ) { return $this->is_global_group( $group ) ? 'global' : 'site-' . ( is_numeric( $this->blog_prefix ) ? (int) $this->blog_prefix : 1 ); }

	private function ch_context( $group ) {
		if ( $this->ch_context_blog !== $this->blog_prefix ) {
			$this->ch_context_blog = $this->blog_prefix;
			$this->ch_contexts = array();
		}
		if ( isset( $this->ch_contexts[ $group ] ) ) { return $this->ch_contexts[ $group ]; }
		if ( count( $this->ch_contexts ) >= 256 ) { $this->ch_contexts = array(); }
		return $this->ch_contexts[ $group ] = $this->ch_scope( $group ) . ':' . $group;
	}

	private function ch_register_group( $scope, $group_key, $group ) {
		// A read of a group creates only its epoch field. The first write registers it and refreshes metadata TTLs.
		if ( ! $this->ch_writing ) { return; }
		$registry = $this->ch_base . ':' . $this->ch_root . ':groups';
		$member   = $this->ch_scope( $group ) . '|' . rawurlencode( $group );
		if ( isset( $this->ch_touched[ $member ] ) ) { return; }
		$this->ch_touched[ $member ] = true;
		$expiry = $this->ch_config['maxttl'] * 2;
		$keys   = array( $registry, $scope . ':epoch', $group_key . ':epoch', $this->ch_epochs_key );
		if ( ! defined( 'WP_REDIS_CLUSTER' ) && ! $this->ch_config['servers'] && ! $this->ch_config['sentinels'] ) {
			$script = "redis.call('SADD',KEYS[1],ARGV[1]); local n=redis.call('SCARD',KEYS[1]); if n>tonumber(ARGV[3]) then redis.call('SPOP',KEYS[1],n-tonumber(ARGV[3])) end; for i=2,#KEYS do redis.call('EXPIRE',KEYS[i],ARGV[2]) end; return 1";
			$this->ch_command( array_merge( array( 'EVAL', $script, count( $keys ) ), $keys, array( $member, $expiry, self::CH_GROUP_CAP ) ) );
		} else {
			if ( $this->ch_command( array( 'SADD', $registry, $member ) ) ) {
				$count = (int) $this->ch_command( array( 'SCARD', $registry ) );
				if ( $count > self::CH_GROUP_CAP ) { $this->ch_command( array( 'SPOP', $registry, $count - self::CH_GROUP_CAP ) ); }
			}
			foreach ( $keys as $metadata ) { $this->ch_command( array( 'EXPIRE', $metadata, $expiry ) ); }
		}
		$this->ch_registry[ $member ] = true;
	}

	public function add_global_groups( $groups ) {
		$this->ch_contexts = array();
		$this->ch_ids = array(); $this->ch_ids_count = 0;
		$this->ch_derived = array(); $this->ch_prefixes = array(); $this->ch_derived_count = 0;
		parent::add_global_groups( $groups );
	}

	public function add_non_persistent_groups( $groups ) {
		parent::add_non_persistent_groups( $groups );
		$this->ch_ids = array(); $this->ch_ids_count = 0;
	}

	public function fast_build_key( $key, $group = 'default' ) {
		if ( ! $this->ch_ready ) { return parent::fast_build_key( $key, $group ); }
		try {
		if ( 'harborcache' === $group && 'metrics' === $key ) { return $this->ch_base . ( $this->ch_config['analytics']['persist'] ? '' : ':' . $this->ch_root ) . ':analytics:metrics'; }
		$group  = $group ?: 'default';
		if (
			null === $this->ch_layout_state ||
			$this->ch_layout_state[0] !== $this->ch_config['serializer'] ||
			$this->ch_layout_state[1] !== $this->ch_config['compression'] ||
			$this->ch_layout_state[2] !== $this->ch_config['split_alloptions'] ||
			$this->ch_layout_state[3] !== $this->ch_config['client'] ||
			$this->ch_layout_state[4] !== $this->ch_wp_version()
		) {
			$this->ch_derived = array();
			$this->ch_prefixes = array();
			$this->ch_derived_count = 0;
			$this->ch_ids = array();
			$this->ch_ids_count = 0;
			$this->ch_layout_state = array( $this->ch_config['serializer'], $this->ch_config['compression'], $this->ch_config['split_alloptions'], $this->ch_config['client'], $this->ch_wp_version() );
		}
		$bucket = $this->blog_prefix;
		if ( isset( $this->ch_ids[ $bucket ][ $group ][ $key ] ) ) { return $this->ch_ids[ $bucket ][ $group ][ $key ]; }
		if ( $this->is_ignored_group( $group ) ) { return $this->ch_id( $bucket, $group, $key, parent::fast_build_key( $key, $group ) ); }
		$layout = $this->ch_config['split_alloptions'] && 'relay' === $this->ch_config['client'] ? ':relay-options-v1' : '';
		$context = $this->ch_context( $group );
		$cacheable = is_string( $key ) || is_int( $key );
		if ( $cacheable && isset( $this->ch_derived[ $context ][ $key ] ) ) { return $this->ch_id( $bucket, $group, $key, $this->ch_derived[ $context ][ $key ] ); }
		if ( isset( $this->ch_prefixes[ $context ] ) ) { $prefix = $this->ch_prefixes[ $context ]; }
		else {
		$format = $this->ch_config['serializer'] . ':' . $this->ch_config['compression'] . ':' . (int) $this->ch_config['split_alloptions'] . $layout . ':wp-' . $this->ch_wp_version();
		$scope = $this->ch_base . ':' . $this->ch_root . ':' . $this->ch_scope( $group );
		$epoch = $this->ch_token( $scope . ':epoch' );
		if ( ! $this->ch_ready ) { return parent::fast_build_key( $key, $group ); }
		$group_key = $scope . ':' . $epoch . ':g:' . rawurlencode( $group );
		$version = $this->ch_token( $group_key . ':epoch' );
		if ( ! $this->ch_ready ) { return parent::fast_build_key( $key, $group ); }
		if ( ! isset( $this->ch_registered[ $group_key ] ) ) {
			$this->ch_register_group( $scope, $group_key, $group );
			$this->ch_registered[ $group_key ] = true;
		}
		$signature = substr( hash( 'sha256', $format ), 0, 8 );
		// Keep one group's bulk operations in one Redis Cluster hash slot.
		$slot = defined( 'WP_REDIS_CLUSTER' ) ? ':{hc-' . substr( hash( 'sha256', $group_key ), 0, 24 ) . '}' : '';
		$prefix = $group_key . ':' . $version . $slot . ':v:' . $signature . ':';
		if ( count( $this->ch_prefixes ) >= 256 ) { $this->ch_prefixes = array(); }
		$this->ch_prefixes[ $context ] = $prefix;
		}
		$derived = $prefix . hash( 'sha256', (string) $key );
		if ( $cacheable ) {
			if ( $this->ch_derived_count >= 1024 ) { $this->ch_derived = array(); $this->ch_derived_count = 0; }
			$this->ch_derived[ $context ][ $key ] = $derived; ++$this->ch_derived_count;
		}
		return $this->ch_id( $bucket, $group, $key, $derived );
		} catch ( Throwable $error ) { $this->handle_exception( $error ); return parent::fast_build_key( $key, $group ); }
	}

	/** Memoize one request-local cache id per blog, group and key. */
	private function ch_id( $bucket, $group, $key, $id ) {
		if ( ! is_string( $key ) && ! is_int( $key ) ) { return $id; }
		if ( $this->ch_ids_count >= 4096 ) { $this->ch_ids = array(); $this->ch_ids_count = 0; }
		$this->ch_ids[ $bucket ][ $group ][ $key ] = $id;
		++$this->ch_ids_count;
		return $id;
	}

	protected function maybe_serialize( $data ) { return $this->ch_codec->encode( $data ); }
	protected function maybe_unserialize( $data ) { return $this->ch_codec->decode( $data ); }

	protected function is_ignored_group( $group ) {
		if ( $this->ch_ignored_source !== $this->ignored_groups ) {
			$this->ch_ignored_source = $this->ignored_groups;
			$this->ch_ignored_exact = array(); $this->ch_ignored_patterns = array(); $this->ch_ignored_results = array();
			foreach ( $this->ignored_groups as $pattern ) {
				$pattern = (string) $pattern;
				if ( false === strpos( $pattern, '*' ) ) { $this->ch_ignored_exact[ $pattern ] = true; }
				else { $this->ch_ignored_patterns[] = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/D'; }
			}
		}
		$group = (string) $group;
		if ( isset( $this->ch_ignored_exact[ $group ] ) ) { return true; }
		if ( isset( $this->ch_ignored_results[ $group ] ) ) { return $this->ch_ignored_results[ $group ]; }
		$ignored = false;
		foreach ( $this->ch_ignored_patterns as $pattern ) { if ( preg_match( $pattern, $group ) ) { $ignored = true; break; } }
		if ( count( $this->ch_ignored_results ) >= 256 ) { $this->ch_ignored_results = array(); }
		$this->ch_ignored_results[ $group ] = $ignored;
		return $ignored;
	}

	private function ch_rotate( $key ) {
		$value = bin2hex( random_bytes( 12 ) );
		if ( ! \HarborCache\Namespace_Guard::is_ok( $this->ch_command( array( 'SET', $key, $value ) ) ) ) { throw new RuntimeException( 'Cache namespace rotation failed.' ); }
		if ( $key === $this->ch_base . ':root' ) {
			$this->ch_tokens = array();
			$this->ch_epochs = null;
			$this->ch_registry = null;
			$this->ch_epochs_key = null;
			$this->ch_touched = array();
		} else {
			$field = $this->ch_epoch_field( $key );
			if ( null !== $field ) {
				$this->ch_epochs_load();
				$reply = $this->ch_command( array( 'HSET', $this->ch_epochs_key, $field, $value ) );
				if ( null === $reply || false === $reply ) { throw new RuntimeException( 'Cache namespace rotation failed.' ); }
				$this->ch_epochs[ $field ] = $value;
			}
		}
		$this->ch_tokens[ $key ] = $value;
		$this->ch_derived = array(); $this->ch_prefixes = array(); $this->ch_derived_count = 0;
		$this->ch_ids = array(); $this->ch_ids_count = 0;
		return $value;
	}

	public function flush() {
		$this->flush_runtime();
		if ( ! $this->redis_status() ) { return false; }
		$scope = $this->ch_config['network_flush'];
		return $this->flush_scope( 'site' === $scope ? $this->ch_scope( 'default' ) : $scope );
	}

	public function flush_scope( $scope ) {
		try {
			$this->flush_runtime();
			if ( 'all' !== $scope && 'global' !== $scope && ! preg_match( '/^site-[1-9][0-9]*$/D', $scope ) ) { return false; }
			if ( 'all' === $scope ) { $guard = $this->ch_base . ':root'; $old = $this->ch_root; $pattern = $this->ch_base . ':' . $old . ':*'; $this->ch_root = $this->ch_rotate( $guard ); }
			else { $guard = $this->ch_base . ':' . $this->ch_root . ':' . $scope . ':epoch'; $old = $this->ch_token( $guard ); $pattern = $this->ch_base . ':' . $this->ch_root . ':' . $scope . ':' . $old . ':*'; $this->ch_rotate( $guard ); }
			if ( $this->ch_config['async_flush'] ) { $this->ch_reclaim( $pattern, $guard, $old ); }
			return true;
		} catch ( Throwable $error ) { $this->handle_exception( $error ); return false; }
	}

	public function flush_group( $group, $strategy = null ) {
		try {
			$strategy = $strategy ?: $this->ch_config['group_flush'];
			if ( 'full' === $strategy ) { return $this->flush_scope( 'all' ); }
			$group = $this->sanitize_key_part( $group ?: 'default' );
			$scope = $this->ch_base . ':' . $this->ch_root . ':' . $this->ch_scope( $group );
			$epoch = $this->ch_token( $scope . ':epoch' );
			$key = $scope . ':' . $epoch . ':g:' . rawurlencode( $group ) . ':epoch';
			$old = $this->ch_token( $key );
			$this->ch_rotate( $key );
			$this->ch_command( array( 'EXPIRE', $key, $this->ch_config['maxttl'] * 2 ) );
			$this->flush_runtime();
			if ( 'atomic' !== $strategy || $this->ch_config['async_flush'] ) { $this->ch_reclaim( substr( $key, 0, -6 ) . ':' . $old . ':*', $key, $old, $strategy ); }
			return true;
		} catch ( Throwable $error ) { $this->handle_exception( $error ); return false; }
	}

	public function increment( $key, $offset = 1, $group = 'default' ) {
		if ( ! is_int( $key ) && ( ! is_string( $key ) || '' === trim( $key ) ) ) { return false; }
		if ( $this->is_ignored_group( $group ) || ! $this->redis_status() ) {
			$value = $this->get( $key, $group, false, $found );
			if ( ! $found ) { return false; }
			$value = (int) $value; $offset = (int) $offset;
			if ( $offset > 0 && $value > PHP_INT_MAX - $offset ) { return false; }
			$value = $offset < 0 && $value < PHP_INT_MIN - $offset ? 0 : max( 0, $value + $offset );
			$this->add_to_internal_cache( $this->build_key( $key, $group ), $value );
			return $value;
		}
		$previous = $this->ch_writing; $this->ch_writing = true;
		try {
			// Decimal arithmetic avoids Lua's loss of integer precision above 2^53.
			$script = <<<'LUA'
local v=redis.call('GET',KEYS[1]); if not v then return {0} end
local function norm(s) local neg=string.sub(s,1,1)=='-'; if neg then s=string.sub(s,2) end; s=string.gsub(s,'^0+',''); if s=='' then s='0'; neg=false end; return s,neg end
local function cmp(a,b) if #a~=#b then return #a>#b end; return a>=b end
local function add(a,b)
 local out=''; local carry=0; local i=#a; local j=#b
 while i>0 or j>0 or carry>0 do
  local n=(i>0 and tonumber(string.sub(a,i,i)) or 0)+(j>0 and tonumber(string.sub(b,j,j)) or 0)+carry
  out=tostring(n%10)..out; carry=math.floor(n/10); i=i-1; j=j-1
 end; return out
end
local function sub(a,b)
 local out=''; local borrow=0; local j=#b
 for i=#a,1,-1 do local n=tonumber(string.sub(a,i,i))-borrow-(j>0 and tonumber(string.sub(b,j,j)) or 0); borrow=0; if n<0 then n=n+10; borrow=1 end; out=tostring(n)..out; j=j-1 end
 return (norm(out))
end
local raw=string.sub(v,2)
if (string.sub(v,1,1)~='N' and string.sub(v,1,1)~='S') or not string.match(raw,'^%-?%d+$') then raw='0' end
local a,an=norm(raw); local b,bn=norm(ARGV[1]); local r; local negative
if an==bn then r=add(a,b); negative=an elseif cmp(a,b) then r=sub(a,b); negative=an else r=sub(b,a); negative=bn end
if negative then r='0' end
if not cmp(ARGV[2],r) then return {0} end
local t=redis.call('PTTL',KEYS[1]); redis.call('SET',KEYS[1],'N'..r); if t>0 then redis.call('PEXPIRE',KEYS[1],t) end; return {1,r}
LUA;
			$value = $this->ch_command( array( 'EVAL', $script, 1, $this->build_key( $key, $group ), (string) (int) $offset, (string) PHP_INT_MAX ) );
			$derived = $this->build_key( $key, $group );
			if ( array_key_exists( $derived, $this->cache ) ) {
				$this->runtime_bytes -= $this->estimate_bytes( $this->cache[ $derived ] );
				unset( $this->cache[ $derived ] );
			}
			return is_array( $value ) && 1 === (int) ( $value[0] ?? 0 ) && isset( $value[1] ) ? (int) $value[1] : false;
		} catch ( Throwable $error ) { $this->handle_exception( $error ); return false; }
		finally { $this->ch_writing = $previous; }
	}

	public function decrement( $key, $offset = 1, $group = 'default' ) { return PHP_INT_MIN === (int) $offset ? false : $this->increment( $key, -(int) $offset, $group ); }

	public function prefetch( array $groups ) {
		foreach ( $groups as $group => $keys ) {
			$group = (string) $group;
			$keys  = (array) $keys;
			if ( in_array( $group, $this->ch_config['non_prefetchable_groups'], true ) || $this->is_ignored_group( $group ) || count( $keys ) < 2 ) { continue; }
			if ( $this->ch_config['split_alloptions'] && 'options' === $group ) { $keys = array_values( array_diff( $keys, array( 'alloptions' ) ) ); }
			if ( ! $keys ) { continue; }
			foreach ( (array) $this->get_multiple( $keys, $group ) as $key => $value ) {
				if ( $this->ch_prefetch_incomplete( $value ) ) {
					$this->ch_prefetch_skip[ $group ][ (string) $key ] = true;
					$this->ch_forget( $this->build_key( $key, $group ) );
				}
			}
		}
	}

	/** A prefetch manifest is useful only for cacheable page views. */
	private function ch_prefetchable_request() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) { return false; }
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		return ( 'GET' === $method || 'HEAD' === $method ) && ! empty( $_SERVER['REQUEST_URI'] );
	}

	/** Let a core upgrade rotate the derived-key signature without deleting old values. */
	private function ch_wp_version() {
		return isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '';
	}

	/** Drop one derived key from request memory without touching Redis. */
	private function ch_forget( $id ) {
		if ( array_key_exists( $id, $this->cache ) ) {
			$this->runtime_bytes -= $this->estimate_bytes( $this->cache[ $id ] );
			unset( $this->cache[ $id ] );
		}
	}

	/** Relay reports invalidated wire keys (or an Event object); evict only those from request memory. */
	public function relay_invalidated( $keys ) {
		if ( getenv( 'HC_RELAY_DEBUG' ) ) { fwrite( STDERR, 'HC_RELAY_EVENT ' . json_encode( is_object( $keys ) ? get_object_vars( $keys ) : $keys ) . "\n" ); }
		if ( is_object( $keys ) ) { $keys = array( $keys->key ?? null ); }
		foreach ( (array) $keys as $key ) {
			if ( is_object( $key ) ) { $key = $key->key ?? null; }
			if ( ! is_string( $key ) || 0 !== strpos( $key, $this->ch_base . ':' ) ) { continue; }
			// A namespace token or layout marker invalidates every memoized id.
			if ( false === strpos( $key, ':v:' ) ) { $this->ch_reset_layout(); $this->flush_runtime(); return; }
			if ( getenv( 'HC_RELAY_DEBUG' ) ) { fwrite( STDERR, 'HC_RELAY_HAD ' . json_encode( array( 'had' => array_key_exists( $key, $this->cache ), 'count' => count( $this->cache ) ) ) . "\n" ); }
			$this->ch_forget( $key );
			unset( $this->ch_option_snapshots[ $key ] );
		}
	}

	/** Drop memoized ids after an external namespace change. */
	private function ch_reset_layout() {
		$this->ch_derived = array(); $this->ch_prefixes = array(); $this->ch_derived_count = 0;
		$this->ch_ids = array(); $this->ch_ids_count = 0;
		$this->ch_tokens = array(); $this->ch_epochs = null; $this->ch_epochs_key = null; $this->ch_registry = null; $this->ch_touched = array();
		if ( $this->ch_ready ) {
			try { $this->ch_root = $this->ch_token( $this->ch_base . ':root' ); }
			catch ( Throwable $error ) { $this->handle_exception( $error ); }
		}
	}

	/** An incomplete class must not be replayed from a stored manifest. */
	private function ch_prefetch_incomplete( $value ) {
		if ( $value instanceof \__PHP_Incomplete_Class ) { return true; }
		if ( ! is_array( $value ) && ! is_object( $value ) ) { return false; }
		$found = false;
		$data  = (array) $value;
		array_walk_recursive( $data, static function ( $item ) use ( &$found ) { if ( $item instanceof \__PHP_Incomplete_Class ) { $found = true; } } );
		return $found;
	}

	private function ch_prefetch_id() {
		$user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$verb = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		return $this->ch_base . ':prefetch:' . hash( 'sha256', $this->blog_prefix . ':' . $user . ':' . $verb . ':' . $host . ':' . $uri );
	}
	public function track_prefetch( $key, $value, $group, $force, $found, $time ) {
		if ( $this->ch_prefetching || ! $found || in_array( $group, $this->ch_config['non_prefetchable_groups'], true ) || $this->is_ignored_group( $group ) || isset( $this->ch_prefetch_skip[ $group ][ (string) $key ] ) ) { return; }
		if ( count( $this->ch_prefetch_keys, COUNT_RECURSIVE ) < 1000 ) { $this->ch_prefetch_keys[ $group ][ (string) $key ] = $key; }
	}
	public function prefetch_request() {
		if ( ! $this->redis_status() || ! $this->ch_prefetchable_request() ) { return; }
		try {
			$data = $this->ch_command( array( 'GET', $this->ch_prefetch_id() ) );
			$groups = is_string( $data ) ? json_decode( $data, true ) : array();
			if ( is_array( $groups ) ) { $this->ch_prefetching = true; $this->prefetch( $groups ); }
		} catch ( Throwable $error ) { /* Prefetch is optional; normal cache reads remain available. */ }
		finally { $this->ch_prefetching = false; }
	}
	public function save_prefetch() {
		if ( ! $this->ch_prefetch_keys || ! $this->redis_status() || ! $this->ch_prefetchable_request() ) { return; }
		foreach ( $this->ch_prefetch_skip as $group => $keys ) { foreach ( array_keys( $keys ) as $key ) { unset( $this->ch_prefetch_keys[ $group ][ $key ] ); } }
		if ( $this->ch_config['split_alloptions'] ) { unset( $this->ch_prefetch_keys['options']['alloptions'] ); }
		if ( ! $this->ch_prefetch_keys ) { return; }
		try { $this->ch_command( array( 'SET', $this->ch_prefetch_id(), json_encode( array_map( 'array_values', $this->ch_prefetch_keys ) ), 'EX', 86400 ) ); }
		catch ( Throwable $error ) { /* A failed prefetch manifest must not break the response. */ }
	}

	public function ch_groups() {
		$this->ch_registry_load();
		$rows = array_keys( $this->ch_registry );
		return array_map( static function ( $row ) { list( $scope, $group ) = explode( '|', $row, 2 ); return array( 'scope' => $scope, 'group' => rawurldecode( $group ) ); }, $rows );
	}

	public function flush_pattern( $pattern ) {
		$count = 0;
		foreach ( $this->ch_groups() as $row ) {
			if ( $row['scope'] === $this->ch_scope( $row['group'] ) && preg_match( '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/D', $row['group'] ) ) {
				if ( $this->flush_group( $row['group'], $this->ch_config['pattern_flush'] ) ) { ++$count; }
			}
		}
		return $count;
	}

	public function ch_diagnostics( $with_bytes = true ) {
		$info = $this->info( $with_bytes );
		$namespace = $this->ch_ready ? 'owned' : ( 'prefix-conflict' === $this->ch_failure_reason ? 'conflict' : 'unknown' );
		return array( 'connected' => $this->redis_status(), 'transport_ok' => (bool) $this->ch_transport_ok, 'ready' => $this->ch_ready, 'namespace' => $namespace, 'codec' => $this->ch_codec_ok ? 'ok' : 'error', 'reason' => $this->ch_ready ? '' : ( $this->ch_failure_reason ?: 'backend-unavailable' ), 'client' => $info->meta['Client'], 'redis' => $this->redis_version(), 'runtime_hits' => $this->runtime_hits, 'store_hits' => $this->store_hits, 'store_misses' => $this->store_misses, 'store_ratio' => $this->store_hits + $this->store_misses > 0 ? round( 100 * $this->store_hits / ( $this->store_hits + $this->store_misses ), 1 ) : null, 'hits' => $info->hits, 'misses' => $info->misses, 'ratio' => $info->ratio, 'calls' => $info->calls, 'milliseconds' => round( $info->time * 1000, 3 ), 'entries' => count( $this->cache ), 'bytes' => $info->bytes, 'bytes_estimate' => $info->bytes_estimate, 'serializer' => $this->ch_config['serializer'], 'compression' => $this->ch_config['compression'], 'max_ttl' => $this->ch_config['maxttl'] );
	}

	public function storage_summary() {
		$result = array( 'scope' => $this->ch_config['shared'] ? 'shared-server' : 'dedicated-server', 'memory_bytes' => null, 'keys' => null );
		if ( $this->ch_config['shared'] || ! $this->redis_status() ) { return $result; }
		$result['memory_bytes'] = 0; $result['keys'] = 0;
		foreach ( $this->cleanup_nodes() as $node ) {
			$memory = $this->cleanup_node_command( $node, array( 'INFO', 'memory' ) );
			if ( is_string( $memory ) && preg_match( '/^used_memory:([0-9]+)/m', $memory, $match ) ) { $result['memory_bytes'] += (int) $match[1]; }
			elseif ( is_array( $memory ) && isset( $memory['used_memory'] ) ) { $result['memory_bytes'] += (int) $memory['used_memory']; }
			else { throw new RuntimeException( 'Redis memory information is unavailable.' ); }
			$result['keys'] += (int) $this->cleanup_node_command( $node, array( 'DBSIZE' ) );
		}
		return $result;
	}

	private function ch_hash_write( $operation, array $value, $group, $expire ) {
		if ( 'relay' === $this->ch_config['client'] ) { return $this->relay_options_write( $operation, $value, $group, $expire ); }
		try {
		$key = $this->build_key( 'alloptions', $group );
		$encoded = array();
		foreach ( $value as $field => $data ) { $encoded[ 'v:' . $field ] = $this->ch_codec->encode( $data ); }
		$baseline = $this->ch_option_snapshots[ $key ] ?? null;
		$deleted = is_array( $baseline ) ? array_diff_key( $baseline, $encoded ) : array();
		// Patch only fields changed by this request. Unrelated concurrent updates survive.
		$script = "local exists=redis.call('EXISTS',KEYS[1]); if (ARGV[1]=='add' and exists==1) or (ARGV[1]=='replace' and exists==0) then return 0 end; local patch=ARGV[3]=='1' and exists==1; if not patch then redis.call('DEL',KEYS[1]) end; local n=tonumber(ARGV[4]); for i=5,4+n do if patch then redis.call('HDEL',KEYS[1],ARGV[i]) end end; redis.call('HSET',KEYS[1],'_ch_empty','1'); for i=5+n,#ARGV,3 do if not patch or ARGV[i+2]=='1' then redis.call('HSET',KEYS[1],ARGV[i],ARGV[i+1]) end end; redis.call('EXPIRE',KEYS[1],ARGV[2]); return 1";
		$args = array( 'EVAL', $script, 1, $key, $operation, $expire > 0 ? min( $expire, $this->ch_config['maxttl'] ) : $this->ch_config['maxttl'], is_array( $baseline ) ? '1' : '0', count( $deleted ) );
		foreach ( $deleted as $field => $unused ) { $args[] = $field; }
		foreach ( $encoded as $field => $data ) { $args[] = $field; $args[] = $data; $args[] = ! is_array( $baseline ) || ! isset( $baseline[ $field ] ) || $baseline[ $field ] !== $data ? '1' : '0'; }
		$result = (bool) $this->ch_command( $args );
		if ( $result ) { $this->ch_option_snapshots[ $key ] = $encoded; $this->add_to_internal_cache( $key, $value ); }
		return $result;
		} catch ( Throwable $error ) {
			$this->handle_exception( $error );
			if ( 'add' === $operation ) { return parent::add( 'alloptions', $value, $group, $expire ); }
			if ( 'replace' === $operation ) { return parent::replace( 'alloptions', $value, $group, $expire ); }
			return parent::set( 'alloptions', $value, $group, $expire );
		}
	}

	public function set( $key, $value, $group = 'default', $expiration = 0 ) {
		if ( ! is_int( $key ) && ( ! is_string( $key ) || '' === trim( $key ) ) ) { return false; }
		$previous = $this->ch_writing; $this->ch_writing = true;
		try {
			if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && 'alloptions' === $key && is_array( $value ) && $this->redis_status() ) { return $this->ch_hash_write( 'set', $value, $group, $expiration ); }
			return parent::set( $key, $value, $group, $expiration );
		} finally { $this->ch_writing = $previous; }
	}
	public function add( $key, $value, $group = 'default', $expiration = 0 ) {
		if ( ! is_int( $key ) && ( ! is_string( $key ) || '' === trim( $key ) ) ) { return false; }
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) { return false; }
		$previous = $this->ch_writing; $this->ch_writing = true;
		try {
			if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && 'alloptions' === $key && is_array( $value ) && $this->redis_status() ) { return $this->ch_hash_write( 'add', $value, $group, $expiration ); }
			return parent::add( $key, $value, $group, $expiration );
		} finally { $this->ch_writing = $previous; }
	}
	public function replace( $key, $value, $group = 'default', $expiration = 0 ) {
		if ( ! is_int( $key ) && ( ! is_string( $key ) || '' === trim( $key ) ) ) { return false; }
		$previous = $this->ch_writing; $this->ch_writing = true;
		try {
			if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && 'alloptions' === $key && is_array( $value ) && $this->redis_status() ) { return $this->ch_hash_write( 'replace', $value, $group, $expiration ); }
			return parent::replace( $key, $value, $group, $expiration );
		} finally { $this->ch_writing = $previous; }
	}
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( $force && ! $this->ch_relay_bypass && 'relay' === $this->ch_config['client'] ) {
			return $this->relay_uncached_read( function () use ( $key, $group, &$found ) { return $this->get( $key, $group, true, $found ); } );
		}
		if ( ! is_int( $key ) && ( ! is_string( $key ) || '' === trim( $key ) ) ) { $found = false; return false; }
		if ( ! $force && $this->ch_ready ) {
			if ( $this->ch_default_sanitizer ) {
				$san_key = is_string( $key ) ? str_replace( ':', '-', $key ) : $key;
				$san_group = is_string( $group ) ? str_replace( ':', '-', $group ?: 'default' ) : ( $group ?: 'default' );
			} else {
				$san_key = $this->sanitize_key_part( $key );
				$san_group = $this->sanitize_key_part( $group ?: 'default' );
			}
			$id = $this->ch_ids[ $this->blog_prefix ][ $san_group ][ $san_key ] ?? null;
			if (
				null !== $id &&
				isset( $this->ch_layout_state[0] ) &&
				$this->ch_layout_state[0] === $this->ch_config['serializer'] &&
				$this->ch_layout_state[1] === $this->ch_config['compression'] &&
				$this->ch_layout_state[2] === $this->ch_config['split_alloptions'] &&
				$this->ch_layout_state[3] === $this->ch_config['client'] &&
				$this->ch_layout_state[4] === $this->ch_wp_version() &&
				array_key_exists( $id, $this->cache )
			) {
				$found = true; ++$this->cache_hits; ++$this->runtime_hits; return is_object( $this->cache[ $id ] ) ? clone $this->cache[ $id ] : $this->cache[ $id ];
			}
		}
		if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && 'alloptions' === $key && $this->redis_status() ) {
			if ( 'relay' === $this->ch_config['client'] ) { return $this->relay_options_read( $force, $found ); }
			try {
			$derived = $this->build_key( $key, $group );
			if ( ! $force && array_key_exists( $derived, $this->cache ) ) { $found = true; ++$this->cache_hits; ++$this->runtime_hits; return $this->get_from_internal_cache( $derived ); }
			$raw = $this->ch_command( array( 'HGETALL', $derived ) );
			$found = ! empty( $raw );
			if ( ! $found ) { ++$this->store_misses; ++$this->cache_misses; return false; }
			$value = array();
			$this->ch_option_snapshots[ $derived ] = array();
			for ( $i = 0; $i < count( $raw ); $i += 2 ) { if ( 0 === strpos( $raw[ $i ], 'v:' ) ) { $value[ substr( $raw[ $i ], 2 ) ] = $this->ch_codec->decode( $raw[ $i + 1 ] ); $this->ch_option_snapshots[ $derived ][ $raw[ $i ] ] = $raw[ $i + 1 ]; } }
			++$this->store_hits; ++$this->cache_hits; $this->add_to_internal_cache( $derived, $value ); return $value;
			} catch ( Throwable $error ) { $this->handle_exception( $error ); }
		}
		return parent::get( $key, $group, $force, $found );
	}
	public function get_multiple( $keys, $group = 'default', $force = false ) {
		if ( $force && ! $this->ch_relay_bypass && 'relay' === $this->ch_config['client'] ) {
			return $this->relay_uncached_read( function () use ( $keys, $group ) { return $this->get_multiple( $keys, $group, true ); } );
		}
		if ( ! is_array( $keys ) ) { return false; }
		if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && in_array( 'alloptions', $keys, true ) ) { $r = array(); foreach ( $keys as $key ) { $r[ $key ] = $this->get( $key, $group, $force ); } return $r; }
		return parent::get_multiple( $keys, $group, $force );
	}
	public function set_multiple( array $data, $group = 'default', $expire = 0 ) {
		if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && array_key_exists( 'alloptions', $data ) ) { $r = array(); foreach ( $data as $k => $v ) { $r[ $k ] = $this->set( $k, $v, $group, $expire ); } return $r; }
		$previous = $this->ch_writing; $this->ch_writing = true;
		try { return parent::set_multiple( $data, $group, $expire ); }
		catch ( Throwable $error ) { $this->handle_exception( $error ); return array_fill_keys( array_keys( $data ), false ); }
		finally { $this->ch_writing = $previous; }
	}
	public function add_multiple( array $data, $group = 'default', $expire = 0 ) {
		if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && array_key_exists( 'alloptions', $data ) ) { $r = array(); foreach ( $data as $k => $v ) { $r[ $k ] = $this->add( $k, $v, $group, $expire ); } return $r; }
		$previous = $this->ch_writing; $this->ch_writing = true;
		try { return parent::add_multiple( $data, $group, $expire ); }
		catch ( Throwable $error ) { $this->handle_exception( $error ); return array_fill_keys( array_keys( $data ), false ); }
		finally { $this->ch_writing = $previous; }
	}
}
