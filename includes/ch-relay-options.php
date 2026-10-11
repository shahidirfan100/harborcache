<?php
/** Separate option strings let Relay cache option values independently. */
defined( 'ABSPATH' ) || exit;
trait HarborCache_Relay_Options {
	private function relay_options_write( $operation, array $value, $group, $expire ) {
		try {
			$key = $this->build_key( 'alloptions', $group ); $encoded = array();
			foreach ( $value as $name => $data ) { $encoded[ (string) $name ] = $this->ch_codec->encode( $data ); }
			$baseline = $this->ch_option_snapshots[ $key ] ?? null;
			$deleted = is_array( $baseline ) ? array_diff_key( $baseline, $encoded ) : array();
			$keys = array( $key ); $changes = array();
			foreach ( $deleted as $name => $unused ) { $keys[] = $key . ':o:' . hash( 'sha256', (string) $name ); $changes[] = array( (string) $name, '', 'delete' ); }
			foreach ( $encoded as $name => $data ) { $keys[] = $key . ':o:' . hash( 'sha256', (string) $name ); $changes[] = array( (string) $name, $data, ! is_array( $baseline ) || ! isset( $baseline[ $name ] ) || $baseline[ $name ] !== $data ? 'write' : 'keep' ); }
			$script = <<<'LUA'
local old=redis.call('GET',KEYS[1]);
if (ARGV[1]=='add' and old) or (ARGV[1]=='replace' and not old) then return 0 end
local patch=ARGV[3]=='1' and old; local names={};
if patch then names=cjson.decode(old) end
for i=2,#KEYS do
 local p=4+(i-2)*3; local name=ARGV[p]; local value=ARGV[p+1]; local action=ARGV[p+2];
 if action=='delete' then if patch then names[name]=nil; redis.call('DEL',KEYS[i]) end
 else
  if not patch or action=='write' then redis.call('SET',KEYS[i],value,'EX',ARGV[2]); names[name]=true
  elseif names[name] and redis.call('EXPIRE',KEYS[i],ARGV[2])==0 then
   redis.call('DEL',KEYS[1]); return 0
  end
 end
end
redis.call('SET',KEYS[1],cjson.encode(names),'EX',ARGV[2]); return 1
LUA;
			$args = array_merge( array( 'EVAL', $script, count( $keys ) ), $keys, array( $operation, $expire > 0 ? min( $expire, $this->ch_config['maxttl'] ) : $this->ch_config['maxttl'], is_array( $baseline ) ? '1' : '0' ) );
			foreach ( $changes as $change ) { foreach ( $change as $argument ) { $args[] = $argument; } }
			$result = (bool) $this->ch_command( $args );
			if ( $result ) { $this->ch_option_snapshots[ $key ] = $encoded; $this->add_to_internal_cache( $key, $value ); }
			else { unset( $this->ch_option_snapshots[ $key ], $this->cache[ $key ] ); }
			return $result;
		} catch ( Throwable $error ) { $this->handle_exception( $error ); if ( 'add' === $operation ) { return parent::add( 'alloptions', $value, $group, $expire ); } if ( 'replace' === $operation ) { return parent::replace( 'alloptions', $value, $group, $expire ); } return parent::set( 'alloptions', $value, $group, $expire ); }
	}

	private function relay_options_read( $force, &$found ) {
		$key = $this->build_key( 'alloptions', 'options' );
		if ( ! $force && array_key_exists( $key, $this->cache ) ) { $found = true; ++$this->runtime_hits; ++$this->cache_hits; return $this->get_from_internal_cache( $key ); }
		try {
			$manifest = $this->ch_command( array( 'GET', $key ) );
			if ( ! is_string( $manifest ) ) { $found = false; ++$this->store_misses; ++$this->cache_misses; return false; }
			$names = json_decode( $manifest, true );
			if ( ! is_array( $names ) ) { throw new RuntimeException( 'Invalid option manifest.' ); }
			$fields = array_keys( $names ); $keys = array();
			foreach ( $fields as $name ) { $keys[] = $key . ':o:' . hash( 'sha256', (string) $name ); }
			$raw = $keys ? $this->redis_call( 'mget', $keys ) : array(); $value = array(); $baseline = array();
			foreach ( $fields as $i => $name ) {
				if ( ! isset( $raw[ $i ] ) || false === $raw[ $i ] ) { $found = false; ++$this->store_misses; ++$this->cache_misses; return false; }
				$value[ $name ] = $this->ch_codec->decode( $raw[ $i ] ); $baseline[ $name ] = $raw[ $i ];
			}
			$this->ch_option_snapshots[ $key ] = $baseline; $this->add_to_internal_cache( $key, $value ); $found = true; ++$this->store_hits; ++$this->cache_hits; return $value;
		} catch ( Throwable $error ) { $this->handle_exception( $error ); $found = false; ++$this->cache_misses; return false; }
	}
}
