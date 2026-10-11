<?php
/** Bounded reclamation of generations made unreachable by namespace rotation. */
defined( 'ABSPATH' ) || exit;

trait HarborCache_Cleanup {
	private function ch_reclaim( $pattern, $guard_key, $old_value, $strategy = 'incremental' ) {
		$job = array( 'pattern' => $pattern, 'guard_key' => $guard_key, 'old_value' => $old_value, 'cursors' => array(), 'pending' => array(), 'done' => array(), 'created' => time() );
		try {
			if ( in_array( $strategy, array( 'scan', 'keys' ), true ) ) { $job = $this->cleanup_job( $job, $strategy ); }
			if ( $job ) { $this->ch_command( array( 'LPUSH', $this->ch_base . ':cleanup', json_encode( $job ) ) ); $this->ch_command( array( 'LTRIM', $this->ch_base . ':cleanup', 0, 999 ) ); }
			if ( $job && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( 'harborcache_cleanup' ) ) { wp_schedule_event( time() + 60, 'harborcache_minute', 'harborcache_cleanup' ); }
		} catch ( Throwable $error ) { \HarborCache\Observer::log( $this->ch_config, 'warning', 'Cache invalidated; background reclamation will rely on TTL expiry.' ); }
	}

	private function cleanup_nodes() {
		$client = $this->redis_instance();
		if ( defined( 'WP_REDIS_CLUSTER' ) && $client instanceof \Predis\Client ) {
			$nodes = array(); $slots = null; $last = null;
			foreach ( $client->getConnection()->getIterator() as $connection ) {
				try { $slots = $client->getClientBy( 'id', (string) $connection )->executeRaw( array( 'CLUSTER', 'SLOTS' ) ); if ( is_array( $slots ) && $slots ) { break; } }
				catch ( Throwable $error ) { $last = $error; }
			}
			if ( ! is_array( $slots ) || ! $slots ) { throw $last ?: new RuntimeException( 'Cluster primaries are unavailable.' ); }
			foreach ( $slots as $range ) { $node = $range[2]; $nodes[ $node[0] . ':' . $node[1] ] = $this->topology_client( $node ); }
			return $nodes;
		}
		if ( $client instanceof \RedisCluster || ( class_exists( '\Relay\Cluster' ) && $client instanceof \Relay\Cluster ) ) { $nodes = array(); foreach ( $client->_masters() as $node ) { $nodes[ implode( ':', $node ) ] = $node; } return $nodes; }
		if ( $client instanceof \Predis\Client && ( $this->ch_config['servers'] || $this->ch_config['sentinels'] ) ) { return array( 'primary' => $client->getClientBy( 'role', 'master' ) ); }
		return array( 'single' => $client );
	}

	private function cleanup_node_command( $node, array $args ) {
		return $this->observe_command( $args[0], function () use ( $node, $args ) {
			if ( $node instanceof \Predis\Client ) { return $node->executeRaw( $args ); }
			if ( is_array( $node ) ) { return $this->redis_instance()->rawCommand( $node, ...$args ); }
			if ( $node instanceof \Credis_Client ) {
				if ( 'SCAN' === $args[0] ) { $cursor = '0' === (string) $args[1] ? null : (int) $args[1]; $keys = $node->scan( $cursor, $args[3], $args[5] ); return array( (string) $cursor, $keys ?: array() ); }
				$command = array_shift( $args ); return $node->rawCommand( $command, $args );
			}
			return $node->rawCommand( ...$args );
		} );
	}

