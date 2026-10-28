<?php
/** Configuration available before WordPress options load. */
namespace HarborCache;

// WordPress drop-ins require wp_cache_* names; WP_REDIS_* constants preserve the upstream configuration API.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/ch-paths.php';

final class Config {
	private static $loaded;

	public static function defaults() {
		static $defaults;
		if ( null !== $defaults ) { return $defaults; }
		// Match the free plugin's behaviour: use the C extension when the server has it.
		$defaults = array(
			'client' => class_exists( '\Redis' ) ? 'phpredis' : 'predis', 'host' => '127.0.0.1', 'port' => 6379,
			'database' => 0, 'username' => '', 'password' => '', 'prefix' => '', 'scheme' => 'tcp', 'path' => '',
			'timeout' => 1.0, 'read_timeout' => 1.0, 'persistent' => false,
			'cluster' => array(), 'sentinels' => array(), 'service' => '', 'servers' => array(),
			'tls_options' => array( 'verify_peer' => true, 'verify_peer_name' => true ),
			'serializer' => 'php', 'compression' => 'none', 'maxttl' => 86400,
			'queryttl' => 3600, 'global_groups' => array(), 'non_persistent_groups' => array(),
			'non_prefetchable_groups' => array(), 'network_flush' => 'all',
			'analytics' => array( 'enabled' => true, 'retention' => 7200, 'sample_rate' => 10, 'persist' => true, 'persist_limit' => 1000, 'footnote' => false ),
			'debug' => false, 'save_commands' => false, 'logger' => null, 'tracer' => null,
			'prefetch' => false, 'split_alloptions' => false, 'retry_interval' => 25,
			'allow_server_controls' => false,
			'retries' => 0, 'backoff' => 'smart', 'shared' => true,
			'async_flush' => false, 'group_flush' => 'atomic', 'pattern_flush' => 'incremental',
			'gc_batch_size' => 100, 'gc_budget_ms' => 50, 'strict' => true,
			'failure_mode' => 'silent', 'auto_update_dropin' => true, 'query_maintenance' => false,
			'cache' => 'HarborCache_Advanced_Cache', 'connector' => null,
			'log_levels' => array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice' ),
			'cluster_failover' => 'none', 'cluster_distribution_strategy' => null, 'cluster_failover_strategy' => null, 'cluster_az' => null,
			'replication_strategy' => 'distribute',
			'relay' => array( 'cache' => true, 'listeners' => false, 'invalidations' => true, 'ignored' => array( '*:analytics:*' ), 'allowed' => null, 'adaptive' => null ),
		);
		return $defaults;
	}

