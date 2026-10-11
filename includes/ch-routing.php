<?php
/** Explicit read routing; namespace metadata and writes always use primary nodes. */
defined( 'ABSPATH' ) || exit;
trait HarborCache_Routing {
	private $ch_slots;
	private $ch_replica_clients = array();
	private $ch_written = array();
	private $ch_concentrated;
	public $ch_routes = array( 'primary' => 0, 'replica' => 0 );
	public function mark_write( $key ) {
		if ( ! defined( 'WP_REDIS_CLUSTER' ) && ! $this->ch_config['servers'] && ! $this->ch_config['sentinels'] ) { return; }
		foreach ( is_array( $key ) ? $key : array( $key ) as $item ) { if ( is_string( $item ) || is_int( $item ) ) { $this->ch_written[ (string) $item ] = true; } }
	}

	private function reset_read_topology() {
		$this->ch_slots = null;
		$this->ch_replica_clients = array();
		$this->ch_concentrated = null;
		$this->ch_native_nodes = array();
		$this->ch_native_clients = array();
		$this->ch_native_primary = null;
		// Keep the request write set: a reconnect must not allow stale own-write reads.
	}

	private function routed_read( $method, array $args, callable $primary ) {
		$client = $this->redis_instance();
		if ( $client instanceof \Predis\Client && ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) ) { $primary = function () use ( $client, $method, $args ) { return $client->getClientBy( 'role', 'master' )->$method( ...$args ); }; }
		$keys = is_array( $args[0] ?? null ) ? $args[0] : array( $args[0] ?? '' );
		$own_write = array_intersect_key( $this->ch_written, array_fill_keys( $keys, true ) );
		if ( $this->ch_native_nodes ) { return $this->native_topology_read( $method, $args, (bool) $own_write ); }
		if ( $client instanceof \RedisCluster || ( class_exists( '\Relay\Cluster' ) && $client instanceof \Relay\Cluster ) ) { return $this->native_read( $primary, (bool) $own_write ); }
		if ( ! ( $client instanceof \Predis\Client ) || $own_write ) { ++$this->ch_routes['primary']; return $primary(); }
		$replicas = array(); $strategy = 'none';
		if ( defined( 'WP_REDIS_CLUSTER' ) ) {
			$strategy = $this->ch_config['cluster_failover'];
			if ( 'none' === $strategy ) { ++$this->ch_routes['primary']; return $primary(); }
			$slot = $client->getConnection()->getClusterStrategy()->getSlotByKey( (string) reset( $keys ) );
			if ( null === $this->ch_slots ) {
				$last = null;
				foreach ( $client->getConnection()->getIterator() as $connection ) {
					try { $slots = $client->getClientBy( 'id', (string) $connection )->executeRaw( array( 'CLUSTER', 'SLOTS' ) ); if ( is_array( $slots ) && $slots ) { $this->ch_slots = $slots; break; } }
					catch ( Throwable $error ) { $last = $error; }
				}
				if ( null === $this->ch_slots ) { throw $last ?: new RuntimeException( 'Cluster topology is unavailable.' ); }
			}
			foreach ( $this->ch_slots as $range ) { if ( $slot >= $range[0] && $slot <= $range[1] ) {
				foreach ( array_slice( $range, 3 ) as $node ) { $replicas[] = $this->topology_client( $node ); } break;
			} }
		} elseif ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) {
			$connection = $client->getConnection();
			if ( method_exists( $connection, 'getSlaves' ) ) { foreach ( $connection->getSlaves() as $replica ) { $replicas[] = new \Predis\Client( $replica ); } }
			$strategy = $this->ch_config['replication_strategy'];
		}
		if ( 'error' === $strategy ) {
			try { ++$this->ch_routes['primary']; return $primary(); }
			catch ( Throwable $error ) { if ( ! $replicas ) { throw $error; } }
		}
		if ( ! $replicas ) {
			if ( 'distribute_replicas' === $strategy ) { throw new RuntimeException( 'No read replica is available.' ); }
			++$this->ch_routes['primary']; return $primary();
		}
		if ( 'concentrate' === $strategy ) { if ( null === $this->ch_concentrated ) { $this->ch_concentrated = random_int( 0, count( $replicas ) - 1 ); } $replicas = array( $replicas[ $this->ch_concentrated % count( $replicas ) ] ); }
		else { shuffle( $replicas ); }
		if ( 'distribute' === $strategy && 0 === random_int( 0, count( $replicas ) ) ) { ++$this->ch_routes['primary']; return $primary(); }
		$last = null;
		foreach ( $replicas as $replica ) {
			try { if ( defined( 'WP_REDIS_CLUSTER' ) ) { $replica->executeRaw( array( 'READONLY' ) ); } ++$this->ch_routes['replica']; return $replica->$method( ...$args ); }
			catch ( Throwable $error ) { $last = $error; }
		}
		$this->ch_slots = null;
		if ( 'distribute_replicas' === $strategy ) { throw $last ?: new RuntimeException( 'No read replica is available.' ); }
		++$this->ch_routes['primary']; return $primary();
	}

	private function topology_client( array $node ) {
		$id = $node[0] . ':' . $node[1];
		if ( ! isset( $this->ch_replica_clients[ $id ] ) ) {
			$config = $this->ch_config;
			$params = array( 'host' => $node[0], 'port' => $node[1], 'scheme' => $config['scheme'], 'timeout' => $config['timeout'], 'read_write_timeout' => $config['read_timeout'], 'ssl' => $config['tls_options'] );
			if ( '' !== $config['username'] ) { $params['username'] = $config['username']; }
			if ( '' !== $config['password'] ) { $params['password'] = $config['password']; }
			$this->ch_replica_clients[ $id ] = new \Predis\Client( $params );
		}
		return $this->ch_replica_clients[ $id ];
	}
}