	private function cleanup_job( array $job, $strategy = 'scan', $deadline = null ) {
		$deadline = null === $deadline ? microtime( true ) + $this->ch_config['gc_budget_ms'] / 1000 : $deadline;
		if ( ! isset( $job['pattern'], $job['guard_key'], $job['old_value'], $job['created'] ) || ! is_string( $job['pattern'] ) || ! is_string( $job['guard_key'] ) || ! is_string( $job['old_value'] ) ) { return null; }
		if ( ! preg_match( '/^' . preg_quote( $this->ch_base, '/' ) . ':[a-f0-9]{24}:(?:[a-zA-Z0-9:%_.~{}-]+:)?\*$/D', $job['pattern'] ) || 0 !== strpos( $job['guard_key'], $this->ch_base . ':' ) || ! preg_match( '/^[a-f0-9]{24}$/D', $job['old_value'] ) ) { return null; }
		$guard = $job['guard_key'];
		if ( $guard === $this->ch_base . ':root' ) { $expected = $this->ch_base . ':' . $job['old_value'] . ':*'; }
		elseif ( preg_match( '/^' . preg_quote( $this->ch_base, '/' ) . ':[a-f0-9]{24}:(?:global|site-[0-9]+)(?::[a-f0-9]{24}:g:[a-zA-Z0-9%_.~-]+)?:epoch$/D', $guard ) ) { $expected = substr( $guard, 0, -6 ) . ':' . $job['old_value'] . ':*'; }
		else { return null; }
		if ( $job['pattern'] !== $expected || ! is_int( $job['created'] ) || $job['created'] > time() ) { return null; }
		foreach ( array( 'pending', 'cursors', 'done' ) as $field ) { if ( ! isset( $job[ $field ] ) || ! is_array( $job[ $field ] ) ) { return null; } }
		foreach ( $job['pending'] as $keys ) { if ( ! is_array( $keys ) ) { return null; } }
		foreach ( $job['cursors'] as $cursor ) { if ( ! is_string( $cursor ) || ! ctype_digit( $cursor ) ) { return null; } }
		// A cleanup job may delete only a generation whose token has already changed.
		$current = $this->ch_command( array( 'GET', $guard ) );
		if ( ! is_string( $current ) || ! preg_match( '/^[a-f0-9]{24}$/D', $current ) || $current === $job['old_value'] || time() - $job['created'] > $this->ch_config['maxttl'] * 2 ) { return null; }
		$budget = $this->ch_config['gc_batch_size'];
		$nodes = $this->cleanup_nodes();
		foreach ( $nodes as $id => $node ) {
			if ( microtime( true ) >= $deadline ) { return $job; }
			if ( ! empty( $job['done'][ $id ] ) ) { continue; }
			$pending = $job['pending'][ $id ] ?? array();
			if ( ! $pending ) {
				if ( 'keys' === $strategy && ! isset( $job['cursors'][ $id ] ) ) { $pending = (array) $this->cleanup_node_command( $node, array( 'KEYS', $job['pattern'] ) ); $job['cursors'][ $id ] = '0'; }
				else { $page = $this->cleanup_node_command( $node, array( 'SCAN', $job['cursors'][ $id ] ?? '0', 'MATCH', $job['pattern'], 'COUNT', max( 100, $budget ) ) ); $job['cursors'][ $id ] = (string) $page[0]; $pending = (array) $page[1]; }
			}
			$delete = array(); $offset = 0;
			foreach ( $pending as $key ) {
				if ( $budget <= 0 || microtime( true ) >= $deadline ) { break; }
				++$offset;
				if ( is_string( $key ) && 0 === strpos( $key, substr( $job['pattern'], 0, -1 ) ) ) { $delete[] = $key; --$budget; }
				if ( count( $delete ) >= ( is_array( $node ) ? 1 : 100 ) ) { break; }
			}
			if ( $delete ) { $this->cleanup_delete_batch( $node, $delete ); }
			$pending = array_slice( $pending, $offset );
			$job['pending'][ $id ] = $pending;
			if ( ! $pending && '0' === $job['cursors'][ $id ] ) { $job['done'][ $id ] = true; }
			if ( $budget <= 0 ) { break; }
		}
		foreach ( $nodes as $id => $unused ) { if ( empty( $job['done'][ $id ] ) ) { return $job; } }
		return null;
	}

