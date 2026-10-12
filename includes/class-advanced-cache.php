<?php
/** Original extensions to the GPL Redis Object Cache engine. */
defined( 'ABSPATH' ) || exit;
require_once WP_REDIS_PLUGIN_PATH . '/includes/ch-codec.php';
require_once WP_REDIS_PLUGIN_PATH . '/includes/ch-observer.php';
require_once WP_REDIS_PLUGIN_PATH . '/includes/ch-cleanup.php';
require_once WP_REDIS_PLUGIN_PATH . '/includes/ch-routing.php';
require_once WP_REDIS_PLUGIN_PATH . '/includes/ch-native.php';
require_once WP_REDIS_PLUGIN_PATH . '/includes/ch-topology.php';
require_once WP_REDIS_PLUGIN_PATH . '/includes/ch-operations.php';
require_once WP_REDIS_PLUGIN_PATH . '/includes/ch-relay-options.php';

class HarborCache_Advanced_Cache extends WP_Object_Cache {
	use HarborCache_Cleanup;
	use HarborCache_Routing;
	use HarborCache_Native;
	use HarborCache_Topology;
	use HarborCache_Operations;
	use HarborCache_Relay_Options;
	public $ch_config;
	// Also declare counters here so an older owned drop-in can load the upgraded plugin until refresh.
	public $runtime_hits = 0;
	public $store_hits = 0;
	public $store_misses = 0;
	private $ch_codec;
	private $ch_tokens = array();
	private $ch_root;
	private $ch_base;
	private $ch_ready = false;
	private $ch_registered = array();
	public $ch_commands = array();
	private $ch_prefetch_keys = array();
	private $ch_prefetching = false;
	private $ch_option_snapshots = array();
	private $ch_reconnecting = false;
	private $ch_initializing = true;
	private $ch_derived = array();
	private $ch_prefixes = array();
	private $ch_derived_count = 0;
	private $ch_layout_state;
	private $ch_ignored_source;
	private $ch_ignored_exact = array();
	private $ch_ignored_patterns = array();
	private $ch_ignored_results = array();
	public $ch_retries = 0;

	public function __construct( $fail_gracefully = false ) {
		$this->ch_config = \HarborCache\Config::load();
		$codec_error = null;
		try { $this->ch_codec = new \HarborCache\Codec( $this->ch_config ); }
		catch ( Throwable $error ) { $codec_error = $error; $this->ch_codec = new \HarborCache\Codec( array( 'serializer' => 'php', 'compression' => 'none' ) ); }
		parent::__construct( true );
		if ( $codec_error ) { $this->handle_exception( $codec_error ); }
		$this->ch_base = WP_REDIS_PREFIX . 'meta';
		if ( $this->redis_status() ) {
			try {
				$this->ch_root = $this->ch_token( $this->ch_base . ':root' ); $this->ch_ready = '' !== $this->ch_root && $this->redis_status();
				if ( $this->ch_config['strict'] ) {
					global $table_prefix;
					$identity = hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $table_prefix . ':' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : WP_CONTENT_DIR ) );
					$this->ch_command( array( 'SET', $this->ch_base . ':owner', $identity, 'NX' ) );
					$owner = $this->ch_command( array( 'GET', $this->ch_base . ':owner' ) );
					if ( is_string( $owner ) && $owner !== $identity ) { $this->flush_scope( 'all' ); $this->ch_command( array( 'SET', $this->ch_base . ':owner', $identity ) ); \HarborCache\Observer::log( $this->ch_config, 'warning', 'Cache prefix ownership changed; integrity namespace rotated.' ); }
				}
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
		\HarborCache\Observer::log( $this->ch_config, 'error', 'HarborCache backend unavailable.', array( 'type' => get_class( $exception ) ) );
		if ( 'debug' === $this->ch_config['failure_mode'] || ( $this->ch_initializing && 'strict' === $this->ch_config['failure_mode'] ) ) { throw new RuntimeException( 'HarborCache backend unavailable. Check connection settings and Redis service.' ); }
		parent::handle_exception( new RuntimeException( 'HarborCache backend unavailable. Check connection settings and Redis service.' ) );
	}

