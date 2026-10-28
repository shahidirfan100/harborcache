<?php
/** Codec round-trip checks. Runs without WordPress or Redis. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'HARBORCACHE_PLUGIN_PATH', dirname( __DIR__ ) );
require_once dirname( __DIR__ ) . '/includes/ch-codec.php';

$codec   = new HarborCache\Codec( array( 'serializer' => 'php', 'compression' => 'none' ) );
$failed  = 0;
function hc_codec_gate( $name, $pass, $detail = '' ) {
	global $failed;
	if ( ! $pass ) { ++$failed; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}

$plain = $codec->encode( 'hello world' );
hc_codec_gate( 'A plain string uses the S marker', 0 === strpos( $plain, 'S' ) && 'hello world' === substr( $plain, 1 ), $plain );
hc_codec_gate( 'A plain string round-trips', 'hello world' === $codec->decode( $plain ) );

$numeric = $codec->encode( '12345' );
hc_codec_gate( 'A numeric string stays a string', 'S12345' === $numeric && '12345' === $codec->decode( $numeric ) );

hc_codec_gate( 'An empty string round-trips', 'S' === $codec->encode( '' ) && '' === $codec->decode( 'S' ) );

$binary = "a\0b\xffc";
hc_codec_gate( 'A binary string round-trips', $binary === $codec->decode( $codec->encode( $binary ) ) );

hc_codec_gate( 'Integers keep the N marker', 'N-42' === $codec->encode( -42 ) && -42 === $codec->decode( 'N-42' ) );

$array = array( 'typed' => false, 'nested' => array( 1, 'two', null ) );
hc_codec_gate( 'Arrays round-trip through V1', $array === $codec->decode( $codec->encode( $array ) ) );

hc_codec_gate( 'Booleans round-trip through V1', true === $codec->decode( $codec->encode( true ) ) && false === $codec->decode( $codec->encode( false ) ) );

$legacy = 'V1' . serialize( 'legacy string' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
hc_codec_gate( 'A 1.2.7 serialized string still decodes', 'legacy string' === $codec->decode( $legacy ) );

try {
	$codec->decode( 'V1not-a-serialized-value' );
	hc_codec_gate( 'Corrupt V1 data is rejected', false );
} catch ( RuntimeException $error ) {
	hc_codec_gate( 'Corrupt V1 data is rejected', true );
}

echo 'RESULT ' . ( $failed ? 'FAILED' : 'PASSED' ) . ' with ' . $failed . " failure(s)\n";
exit( $failed ? 1 : 0 );
