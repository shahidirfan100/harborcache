<?php
/** Check PhpRedis TLS independently of WordPress bootstrap. */
$ca_file = getenv( 'HC_TLS_CA' );
if ( ! is_string( $ca_file ) || ! is_readable( $ca_file ) || ! class_exists( 'Redis' ) ) {
	throw new RuntimeException( 'PhpRedis TLS smoke test is not configured.' );
}

$redis = new Redis();
$connected = $redis->connect(
	'tls://127.0.0.1',
	6380,
	1.0,
	'',
	0,
	1.0,
	array(
		'stream' => array(
			'cafile' => $ca_file,
			'verify_peer' => true,
			'verify_peer_name' => true,
		),
	)
);

if ( ! $connected || ! $redis->ping() ) {
	throw new RuntimeException( 'PhpRedis could not complete a trusted TLS Redis request.' );
}

$redis->close();
echo "PASS PhpRedis trusted TLS works outside WordPress bootstrap\n";
