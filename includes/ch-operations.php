<?php
/** Bounded maintenance and diagnostics, run only from explicit admin/CLI jobs. */
defined( 'ABSPATH' ) || exit;

trait HarborCache_Operations {
	public function group_diagnostics( $scope, $group, $memory = false ) {
		if ( ! preg_match( '/^(global|site-[1-9][0-9]*)$/D', $scope ) || ! is_string( $group ) || strlen( $group ) > 200 ) { throw new InvalidArgumentException( 'Invalid cache group scope.' ); }
		$base = $this->ch_base . ':' . $this->ch_root . ':' . $scope;
		$base .= ':' . $this->ch_token( $base . ':epoch' ) . ':g:' . rawurlencode( $group );
		$base .= ':' . $this->ch_token( $base . ':epoch' );
		$result = array( 'scope' => $scope, 'group' => $group, 'keys' => 0, 'memory_bytes' => $memory ? 0 : null, 'complete' => true, 'memory_available' => true );
		$start = microtime( true ); $seen = array(); $pages = 0;
		foreach ( $this->cleanup_nodes() as $node ) {
			$cursor = '0';
			do {
				$page = $this->cleanup_node_command( $node, array( 'SCAN', $cursor, 'MATCH', $base . '*:v:*', 'COUNT', 100 ) );
				$cursor = (string) $page[0]; ++$pages;
				foreach ( (array) $page[1] as $key ) {
					if ( isset( $seen[ $key ] ) ) { continue; }
					if ( count( $seen ) >= 500 || microtime( true ) - $start > .2 ) { $result['complete'] = false; break 3; }
					$seen[ $key ] = true; ++$result['keys'];
					if ( $memory && $result['memory_available'] ) {
						try { $bytes = $this->cleanup_node_command( $node, array( 'MEMORY', 'USAGE', $key, 'SAMPLES', 5 ) ); $result['memory_bytes'] += (int) $bytes; }
						catch ( Throwable $error ) { $result['memory_available'] = false; $result['memory_bytes'] = null; }
					}
				}
				if ( $pages >= 20 || microtime( true ) - $start > .2 ) { $result['complete'] = false; break 2; }
			} while ( '0' !== $cursor );
		}
		$result['milliseconds'] = round( ( microtime( true ) - $start ) * 1000, 3 );
		return $result;
	}

