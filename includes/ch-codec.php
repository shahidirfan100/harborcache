<?php
/** Versioned values; configuration changes use a different keyspace. */
namespace HarborCache;

defined( 'ABSPATH' ) || exit;

final class Codec {
	private $serializer;
	private $compression;
	private $decoder;
	private $decode_error;
	private $verified = array();

	public function __construct( array $config ) {
		$this->serializer = $config['serializer'];
		$this->decode_error = static function () { throw new \RuntimeException( 'Corrupt serialized cache value.' ); };
		$this->compression = $config['compression'];
		if ( ! in_array( $this->serializer, array( 'php', 'igbinary' ), true ) || ( 'igbinary' === $this->serializer && ! function_exists( 'igbinary_serialize' ) ) ) {
			throw new \RuntimeException( 'Configured serializer is unavailable.' );
		}
		if ( ! in_array( $this->compression, array( 'none', 'lzf', 'lz4', 'zstd' ), true ) ) {
			throw new \RuntimeException( 'Unknown compression algorithm.' );
		}
		$this->decoder = 'lzf' === $this->compression ? 'lzf_decompress' : $this->compression . '_uncompress';
		if ( 'none' !== $this->compression && ( ! function_exists( $this->compression . '_compress' ) || ! function_exists( $this->decoder ) ) ) {
			throw new \RuntimeException( 'Configured compression requires its PHP codec extension.' );
		}
	}

	public function encode( $value ) {
		if ( is_int( $value ) ) {
			return 'N' . (int) $value;
		}
		if ( is_string( $value ) && is_numeric( $value ) ) { return 'S' . $value; }
		$data = 'php' === $this->serializer ? serialize( $value ) : igbinary_serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		if ( ! is_string( $data ) ) {
			throw new \RuntimeException( 'Value encoding failed.' );
		}
		// Only object-bearing payloads can instantiate a class during unserialize.
		// igbinary has no text token to inspect, so every igbinary payload is signed.
		$signed = 'php' !== $this->serializer || (bool) preg_match( '/(?:^|[;{}])(?:O|C|E):[0-9]+:/', $data );
		if ( 'none' !== $this->compression ) {
			$data = call_user_func( $this->compression . '_compress', $data );
		}
		if ( ! is_string( $data ) ) {
			throw new \RuntimeException( 'Value encoding failed.' );
		}
		return ( $signed ? 'V2' . hash_hmac( 'sha256', $data, self::secret() ) : 'V1' ) . $data;
	}

	public function decode( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			throw new \RuntimeException( 'Invalid cache value.' );
		}
		if ( 'N' === $raw[0] && preg_match( '/^-?\d+$/D', substr( $raw, 1 ) ) ) {
			return (int) substr( $raw, 1 );
		}
		if ( 'S' === $raw[0] && is_numeric( substr( $raw, 1 ) ) ) { return substr( $raw, 1 ); }
		$version = substr( $raw, 0, 2 );
		if ( 'V2' === $version ) {
			if ( strlen( $raw ) < 66 ) { throw new \RuntimeException( 'Truncated signed cache value.' ); }
			$mac  = substr( $raw, 2, 64 );
			$data = substr( $raw, 66 );
			if ( ! isset( $this->verified[ $raw ] ) ) {
				if ( ! hash_equals( hash_hmac( 'sha256', $data, self::secret() ), $mac ) ) {
					throw new \RuntimeException( 'Cache value signature mismatch.' );
				}
				if ( count( $this->verified ) >= 256 ) { $this->verified = array(); }
				$this->verified[ $raw ] = true;
			}
		} elseif ( 'V1' === $version ) {
			if ( 'php' !== $this->serializer ) { throw new \RuntimeException( 'Unsigned igbinary cache value.' ); }
			$data = substr( $raw, 2 );
			if ( preg_match( '/(?:^|[;{}])(?:O|C|E):[0-9]+:/', $data ) ) {
				throw new \RuntimeException( 'Unsigned cache value contains an object.' );
			}
		} else {
			throw new \RuntimeException( 'Unknown cache value format.' );
		}
		if ( 'none' !== $this->compression ) {
			$data = call_user_func( $this->decoder, $data );
		}
		if ( ! is_string( $data ) ) {
			throw new \RuntimeException( 'Value decoding failed.' );
		}
		// An old V1 payload may only be deserialized when it is object-free.
		if ( 'V1' === $version && ( preg_match( '/(?:^|[;{}])(?:O|C|E):[0-9]+:/', $data ) ) ) {
			throw new \RuntimeException( 'Unsigned cache value contains an object.' );
		}
		if ( 'php' === $this->serializer ) {
			// Suppression avoids installing and restoring an error handler for
			// every value. A false result is only valid for serialized false.
			$value = @unserialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $value && 'b:0;' !== $data ) {
				throw new \RuntimeException( 'Corrupt serialized cache value.' );
			}
			return $value;
		}
		// igbinary uses a binary envelope where false is a valid value; keep the
		// warning handler so corrupt payloads still fail as exceptions.
		set_error_handler( $this->decode_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		try {
			return igbinary_unserialize( $data );
		} finally {
			restore_error_handler();
		}
	}

	/** A per-installation integrity key; salt rotation intentionally cools the cache. */
	private static function secret() {
		static $secret = null;
		if ( null !== $secret ) { return $secret; }
		if ( defined( 'AUTH_KEY' ) && is_string( AUTH_KEY ) && '' !== AUTH_KEY ) { return $secret = AUTH_KEY; }
		if ( function_exists( 'wp_salt' ) ) {
			$salt = wp_salt( 'auth' );
			if ( is_string( $salt ) && '' !== $salt ) { return $secret = $salt; }
		}
		return $secret = hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) );
	}
}