	/** Avoid the PHP 7.4/PhpRedis TLS 1.3 stream hang; preserve explicit policy. */
	public static function tls_options( array $options, $client, $php_version = PHP_VERSION_ID ) {
		if ( 'phpredis' === $client && $php_version >= 70400 && $php_version < 80000 && ! array_key_exists( 'crypto_method', $options ) && defined( 'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT' ) ) {
			$options['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
		}
		return $options;
	}

	public static function load( $refresh = false ) {
		if ( ! $refresh && null !== self::$loaded ) { return self::$loaded; }
		$input = defined( 'HARBORCACHE_CONFIG' ) ? HARBORCACHE_CONFIG : array();
		if ( ! is_array( $input ) ) {
			throw new \InvalidArgumentException( 'HARBORCACHE_CONFIG must be an array.' );
		}
		$saved = array();
		if ( is_readable( Paths::content( 'harborcache-config.php' ) ) ) {
			$saved = include Paths::content( 'harborcache-config.php' );
			if ( ! is_array( $saved ) ) { throw new \InvalidArgumentException( 'Saved configuration must be an array.' ); }
		}
		self::$loaded = self::validate( array_replace( self::defaults(), $saved, $input ), true );
		return self::$loaded;
	}

	public static function validate( array $config, $legacy_overrides = false ) {
		if ( array_diff_key( $config, self::defaults() ) ) { throw new \InvalidArgumentException( 'Unknown HarborCache configuration option.' ); }
		$config = array_replace( self::defaults(), $config );
		if ( ! in_array( $config['failure_mode'], array( 'silent', 'strict', 'debug' ), true ) ) { throw new \InvalidArgumentException( 'Unknown failure mode.' ); }
		foreach ( array( 'auto_update_dropin', 'query_maintenance' ) as $flag ) { if ( ! is_bool( $config[ $flag ] ) ) { throw new \InvalidArgumentException( 'Maintenance flags must be booleans.' ); } }
		if ( ! is_int( $config['gc_budget_ms'] ) || $config['gc_budget_ms'] < 1 || $config['gc_budget_ms'] > 1000 ) { throw new \InvalidArgumentException( 'Cleanup budget must be from 1 to 1000 milliseconds.' ); }
		foreach ( array( 'analytics', 'relay' ) as $section ) { if ( ! is_array( $config[ $section ] ) ) { throw new \InvalidArgumentException( 'Configuration sections must be arrays.' ); } $config[ $section ] = array_replace( self::defaults()[ $section ], $config[ $section ] ); }
		foreach ( array( 'analytics', 'relay' ) as $section ) { if ( array_diff_key( $config[ $section ], self::defaults()[ $section ] ) ) { throw new \InvalidArgumentException( 'Unknown analytics or Relay option.' ); } }
		foreach ( array( 'host', 'path', 'username', 'password', 'service', 'prefix' ) as $key ) { if ( ! is_string( $config[ $key ] ) || false !== strpos( $config[ $key ], "\0" ) ) { throw new \InvalidArgumentException( 'Connection values must be strings without null bytes.' ); } }
		foreach ( array( 'persistent', 'debug', 'save_commands', 'prefetch', 'split_alloptions', 'allow_server_controls', 'shared', 'async_flush', 'strict' ) as $key ) { if ( ! is_bool( $config[ $key ] ) ) { throw new \InvalidArgumentException( 'Configuration flags must be booleans.' ); } }
		if ( ! in_array( $config['serializer'], array( 'php', 'igbinary' ), true ) || ! in_array( $config['compression'], array( 'none', 'lzf', 'lz4', 'zstd' ), true ) ) { throw new \InvalidArgumentException( 'Unsupported serialization or compression mode.' ); }
		if ( ! is_array( $config['analytics'] ) ) { throw new \InvalidArgumentException( 'Analytics configuration must be an array.' ); }
		if ( null !== $config['logger'] && ! is_callable( $config['logger'] ) && ! ( is_object( $config['logger'] ) && method_exists( $config['logger'], 'log' ) ) && ! ( is_string( $config['logger'] ) && class_exists( $config['logger'] ) && method_exists( $config['logger'], 'log' ) ) ) { throw new \InvalidArgumentException( 'Logger must be callable or provide a log method.' ); }
		if ( null !== $config['tracer'] && ! is_callable( $config['tracer'] ) && ! in_array( $config['tracer'], array( 'none', 'newrelic', 'opentelemetry', 'open-telemetry' ), true ) && ! ( is_object( $config['tracer'] ) && ( method_exists( $config['tracer'], 'getTracer' ) || method_exists( $config['tracer'], 'spanBuilder' ) ) ) ) { throw new \InvalidArgumentException( 'Unknown tracer.' ); }
		if ( null !== $config['connector'] && ! is_callable( $config['connector'] ) ) { throw new \InvalidArgumentException( 'Connector must be a callable receiving configuration.' ); }
		if ( ! is_callable( $config['cache'] ) && ( ! is_string( $config['cache'] ) || '' === $config['cache'] ) ) { throw new \InvalidArgumentException( 'Cache must name a HarborCache implementation class or factory.' ); }
		foreach ( array( 'backoff' => array( 'none', 'constant', 'exponential', 'smart' ), 'group_flush' => array( 'atomic', 'scan', 'keys', 'incremental', 'full' ), 'pattern_flush' => array( 'scan', 'keys', 'incremental' ) ) as $key => $values ) { if ( ! in_array( $config[ $key ], $values, true ) ) { throw new \InvalidArgumentException( 'Unknown retry or flush strategy.' ); } }
		foreach ( array( 'retries' => array( 0, 5 ), 'gc_batch_size' => array( 1, 1000 ) ) as $key => $bounds ) { if ( ! is_int( $config[ $key ] ) || $config[ $key ] < $bounds[0] || $config[ $key ] > $bounds[1] ) { throw new \InvalidArgumentException( 'Invalid retry or cleanup limit.' ); } }
		if ( ! is_array( $config['log_levels'] ) || count( array_filter( $config['log_levels'], 'is_string' ) ) !== count( $config['log_levels'] ) || array_diff( $config['log_levels'], array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' ) ) ) { throw new \InvalidArgumentException( 'Unknown log levels.' ); }
		foreach ( array( 'cluster_failover' => array( 'none', 'error', 'distribute', 'distribute_replicas' ), 'replication_strategy' => array( 'distribute', 'distribute_replicas', 'concentrate' ) ) as $key => $values ) { if ( ! in_array( $config[ $key ], $values, true ) ) { throw new \InvalidArgumentException( 'Unknown read routing strategy.' ); } }
		foreach ( array( 'cluster_distribution_strategy' => array( 'none', 'random', 'random_replica', 'replicas', 'all' ), 'cluster_failover_strategy' => array( 'none', 'random_replica', 'primary', 'replicas', 'all' ) ) as $key => $values ) { if ( null !== $config[ $key ] && ( 'relay' !== $config['client'] || ! in_array( $config[ $key ], $values, true ) ) ) { throw new \InvalidArgumentException( 'This routing option requires Relay and a supported strategy.' ); } }
		if ( null !== $config['cluster_az'] && ( 'relay' !== $config['client'] || ! is_string( $config['cluster_az'] ) ) ) { throw new \InvalidArgumentException( 'Availability-zone routing requires Relay.' ); }
		foreach ( array( 'enabled', 'persist', 'footnote' ) as $key ) { if ( ! is_bool( $config['analytics'][ $key ] ) ) { throw new \InvalidArgumentException( 'Analytics flags must be booleans.' ); } }
		if ( null !== $config['analytics']['persist_limit'] && ( ! is_int( $config['analytics']['persist_limit'] ) || $config['analytics']['persist_limit'] < 1 || $config['analytics']['persist_limit'] > 100000 ) ) { throw new \InvalidArgumentException( 'Analytics limit must be null or an integer from 1 to 100000.' ); }
		foreach ( array( 'cache', 'listeners', 'invalidations' ) as $key ) { if ( ! is_bool( $config['relay'][ $key ] ) ) { throw new \InvalidArgumentException( 'Relay flags must be booleans.' ); } }
		foreach ( array( 'ignored', 'allowed' ) as $key ) { if ( null === $config['relay'][ $key ] && 'allowed' === $key ) { continue; } if ( ! is_array( $config['relay'][ $key ] ) ) { throw new \InvalidArgumentException( 'Relay patterns must be arrays.' ); } foreach ( $config['relay'][ $key ] as $pattern ) { if ( ! is_string( $pattern ) ) { throw new \InvalidArgumentException( 'Relay patterns must be strings.' ); } } }
		if ( null !== $config['relay']['adaptive'] ) {
			$a = $config['relay']['adaptive'];
			if ( ! is_array( $a ) || ! isset( $a['width'], $a['depth'] ) || ! is_int( $a['width'] ) || $a['width'] < 512 || $a['width'] > 2147483648 || ! is_int( $a['depth'] ) || $a['depth'] < 1 || $a['depth'] > 8 ) { throw new \InvalidArgumentException( 'Invalid adaptive cache dimensions.' ); }
			$config['relay']['adaptive'] = array_replace( array( 'events' => 10, 'ratio' => 3.0, 'formula' => 'pure' ), $a );
			$a = $config['relay']['adaptive'];
			if ( ! is_int( $a['events'] ) || $a['events'] < 1 || ! is_numeric( $a['ratio'] ) || $a['ratio'] <= 0 || ! in_array( $a['formula'], array( 'pure', 'scaled' ), true ) ) { throw new \InvalidArgumentException( 'Invalid adaptive cache policy.' ); }
		}
		if ( is_string( $config['host'] ) && 0 === strpos( $config['host'], '/' ) ) { $config['scheme'] = 'unix'; $config['path'] = $config['host']; }
		elseif ( is_string( $config['host'] ) && preg_match( '#^(tcp|tls)://([^/]+)$#D', $config['host'], $parts ) ) { $config['scheme'] = $parts[1]; $config['host'] = $parts[2]; }
		if ( $legacy_overrides ) { foreach ( array( 'host', 'port', 'database', 'client', 'username', 'password', 'timeout', 'read_timeout', 'persistent', 'maxttl' ) as $legacy ) { if ( defined( 'WP_REDIS_' . strtoupper( $legacy ) ) ) { $config[ $legacy ] = constant( 'WP_REDIS_' . strtoupper( $legacy ) ); } } }
		if ( is_string( $config['host'] ) && 0 === strpos( $config['host'], '/' ) ) { $config['scheme'] = 'unix'; $config['path'] = $config['host']; }
		elseif ( is_string( $config['host'] ) && preg_match( '#^(tcp|tls)://([^/]+)$#D', $config['host'], $parts ) ) { $config['scheme'] = $parts[1]; $config['host'] = $parts[2]; }
		$config['analytics'] = array_replace( self::defaults()['analytics'], (array) $config['analytics'] );
		if ( '' === $config['prefix'] ) {
			$config['prefix'] = substr( hash( 'sha256', Paths::content() ), 0, 16 );
		}
		foreach ( array( 'port' => array( 1, 65535 ), 'database' => array( 0, 65535 ), 'maxttl' => array( 1, 2592000 ), 'queryttl' => array( 1, 2592000 ) ) as $key => $bounds ) {
			if ( ! is_int( $config[ $key ] ) || $config[ $key ] < $bounds[0] || $config[ $key ] > $bounds[1] ) {
				throw new \InvalidArgumentException( 'Invalid HarborCache configuration value.' );
			}
		}
		foreach ( array( 'timeout', 'read_timeout' ) as $key ) {
			if ( ! is_numeric( $config[ $key ] ) || $config[ $key ] <= 0 || $config[ $key ] > 30 ) {
				throw new \InvalidArgumentException( 'Timeout must be between 0 and 30 seconds.' );
			}
		}
		foreach ( array( 'global_groups', 'non_persistent_groups', 'non_prefetchable_groups', 'cluster', 'sentinels', 'servers', 'tls_options' ) as $key ) {
			if ( ! is_array( $config[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Configuration collection must be an array.' );
			}
		}
		if ( ! is_string( $config['prefix'] ) || '' === $config['prefix'] || strlen( $config['prefix'] ) > 128 ) {
			throw new \InvalidArgumentException( 'Invalid HarborCache prefix.' );
		}
		if ( ! in_array( $config['network_flush'], array( 'all', 'site', 'global' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown network flush scope.' );
		}
		if ( ! in_array( $config['scheme'], array( 'tcp', 'tls', 'unix' ), true ) || ! in_array( $config['client'], array( 'predis', 'phpredis', 'relay', 'credis' ), true ) ) { throw new \InvalidArgumentException( 'Unsupported Redis connection configuration.' ); }
		foreach ( array( 'global_groups', 'non_persistent_groups', 'non_prefetchable_groups' ) as $key ) { foreach ( $config[ $key ] as $group ) { if ( ! is_string( $group ) || '' === trim( $group ) ) { throw new \InvalidArgumentException( 'Cache groups must be nonempty strings.' ); } } }
		foreach ( array( 'cluster', 'servers', 'sentinels' ) as $key ) {
			if ( count( $config[ $key ] ) > 32 ) { throw new \InvalidArgumentException( 'Connection topology supports at most 32 configured nodes.' ); }
			foreach ( $config[ $key ] as $server ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs before normal WordPress helpers load.
				$node = is_string( $server ) ? parse_url( false === strpos( $server, '://' ) ? 'tcp://' . $server : $server ) : $server;
				if ( ! is_array( $node ) || ( 'cluster' === $key && ! is_string( $server ) ) || ! isset( $node['host'], $node['port'] ) || ! is_string( $node['host'] ) || '' === $node['host'] || ! is_int( $node['port'] ) || $node['port'] < 1 || $node['port'] > 65535 || ! in_array( $node['scheme'] ?? 'tcp', array( 'tcp', 'tls' ), true ) ) { throw new \InvalidArgumentException( 'Topology nodes need a TCP or TLS host and valid port.' ); }
			}
		}
		if ( ! is_int( $config['retry_interval'] ) || $config['retry_interval'] < 0 || $config['retry_interval'] > 1000 ) { throw new \InvalidArgumentException( 'Retry interval must be between 0 and 1000 milliseconds.' ); }
		if ( $config['sentinels'] && '' === $config['service'] ) { throw new \InvalidArgumentException( 'Sentinel requires a service name.' ); }
		if ( $config['cluster'] && 0 !== $config['database'] ) { throw new \InvalidArgumentException( 'Redis Cluster requires database zero.' ); }
		if ( ( $config['sentinels'] || $config['servers'] ) && 'credis' === $config['client'] && null === $config['connector'] ) { throw new \InvalidArgumentException( 'Replica and Sentinel topology requires Predis, PhpRedis, Relay or a custom connector.' ); }
		if ( (int) ! empty( $config['cluster'] ) + (int) ! empty( $config['servers'] ) + (int) ! empty( $config['sentinels'] ) > 1 ) { throw new \InvalidArgumentException( 'Choose one connection topology.' ); }
		if ( 'unix' === $config['scheme'] && '' === $config['path'] ) { throw new \InvalidArgumentException( 'Unix transport requires a socket path.' ); }
		if ( ! is_numeric( $config['analytics']['sample_rate'] ) || $config['analytics']['sample_rate'] < 0 || $config['analytics']['sample_rate'] > 100 || ! is_int( $config['analytics']['retention'] ) || $config['analytics']['retention'] < 60 || $config['analytics']['retention'] > 86400 ) { throw new \InvalidArgumentException( 'Invalid analytics sample rate or retention.' ); }
		$config['tls_options'] = self::tls_options( $config['tls_options'], $config['client'] );
		return $config;
	}

	public static function apply() {
		static $applied = false;
		if ( $applied ) { return; }
		if ( defined( 'HARBORCACHE_DISABLED' ) && HARBORCACHE_DISABLED ) { if ( ! defined( 'WP_REDIS_DISABLED' ) ) { define( 'WP_REDIS_DISABLED', true ); } return; }
		$config = self::load();
		$map = array( 'scheme' => 'SCHEME', 'path' => 'PATH', 'client' => 'CLIENT', 'host' => 'HOST', 'port' => 'PORT', 'database' => 'DATABASE', 'password' => 'PASSWORD', 'username' => 'USERNAME', 'timeout' => 'TIMEOUT', 'read_timeout' => 'READ_TIMEOUT', 'persistent' => 'PERSISTENT', 'maxttl' => 'MAXTTL', 'global_groups' => 'GLOBAL_GROUPS', 'non_persistent_groups' => 'IGNORED_GROUPS', 'tls_options' => 'SSL_CONTEXT', 'retry_interval' => 'RETRY_INTERVAL' );
		foreach ( $map as $key => $suffix ) {
			if ( ! defined( 'WP_REDIS_' . $suffix ) ) {
				define( 'WP_REDIS_' . $suffix, $config[ $key ] );
				if ( 'client' === $key ) { define( 'HARBORCACHE_CLIENT_FROM_CONFIG', true ); }
			}
		}
		if ( ! defined( 'WP_REDIS_PREFIX' ) ) { define( 'WP_REDIS_PREFIX', 'ch:' . substr( hash( 'sha256', $config['prefix'] ), 0, 24 ) . ':' ); define( 'HARBORCACHE_PREFIX_FROM_CONFIG', true ); }
		if ( $config['cluster'] && ! defined( 'WP_REDIS_CLUSTER' ) ) { define( 'WP_REDIS_CLUSTER', $config['cluster'] ); }
		if ( $config['servers'] && ! defined( 'WP_REDIS_SERVERS' ) ) { define( 'WP_REDIS_SERVERS', $config['servers'] ); }
		if ( $config['sentinels'] ) {
			if ( ! defined( 'WP_REDIS_SERVERS' ) ) { define( 'WP_REDIS_SERVERS', $config['sentinels'] ); }
			if ( ! defined( 'WP_REDIS_SENTINEL' ) ) { define( 'WP_REDIS_SENTINEL', $config['service'] ); }
		}
		$flags = array( 'WP_REDIS_DISABLED' => defined( 'HARBORCACHE_DISABLED' ) && HARBORCACHE_DISABLED, 'WP_REDIS_DISABLE_BANNERS' => true, 'WP_REDIS_DISABLE_COMMENT' => true, 'WP_REDIS_LOAD_APEXCHARTS' => false, 'WP_REDIS_CHART_COLOR' => '#0b615e', 'WP_REDIS_DISABLE_DROPIN_AUTOUPDATE' => true );
		foreach ( $flags as $key => $value ) { if ( ! defined( $key ) ) { define( $key, $value ); } }
		add_filter( 'redis_cache_expiration', static function ( $ttl, $key, $group ) use ( $config ) {
			$max = substr( (string) $group, -8 ) === '-queries' ? min( $config['maxttl'], $config['queryttl'] ) : $config['maxttl'];
			return $ttl > 0 ? min( $max, (int) $ttl ) : $max;
		}, 10, 3 );
		$applied = true;
	}
}