	protected function redis_pipeline() {
		$client = $this->redis_instance();
		$pipeline = $client instanceof \Predis\Client && ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) ? $client->getClientBy( 'role', 'master' )->pipeline() : parent::redis_pipeline();
		return new \HarborCache\Pipeline( $pipeline, $this );
	}
	protected function redis_call( $method, ...$args ) {
		$readonly = in_array( strtolower( $method ), array( 'get', 'mget', 'pttl' ), true );
		if ( ! defined( 'WP_REDIS_CLUSTER' ) && ! $this->ch_config['servers'] && ! $this->ch_config['sentinels'] ) {
			if ( $readonly ) { ++$this->ch_routes['primary']; }
			if ( ! $this->ch_config['save_commands'] && ! $this->ch_config['debug'] && ! $this->ch_config['retries'] && ( null === $this->ch_config['tracer'] || 'none' === $this->ch_config['tracer'] ) ) { return parent::redis_call( $method, ...$args ); }
			return $this->observe_command( strtoupper( $method ), function () use ( $method, $args ) { return parent::redis_call( $method, ...$args ); }, $readonly );
		}
		if ( ! $readonly ) { $this->mark_write( $args[0] ?? '' ); }
		return $this->observe_command( strtoupper( $method ), function () use ( $method, $args, $readonly ) {
			$call = function () use ( $method, $args ) { return parent::redis_call( $method, ...$args ); };
			$client = $this->redis_instance();
			if ( ! $readonly && $client instanceof \Predis\Client && ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) ) { return $client->getClientBy( 'role', 'master' )->$method( ...$args ); }
			return $readonly ? $this->routed_read( $method, $args, $call ) : $call();
		}, $readonly );
	}

	public function observe_command( $command, callable $call, $readonly = false, array $batch = array() ) {
		if ( ! $this->ch_config['save_commands'] && ! $this->ch_config['debug'] && ! $this->ch_config['retries'] && ( null === $this->ch_config['tracer'] || 'none' === $this->ch_config['tracer'] ) ) { return $call(); }
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
		if ( defined( 'WP_REDIS_CLUSTER' ) || $this->ch_config['servers'] || $this->ch_config['sentinels'] ) {
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
				if ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) { return $client->getClientBy( 'role', 'master' )->executeRaw( $args ); }
				if ( defined( 'WP_REDIS_CLUSTER' ) && in_array( strtoupper( $args[0] ), array( 'PING', 'INFO', 'SLOWLOG', 'CONFIG' ), true ) ) { return $client->getClientBy( 'slot', 0 )->executeRaw( $args ); }
				return $client->executeRaw( $args );
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
			$result = $client->$method( ...$args );
			if ( $client instanceof \Credis_Client && 'hgetall' === $method && is_array( $result ) ) { $flat = array(); foreach ( $result as $field => $value ) { $flat[] = $field; $flat[] = $value; } return $flat; }
			return $result;
		};
		try {
			$result = $this->observe_command( strtoupper( $args[0] ), $call, in_array( strtoupper( $args[0] ), array( 'GET', 'HGETALL', 'SMEMBERS', 'INFO', 'PING', 'TTL', 'PTTL', 'EXISTS' ), true ) );
			return false === $result ? null : $result;
		} finally {
			++$this->cache_calls;
			$this->cache_time += microtime( true ) - $start;
		}
	}

	private function ch_token( $key ) {
		if ( isset( $this->ch_tokens[ $key ] ) ) { return $this->ch_tokens[ $key ]; }
		try {
		$value = $this->ch_command( array( 'GET', $key ) );
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{24}$/D', $value ) ) {
			$value = bin2hex( random_bytes( 12 ) );
			if ( null === $this->ch_command( array( 'SET', $key, $value, 'NX' ) ) ) { $value = $this->ch_command( array( 'GET', $key ) ); }
			if ( ! is_string( $value ) ) { throw new RuntimeException( 'Cache namespace initialization failed.' ); }
		}
		return $this->ch_tokens[ $key ] = $value;
		} catch ( Throwable $error ) { $this->handle_exception( $error ); return ''; }
	}

	private function ch_scope( $group ) { return $this->is_global_group( $group ) ? 'global' : 'site-' . ( is_numeric( $this->blog_prefix ) ? (int) $this->blog_prefix : 1 ); }

	public function fast_build_key( $key, $group = 'default' ) {
		if ( ! $this->ch_ready ) { return parent::fast_build_key( $key, $group ); }
		try {
		if ( 'harborcache' === $group && 'metrics' === $key ) { return $this->ch_base . ( $this->ch_config['analytics']['persist'] ? '' : ':' . $this->ch_root ) . ':analytics:metrics'; }
		$group = $group ?: 'default';
		$state = array( $this->ch_config['serializer'], $this->ch_config['compression'], $this->ch_config['split_alloptions'], $this->ch_config['client'] );
		if ( $state !== $this->ch_layout_state ) { $this->ch_derived = array(); $this->ch_prefixes = array(); $this->ch_derived_count = 0; $this->ch_layout_state = $state; }
		$layout = $this->ch_config['split_alloptions'] && 'relay' === $this->ch_config['client'] ? ':relay-options-v1' : '';
		$context = $this->ch_scope( $group ) . ':' . $group;
		$cacheable = is_string( $key ) || is_int( $key );
		if ( $cacheable && isset( $this->ch_derived[ $context ][ $key ] ) ) { return $this->ch_derived[ $context ][ $key ]; }
		if ( isset( $this->ch_prefixes[ $context ] ) ) { $prefix = $this->ch_prefixes[ $context ]; }
		else {
		$format = $this->ch_config['serializer'] . ':' . $this->ch_config['compression'] . ':' . (int) $this->ch_config['split_alloptions'] . $layout;
		$scope = $this->ch_base . ':' . $this->ch_root . ':' . $this->ch_scope( $group );
		$epoch = $this->ch_token( $scope . ':epoch' );
		if ( ! $this->ch_ready ) { return parent::fast_build_key( $key, $group ); }
		$group_key = $scope . ':' . $epoch . ':g:' . rawurlencode( $group );
		$version = $this->ch_token( $group_key . ':epoch' );
		if ( ! $this->ch_ready ) { return parent::fast_build_key( $key, $group ); }
		if ( ! isset( $this->ch_registered[ $group_key ] ) ) {
			$this->ch_command( array( 'SADD', $this->ch_base . ':' . $this->ch_root . ':groups', $this->ch_scope( $group ) . '|' . rawurlencode( $group ) ) );
			foreach ( array( $scope . ':epoch', $group_key . ':epoch', $this->ch_base . ':' . $this->ch_root . ':groups' ) as $metadata ) { $this->ch_command( array( 'EXPIRE', $metadata, $this->ch_config['maxttl'] * 2 ) ); }
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
		return $derived;
		} catch ( Throwable $error ) { $this->handle_exception( $error ); return parent::fast_build_key( $key, $group ); }
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
		if ( isset( $this->ch_ignored_exact[ $group ] ) || parent::is_ignored_group( $group ) ) { return true; }
		if ( isset( $this->ch_ignored_results[ $group ] ) ) { return $this->ch_ignored_results[ $group ]; }
		$ignored = false;
		foreach ( $this->ch_ignored_patterns as $pattern ) { if ( preg_match( $pattern, $group ) ) { $ignored = true; break; } }
		if ( count( $this->ch_ignored_results ) >= 256 ) { $this->ch_ignored_results = array(); }
		$this->ch_ignored_results[ $group ] = $ignored;
		return $ignored;
	}

	private function ch_rotate( $key ) { $value = bin2hex( random_bytes( 12 ) ); $this->ch_command( array( 'SET', $key, $value ) ); $this->ch_tokens[ $key ] = $value; $this->ch_derived = array(); $this->ch_prefixes = array(); $this->ch_derived_count = 0; return $value; }

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
		try {
			// Decimal arithmetic avoids Lua's loss of integer precision above 2^53.
			$script = <<<'LUA'
local v=redis.call('GET',KEYS[1]); if not v then return false end
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
if not cmp(ARGV[2],r) then return false end
local t=redis.call('PTTL',KEYS[1]); redis.call('SET',KEYS[1],'N'..r); if t>0 then redis.call('PEXPIRE',KEYS[1],t) end; return r
LUA;
			$value = $this->ch_command( array( 'EVAL', $script, 1, $this->build_key( $key, $group ), (string) (int) $offset, (string) PHP_INT_MAX ) );
			unset( $this->cache[ $this->build_key( $key, $group ) ] );
			return null === $value ? false : (int) $value;
		} catch ( Throwable $error ) { $this->handle_exception( $error ); return false; }
	}

	public function decrement( $key, $offset = 1, $group = 'default' ) { return PHP_INT_MIN === (int) $offset ? false : $this->increment( $key, -(int) $offset, $group ); }

	public function prefetch( array $groups ) {
		foreach ( $groups as $group => $keys ) {
			if ( ! in_array( $group, $this->ch_config['non_prefetchable_groups'], true ) ) { $this->get_multiple( (array) $keys, $group ); }
		}
	}

	private function ch_prefetch_id() {
		$user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		return $this->ch_base . ':prefetch:' . hash( 'sha256', $this->blog_prefix . ':' . $user . ':' . ( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Hashed, never output or executed.
	}
	public function track_prefetch( $key, $value, $group, $force, $found, $time ) {
		if ( $this->ch_prefetching || ! $found || in_array( $group, $this->ch_config['non_prefetchable_groups'], true ) || $this->is_ignored_group( $group ) ) { return; }
		if ( count( $this->ch_prefetch_keys, COUNT_RECURSIVE ) < 250 ) { $this->ch_prefetch_keys[ $group ][ (string) $key ] = $key; }
	}
	public function prefetch_request() {
		if ( ! $this->redis_status() ) { return; }
		try {
			$data = $this->ch_command( array( 'GET', $this->ch_prefetch_id() ) );
			$groups = is_string( $data ) ? json_decode( $data, true ) : array();
			if ( is_array( $groups ) ) { $this->ch_prefetching = true; $this->prefetch( $groups ); }
		} catch ( Throwable $error ) { /* Prefetch is optional; normal cache reads remain available. */ }
		finally { $this->ch_prefetching = false; }
	}
	public function save_prefetch() {
		if ( $this->ch_prefetch_keys && $this->redis_status() ) {
			try { $this->ch_command( array( 'SET', $this->ch_prefetch_id(), json_encode( array_map( 'array_values', $this->ch_prefetch_keys ) ), 'EX', min( 3600, $this->ch_config['maxttl'] ) ) ); }
			catch ( Throwable $error ) { /* A failed prefetch manifest must not break the response. */ }
		}
	}

	public function ch_groups() {
		$rows = $this->ch_command( array( 'SMEMBERS', $this->ch_base . ':' . $this->ch_root . ':groups' ) );
		return array_map( static function ( $row ) { list( $scope, $group ) = explode( '|', $row, 2 ); return array( 'scope' => $scope, 'group' => rawurldecode( $group ) ); }, (array) $rows );
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

	public function ch_diagnostics() {
		$info = $this->info();
		return array( 'connected' => $this->redis_status(), 'client' => $info->meta['Client'], 'redis' => $this->redis_version(), 'runtime_hits' => $this->runtime_hits, 'store_hits' => $this->store_hits, 'store_misses' => $this->store_misses, 'store_ratio' => $this->store_hits + $this->store_misses > 0 ? round( 100 * $this->store_hits / ( $this->store_hits + $this->store_misses ), 1 ) : null, 'hits' => $info->hits, 'misses' => $info->misses, 'ratio' => $info->ratio, 'calls' => $info->calls, 'milliseconds' => round( $info->time * 1000, 3 ), 'bytes' => $info->bytes, 'serializer' => $this->ch_config['serializer'], 'compression' => $this->ch_config['compression'], 'max_ttl' => $this->ch_config['maxttl'] );
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
		if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && 'alloptions' === $key && is_array( $value ) && $this->redis_status() ) { return $this->ch_hash_write( 'set', $value, $group, $expiration ); }
		return parent::set( $key, $value, $group, $expiration );
	}
	public function add( $key, $value, $group = 'default', $expiration = 0 ) {
		if ( ! is_int( $key ) && ( ! is_string( $key ) || '' === trim( $key ) ) ) { return false; }
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) { return false; }
		if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && 'alloptions' === $key && is_array( $value ) && $this->redis_status() ) { return $this->ch_hash_write( 'add', $value, $group, $expiration ); }
		return parent::add( $key, $value, $group, $expiration );
	}
	public function replace( $key, $value, $group = 'default', $expiration = 0 ) {
		if ( ! is_int( $key ) && ( ! is_string( $key ) || '' === trim( $key ) ) ) { return false; }
		if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && 'alloptions' === $key && is_array( $value ) && $this->redis_status() ) { return $this->ch_hash_write( 'replace', $value, $group, $expiration ); }
		return parent::replace( $key, $value, $group, $expiration );
	}
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( ! is_int( $key ) && ( ! is_string( $key ) || '' === trim( $key ) ) ) { $found = false; return false; }
		if ( ! $force && $this->ch_ready ) {
			$san_key = $this->sanitize_key_part( $key ); $san_group = $this->sanitize_key_part( $group ?: 'default' );
			$context = $this->ch_scope( $san_group ) . ':' . $san_group;
			$derived = $this->ch_derived[ $context ][ $san_key ] ?? null;
			if ( null !== $derived && $this->ch_layout_state === array( $this->ch_config['serializer'], $this->ch_config['compression'], $this->ch_config['split_alloptions'], $this->ch_config['client'] ) && array_key_exists( $derived, $this->cache ) ) {
				$found = true; ++$this->cache_hits; ++$this->runtime_hits; return is_object( $this->cache[ $derived ] ) ? clone $this->cache[ $derived ] : $this->cache[ $derived ];
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
		if ( ! is_array( $keys ) ) { return false; }
		if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && in_array( 'alloptions', $keys, true ) ) { $r = array(); foreach ( $keys as $key ) { $r[ $key ] = $this->get( $key, $group, $force ); } return $r; }
		return parent::get_multiple( $keys, $group, $force );
	}
	public function set_multiple( array $data, $group = 'default', $expire = 0 ) {
		if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && array_key_exists( 'alloptions', $data ) ) { $r = array(); foreach ( $data as $k => $v ) { $r[ $k ] = $this->set( $k, $v, $group, $expire ); } return $r; }
		try { return parent::set_multiple( $data, $group, $expire ); }
		catch ( Throwable $error ) { $this->handle_exception( $error ); return array_fill_keys( array_keys( $data ), false ); }
	}
	public function add_multiple( array $data, $group = 'default', $expire = 0 ) {
		if ( $this->ch_config['split_alloptions'] && ! $this->is_ignored_group( $group ) && 'options' === $group && array_key_exists( 'alloptions', $data ) ) { $r = array(); foreach ( $data as $k => $v ) { $r[ $k ] = $this->add( $k, $v, $group, $expire ); } return $r; }
		try { return parent::add_multiple( $data, $group, $expire ); }
		catch ( Throwable $error ) { $this->handle_exception( $error ); return array_fill_keys( array_keys( $data ), false ); }
	}
}
