<?php
/** Ownership checks shared by the installed cache and drop-in lifecycle probe. */
namespace HarborCache;
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/ch-paths.php';

final class Namespace_Guard {
    /**
     * Stable installation identity.
     *
     * AUTH_KEY is deliberately excluded: rotating security salts must not
     * invalidate a healthy cache namespace. The content path plus database
     * identity still separates clones and shared-Redis installations.
     */
    public static function identity() {
        global $table_prefix, $wpdb;
        $prefix = function_exists( 'is_multisite' ) && is_multisite() && isset( $wpdb->base_prefix ) ? $wpdb->base_prefix : $table_prefix;
        return hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $prefix . ':' . Paths::content() );
    }

    /** The identity used before 1.3.0, kept only to migrate existing owners. */
    public static function legacy_identity() {
        global $table_prefix, $wpdb;
        $prefix = function_exists( 'is_multisite' ) && is_multisite() && isset( $wpdb->base_prefix ) ? $wpdb->base_prefix : $table_prefix;
        return hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . ':' . $prefix . ':' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : Paths::content() ) );
    }

    public static function is_ok( $reply ) {
        return true === $reply || 'OK' === $reply || ( $reply instanceof \Predis\Response\Status && 'OK' === (string) $reply );
    }

    public static function checked_reply( $client, $reply ) {
        if ( false !== $reply ) { return $reply; }
        if ( method_exists( $client, 'getLastError' ) ) {
            $error = $client->getLastError();
            if ( is_string( $error ) && '' !== $error ) {
                if ( method_exists( $client, 'clearLastError' ) ) { $client->clearLastError(); }
                throw new \RuntimeException( 'Redis rejected a cache command.' );
            }
        }
        if ( method_exists( $client, 'isConnected' ) && ! $client->isConnected() && ! ( $client instanceof \Relay\Relay || $client instanceof \Relay\Cluster ) ) {
            // Relay may serve a valid shared-cache miss without an attached socket; its errors are checked above.
            throw new \RuntimeException( 'Redis connection was lost.' );
        }
        return null;
    }

    public static function command( $client, array $args ) {
        if ( $client instanceof \Predis\Client ) {
            $error = false;
            $reply = $client->executeRaw( $args, $error );
            if ( $error ) { throw new \RuntimeException( 'Redis rejected a cache command.' ); }
        } elseif ( method_exists( $client, 'rawCommand' ) ) {
            $reply = $client->rawCommand( ...$args );
        } else {
            $method = strtolower( array_shift( $args ) );
            $reply = $client->$method( ...$args );
        }
        return self::checked_reply( $client, $reply );
    }

    /**
     * Read the owner without writing. Used by the connection check and dashboard.
     *
     * @return array{state:string,fingerprint:string}
     */
    public static function owner_state( callable $command, $base ) {
        try {
            $owner = $command( array( 'GET', $base . ':owner' ) );
        } catch ( \Throwable $error ) {
            return array( 'state' => 'unknown', 'fingerprint' => '' );
        }
        if ( null === $owner || false === $owner || '' === (string) $owner ) {
            return array( 'state' => 'unclaimed', 'fingerprint' => '' );
        }
        $owner = (string) $owner;
        $fingerprint = substr( $owner, 0, 8 );
        if ( hash_equals( self::identity(), $owner ) ) {
            return array( 'state' => 'owned', 'fingerprint' => $fingerprint );
        }
        if ( hash_equals( self::legacy_identity(), $owner ) ) {
            return array( 'state' => 'legacy', 'fingerprint' => $fingerprint );
        }
        return array( 'state' => 'conflict', 'fingerprint' => $fingerprint );
    }

    public static function claim( callable $command, $base, $owner = null ) {
        $identity = self::identity();
        if ( null === $owner ) { $owner = $command( array( 'GET', $base . ':owner' ) ); }
        if ( null === $owner || false === $owner ) {
            $reply = $command( array( 'SET', $base . ':owner', $identity, 'NX' ) );
            if ( self::is_ok( $reply ) ) { return; }
            if ( null !== $reply && false !== $reply ) { throw new \RuntimeException( 'Cache ownership initialization failed.' ); }
            $owner = $command( array( 'GET', $base . ':owner' ) );
        }
        if ( is_string( $owner ) && hash_equals( $identity, $owner ) ) { return; }
        // Upgrade an owner written by <= 1.2.x (AUTH_KEY based) without forcing a flush.
        if ( is_string( $owner ) && hash_equals( self::legacy_identity(), $owner ) ) {
            $reply = $command( array( 'SET', $base . ':owner', $identity ) );
            if ( ! self::is_ok( $reply ) ) { throw new \RuntimeException( 'Cache ownership migration failed.' ); }
            return;
        }
        throw new Prefix_Conflict( 'Cache prefix belongs to another installation. Configure a distinct prefix or restore this installation identity.' );
    }

    /** Explicit, confirmed takeover after the operator verified the old owner is gone. */
    public static function reclaim( callable $command, $base ) {
        $reply = $command( array( 'SET', $base . ':owner', self::identity() ) );
        if ( ! self::is_ok( $reply ) ) { throw new \RuntimeException( 'Cache namespace reclaim failed.' ); }
        return true;
    }
}

class Prefix_Conflict extends \RuntimeException {}
