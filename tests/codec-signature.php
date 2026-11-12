<?php
/**
 * Signed cache values: valid round-trip, tamper rejection and legacy rejection.
 *
 * php tests/codec-signature.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'AUTH_KEY', 'codec-signature-fixture-key' );
require_once dirname( __DIR__ ) . '/includes/ch-codec.php';

$failures = 0;
function hc_codec_gate( $name, $pass, $detail = '' ) {
	global $failures;
	if ( ! $pass ) { ++$failures; }
	echo ( $pass ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' (' . $detail . ')' : '' ) . "\n";
}
function hc_codec_throws( callable $callback ) {
	try { $callback(); } catch ( RuntimeException $error ) { return true; }
	return false;
}

$codec = new HarborCache\Codec( array( 'serializer' => 'php', 'compression' => 'none' ) );
$value = array( 'number' => 42, 'text' => 'hello', 'nested' => array( false, null, 1.5 ) );
hc_codec_gate( 'Signed values round-trip', $value === $codec->decode( $codec->encode( $value ) ) );
hc_codec_gate( 'Integers keep their compact form', 42 === $codec->decode( $codec->encode( 42 ) ) );
hc_codec_gate( 'Numeric strings stay strings', '42' === $codec->decode( $codec->encode( '42' ) ) );

$raw = $codec->encode( $value );
hc_codec_gate( 'Encoded values carry the V2 signature prefix', 0 === strpos( $raw, 'V2' ) && strlen( $raw ) > 66 );
hc_codec_gate( 'A changed payload is rejected', hc_codec_throws( static function () use ( $codec, $raw ) {
	$codec->decode( substr( $raw, 0, -1 ) . ( 'A' === substr( $raw, -1 ) ? 'B' : 'A' ) );
} ) );
hc_codec_gate( 'A forged signature is rejected', hc_codec_throws( static function () use ( $codec ) {
	$codec->decode( 'V2' . str_repeat( '0', 64 ) . serialize( array( 'forged' => true ) ) );
} ) );
hc_codec_gate( 'The unsigned V1 format is rejected', hc_codec_throws( static function () use ( $codec ) {
	$codec->decode( 'V1' . serialize( array( 'legacy' => true ) ) );
} ) );

echo 'RESULT ' . ( $failures ? 'FAILED' : 'PASSED' ) . ' with ' . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
