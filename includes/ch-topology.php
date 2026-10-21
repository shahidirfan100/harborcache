<?php
/** Native primary/replica connections and Sentinel discovery. */
defined( 'ABSPATH' ) || exit;
trait HarborCache_Topology {
	private $ch_native_nodes = array();
	private $ch_native_clients = array();
	private $ch_native_primary;
	private function native_node( $value ) {
		$node = is_array( $value ) ? $value : parse_url( false === strpos( $value, '://' ) ? 'tcp://' . $value : $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Bootstrap before URL helpers load.
		if ( ! is_array( $value ) && isset( $node['query'] ) ) { parse_str( $node['query'], $query ); $node = array_replace( $node, array_intersect_key( $query, array_flip( array( 'role' ) ) ) ); }
		if ( isset( $node['user'] ) ) { $node['username'] = rawurldecode( $node['user'] ); }
		if ( isset( $node['pass'] ) ) { $node['password'] = rawurldecode( $node['pass'] ); }
		return $node;
	}
	private function native_client( array $node, $sentinel = false ) {
		$config = $this->ch_config; $class = 'relay' === $config['client'] ? '\Relay\Relay' : '\Redis';
		$client = new $class(); $scheme = $node['scheme'] ?? $config['scheme'];
		$host = ( 'tls' === $scheme ? 'tls://' : '' ) . $node['host'];
		$context = 'tls' === $scheme ? array( 'stream' => $config['tls_options'] ) : array();
		// Sentinel credentials are explicit per-node; Redis data credentials are not sent to Sentinel.
		$user = $node['username'] ?? ( $sentinel ? '' : $config['username'] );
		$password = $node['password'] ?? ( $sentinel ? '' : $config['password'] );
		$method = $config['persistent'] && ! $sentinel ? 'pconnect' : 'connect';
		$id = 'hc-' . hash( 'sha256', $config['client'] . $host . ':' . $node['port'] . ':' . $config['database'] . ':' . $user . ':' . $password );
		if ( ! $client->$method( $host, (int) $node['port'], (float) $config['timeout'], 'pconnect' === $method ? $id : null, 0, (float) $config['read_timeout'], $context ) ) { throw new RuntimeException( 'Native Redis connection failed.' ); }
		if ( '' !== $password && ! $client->auth( '' === $user ? $password : array( $user, $password ) ) ) { throw new RuntimeException( 'Native Redis authentication failed.' ); }
		if ( ! $sentinel && ! $client->select( $config['database'] ) ) { throw new RuntimeException( 'Native Redis database unavailable.' ); }
		if ( defined( $class . '::OPT_MAX_RETRIES' ) ) { $client->setOption( constant( $class . '::OPT_MAX_RETRIES' ), 0 ); }
		if ( 'relay' === $config['client'] ) {
			foreach ( array( 'OPT_USE_CACHE' => ! $sentinel && $config['relay']['cache'], 'OPT_CLIENT_INVALIDATIONS' => $config['relay']['invalidations'], 'OPT_IGNORE_PATTERNS' => $config['relay']['ignored'], 'OPT_ALLOW_PATTERNS' => $config['relay']['allowed'] ) as $option => $setting ) {
				if ( defined( $class . '::' . $option ) && false === $client->setOption( constant( $class . '::' . $option ), $setting ) ) { throw new RuntimeException( 'Native Relay option rejected.' ); }
			}
		}
		if ( 'relay' === $config['client'] && ! $sentinel ) {
			if ( $config['relay']['adaptive'] ) {
				$a = $config['relay']['adaptive'];
				if ( ! defined( $class . '::OPT_ADAPTIVE_CACHE' ) || false === $client->setOption( constant( $class . '::OPT_ADAPTIVE_CACHE' ), array( 'enabled' => true, 'width' => $a['width'], 'depth' => $a['depth'], 'min_events' => $a['events'], 'min_ratio' => (float) $a['ratio'], 'formula' => $a['formula'] ) ) ) { throw new RuntimeException( 'Native Relay adaptive policy unavailable.' ); }
			}
			if ( $config['relay']['listeners'] ) { $client->onFlushed( function () { $this->flush_runtime(); } ); $client->onInvalidated( function () { $this->flush_runtime(); } ); }
		}
		return $client;
	}
	private function native_discover() {
		$config = $this->ch_config;
		if ( ! $config['sentinels'] ) {
			$nodes = array(); $primary = 0;
			foreach ( array_values( $config['servers'] ) as $i => $server ) { $node = $this->native_node( $server ); $role = $node['role'] ?? ( 0 === $i ? 'primary' : 'replica' ); if ( in_array( $role, array( 'primary', 'master' ), true ) ) { ++$primary; $node['role'] = 'primary'; } elseif ( in_array( $role, array( 'replica', 'slave' ), true ) ) { $node['role'] = 'replica'; } else { throw new RuntimeException( 'Invalid native node role.' ); } $nodes[] = $node; }
			if ( 1 !== $primary ) { throw new RuntimeException( 'Native replication needs exactly one primary.' ); }
			return $nodes;
		}
		foreach ( $config['sentinels'] as $server ) {
			try {
				$node = $this->native_node( $server ); $sentinel = $this->native_client( $node, true );
				$address = $sentinel->rawCommand( 'SENTINEL', 'get-master-addr-by-name', $config['service'] );
				if ( ! is_array( $address ) || 2 !== count( $address ) || ! ctype_digit( (string) $address[1] ) ) { throw new RuntimeException( 'Sentinel primary unavailable.' ); }
				$nodes = array( array( 'host' => $address[0], 'port' => (int) $address[1], 'scheme' => $config['scheme'], 'role' => 'primary' ) );
				$replicas = $sentinel->rawCommand( 'SENTINEL', 'replicas', $config['service'] );
				foreach ( (array) $replicas as $row ) { $info = array(); for ( $i = 0; $i + 1 < count( $row ); $i += 2 ) { $info[ $row[$i] ] = $row[$i+1]; } if ( isset( $info['ip'], $info['port'] ) && false === strpos( $info['flags'] ?? '', 'down' ) && 'ok' === ( $info['master-link-status'] ?? 'ok' ) ) { $nodes[] = array( 'host' => $info['ip'], 'port' => (int) $info['port'], 'scheme' => $config['scheme'], 'role' => 'replica' ); } if ( count( $nodes ) >= 32 ) { break; } }
				$sentinel->close(); return $nodes;
			} catch ( Throwable $error ) { /* Try the next configured Sentinel, without exposing endpoints. */ }
		}
		throw new RuntimeException( 'No configured Sentinel could discover a primary.' );
	}
	private function connect_native_topology() {
		$this->ch_native_nodes = $this->native_discover(); $this->ch_native_clients = array();
		foreach ( $this->ch_native_nodes as $i => $node ) {
			if ( 'primary' !== $node['role'] ) { continue; }
			$this->redis = $this->native_client( $node );
			$role = $this->redis->rawCommand( 'ROLE' );
			if ( ! is_array( $role ) || 'master' !== ( $role[0] ?? '' ) ) { throw new RuntimeException( 'Discovered Redis node is not a writable primary.' ); }
			$this->ch_native_primary = $i; $this->ch_native_clients[$i] = $this->redis;
			$this->diagnostics['client'] = ( 'relay' === $this->ch_config['client'] ? 'Relay' : 'PhpRedis' ) . ' native topology';
		}
	}
	private function native_topology_read( $method, array $args, $own_write ) {
		$mode = $this->ch_config['replication_strategy']; $primary = $this->redis;
		$replicas = array_keys( array_filter( $this->ch_native_nodes, static function ( $node ) { return 'replica' === $node['role']; } ) );
		if ( $own_write || ! $replicas || ( 'distribute' === $mode && 0 === random_int( 0, count( $replicas ) ) ) ) { if ( ! $own_write && ! $replicas && 'distribute_replicas' === $mode ) { throw new RuntimeException( 'No native read replica available.' ); } ++$this->ch_routes['primary']; return $this->native_data_read( $primary, $method, $args ); }
		if ( 'concentrate' === $mode ) { if ( null === $this->ch_concentrated ) { $this->ch_concentrated = random_int( 0, count( $replicas ) - 1 ); } $replicas = array( $replicas[ $this->ch_concentrated % count( $replicas ) ] ); } else { shuffle( $replicas ); }
		foreach ( $replicas as $i ) { try { if ( ! isset( $this->ch_native_clients[$i] ) ) { $this->ch_native_clients[$i] = $this->native_client( $this->ch_native_nodes[$i] ); } ++$this->ch_routes['replica']; return $this->native_data_read( $this->ch_native_clients[$i], $method, $args ); } catch ( Throwable $error ) { unset( $this->ch_native_clients[$i] ); } }
		if ( 'distribute_replicas' === $mode ) { throw new RuntimeException( 'Native read replicas unavailable.' ); }
		++$this->ch_routes['primary']; return $this->native_data_read( $primary, $method, $args );
	}
	private function native_data_read( $client, $method, array $args ) {
		try {
			$result = $client->$method( ...$args );
			if ( false === $result ) { \HarborCache\Namespace_Guard::checked_reply( $client, $result ); }
			return $result;
		} catch ( Throwable $error ) {
			try { $client->close(); } catch ( Throwable $ignored ) {}
			throw $error;
		}
	}
	private function native_topology_nodes() {
		$nodes = array( $this->redis ); foreach ( $this->ch_native_nodes as $i => $node ) { if ( $i === $this->ch_native_primary ) { continue; } try { $nodes[] = $this->ch_native_clients[$i] ?? ( $this->ch_native_clients[$i] = $this->native_client( $node ) ); } catch ( Throwable $error ) { $nodes[] = null; } } return $nodes;
	}
}
