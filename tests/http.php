<?php
/** Disposable CI endpoint; never include in the installable plugin. */
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('HARBORCACHE_CONFIG', array('client'=>'phpredis','prefix'=>'hc-fpm-ci','analytics'=>array('enabled'=>false)));
require __DIR__.'/../wp-load.php';
header('Content-Type: application/json');
if (!$GLOBALS['wp_object_cache']->redis_status()) { http_response_code(503); exit('Backend unavailable'); }
if (isset($_GET['init'])) { wp_cache_set('requests',0,'hc-http',120); echo '{}'; exit; }
if (isset($_GET['summary'])) { echo json_encode(array('requests'=>wp_cache_get('requests','hc-http',true))); exit; }
$key=bin2hex(random_bytes(12)); $value=array('typed'=>array(false,null,42),'data'=>str_repeat('x',16384));
$ok=wp_cache_set($key,$value,'hc-http',60) && $value===wp_cache_get($key,'hc-http',true);
$ok=$ok && false!==wp_cache_incr('requests',1,'hc-http'); wp_cache_delete($key,'hc-http');
if (!$ok) { http_response_code(500); }
echo json_encode(array('ok'=>$ok,'php'=>PHP_VERSION,'peak_bytes'=>memory_get_peak_usage(true)));
