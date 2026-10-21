<?php
/** Sequential cache-operation benchmark; excludes WordPress bootstrap. */
$engine=$argv[1];$source=$argv[2];$round=(int)$argv[3];$sampling=(int)($argv[4]??0);
define('WP_HTTP_BLOCK_EXTERNAL',true);
$host=getenv('HC_REDIS_HOST')?:'127.0.0.1';$port=max(1,min(65535,(int)(getenv('HC_REDIS_PORT')?:6379)));$prefix=(getenv('HC_BENCH_PREFIX')?:'hc-linux-').$engine.'-'.$round.'-'.$sampling.':';
define('WP_REDIS_CLIENT','phpredis');define('WP_REDIS_HOST',$host);define('WP_REDIS_PORT',$port);define('WP_REDIS_PREFIX',$prefix);
if($engine==='free'){define('WP_REDIS_PLUGIN_PATH',$source);define('WP_REDIS_FILE',rtrim($source,'/\\').DIRECTORY_SEPARATOR.'redis-cache.php');}else{define('HARBORCACHE_PLUGIN_PATH',$source);}
if($engine!=='free'){define('HARBORCACHE_CONFIG',array('client'=>'phpredis','analytics'=>array('enabled'=>$sampling>0,'sample_rate'=>$sampling),'prefetch'=>false,'split_alloptions'=>false));}
$bootstrap_start=hrtime(true);$bootstrap_usage=getrusage();
require rtrim(getenv('WP_ROOT'),'/').'/wp-load.php';
if($engine!=='free'){require rtrim($source,'/\\').DIRECTORY_SEPARATOR.'harborcache.php';}
$bootstrap_ms=(hrtime(true)-$bootstrap_start)/1e6;$bootstrap_after=getrusage();$bootstrap_cpu_ms=(($bootstrap_after['ru_utime.tv_sec']+$bootstrap_after['ru_stime.tv_sec'])-($bootstrap_usage['ru_utime.tv_sec']+$bootstrap_usage['ru_stime.tv_sec']))*1000+(($bootstrap_after['ru_utime.tv_usec']+$bootstrap_after['ru_stime.tv_usec'])-($bootstrap_usage['ru_utime.tv_usec']+$bootstrap_usage['ru_stime.tv_usec']))/1000;
$c=$GLOBALS['wp_object_cache'];if(!$c->redis_status()){throw new RuntimeException('Backend unavailable');}
$probe=new Redis();$probe->connect($host,$port);$before=$probe->info('cpu');$stats=$probe->info('stats');
$cpu=static function(){$r=getrusage();return ($r['ru_utime.tv_sec']+$r['ru_stime.tv_sec'])*1000+($r['ru_utime.tv_usec']+$r['ru_stime.tv_usec'])/1000;};
$cpu0=$cpu();$keys=range(1,100);$payload=array('values'=>range(1,50),'label'=>'Equal cache workload');$data=array_fill_keys($keys,$payload);wp_cache_set_multiple($data,'hc-bench',120);$times=array();
$measure=static function($label,$call)use(&$times){for($warmup=0;$warmup<5;++$warmup){$call();}$samples=array();for($batch=0;$batch<50;++$batch){$start=hrtime(true);$call();$samples[]=(hrtime(true)-$start)/1e6;}sort($samples);$times[$label]=array('p50_ms'=>$samples[24],'p95_ms'=>$samples[47],'p99_ms'=>$samples[49]);};
$measure('runtime_1000',static function(){for($i=0;$i<1000;++$i){wp_cache_get(1,'hc-bench');}});
$measure('redis_20',static function(){for($i=0;$i<20;++$i){wp_cache_get(1,'hc-bench',true);}});
$measure('bulk_100',static function()use($keys){wp_cache_get_multiple($keys,'hc-bench',true);});
$measure('writes_20',static function()use($payload){for($i=0;$i<20;++$i){wp_cache_set(1,$payload,'hc-bench',120);}});
$measure('misses_20',static function(){for($i=0;$i<20;++$i){wp_cache_get('absent','hc-bench',true);}});
$measure('cold_20',static function(){for($i=0;$i<20;++$i){wp_cache_get('cold-'.bin2hex(random_bytes(8)),'hc-bench',true);}});
$measure('large_16k',static function(){$payload=str_repeat('x',16384);wp_cache_set('large',$payload,'hc-bench',120);if($payload!==wp_cache_get('large','hc-bench',true)){throw new RuntimeException('Large value mismatch');}});
$metrics=0;if($engine!=='free'){$start=hrtime(true);HarborCache\Core\Metrics::record();$metrics=(hrtime(true)-$start)/1e6;remove_action('shutdown',array(HarborCache\Core\Metrics::class,'record'));}
$after=$probe->info('cpu');$afterstats=$probe->info('stats');$memory=$probe->info('memory');
echo json_encode(array('engine'=>$engine,'round'=>$round,'sampling'=>$sampling,'php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),'timings'=>$times,'bootstrap_wall_ms'=>$bootstrap_ms,'bootstrap_php_cpu_ms'=>$bootstrap_cpu_ms,'metrics_record_ms'=>$metrics,'php_cpu_ms'=>$cpu()-$cpu0,'redis_cpu_ms'=>(($after['used_cpu_sys']+$after['used_cpu_user'])-($before['used_cpu_sys']+$before['used_cpu_user']))*1000,'redis_commands'=>$afterstats['total_commands_processed']-$stats['total_commands_processed'],'php_peak_bytes'=>memory_get_peak_usage(true),'redis_rss_bytes'=>$memory['used_memory_rss'],'limitations'=>'Sequential operation/batch distributions, not concurrent HTTP request percentiles; CPU/RSS include shared Redis daemon activity; operation timings exclude bootstrap, which is reported separately; free-engine bootstrap omits the free plugin admin bootstrap'))."\n";
