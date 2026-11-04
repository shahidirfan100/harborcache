<?php
/** User-initiated readiness check. Uses the configured client and installation namespace. */
namespace HarborCache;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/ch-config.php';
require_once __DIR__ . '/ch-codec.php';
require_once __DIR__ . '/ch-namespace.php';

final class Connection_Check {
	/**
	 * Use the configured timeout. The previous five second floor made a probe
	 * pass while the live cache (default one second) could not connect.
	 */
	const MIN_TIMEOUT = 0.1;

	/**
	 * Check the candidate configuration without replacing live settings.
	 *
	 * @param array  $config  Validated configuration.
	 * @param string $context Where the check started: manual, save, cli.
	 * @return array
	 */
	public static function run( array $config, $context = 'manual' ) {
		$started = microtime( true );
		$timeout = max( self::MIN_TIMEOUT, min( 30.0, (float) ( $config['timeout'] ?? 1 ) ) );
		$result  = array(
			'ok'                   => false,
			'transport_ok'         => false,
			'namespace'            => 'unknown',
			'namespace_fingerprint' => '',
			'codec'                => 'unknown',
			'client'               => isset( $config['client'] ) ? (string) $config['client'] : 'predis',
			'client_version'       => '',
			'redis_version'        => '',
			'database'             => isset( $config['database'] ) ? (int) $config['database'] : 0,
			'scheme'               => isset( $config['scheme'] ) ? (string) $config['scheme'] : 'tcp',
			'host'                 => self::endpoint( $config ),
			'timeout'              => $timeout,
			'elapsed_ms'           => 0.0,
			'checked_at'           => time(),
			'error_code'           => '',
			'message'              => '',
			'detail'               => '',
			'context'              => (string) $context,
		);

		$client = null;

		try {
			$result['codec'] = self::codec_check( $config );
			$client = self::connect( $config, $timeout, $result );
			$pong   = self::command( $client, array( 'PING' ) );
			if ( ! self::is_pong( $pong ) ) {
				throw new \RuntimeException( 'Redis answered, but not with PONG.' );
			}
			$result['transport_ok'] = true;

			$owner = Namespace_Guard::owner_state(
				static function ( $args ) use ( $client ) {
					return self::command( $client, $args );
				},
				self::base( $config )
			);
			$result['namespace']             = $owner['state'];
			$result['namespace_fingerprint'] = $owner['fingerprint'];

			$key   = self::probe_key( $config );
			$write = self::command( $client, array( 'SET', $key, '1', 'PX', '5000', 'NX' ) );
			if ( null !== $write && ! Namespace_Guard::is_ok( $write ) && true !== $write ) {
				throw new \RuntimeException( 'Redis rejected a temporary write.' );
			}
			$value = self::command( $client, array( 'GET', $key ) );
			self::command( $client, array( 'DEL', $key ) );
			if ( '1' !== (string) $value ) {
				throw new \RuntimeException( 'Redis did not return the temporary value.' );
			}

			try {
				$info                    = self::command( $client, array( 'INFO', 'server' ) );
				$result['redis_version'] = self::redis_version( $info );
			} catch ( \Throwable $ignored ) {
				// Server information is optional; it must not hide working data access.
				$result['redis_version'] = '';
			}

			if ( 'conflict' === $owner['state'] ) {
				$result['error_code'] = 'namespace-conflict';
				$result['message']    = __( 'Redis is reachable, but this cache namespace belongs to another installation. Choose a distinct prefix or reclaim the namespace after confirming the old owner is gone.', 'harborcache-object-cache-with-redis' );
			} elseif ( 'error' === $result['codec'] ) {
				$result['error_code'] = 'codec';
				$result['message']    = __( 'Redis is reachable, but the configured serializer or compression could not round-trip a value.', 'harborcache-object-cache-with-redis' );
			} else {
				$result['ok']      = true;
				$result['message'] = __( 'Connected and verified through the cache value format.', 'harborcache-object-cache-with-redis' );
			}
		} catch ( \Throwable $error ) {
			$result = array_merge( $result, self::classify( $error ) );
		} finally {
			self::close( $client );
			$result['elapsed_ms'] = round( ( microtime( true ) - $started ) * 1000, 1 );
		}

		if ( 'error' === $result['codec'] && empty( $result['error_code'] ) ) {
			$result['error_code'] = 'codec';
			$result['message']    = __( 'Redis is reachable, but the configured serializer or compression could not round-trip a value.', 'harborcache-object-cache-with-redis' );
		}

		return $result;
	}

