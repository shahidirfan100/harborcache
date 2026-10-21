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
