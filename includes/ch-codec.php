<?php
/** Versioned values; configuration changes use a different keyspace. */
namespace HarborCache;

defined( 'ABSPATH' ) || exit;

final class Codec {
	private $serializer;
	private $compression;
	private $decoder;
	private $decode_error;

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
		if ( 'none' !== $this->compression ) {
			$data = call_user_func( $this->compression . '_compress', $data );
		}
		if ( ! is_string( $data ) ) {
			throw new \RuntimeException( 'Value encoding failed.' );
		}
		return 'V1' . $data;
	}

	public function decode( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			throw new \RuntimeException( 'Invalid cache value.' );
		}
		if ( 'N' === $raw[0] && preg_match( '/^-?\d+$/D', substr( $raw, 1 ) ) ) {
			return (int) substr( $raw, 1 );
		}
		if ( 'S' === $raw[0] && is_numeric( substr( $raw, 1 ) ) ) { return substr( $raw, 1 ); }
		if ( 'V1' !== substr( $raw, 0, 2 ) ) {
			throw new \RuntimeException( 'Unknown cache value format.' );
		}
		$data = substr( $raw, 2 );
		if ( 'none' !== $this->compression ) {
			$data = call_user_func( $this->decoder, $data );
		}
		if ( ! is_string( $data ) ) {
			throw new \RuntimeException( 'Value decoding failed.' );
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Let callers handle malformed serialization as an exception.
		set_error_handler( $this->decode_error );
		try {
			return 'php' === $this->serializer ? unserialize( $data ) : igbinary_unserialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		} finally {
			restore_error_handler();
		}
	}
}