	/** Round-trip one value through the exact serializer and compression pair. */
	private static function codec_check( array $config ) {
		try {
			$codec = new Codec( $config );
			$probe = array( 'check' => 'harborcache', 'number' => 42 );
			return $codec->decode( $codec->encode( $probe ) ) === $probe ? 'ok' : 'error';
		} catch ( \Throwable $error ) {
			return 'error';
		}
	}

	/**
	 * Explicit namespace takeover after the operator confirms the old owner is gone.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function reclaim( array $config ) {
		$client  = null;
		$timeout = max( self::MIN_TIMEOUT, min( 30.0, (float) ( $config['timeout'] ?? 1 ) ) );
		$probe   = array();
		try {
			$client = self::connect( $config, $timeout, $probe );
			Namespace_Guard::reclaim(
				static function ( $args ) use ( $client ) {
					return self::command( $client, $args );
				},
				self::base( $config )
			);
			return array( 'ok' => true, 'message' => __( 'This installation now owns the cache namespace. The next request will use it.', 'harborcache-object-cache-with-redis' ) );
		} catch ( \Throwable $error ) {
			return array( 'ok' => false, 'message' => $error->getMessage() );
		} finally {
			self::close( $client );
		}
	}

	private static function connect( array $config, $timeout, array &$result ) {
		if ( is_callable( $config['connector'] ?? null ) ) {
			$client = call_user_func( $config['connector'], $config );
			if ( ! is_object( $client ) ) {
				throw new \RuntimeException( 'The custom connector did not return a client.' );
			}
			$result['client_version'] = 'custom connector';
			return $client;
		}

		$client = $config['client'] ?? 'predis';
		if ( in_array( $client, array( 'phpredis', 'relay' ), true ) && ! $config['cluster'] && ! $config['sentinels'] && ! $config['servers'] ) {
			return self::connect_native( $config, $timeout, $result );
		}
		if ( 'credis' === $client && ! $config['cluster'] && ! $config['sentinels'] && ! $config['servers'] ) {
			return self::connect_credis( $config, $timeout, $result );
		}

		return self::connect_predis( $config, $timeout, $result );
	}

	private static function connect_predis( array $config, $timeout, array &$result ) {
		if ( ! class_exists( '\\Predis\\Client' ) ) {
			require_once HARBORCACHE_PLUGIN_PATH . '/vendor/predis/predis/autoload.php';
		}

		$parameters = array(
			'scheme'             => $config['scheme'],
			'timeout'            => $timeout,
			'read_write_timeout' => $timeout,
		);
		if ( 'unix' === $config['scheme'] ) {
			$parameters['path'] = $config['path'];
		} else {
			$parameters['host'] = $config['host'];
			$parameters['port'] = (int) $config['port'];
		}
		if ( '' !== (string) $config['username'] ) {
			$parameters['username'] = $config['username'];
		}
		if ( '' !== (string) $config['password'] ) {
			$parameters['password'] = $config['password'];
		}
		if ( (int) $config['database'] > 0 ) {
			$parameters['database'] = (int) $config['database'];
		}
		if ( 'tls' === $config['scheme'] ) {
			$parameters['ssl'] = Config::tls_options( $config['tls_options'], 'predis' );
		}

		$options = array();
		if ( $config['sentinels'] ) {
			$parameters['servers'] = $config['sentinels'];
			$options['replication'] = 'sentinel';
			$options['service']     = $config['service'];
		} elseif ( $config['servers'] ) {
			$parameters['servers'] = $config['servers'];
			$options['replication']  = 'predis';
			$options['autodiscovery'] = false;
		} elseif ( $config['cluster'] ) {
			$parameters['cluster'] = $config['cluster'];
			$options['cluster']    = 'redis';
		}

		if ( $config['sentinels'] || $config['servers'] || $config['cluster'] ) {
			$options['parameters'] = array(
				'scheme'             => $config['scheme'],
				'timeout'            => $timeout,
				'read_write_timeout' => $timeout,
				'database'           => (int) $config['database'],
			);
			if ( '' !== (string) $config['username'] ) {
				$options['parameters']['username'] = $config['username'];
			}
			if ( '' !== (string) $config['password'] ) {
				$options['parameters']['password'] = $config['password'];
			}
		}

		$client = new \Predis\Client( $parameters, $options );
		$client->connect();
		$result['client_version'] = 'Predis ' . \Predis\Client::VERSION;
		if ( 'phpredis' === ( $config['client'] ?? '' ) || 'relay' === ( $config['client'] ?? '' ) ) {
			$result['client'] = 'predis';
			$result['detail'] = 'Topology probes use Predis. The runtime keeps the configured client.';
		}
		return $client;
	}

	private static function connect_native( array $config, $timeout, array &$result ) {
		$relay  = 'relay' === $config['client'];
		$class  = $relay ? '\\Relay\\Relay' : '\\Redis';
		if ( ! class_exists( $class ) ) {
			if ( $relay ) {
				throw new \RuntimeException( 'The Relay PHP extension is not installed.' );
			}
			throw new \RuntimeException( 'The Redis PHP extension is not installed.' );
		}

		$client = new $class();
		$host   = $config['host'];
		$port   = (int) $config['port'];
		$context = null;

		if ( 'unix' === $config['scheme'] ) {
			$host = $config['path'];
			$port = 0;
		} elseif ( 'tls' === $config['scheme'] ) {
			$host    = 'tls://' . str_replace( 'tls://', '', $config['host'] );
			$context = array( 'stream' => Config::tls_options( $config['tls_options'], $config['client'] ) );
		}

		$args = array( $host, $port, $timeout, null, 0, $timeout );
		if ( null !== $context ) {
			$args[] = $context;
		}
		try {
			$client->connect( ...$args );
		} catch ( \ArgumentCountError $error ) {
			// Older native builds do not accept the stream context argument.
			array_pop( $args );
			$client->connect( ...$args );
		}

		if ( '' !== (string) $config['username'] ) {
			$auth = $client->auth( array( $config['username'], $config['password'] ) );
		} elseif ( '' !== (string) $config['password'] ) {
			$auth = $client->auth( $config['password'] );
		} else {
			$auth = true;
		}
		if ( false === $auth ) {
			throw new \RuntimeException( esc_html( self::native_error( $client, 'Redis rejected the password or ACL user.' ) ) );
		}
		if ( (int) $config['database'] > 0 ) {
			$selected = $client->select( (int) $config['database'] );
			if ( false === $selected ) {
				throw new \RuntimeException( esc_html( self::native_error( $client, 'Redis rejected the selected database.' ) ) );
			}
		}

		$result['client_version'] = $relay ? 'Relay ' . phpversion( 'relay' ) : 'PhpRedis ' . phpversion( 'redis' );
		return $client;
	}

	private static function connect_credis( array $config, $timeout, array &$result ) {
		$path = HARBORCACHE_PLUGIN_PATH . '/vendor/colinmollenhour/credis/Client.php';
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( 'The bundled Credis client is missing.' );
		}
		require_once $path;

		$tls    = 'tls' === $config['scheme'] ? Config::tls_options( $config['tls_options'], 'credis' ) : null;
		$client = new \Credis_Client(
			'unix' === $config['scheme'] ? $config['path'] : $config['host'],
			(int) $config['port'],
			$timeout,
			false,
			(int) $config['database'],
			'' !== (string) $config['password'] ? $config['password'] : null,
			'' !== (string) $config['username'] ? $config['username'] : null,
			$tls
		);
		$client->connect();
		$result['client_version'] = 'Credis (bundled)';
		return $client;
	}

	private static function command( $client, array $args ) {
		if ( $client instanceof \Predis\Client ) {
			$error = false;
			$reply = $client->executeRaw( $args, $error );
			if ( $error ) {
				throw new \RuntimeException( 'Redis rejected a probe command.' );
			}
			return $reply;
		}
		if ( $client instanceof \Credis_Client ) {
			return $client->rawCommand( $args[0], array_slice( $args, 1 ) );
		}
		if ( method_exists( $client, 'rawCommand' ) ) {
			return $client->rawCommand( ...$args );
		}
		$method = strtolower( array_shift( $args ) );
		return $client->$method( ...$args );
	}

	private static function native_error( $client, $fallback ) {
		if ( method_exists( $client, 'getLastError' ) ) {
			$error = $client->getLastError();
			if ( is_string( $error ) && '' !== $error ) {
				if ( method_exists( $client, 'clearLastError' ) ) {
					$client->clearLastError();
				}
				return $error;
			}
		}
		return $fallback;
	}

	private static function close( $client ) {		if ( ! is_object( $client ) ) {
			return;
		}
		try {
			if ( $client instanceof \Predis\Client ) {
				$client->disconnect();
			} elseif ( method_exists( $client, 'close' ) ) {
				$client->close();
			}
		} catch ( \Throwable $ignored ) {
			// A probe must not fail while closing a socket.
		}
	}

	private static function is_pong( $reply ) {
		if ( $reply instanceof \Predis\Response\Status ) {
			return 'PONG' === strtoupper( (string) $reply );
		}
		return true === $reply || 'PONG' === strtoupper( (string) $reply );
	}

	private static function redis_version( $info ) {
		$text = is_array( $info ) ? (string) ( $info['redis_version'] ?? '' ) : (string) $info;
		if ( preg_match( '/^redis_version:([^\r\n]+)/m', $text, $match ) ) {
			return trim( $match[1] );
		}
		return '';
	}

	private static function prefix( array $config ) {
		return defined( 'WP_REDIS_PREFIX' ) && ! defined( 'HARBORCACHE_PREFIX_FROM_CONFIG' ) ? WP_REDIS_PREFIX : 'ch:' . substr( hash( 'sha256', (string) $config['prefix'] ), 0, 24 ) . ':';
	}

	private static function base( array $config ) {
		return self::prefix( $config ) . 'meta';
	}

	private static function probe_key( array $config ) {
		return self::prefix( $config ) . 'probe:' . bin2hex( random_bytes( 6 ) );
	}

	private static function endpoint( array $config ) {
		if ( 'unix' === ( $config['scheme'] ?? 'tcp' ) ) {
			return (string) ( $config['path'] ?? '' );
		}
		if ( ! empty( $config['cluster'] ) ) {
			return sprintf( '%d cluster nodes', count( $config['cluster'] ) );
		}
		if ( ! empty( $config['sentinels'] ) ) {
			return sprintf( '%s via %d sentinels', (string) ( $config['service'] ?? '' ), count( $config['sentinels'] ) );
		}
		if ( ! empty( $config['servers'] ) ) {
			return sprintf( '%d configured servers', count( $config['servers'] ) );
		}
		return sprintf( '%s:%d', (string) ( $config['host'] ?? '' ), (int) ( $config['port'] ?? 0 ) );
	}

	private static function classify( \Throwable $error ) {
		$raw  = $error->getMessage();
		$text = strtolower( $raw );
		$code = 'unreachable';

		if ( false !== strpos( $text, 'namespace' ) || false !== strpos( $text, 'belongs to another' ) ) {
			$code    = 'namespace-conflict';
			$message = __( 'Redis is reachable, but this cache namespace belongs to another installation.', 'harborcache-object-cache-with-redis' );
		} elseif ( false !== strpos( $text, 'serializer' ) || false !== strpos( $text, 'compression' ) || false !== strpos( $text, 'codec' ) ) {
			$code    = 'codec';
			$message = __( 'The configured serializer or compression is unavailable or cannot round-trip a value.', 'harborcache-object-cache-with-redis' );
		} elseif ( false !== strpos( $text, 'noauth' ) || false !== strpos( $text, 'wrongpass' ) || false !== strpos( $text, 'invalid password' ) || false !== strpos( $text, 'without any password configured' ) || false !== strpos( $text, 'no password is set' ) ) {
			$code    = 'auth';
			$message = __( 'Redis rejected the password or ACL user.', 'harborcache-object-cache-with-redis' );
		} elseif ( false !== strpos( $text, 'noperm' ) || false !== strpos( $text, 'permission' ) ) {
			$code    = 'acl';
			$message = __( 'The Redis user is not allowed to run the commands this cache needs.', 'harborcache-object-cache-with-redis' );
		} elseif ( false !== strpos( $text, 'refused' ) ) {
			$code    = 'refused';
			$message = __( 'Nothing is accepting connections at that host and port.', 'harborcache-object-cache-with-redis' );
		} elseif ( false !== strpos( $text, 'timed out' ) || false !== strpos( $text, 'timeout' ) || false !== strpos( $text, 'period of time' ) || false !== strpos( $text, 'failed to respond' ) ) {
			$code    = 'timeout';
			$message = __( 'The connection timed out before Redis answered.', 'harborcache-object-cache-with-redis' );
		} elseif ( false !== strpos( $text, 'certificate' ) || false !== strpos( $text, 'ssl' ) || false !== strpos( $text, 'tls' ) ) {
			$code    = 'tls';
			$message = __( 'The TLS handshake or certificate check failed.', 'harborcache-object-cache-with-redis' );
		} elseif ( false !== strpos( $text, 'getaddrinfo' ) || false !== strpos( $text, 'name or service not known' ) || false !== strpos( $text, 'php_network_getaddresses' ) || false !== strpos( $text, 'nodename nor servname' ) ) {
			$code    = 'dns';
			$message = __( 'The host name could not be resolved.', 'harborcache-object-cache-with-redis' );
		} elseif ( false !== strpos( $text, 'no such file' ) || false !== strpos( $text, 'socket' ) ) {
			$code    = 'socket';
			$message = __( 'The Unix socket could not be opened.', 'harborcache-object-cache-with-redis' );
		} elseif ( false !== strpos( $text, 'extension is not installed' ) || ( false !== strpos( $text, 'class' ) && false !== strpos( $text, 'not found' ) ) ) {
			$code    = 'extension';
			$message = __( 'The selected PHP Redis extension is not available on this server.', 'harborcache-object-cache-with-redis' );
		} elseif ( false !== strpos( $text, 'database' ) || false !== strpos( $text, 'select' ) || false !== strpos( $text, 'db index' ) ) {
			$code    = 'database';
			$message = __( 'Redis rejected the selected database.', 'harborcache-object-cache-with-redis' );
		} else {
			$message = __( 'Redis did not complete the connection check.', 'harborcache-object-cache-with-redis' );
		}

		return array(
			'error_code' => $code,
			'message'    => $message,
			'detail'     => $raw,
		);
	}

	/** Render one result card for the settings screen and AJAX replies. */
	public static function render( array $probe ) {
		$probe += array(
			'ok'                    => false,
			'transport_ok'          => false,
			'namespace'             => 'unknown',
			'namespace_fingerprint' => '',
			'codec'                 => 'unknown',
			'elapsed_ms'            => 0.0,
			'client'                => '',
			'client_version'        => '',
			'host'                  => '',
			'database'              => 0,
			'redis_version'         => '',
			'timeout'               => 0,
			'message'               => __( 'The connection check did not return a result.', 'harborcache-object-cache-with-redis' ),
			'checked_at'            => time(),
		);
		$ok        = ! empty( $probe['ok'] );
		$transport = ! empty( $probe['transport_ok'] );
		$class     = $ok ? 'is-connected' : ( $transport ? 'is-degraded' : 'is-failed' );
		$client    = (string) ( $probe['client'] ?? '' );
		$html      = '<div class="ch-probe ' . esc_attr( $class ) . '" role="status" aria-live="polite">';

		if ( $ok ) {
			$html .= '<strong>' . esc_html( sprintf(
				/* translators: %s: elapsed time in milliseconds. */
				__( 'Connected in %s ms', 'harborcache-object-cache-with-redis' ),
				number_format_i18n( (float) $probe['elapsed_ms'], 1 )
			) ) . '</strong>';
		} elseif ( $transport ) {
			$html .= '<strong>' . esc_html__( 'Redis reached, cache not ready', 'harborcache-object-cache-with-redis' ) . '</strong>';
		} else {
			$html .= '<strong>' . esc_html__( 'Not connected', 'harborcache-object-cache-with-redis' ) . '</strong>';
		}

		$html .= '<p>' . esc_html( (string) $probe['message'] ) . '</p>';
		$html .= '<ul class="ch-probe-checks">';
		foreach ( self::checks( $probe ) as $row ) {
			$html .= '<li class="' . esc_attr( $row['ok'] ? 'is-ok' : 'is-bad' ) . '"><span>' . esc_html( $row['label'] ) . '</span><strong>' . esc_html( $row['value'] ) . '</strong></li>';
		}
		$html .= '</ul>';

		if ( $ok ) {
			$html .= '<p>' . esc_html( sprintf(
				/* translators: 1: client and version, 2: endpoint, 3: database number, 4: Redis version. */
				__( '%1$s at %2$s, database %3$d, Redis %4$s.', 'harborcache-object-cache-with-redis' ),
				'' !== (string) $probe['client_version'] ? $probe['client_version'] : $client,
				$probe['host'],
				(int) $probe['database'],
				'' !== (string) $probe['redis_version'] ? $probe['redis_version'] : __( 'unknown', 'harborcache-object-cache-with-redis' )
			) ) . '</p>';
		} else {
			$html .= '<p class="ch-probe-next">' . esc_html__( 'Check that Redis is running, the host and port are reachable, the password or ACL user matches, and this installation owns its cache prefix. Then try again.', 'harborcache-object-cache-with-redis' ) . '</p>';
		}

		$html .= '<p class="ch-probe-meta">' . esc_html( sprintf(
			/* translators: 1: timeout in seconds, 2: date and time of the check. */
			__( 'Timeout %1$s s. Checked %2$s.', 'harborcache-object-cache-with-redis' ),
			number_format_i18n( (float) ( $probe['timeout'] ?? 0 ), 2 ),
			wp_date( 'M j, H:i:s', (int) $probe['checked_at'] )
		) ) . '</p></div>';

		return $html;
	}

