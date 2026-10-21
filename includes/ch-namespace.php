<?php
/** Ownership checks shared by the installed cache and drop-in lifecycle probe. */
namespace HarborCache;
defined( 'ABSPATH' ) || exit;

final class Namespace_Guard {
    public static function identity() {
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

    public static function claim( callable $command, $base ) {
        $identity = self::identity();
        $owner = $command( array( 'GET', $base . ':owner' ) );
        if ( null === $owner || false === $owner ) {
            $reply = $command( array( 'SET', $base . ':owner', $identity, 'NX' ) );
            if ( self::is_ok( $reply ) ) { return; }
            if ( null !== $reply && false !== $reply ) { throw new \RuntimeException( 'Cache ownership initialization failed.' ); }
            $owner = $command( array( 'GET', $base . ':owner' ) );
        }
        if ( ! is_string( $owner ) || ! hash_equals( $identity, $owner ) ) {
            throw new Prefix_Conflict( 'Cache prefix belongs to another installation. Configure a distinct prefix or restore this installation identity.' );
        }
    }
}

class Prefix_Conflict extends \RuntimeException {}
