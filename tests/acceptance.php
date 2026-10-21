<?php
/** Isolated test installation only. Run with WP_ROOT and HC_TEST_CONFIG. */
define('WP_HTTP_BLOCK_EXTERNAL', true);
$mode=$argv[1]??'main';
$config=json_decode(getenv('HC_TEST_CONFIG') ?: '{}',true);
if(!is_array($config)){throw new RuntimeException('Invalid test configuration');}
$config=array_replace(array('client'=>'phpredis','port'=>6379,'prefix'=>'harborcache-ci','analytics'=>array('enabled'=>false),'split_alloptions'=>true),$config);
define('HARBORCACHE_CONFIG',$config);
if($mode==='transport'){fwrite(STDOUT,"BEGIN WordPress bootstrap\n");fflush(STDOUT);}
require rtrim(getenv('WP_ROOT'),'/').'/wp-load.php';
if($mode==='transport'){fwrite(STDOUT,"PASS WordPress bootstrap\n");fflush(STDOUT);}
$c=$GLOBALS['wp_object_cache'];$checks=array();
function gate($name,$pass){global $checks;$checks[]=array('test'=>$name,'pass'=>(bool)$pass);echo ($pass?'PASS ':'FAIL ').$name."\n";if(!$pass){throw new RuntimeException($name);}}
function harborcache_test_css_scoped($path,$roots){
 $css=file_get_contents($path);if(false===$css){return false;}$css=preg_replace('~/\*.*?\*/~s','',$css);preg_match_all('/(?m)^[ \t]*([^@{}\r\n][^{}\r\n]*)\s*\{/',$css,$matches);
 foreach($matches[1] as $rule){$start=0;$depth=0;$quote='';$length=strlen($rule);for($i=0;$i<$length;$i++){$char=$rule[$i];if(''!==$quote){if($char===$quote&&($i===0||'\\'!==$rule[$i-1])){$quote='';}continue;}if('"'===$char||"'"===$char){$quote=$char;continue;}if('('===$char||'['===$char){++$depth;continue;}if(')'===$char||']'===$char){--$depth;continue;}if(','!==$char||0!==$depth){continue;}$selector=trim(substr($rule,$start,$i-$start));$start=$i+1;$scoped=false;foreach($roots as $root){if(0===strpos($selector,$root)){$scoped=true;break;}}if(!$scoped){return false;}}$selector=trim(substr($rule,$start));$scoped=false;foreach($roots as $root){if(0===strpos($selector,$root)){$scoped=true;break;}}if(!$scoped){return false;}}
 return true;
}
gate('HarborCache owns prefixed runtime identity and retains Redis configuration inputs',defined('HARBORCACHE_FILE')&&defined('HARBORCACHE_VERSION')&&defined('HARBORCACHE_PLUGIN_PATH')&&realpath(dirname(HARBORCACHE_FILE))===realpath(HARBORCACHE_PLUGIN_PATH)&&!defined('WP_REDIS_FILE')&&!defined('WP_REDIS_PLUGIN_PATH')&&defined('WP_REDIS_HOST'));
gate('HarborCache uses only prefixed admin and metrics actions',false!==has_action('admin_post_harborcache_action')&&false===has_action('wp_ajax_roc_flush_cache')&&false===has_action('wp_ajax_roc_dismiss_notice')&&false===has_action('rediscache_discard_metrics')&&(!\HarborCache\Core\Metrics::is_enabled()||false!==has_action('harborcache_metrics_discard')));
gate('Drop-in recovery notices stay on HarborCache settings screens',!\HarborCache\Console::is_console_screen('index.php')&&!\HarborCache\Console::is_console_screen('plugins.php')&&\HarborCache\Console::is_console_screen('settings_page_harborcache')&&\HarborCache\Console::is_console_screen('settings_page_harborcache-network'));
gate('HarborCache dashboard styles stay inside its UI',harborcache_test_css_scoped(dirname(__DIR__).'/assets/css/console.css',array('#harborcache'))&&harborcache_test_css_scoped(dirname(__DIR__).'/assets/css/chart.css',array(':where(#harborcache, #harborcache_status)','#harborcache_status')));
if($mode==='worker'){
 for($i=0;$i<50;++$i){if(false===$c->incr('counter',1,'ci-concurrent')){throw new RuntimeException('Counter worker');}}
 exit;
}
if($mode==='options-worker'){
 $field=$argv[2];$value=$c->get('alloptions','options',true,$found);
 if(!$found){throw new RuntimeException('Missing option snapshot');}
 file_put_contents(getenv('HC_BARRIER').'/'.$field,'ready');
 $deadline=microtime(true)+20;while(!file_exists(getenv('HC_BARRIER').'/release')){if(microtime(true)>$deadline){throw new RuntimeException('Barrier timeout');}usleep(1000);}
 $value[$field]='changed';if(!$c->set('alloptions',$value,'options',120)){throw new RuntimeException('Options worker');}exit;
}
if($mode==='down'){
 gate('Unavailable backend falls back to runtime memory',!$c->redis_status()&&$c->set('fallback',false,'ci-outage')&&false===$c->get('fallback','ci-outage',false,$found)&&$found);
 exit;
}
if($mode==='transport'){
 gate('Configured transport connects',$c->redis_status());
 $transport_key='transport-'.bin2hex(random_bytes(8));
 gate('Configured transport writes',$c->set($transport_key,'before','ci-transport',60));
 $fresh=new HarborCache_Advanced_Cache();
 gate('Fresh transport client connects',$fresh->redis_status());
 $replaced=$fresh->replace($transport_key,'after','ci-transport',60);
 gate('Fresh transport client replaces a persistent key',$replaced);
 $verify=new HarborCache_Advanced_Cache();
 gate('Verification transport client connects',$verify->redis_status());
 $replacement=$verify->get($transport_key,'ci-transport',true);
 gate('Fresh transport client reads the replacement','after'===$replacement);
 gate('Configured transport deletes',$c->delete($transport_key,'ci-transport'));
 exit;
}
gate('Connected to configured Redis transport',$c->redis_status());
if($mode==='recovery'){gate('New worker recovers after restart',$c->set('recovered',42,'ci-outage',60)&&42===$c->get('recovered','ci-outage',true));exit;}
gate('Advanced implementation loaded',$c instanceof HarborCache_Advanced_Cache);
if($config['client']==='relay'){
 $c->set('external',1,'ci-force',60);$c->get('external','ci-force',true);$key_method=new ReflectionMethod($c,'build_key');$key_method->setAccessible(true);$rawkey=$key_method->invoke($c,'external','ci-force');$other=new Redis();$other->connect('127.0.0.1',6379);$other->select((int)($config['database']??0));$other->setex($rawkey,60,'N2');$cache_mode=$c->redis_instance()->getOption(Relay\Relay::OPT_USE_CACHE);gate('Forced Relay read bypasses shared cache',2===$c->get('external','ci-force',true));gate('Relay cache mode restored',$cache_mode===$c->redis_instance()->getOption(Relay\Relay::OPT_USE_CACHE));$other->setex($rawkey,60,'N3');gate('Forced Relay bulk read bypasses shared cache',array('external'=>3)===$c->get_multiple(array('external'),'ci-force',true));$other->close();
}
$values=array(false,null,true,0,-1,PHP_INT_MAX,PHP_INT_MIN,1.25,'','001','1e3','b:0;',array('nested'=>array(false,null)),(object)array('v'=>1),str_repeat('large-value-',10000));
foreach($values as $i=>$value){gate('Write type '.$i,$c->set('type-'.$i,$value,'ci-types',60));$c->flush_runtime();$actual=$c->get('type-'.$i,'ci-types',true,$found);gate('Roundtrip type '.$i,$found&&serialize($actual)===serialize($value));}
$c->set('layout-probe','before','ci-layout',60);$c->get('layout-probe','ci-layout');$original_compression=$c->ch_config['compression'];$c->ch_config['compression']='none'===$original_compression?'lzf':'none';$layout_value=$c->get('layout-probe','ci-layout',false,$layout_found);$c->ch_config['compression']=$original_compression;gate('Layout changes bypass stale runtime values',false===$layout_value&&!$layout_found);
$c->get('absent','ci-types',true,$found);gate('Missing found flag',!$found);
$c->set('clone',(object)array('v'=>1),'ci-types');$obj=$c->get('clone','ci-types');$obj->v=2;gate('Runtime object isolation',1===$c->get('clone','ci-types')->v);
$c->set('flags',null,'ci-flags');$c->get_multiple(array('flags'),'ci-flags');gate('Runtime null is a hit',null===$c->get('flags','ci-flags',false,$found)&&$found);
$data=array('false'=>false,'null'=>null,'text'=>'ok');gate('Bulk writes',!in_array(false,$c->set_multiple($data,'ci-bulk',60),true));$c->flush_runtime();gate('Bulk typed reads',$data===$c->get_multiple(array_keys($data),'ci-bulk',true));
gate('Atomic add',$c->add('add',1,'ci-types')&&!$c->add('add',2,'ci-types'));
gate('Replace semantics',!$c->replace('missing',1,'ci-types')&&$c->replace('add',2,'ci-types'));
$c->set('remote-replace','before','ci-replace',60);$fresh=new HarborCache_Advanced_Cache();gate('Replace sees a persistent key outside request memory',$fresh->replace('remote-replace','after','ci-replace',60));$verify=new HarborCache_Advanced_Cache();gate('Replace update is visible to a fresh worker','after'===$verify->get('remote-replace','ci-replace',true));gate('Replace does not create a missing persistent key',!(new HarborCache_Advanced_Cache())->replace('remote-missing','value','ci-replace',60));
$c->set('big',9007199254740992,'ci-types',60);gate('Counter precision',9007199254740993===$c->incr('big',1,'ci-types'));
$c->set('overflow',PHP_INT_MAX,'ci-types',60);$overflow=$c->incr('overflow',1,'ci-types');$overflow_value=$c->get('overflow','ci-types',true);if(false!==$overflow||PHP_INT_MAX!==$overflow_value){echo json_encode(array('counter_reply'=>$overflow,'counter_value'=>$overflow_value,'reply_type'=>gettype($overflow),'value_type'=>gettype($overflow_value)))."\n";}gate('Counter overflow rejected',false===$overflow&&PHP_INT_MAX===$overflow_value);
$c->set('foreign-proof','keep','ci-kept',60);$c->set('invalidate','old','ci-invalidated',60);gate('Group rotation',$c->flush_group('ci-invalidated'));$fresh=new HarborCache_Advanced_Cache();gate('Fresh worker sees scoped invalidation',false===$fresh->get('invalidate','ci-invalidated',true)&&'keep'===$fresh->get('foreign-proof','ci-kept',true));
$c->ch_command(array('SET','harborcache-ci-foreign','keep','EX',60));gate('Installation invalidation',$c->flush());gate('Foreign key survives','keep'===$c->ch_command(array('GET','harborcache-ci-foreign')));
$c->add_non_persistent_groups(array('private-*'));$c->set('x',1,'private-user');$c->flush_runtime();$ignored_key=$c->build_key('x','private-user');gate('Wildcard exclusions bypass Redis storage',null===$c->ch_command(array('GET',$ignored_key)));gate('Wildcard exclusions remain unavailable after runtime flush',false===$c->get('x','private-user',true));
$c->set('ttl',1,'ci-ttl',1);sleep(2);gate('Expiration',false===$c->get('ttl','ci-ttl',true));
$original_blog_prefix = $c->blog_prefix;
$c->blog_prefix = 999999;
gate( 'Option cache seed writes', $c->set( 'alloptions', array( 'a' => 1, 'b' => 2 ), 'options', 120 ) );
$left = new HarborCache_Advanced_Cache();
$right = new HarborCache_Advanced_Cache();
$left->blog_prefix = 999999;
$right->blog_prefix = 999999;
$seed_key = $c->build_key( 'alloptions', 'options' );
$left_key = $left->build_key( 'alloptions', 'options' );
$right_key = $right->build_key( 'alloptions', 'options' );
gate( 'Fresh workers share one cache namespace', $seed_key === $left_key && $left_key === $right_key );
$a = $left->get( 'alloptions', 'options', true );
$b = $right->get( 'alloptions', 'options', true );
$readers_see_seed = array( 'a' => 1, 'b' => 2 ) === $a && array( 'a' => 1, 'b' => 2 ) === $b;
if ( ! $readers_see_seed && 'relay' === $config['client'] ) {
	$option_key = $left->build_key( 'alloptions', 'options' );
	$probe = new Redis();
	$probe->connect( '127.0.0.1', (int) ( $config['port'] ?? 6379 ) );
	$probe->select( (int) ( $config['database'] ?? 0 ) );
	$manifest = $probe->get( $option_key );
	$names = is_string( $manifest ) ? json_decode( $manifest, true ) : array();
	$raw_values = array();
	foreach ( (array) $names as $name => $unused ) { $raw_values[ $name ] = $probe->get( $option_key . ':o:' . hash( 'sha256', (string) $name ) ); }
	echo json_encode( array( 'option_keys' => array( $c->build_key( 'alloptions', 'options' ), $left->build_key( 'alloptions', 'options' ), $right->build_key( 'alloptions', 'options' ) ), 'left' => $a, 'right' => $b, 'raw_manifest' => $manifest, 'raw_values' => $raw_values ) ) . "\n";
	$probe->close();
}
gate( 'Option readers see the isolated seed', $readers_see_seed );
$a['a'] = 3;
$b['b'] = 4;
gate( 'First option patch writes', $left->set( 'alloptions', $a, 'options', 120 ) );
gate( 'Second option patch writes', $right->set( 'alloptions', $b, 'options', 120 ) );
$final_reader = new HarborCache_Advanced_Cache();
$final_reader->blog_prefix = 999999;
$actual = $final_reader->get( 'alloptions', 'options', true );
$c->blog_prefix = $original_blog_prefix;
gate( 'Independent option patches survive', array( 'a' => 3, 'b' => 4 ) === $actual );
$c->ch_config['async_flush']=true;for($i=0;$i<20;++$i){$c->set($i,'old','ci-cleanup',120);}$c->flush_group('ci-cleanup');$c->set('fresh','keep','ci-cleanup',120);$cleanup_count=0;for($i=0;$i<100;++$i){$state=$c->cleanup_status();if(!$state['pending']&&!$state['inflight']){break;}$cleanup_count+=$c->cleanup(5);}if($state['pending']||$state['inflight']){$base_property=new ReflectionProperty($c,'ch_base');$base_property->setAccessible(true);$base=$base_property->getValue($c);$inflight_method=new ReflectionMethod($c,'cleanup_inflight_key');$inflight_method->setAccessible(true);echo json_encode(array('cleanup_status'=>$state,'cleanup_jobs_processed'=>$cleanup_count,'redis_dbsize'=>$c->ch_command(array('DBSIZE')),'queue'=>$c->ch_command(array('LRANGE',$base.':cleanup',0,1)),'inflight'=>$c->ch_command(array('ZRANGE',$inflight_method->invoke($c),0,-1))))."\n";}gate('Cleanup drains bounded queue',!$state['pending']&&!$state['inflight']);gate('Cleanup preserves new generation','keep'===$c->get('fresh','ci-cleanup',true));
if(getenv('HC_CONCURRENCY')==='1'){
 $c->set('counter',0,'ci-concurrent',120);$children=array();
 for($i=0;$i<4;++$i){$children[]=proc_open(array(PHP_BINARY,__FILE__,'worker'),array(0=>array('pipe','r'),1=>STDOUT,2=>STDERR),$pipes);fclose($pipes[0]);}
 foreach($children as $child){gate('Concurrent counter worker',proc_close($child)===0);}gate('Four-worker atomic counters',200===$c->get('counter','ci-concurrent',true));
 $barrier=sys_get_temp_dir().'/hc-options-'.getmypid();mkdir($barrier);putenv('HC_BARRIER='.$barrier);$initial=wp_load_alloptions();$initial['a']='old';$initial['b']='old';$c->set('alloptions',$initial,'options',120);$children=array();
 foreach(array('a','b') as $field){$children[]=proc_open(array(PHP_BINARY,__FILE__,'options-worker',$field),array(0=>array('pipe','r'),1=>STDOUT,2=>STDERR),$pipes);fclose($pipes[0]);}
 $deadline=microtime(true)+20;while(!file_exists($barrier.'/a')||!file_exists($barrier.'/b')){if(microtime(true)>$deadline){throw new RuntimeException('Options readiness');}usleep(1000);}touch($barrier.'/release');foreach($children as $child){gate('Concurrent option worker',proc_close($child)===0);}$actual=$c->get('alloptions','options',true);if(($actual['a']??null)!=='changed'||($actual['b']??null)!=='changed'){echo json_encode(array('a'=>$actual['a']??null,'b'=>$actual['b']??null))."\n";}gate('Cross-worker option patches',($actual['a']??null)==='changed'&&($actual['b']??null)==='changed');foreach(glob($barrier.'/*') as $file){unlink($file);}rmdir($barrier);
}
$option='harborcache_ci_option';update_option($option,array('v'=>1),true);wp_cache_flush_runtime();gate('WordPress option roundtrip',array('v'=>1)===get_option($option));delete_option($option);
$post=wp_insert_post(array('post_title'=>'HarborCache CI','post_status'=>'publish','post_content'=>'Original'));gate('WordPress publishing',$post>0&&'Original'===get_post($post)->post_content);wp_update_post(array('ID'=>$post,'post_content'=>'Updated'));gate('Post invalidation','Updated'===get_post($post)->post_content);wp_delete_post($post,true);
add_action('harborcache_ci_event',static function(){update_option('harborcache_ci_cron','ran');});wp_schedule_single_event(time()-1,'harborcache_ci_event');require_once ABSPATH.'wp-admin/includes/file.php';do_action('harborcache_ci_event');gate('Cron callback with cache','ran'===get_option('harborcache_ci_cron'));wp_clear_scheduled_hook('harborcache_ci_event');delete_option('harborcache_ci_cron');
echo json_encode(array('php'=>PHP_VERSION,'wordpress'=>get_bloginfo('version'),'client'=>$config['client'],'serializer'=>$config['serializer']??'php','compression'=>$config['compression']??'none','checks'=>count($checks),'failed'=>0))."\n";
