<?php
// Match the disposable site's request context when exercising HTTP-aware application code in CLI.
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
define('WP_HTTP_BLOCK_EXTERNAL',true);
define('HARBORCACHE_CONFIG',array('client'=>'phpredis','prefix'=>'hc-app-ci','analytics'=>array('enabled'=>false),'split_alloptions'=>true));
require rtrim(getenv('WP_ROOT'),'/').'/wp-load.php';
function app_gate($name,$pass){echo($pass?'PASS ':'FAIL ').$name."\n";if(!$pass){throw new RuntimeException($name);}}
if(($argv[1]??'')==='multisite'){
 app_gate('Real multisite bootstrap',is_multisite());
 $site=wp_insert_site(array('domain'=>'localhost','path'=>'/cache-test/','title'=>'Cache test'));
 if(is_wp_error($site)){throw new RuntimeException($site->get_error_message());}
 $first=get_current_blog_id();update_option('hc_isolation','first');wp_cache_set('shared','global','users',60);switch_to_blog($site);update_option('hc_isolation','second');wp_cache_set('key','second','hc-site',60);app_gate('Global group shared across sites','global'===wp_cache_get('shared','users'));restore_current_blog();wp_cache_set('key','first','hc-site',60);
 app_gate('First site options and objects','first'===get_option('hc_isolation')&&'first'===wp_cache_get('key','hc-site',true));
 switch_to_blog($site);app_gate('Second site options and objects','second'===get_option('hc_isolation')&&'second'===wp_cache_get('key','hc-site',true));$GLOBALS['wp_object_cache']->flush_scope('site-'.$site);app_gate('Selected site invalidated',false===wp_cache_get('key','hc-site',true));restore_current_blog();app_gate('Other site survives scoped flush','first'===wp_cache_get('key','hc-site',true));
 $site_id=is_object($site)?(int)$site->blog_id:(int)$site;wp_set_current_user(1);add_filter('harborcache_manager_capability',static function(){return 'manage_options';});switch_to_blog($site_id);wp_cache_set('scope','second','hc-rest-scope',60);restore_current_blog();wp_cache_set('scope','first','hc-rest-scope',60);
 $rest_list=rest_do_request(new WP_REST_Request('GET','/harborcache/v1/groups'));$rest_scopes=array_column((array)($rest_list->get_data()['groups']??array()),'scope');app_gate('REST groups include another site for a super admin',in_array('site-'.$site_id,$rest_scopes,true));
 $rest_detail=rest_do_request(new WP_REST_Request('GET','/harborcache/v1/groups/site-'.$site_id.'/hc-rest-scope'));app_gate('REST group detail allows a super admin',200===$rest_detail->get_status());
 require_once ABSPATH.'wp-admin/includes/user.php';$scope_user=wp_insert_user(array('user_login'=>'hc-rest-scope','user_pass'=>'ci-disposable-only','user_email'=>'hc-rest-scope@example.org','role'=>'administrator'));if(is_wp_error($scope_user)){throw new RuntimeException($scope_user->get_error_message());}wp_set_current_user($scope_user);
 $rest_denied=rest_do_request(new WP_REST_Request('GET','/harborcache/v1/groups/site-'.$site_id.'/hc-rest-scope'));app_gate('REST group detail denies another site',403===$rest_denied->get_status());
 $rest_own=rest_do_request(new WP_REST_Request('GET','/harborcache/v1/groups/site-'.get_current_blog_id().'/hc-rest-scope'));app_gate('REST group detail allows the current site',200===$rest_own->get_status());
 $rest_filtered=rest_do_request(new WP_REST_Request('GET','/harborcache/v1/groups'));$own_scopes=array_column((array)($rest_filtered->get_data()['groups']??array()),'scope');app_gate('REST group list hides another site',!in_array('site-'.$site_id,$own_scopes,true));
 wp_delete_user($scope_user);wp_set_current_user(1);remove_all_filters('harborcache_manager_capability');
 require_once ABSPATH.'wp-admin/includes/ms.php';wpmu_delete_blog($site,true);delete_option('hc_isolation');exit;
}
app_gate('WooCommerce loaded',class_exists('WooCommerce'));
$product=new WC_Product_Simple();$product->set_name('HarborCache integration');$product->set_status('publish');$product->set_regular_price('19.99');$product->set_manage_stock(true);$product->set_stock_quantity(10);$id=$product->save();
try{
 app_gate('Product persisted',19.99===(float)wc_get_product($id)->get_price());
 WC()->initialize_session();WC()->initialize_cart();app_gate('Cart accepts product',false!==WC()->cart->add_to_cart($id,3));WC()->cart->calculate_totals();
 $order_id=WC()->checkout()->create_order(array('billing_first_name'=>'CI','billing_last_name'=>'Test','billing_email'=>'ci@example.org','billing_country'=>'US','payment_method'=>'bacs'));
 app_gate('Checkout creates order',!is_wp_error($order_id)&&$order_id>0);$order=wc_get_order($order_id);app_gate('Checkout order line totals',abs((float)$order->get_subtotal()-59.97)<0.00001);
 wc_maybe_reduce_stock_levels($order_id);wp_cache_flush_runtime();app_gate('Stock cache invalidates',7===(int)wc_get_product($id)->get_stock_quantity());
 $order->set_customer_note('Updated through integration');$order->save();wp_cache_flush_runtime();app_gate('Order update survives cache reload','Updated through integration'===wc_get_order($order_id)->get_customer_note());
 wc_maybe_increase_stock_levels($order_id);wp_cache_flush_runtime();app_gate('Stock restored',10===(int)wc_get_product($id)->get_stock_quantity());$order->delete(true);
}finally{$product->delete(true);if(WC()->cart){WC()->cart->empty_cart();}}
