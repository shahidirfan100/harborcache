<?php
/** Disposable Linux CI only: real ACLs, ownership, stale read nodes and native reconnects. */
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$mode = $argv[1] ?? 'namespace';
$client = getenv( 'HC_SAFETY_CLIENT' ) ?: 'phpredis';
$config = array( 'client' => $client, 'prefix' => 'hc-safety-' . $mode . '-' . $client, 'analytics' => array( 'enabled' => false ), 'split_alloptions' => false );
$control = new Redis();
$control->connect( '127.0.0.1', 6379 );
$user = null;
if ( 'restricted' === $mode ) {
    $user = 'hc-restricted-' . $client;
    $control->rawCommand( 'ACL', 'SETUSER', $user, 'reset', 'on', '>disposable-safety-only', '~*', '+@all', '-info', '-select' );
    $config['username'] = $user;
    $config['password'] = 'disposable-safety-only';
}
if ( 'routing' === $mode ) {
    $config['servers'] = array( 'tcp://127.0.0.1:6379?role=master', 'tcp://127.0.0.1:6381?role=slave' );
    $config['replication_strategy'] = 'distribute_replicas';
}
if ( 'reconnect' === $mode ) { $config['database'] = 15; $config['retries'] = 1; }
define( 'HARBORCACHE_CONFIG', $config );
require rtrim( getenv( 'WP_ROOT' ), '/' ) . '/wp-load.php';
$cache = $GLOBALS['wp_object_cache'];
$checks = 0;
function safety_gate( $name, $pass ) {
    global $checks;
    if ( ! $pass ) { throw new RuntimeException( $name ); }
    ++$checks;
    echo "PASS $name\n";
}
try {
    safety_gate( 'Selected real client connects', $cache instanceof HarborCache_Advanced_Cache && $cache->redis_status() );
    if ( 'restricted' === $mode ) {
        $probe = new \HarborCache\Core\Predis();
        $probe->connect();
        $probe->invalidate_namespace();
        $cache = new HarborCache_Advanced_Cache();
        safety_gate( 'ACL probe and fresh cache work without INFO or SELECT', $cache->redis_status() );
        safety_gate( 'Optional denied server version stays unavailable', null === $cache->redis_version() && null === $cache->redis_version() );
        safety_gate( 'Restricted ACL permits normal typed storage', $cache->set( 'acl', array( 'verified' => true ), 'safety', 60 ) );
        $fresh = new HarborCache_Advanced_Cache();
        safety_gate( 'Restricted ACL persists across cache instances', array( 'verified' => true ) === $fresh->get( 'acl', 'safety', true ) );
    } elseif ( 'namespace' === $mode ) {
        $owner_key = WP_REDIS_PREFIX . 'meta:owner';
        $root_key = WP_REDIS_PREFIX . 'meta:root';
        $owner = $control->get( $owner_key );
        $root = $control->get( $root_key );
        try {
            $control->set( $owner_key, str_repeat( 'f', 64 ) );
            $other = new HarborCache_Advanced_Cache();
            safety_gate( 'Collision uses request-memory fallback', ! $other->redis_status() && $other->set( 'fallback', 42, 'safety' ) && 42 === $other->get( 'fallback', 'safety' ) );
            safety_gate( 'Collision preserves foreign owner and root', str_repeat( 'f', 64 ) === $control->get( $owner_key ) && $root === $control->get( $root_key ) );
            $probe = new \HarborCache\Core\Predis();
            $probe->connect();
            $rejected = false;
            try { $probe->invalidate_namespace(); } catch ( \HarborCache\Prefix_Conflict $error ) { $rejected = true; }
            safety_gate( 'Lifecycle probe cannot take ownership', $rejected && $root === $control->get( $root_key ) );
            $control->set( $owner_key, $owner );
            $control->set( $root_key, 'corrupt' );
            $broken = new HarborCache_Advanced_Cache();
            safety_gate( 'Corrupt live metadata is preserved and rejected', ! $broken->redis_status() && 'corrupt' === $control->get( $root_key ) );
        } finally {
            $control->set( $owner_key, $owner );
            $control->set( $root_key, $root );
        }
        safety_gate( 'Restored valid metadata reconnects', ( new HarborCache_Advanced_Cache() )->redis_status() );
    } elseif ( 'routing' === $mode ) {
        $read_node = new Redis();
        $read_node->connect( '127.0.0.1', 6381 );
        // Freeze the disposable read node to create deterministic stale replies.
        $read_node->rawCommand( 'REPLICAOF', 'NO', 'ONE' );
        $codec = new \HarborCache\Codec( \HarborCache\Config::load() );
        $groups = array( 'options', 'site-options', 'safety-ordinary' );
        try {
            foreach ( $groups as $group ) {
                foreach ( array( 'alloptions', 'notoptions' ) as $key ) {
                    safety_gate( 'Seed primary ' . $group . '/' . $key, $cache->set( $key, array( 'source' => 'primary' ), $group, 60 ) );
                    $read_node->setex( $cache->build_key( $key, $group ), 60, $codec->encode( array( 'source' => 'stale-read-node' ) ) );
                }
            }
            $fresh = new HarborCache_Advanced_Cache();
            foreach ( array( 'options', 'site-options' ) as $group ) {
                safety_gate( 'Critical single read uses primary ' . $group, array( 'source' => 'primary' ) === $fresh->get( 'notoptions', $group, true ) );
                $bulk = $fresh->get_multiple( array( 'alloptions', 'notoptions' ), $group, true );
                safety_gate( 'Critical bulk reads use primary ' . $group, array( 'alloptions' => array( 'source' => 'primary' ), 'notoptions' => array( 'source' => 'primary' ) ) === $bulk );
            }
            safety_gate( 'Critical routing never chose a replica', 0 === $fresh->ch_routes['replica'] );
            safety_gate( 'Ordinary reads retain replica-only behavior', array( 'source' => 'stale-read-node' ) === $fresh->get( 'notoptions', 'safety-ordinary', true ) );
        } finally {
            $read_node->rawCommand( 'REPLICAOF', '127.0.0.1', 6379 );
            $read_node->close();
        }
    } elseif ( 'reconnect' === $mode ) {
        safety_gate( 'Seed nonzero database', $cache->set( 'reconnect', 'database-fifteen', 'safety', 60 ) );
        $key = $cache->build_key( 'reconnect', 'safety' );
        $control->select( 15 );
        $expected = $control->get( $key );
        $control->select( 0 );
        $control->setex( $key, 60, 'wrong-database' );
        $attempts = 0;
        try {
            $actual = $cache->observe_command( 'GET', static function () use ( $cache, $key, &$attempts ) {
                if ( 0 === $attempts++ ) { $cache->redis_instance()->close(); throw new RuntimeException( 'Injected read disconnect' ); }
                return $cache->redis_instance()->get( $key );
            }, true );
            safety_gate( 'Safe reconnect retains the nonzero database', 2 === $attempts && $expected === $actual );
            for ( $i = 0; $i < 20; ++$i ) {
                $cache->set( 'reply-' . $i, $i, 'safety', 60 );
                safety_gate( 'Replies remain aligned after reconnect ' . $i, $i === $cache->get( 'reply-' . $i, 'safety', true ) );
            }
        } finally { $control->del( $key ); }
    } else { throw new RuntimeException( 'Unknown safety mode' ); }
} finally {
    if ( null !== $user ) { $control->rawCommand( 'ACL', 'DELUSER', $user ); }
    $control->close();
}
echo "RESULT $checks live checks passed ($mode, $client)\n";
