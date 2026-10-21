<?php
/** Check PhpRedis TLS independently of WordPress bootstrap. */
$ca_file = getenv( 'HC_TLS_CA' );
if ( ! is_string( $ca_file ) || ! is_readable( $ca_file ) || ! class_exists( 'Redis' ) ) {
	throw new RuntimeException( 'PhpRedis TLS smoke test is not configured.' );
}

define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/ch-config.php';
$redis = new Redis();
$connected = $redis->connect(
	'tls://127.0.0.1',
	6380,
	1.0,
	'',
	0,
	1.0,
	array(
		'stream' => HarborCache\Config::tls_options( array(
			'cafile' => $ca_file,
			'verify_peer' => true,
			'verify_peer_name' => true,
		), 'phpredis' ),
	)
);

if ( ! $connected || ! $redis->ping() ) {
	throw new RuntimeException( 'PhpRedis could not complete a trusted TLS Redis request.' );
}

$redis->close();
echo "PASS PhpRedis trusted TLS works outside WordPress bootstrap\n";