	private static function checks( array $probe ) {
		$namespace = (string) ( $probe['namespace'] ?? 'unknown' );
		$codec     = (string) ( $probe['codec'] ?? 'unknown' );
		$labels    = array(
			'owned'     => __( 'Owned by this installation', 'harborcache-object-cache-with-redis' ),
			'legacy'    => __( 'Legacy owner (will migrate)', 'harborcache-object-cache-with-redis' ),
			'unclaimed' => __( 'Not claimed yet', 'harborcache-object-cache-with-redis' ),
			'conflict'  => __( 'Owned by another installation', 'harborcache-object-cache-with-redis' ),
			'unknown'   => __( 'Unknown', 'harborcache-object-cache-with-redis' ),
		);
		return array(
			array(
				'label' => __( 'Transport', 'harborcache-object-cache-with-redis' ),
				'value' => ! empty( $probe['transport_ok'] ) ? __( 'Reachable', 'harborcache-object-cache-with-redis' ) : __( 'Unreachable', 'harborcache-object-cache-with-redis' ),
				'ok'    => ! empty( $probe['transport_ok'] ),
			),
			array(
				'label' => __( 'Namespace', 'harborcache-object-cache-with-redis' ),
				'value' => ( $labels[ $namespace ] ?? $namespace ) . ( '' !== (string) ( $probe['namespace_fingerprint'] ?? '' ) ? ' · ' . $probe['namespace_fingerprint'] : '' ),
				'ok'    => in_array( $namespace, array( 'owned', 'legacy', 'unclaimed' ), true ),
			),
			array(
				'label' => __( 'Value codec', 'harborcache-object-cache-with-redis' ),
				'value' => 'ok' === $codec ? __( 'Round trip passed', 'harborcache-object-cache-with-redis' ) : __( 'Round trip failed', 'harborcache-object-cache-with-redis' ),
				'ok'    => 'ok' === $codec,
			),
		);
	}
}
