<?php
/** Native client options, kept outside the portable value encoding layer. */
defined( 'ABSPATH' ) || exit;
trait HarborCache_Native {
	/** Forced reads must bypass Relay's process/shared cache as well as WordPress memory. */
	private function relay_uncached_read( callable $call ) {
		$client = $this->redis_instance(); $previous = null; $option = null;
		try {
			if ( $this->redis_status() && ( $client instanceof \Relay\Relay || $client instanceof \Relay\Cluster ) ) {
				// Connection-local options need an active socket even when Relay can serve shared-cache replies.
				if ( method_exists( $client, 'isConnected' ) && ! $client->isConnected() ) {
					$this->connect_using_relay( $this->build_parameters() );
					$client = $this->redis_instance();
				}
				$option = $this->native_constant( 'OPT_USE_CACHE' ); $previous = $client->getOption( $option );
				if ( false === $client->setOption( $option, false ) ) { throw new RuntimeException( 'Relay cannot bypass its local cache.' ); }
			}
		} catch ( Throwable $error ) { $option = null; $this->handle_exception( $error ); }
		$outer_bypass = $this->ch_relay_bypass;
		$this->ch_relay_bypass = true;
		try { return $call(); }
		finally {
			$this->ch_relay_bypass = $outer_bypass;
			$active = $this->redis_instance();
			if ( null !== $option && $this->redis_status() && ( ! method_exists( $active, 'isConnected' ) || $active->isConnected() ) ) {
				try { if ( false === $active->setOption( $option, $previous ) ) { throw new RuntimeException( 'Relay cannot restore its local cache mode.' ); } }
				catch ( Throwable $error ) { $this->handle_exception( $error ); }
			}
		}
	}

	protected function connect_using_phpredis( $parameters ) {
		if ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) { $this->connect_native_topology(); } else { parent::connect_using_phpredis( $parameters ); }
		$this->native_option( 'OPT_MAX_RETRIES', 0, false );
	}

	protected function connect_using_relay( $parameters ) {
		if ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) { $this->connect_native_topology(); }
		elseif ( defined( 'WP_REDIS_CLUSTER' ) ) {
			if ( ! class_exists( '\Relay\Cluster' ) ) { throw new RuntimeException( 'Relay Cluster requires a current Relay extension.' ); }
			$seeds = $this->build_cluster_connection_array();
			$context = 'tls' === $this->ch_config['scheme'] || preg_grep( '/^tls:\/\//i', $seeds ) ? array( 'stream' => $this->ch_config['tls_options'] ) : array();
			$this->redis = new \Relay\Cluster( null, $seeds, (float) $parameters['timeout'], (float) $parameters['read_timeout'], (bool) $parameters['persistent'], $this->build_credentials( $parameters ), $context );
			$this->diagnostics['client'] = 'Relay Cluster (' . phpversion( 'relay' ) . ')';
		} else { parent::connect_using_relay( $parameters ); }
		$this->native_option( 'OPT_MAX_RETRIES', 0, false );
		$options = $this->ch_config['relay'];
		$this->native_option( 'OPT_USE_CACHE', $this->ch_relay_bypass ? false : $options['cache'] );
		$this->native_option( 'OPT_CLIENT_INVALIDATIONS', $options['invalidations'] );
		$this->native_option( 'OPT_IGNORE_PATTERNS', $options['ignored'] );
		$this->native_option( 'OPT_ALLOW_PATTERNS', $options['allowed'] );
		if ( $options['adaptive'] ) {
			$a = $options['adaptive'];
			$this->native_option( 'OPT_ADAPTIVE_CACHE', array( 'enabled' => true, 'width' => $a['width'], 'depth' => $a['depth'], 'min_events' => $a['events'], 'min_ratio' => (float) $a['ratio'], 'formula' => $a['formula'] ) );
		}
		if ( $options['listeners'] ) {
			$this->redis->onFlushed( function () { $this->flush_runtime(); } );
			$this->redis->onInvalidated( function () { $this->flush_runtime(); } );
		}
		if ( null !== $this->ch_config['cluster_az'] ) { $this->native_option( 'OPT_AVAILABILITY_ZONE', $this->ch_config['cluster_az'] ); }
	}

	private function native_constant( $name ) {
		foreach ( array( get_class( $this->redis ), 'Relay\Relay' ) as $class ) { if ( defined( $class . '::' . $name ) ) { return constant( $class . '::' . $name ); } }
		throw new RuntimeException( 'The selected client version does not support the requested native option.' );
	}

	private function native_option( $name, $value, $required = true ) {
		try { $option = $this->native_constant( $name ); }
		catch ( Throwable $error ) { if ( $required ) { throw $error; } return; }
		if ( false === $this->redis->setOption( $option, $value ) && $required ) { throw new RuntimeException( 'The Redis client rejected a native option.' ); }
	}

	private function native_read( callable $call, $own_write ) {
		if ( ! defined( 'WP_REDIS_CLUSTER' ) ) { return $call(); }
		$failover = array( 'none' => 'FAILOVER_NONE', 'error' => 'FAILOVER_ERROR', 'distribute' => 'FAILOVER_DISTRIBUTE', 'distribute_replicas' => 'FAILOVER_DISTRIBUTE_SLAVES' );
		$mode = $own_write ? 'none' : $this->ch_config['cluster_failover'];
		$this->native_option( 'OPT_SLAVE_FAILOVER', $this->native_constant( $failover[ $mode ] ) );
		try {
			if ( 'relay' === $this->ch_config['client'] && ! $own_write ) {
				foreach ( array( 'cluster_distribution_strategy' => array( 'OPT_DISTRIBUTE', 'DISTRIBUTE_' ), 'cluster_failover_strategy' => array( 'OPT_FAILOVER', 'FAILOVER_' ) ) as $key => $map ) {
					if ( null !== $this->ch_config[ $key ] ) { $this->native_option( $map[0], $this->native_constant( $map[1] . strtoupper( $this->ch_config[ $key ] ) ) ); }
				}
			}
			return $call();
		} finally {
			// Metadata reads must never observe a replica's stale namespace token.
			$this->native_option( 'OPT_SLAVE_FAILOVER', $this->native_constant( 'FAILOVER_NONE' ) );
			if ( 'relay' === $this->ch_config['client'] ) {
				if ( null !== $this->ch_config['cluster_distribution_strategy'] ) { $this->native_option( 'OPT_DISTRIBUTE', $this->native_constant( 'DISTRIBUTE_NONE' ) ); }
				if ( null !== $this->ch_config['cluster_failover_strategy'] ) { $this->native_option( 'OPT_FAILOVER', $this->native_constant( 'FAILOVER_NONE' ) ); }
			}
		}
	}
}