	public function node_diagnostics() {
		$nodes = $this->cleanup_nodes(); $client = $this->redis_instance();
		if ( $this->ch_native_nodes ) { $nodes = $this->native_topology_nodes(); }
		if ( $client instanceof \Predis\Client && ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) ) {
			foreach ( $client->getConnection()->getIterator() as $connection ) { $nodes[ (string) $connection ] = $client->getClientBy( 'id', (string) $connection ); }
		}
		if ( defined( 'WP_REDIS_CLUSTER' ) ) {
			$first = reset( $nodes );
			$slots = $this->cleanup_node_command( $first, array( 'CLUSTER', 'SLOTS' ) );
			foreach ( (array) $slots as $range ) { foreach ( array_slice( $range, 2 ) as $node ) { $nodes[ $node[0] . ':' . $node[1] ] = $this->topology_client( $node ); } }
		}
		$result = array(); $i = 0;
		foreach ( array_slice( $nodes, 0, 32, true ) as $node ) {
			$start = microtime( true );
			try { $this->cleanup_node_command( $node, array( 'PING' ) ); $result[] = array( 'node' => ++$i, 'connected' => true, 'milliseconds' => round( ( microtime( true ) - $start ) * 1000, 3 ) ); }
			catch ( Throwable $error ) { $result[] = array( 'node' => ++$i, 'connected' => false, 'error' => 'Node did not respond.' ); }
		}
		return $result;
	}

	public function relay_diagnostics( $key = '', $group = 'default' ) {
		$client = $this->redis_instance();
		if ( 'relay' !== $this->ch_config['client'] || ! isset( $client->adaptiveCache ) ) { return array( 'available' => false ); }
		$adaptive = $client->adaptiveCache;
		$result = array( 'available' => true, 'status' => $adaptive->status, 'width' => $adaptive->width, 'depth' => $adaptive->depth, 'load_factor' => $adaptive->loadFactor() );
		if ( '' !== $key ) { $result['key_stats'] = $adaptive->stats( $this->build_key( $key, $group ) ); }
		return $result;
	}

	public function operational_health() {
		$result = array( 'connected' => $this->redis_status(), 'failure_mode' => $this->ch_config['failure_mode'] );
		if ( ! $result['connected'] ) { return $result; }
		try { $result['cleanup'] = $this->cleanup_status(); } catch ( Throwable $error ) { $result['cleanup_available'] = false; }
		foreach ( array( 'memory', 'stats' ) as $section ) {
			try {
				$raw = $this->ch_command( array( 'INFO', $section ) );
				foreach ( array( 'maxmemory', 'maxmemory_policy', 'used_memory', 'evicted_keys', 'rejected_connections' ) as $name ) {
					if ( is_array( $raw ) && isset( $raw[ $name ] ) ) { $result[ $name ] = $raw[ $name ]; }
					elseif ( is_string( $raw ) && preg_match( '/^' . $name . ':([^\r\n]+)/m', $raw, $match ) ) { $result[ $name ] = $match[1]; }
				}
			} catch ( Throwable $error ) { $result[ $section . '_available' ] = false; }
		}
		if ( $this->ch_config['shared'] ) { unset( $result['used_memory'], $result['maxmemory'] ); }
		return $result;
	}

	/** Recycle query groups gradually; this deliberately expires valid query entries too. */
	public function maintain_queries() {
		if ( ! $this->redis_status() || ! $this->ch_config['query_maintenance'] ) { return 0; }
		$key = $this->ch_base . ':' . $this->ch_root . ':query-maintenance';
		$state = json_decode( (string) $this->ch_command( array( 'GET', $key ) ), true );
		$state = is_array( $state ) ? $state : array( 'cursor' => '0', 'pending' => array() );
		if ( ! isset( $state['cursor'], $state['pending'] ) || ! is_array( $state['pending'] ) || ! ctype_digit( (string) $state['cursor'] ) ) { $state = array( 'cursor' => '0', 'pending' => array() ); }
		if ( ! $state['pending'] ) { $page = $this->ch_command( array( 'SSCAN', $this->ch_base . ':' . $this->ch_root . ':groups', $state['cursor'], 'COUNT', 10 ) ); $state['cursor'] = (string) $page[0]; $state['pending'] = (array) $page[1]; }
		$count = 0; $start = microtime( true );
		for ( $i = 0; $i < 10 && $state['pending']; ++$i ) {
			$row = array_shift( $state['pending'] ); $parts = explode( '|', $row, 2 );
			if ( 2 !== count( $parts ) || ! preg_match( '/^(global|site-[1-9][0-9]*)$/D', $parts[0] ) || '-queries' !== substr( rawurldecode( $parts[1] ), -8 ) ) { continue; }
			$base = $this->ch_base . ':' . $this->ch_root . ':' . $parts[0]; $base .= ':' . $this->ch_token( $base . ':epoch' ) . ':g:' . $parts[1];
			$old = $this->ch_token( $base . ':epoch' ); $this->ch_rotate( $base . ':epoch' );
			$this->ch_reclaim( $base . ':' . $old . ':*', $base . ':epoch', $old ); ++$count;
			if ( ( microtime( true ) - $start ) * 1000 > $this->ch_config['gc_budget_ms'] ) { break; }
		}
		$this->flush_runtime();
		$this->ch_command( array( 'SET', $key, json_encode( $state ), 'EX', $this->ch_config['maxttl'] * 2 ) );
		return $count;
	}
}