	private function cleanup_delete_batch( $node, array $keys ) {
		$command = $this->ch_config['async_flush'] && version_compare( $this->redis_version(), '4.0', '>=' ) ? 'UNLINK' : 'DEL';
		if ( $node instanceof \Predis\Client ) {
			$this->observe_command( 'PIPELINE', static function () use ( $node, $keys, $command ) {
				$pipeline = $node->pipeline();
				foreach ( $keys as $key ) { $pipeline->executeCommand( new \Predis\Command\RawCommand( $command, array( $key ) ) ); }
				return $pipeline->execute();
			} );
		} elseif ( ! is_array( $node ) ) {
			// A single connected node can delete a bounded batch without cross-slot routing.
			$this->cleanup_node_command( $node, array_merge( array( $command ), $keys ) );
		} else { foreach ( $keys as $key ) { $this->cleanup_node_command( $node, array( $command, $key ) ); } }
	}

	private function cleanup_inflight_key() {
		$queue = $this->ch_base . ':cleanup';
		return preg_match( '/\{[^{}]+\}/', $queue ) ? $queue . ':inflight' : '{' . $queue . '}:inflight';
	}

	public function cleanup_status() {
		return array( 'pending' => (int) $this->ch_command( array( 'LLEN', $this->ch_base . ':cleanup' ) ), 'inflight' => (int) $this->ch_command( array( 'ZCARD', $this->cleanup_inflight_key() ) ) );
	}

	public function cleanup( $jobs = 1 ) {
		if ( ! $this->redis_status() ) { return 0; }
		$count = 0;
		$deadline = microtime( true ) + $this->ch_config['gc_budget_ms'] / 1000;
		for ( $i = 0; $i < max( 1, min( 10, (int) $jobs ) ); ++$i ) {
			if ( microtime( true ) >= $deadline ) { break; }
			$queue = $this->ch_base . ':cleanup'; $inflight = $this->cleanup_inflight_key();
			$lease = max( 60, (int) ceil( $this->ch_config['gc_budget_ms'] / 1000 + $this->ch_config['read_timeout'] + 30 ) );
			$claim = <<<'LUA'
local expired=redis.call('ZRANGEBYSCORE',KEYS[2],'-inf',ARGV[1],'LIMIT',0,10)
for _,entry in ipairs(expired) do
 local ok,data=pcall(cjson.decode,entry)
 if redis.call('ZREM',KEYS[2],entry)==1 and ok and type(data)=='table' and type(data.job)=='string' then redis.call('LPUSH',KEYS[1],data.job) end
end
local job=redis.call('RPOP',KEYS[1]); if not job then return false end
local entry=cjson.encode({id=ARGV[3],job=job}); redis.call('ZADD',KEYS[2],ARGV[2],entry); return entry
LUA;
			$entry = $this->ch_command( array( 'EVAL', $claim, 2, $queue, $inflight, time(), time() + $lease, bin2hex( random_bytes( 12 ) ) ) );
			if ( ! is_string( $entry ) ) { break; }
			$envelope = json_decode( $entry, true ); $raw = $envelope['job'] ?? '';
			$job = json_decode( $raw, true );
			$failed = false;
			try { $remaining = is_array( $job ) ? $this->cleanup_job( $job, 'scan', $deadline ) : null; ++$count; }
			catch ( Throwable $error ) { $remaining = $job; $failed = true; }
			$ack = "if redis.call('ZREM',KEYS[2],ARGV[1])==1 and ARGV[2]~='' then redis.call('LPUSH',KEYS[1],ARGV[2]) end; return 1";
			$this->ch_command( array( 'EVAL', $ack, 2, $queue, $inflight, $entry, $remaining ? json_encode( $remaining ) : '' ) );
			if ( $failed ) { break; }
		}
		return $count;
	}
}
