<?php
/**
 * Cache value envelopes: plain V1, signed object V2 and tamper rejection.
 *
 * php tests/codec-signature.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'AUTH_KEY', 'codec-signature-fixture-key' );
require_once dirname( __DIR__ ) . '/includes/ch-codec.php';

class Probe_Gadget {
	public $marker = 'x';
	public function __wakeup() { $GLOBALS['hc_woke'] = true; }
}

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
hc_codec_gate( 'Plain values round-trip', $value === $codec->decode( $codec->encode( $value ) ) );
hc_codec_gate( 'Integers keep their compact form', 42 === $codec->decode( $codec->encode( 42 ) ) );
hc_codec_gate( 'Numeric strings stay strings', '42' === $codec->decode( $codec->encode( '42' ) ) );
hc_codec_gate( 'Object-free payloads use the cheaper V1 envelope', 0 === strpos( $codec->encode( $value ), 'V1' ) );

$object = new Probe_Gadget();
$raw    = $codec->encode( $object );
hc_codec_gate( 'Object payloads carry the V2 signature', 0 === strpos( $raw, 'V2' ) && strlen( $raw ) > 66 );
$GLOBALS['hc_woke'] = false;
$decoded = $codec->decode( $raw );
hc_codec_gate( 'Signed object round-trip instantiates the class', $decoded instanceof Probe_Gadget && 'x' === $decoded->marker && true === $GLOBALS['hc_woke'] );
hc_codec_gate( 'A changed signed payload is rejected', hc_codec_throws( static function () use ( $codec, $raw ) {
	$codec->decode( substr( $raw, 0, -1 ) . ( 'A' === substr( $raw, -1 ) ? 'B' : 'A' ) );
} ) );
hc_codec_gate( 'A forged object signature is rejected', hc_codec_throws( static function () use ( $codec ) {
	$codec->decode( 'V2' . str_repeat( '0', 64 ) . serialize( new Probe_Gadget() ) );
} ) );
hc_codec_gate( 'An unsigned V1 object is rejected', hc_codec_throws( static function () use ( $codec ) {
	$codec->decode( 'V1' . serialize( new Probe_Gadget() ) );
} ) );
hc_codec_gate( 'A legacy unsigned plain value is accepted', array( 'legacy' => true ) === $codec->decode( 'V1' . serialize( array( 'legacy' => true ) ) ) );

echo 'RESULT ' . ( $failures ? 'FAILED' : 'PASSED' ) . ' with ' . $failures . " failure(s)\n";
exit( $failures ? 1 : 0 );
