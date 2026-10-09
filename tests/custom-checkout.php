<?php
/** Disposable local test of the merchant's actual express-order handler; no provider calls. */
use Magellan\V3\{Protocol,Config,Outbox,Capture};
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || !str_contains(home_url(),'127.0.0.1:18783')) { throw new RuntimeException('Disposable local fixture required'); }
$source = getenv('MAGELLAN_UF_CHECKOUT_SOURCE');
if (!$source || !is_file($source)) { throw new RuntimeException('Supply the verified Urbanflowers express-order source via MAGELLAN_UF_CHECKOUT_SOURCE'); }
if (!Config::ready()) { throw new RuntimeException('Run the integration fixture first to provision the local test installation'); }
add_filter('pre_wp_mail', static fn() => true);
add_filter('pre_http_request', static fn() => new WP_Error('fixture_network_disabled','No external calls during this test'), PHP_INT_MAX);
require_once $source;
global $wpdb;
$table=Outbox::table(); $results=[];
$startSequence=(int)$wpdb->get_var("SELECT COALESCE(MAX(seq),0) FROM $table");
$check=static function($name,$ok) use (&$results) { $results[]=['test'=>$name,'pass'=>(bool)$ok]; };
$contextCookie='_mgln_v3_context_' . Config::get()['site_id'];
$visitor=wp_generate_uuid4(); $session=wp_generate_uuid4();
$setContext=static function($sessionId) use($contextCookie,$visitor) {
    $_COOKIE[$contextCookie]=wp_slash(Protocol::json(['site_id'=>Config::get()['site_id'],'visitor_id'=>$visitor,'session_id'=>$sessionId,'updated_at'=>time(),'consent'=>array_merge(Protocol::consent(),['analytics'=>'granted','source'=>'fixture']), 'entry'=>['path'=>'/flowers/','source_evidence'=>['utm'=>['source'=>'google','medium'=>'organic'],'click_ids'=>[],'referrer_domain'=>'www.google.com']]]));
};
$setContext($session);
wc_load_cart(); WC()->cart->empty_cart();
$product=new WC_Product_Simple(); $product->set_name('Disposable express checkout fixture'); $product->set_regular_price('100'); $product->save();
WC()->cart->add_to_cart($product->get_id(),2); WC()->cart->calculate_totals(); Capture::cart();
$cartId=Capture::cart_id(false);
$orderId=UF_Payment_Request_Order::create_from_payment_request(['payment_intent'=>(object)['id'=>'synthetic_express_payment'],'payer_email'=>'express-fixture@example.invalid','payer_name'=>'Synthetic Fixture','payer_phone'=>'','shipping_address'=>[]]);
$check('actual merchant express handler creates a local fixture order',is_int($orderId) && $orderId>0);
if (!$orderId) { throw new RuntimeException('Merchant handler failed before attribution could be tested'); }
Capture::flush(); $order=wc_get_order($orderId); $context=Capture::saved_context($order);
$check('express order freezes the consented checkout session',($context['session_id'] ?? null)===$session);
$check('express order links the server-owned basket',($context['cart_id'] ?? null)===$cartId);
$event=json_decode($wpdb->get_var($wpdb->prepare("SELECT payload FROM $table WHERE entity_key=%s ORDER BY seq DESC LIMIT 1",'order:'.$orderId)),true);
$check('express snapshot retains source and observed total',($event['payload']['checkout_context']['entry']['source_evidence']['utm']['source'] ?? null)==='google' && ($event['payload']['amounts']['total']['amount_minor'] ?? null)==='20000');
$check('express order is labeled web checkout',($event['payload']['channel_origin'] ?? null)==='web_checkout');
$conversionCount=static function($id) use($wpdb,$table) {
    $bodies=$wpdb->get_col("SELECT payload FROM $table WHERE payload LIKE '%\"event_type\":\"cart_converted\"%'");
    return count(array_filter($bodies,static fn($b)=>(json_decode($b,true)['payload']['order_id'] ?? '')===(string)$id));
};
$setContext(wp_generate_uuid4());
do_action('woocommerce_checkout_order_processed',$orderId,[],$order); Capture::flush();
$check('repeated checkout processing neither duplicates conversion nor changes source',$conversionCount($orderId)===1 && (Capture::saved_context(wc_get_order($orderId))['session_id'] ?? null)===$session);
unset($_COOKIE[$contextCookie]);
$refund=wc_create_refund(['order_id'=>$orderId,'amount'=>'25','reason'=>'synthetic fixture','refund_payment'=>false,'restock_items'=>false]); Capture::flush();
$refundEvent=is_wp_error($refund) ? [] : json_decode($wpdb->get_var($wpdb->prepare("SELECT payload FROM $table WHERE entity_key=%s ORDER BY seq DESC LIMIT 1",'refund:'.$refund->get_id())),true);
$check('later express refund retains the saved session without a browser',($refundEvent['session_id'] ?? null)===$session);
$setContext($session); WC()->cart->empty_cart(); WC()->cart->add_to_cart($product->get_id(),1); Capture::cart();
$classic=wc_create_order(); $classic->add_product($product,1); do_action('woocommerce_checkout_create_order',$classic,[]); $classic->save(); do_action('woocommerce_checkout_order_created',$classic); do_action('woocommerce_checkout_order_processed',$classic->get_id(),[],$classic); Capture::flush();
$check('standard checkout remains a single conversion when both hooks fire',$conversionCount($classic->get_id())===1);
$admin=wc_create_order(['created_via'=>'admin']); $admin->add_product($product,1); $admin->save(); Capture::flush();
$check('administrative order is not stamped from the current browser cookie',Capture::saved_context(wc_get_order($admin->get_id()))===[]);
$erased=wc_create_order(); $erased->update_meta_data('_mgln_v3_analytics_erased',true); $erased->save(); do_action('woocommerce_checkout_order_processed',$erased->get_id(),[],$erased); Capture::flush();
$check('checkout fallback cannot resurrect an erased analytics context',Capture::saved_context(wc_get_order($erased->get_id()))===[]);
unset($_COOKIE[$contextCookie]); WC()->cart->empty_cart();
$bodies=$wpdb->get_col($wpdb->prepare("SELECT payload FROM $table WHERE seq>%d AND payload<>'' ORDER BY seq",$startSequence));
file_put_contents(__DIR__.'/custom-checkout-events.json',Protocol::json(array_map(static fn($body)=>json_decode($body),$bodies)));
file_put_contents(__DIR__.'/custom-checkout-byte-fixtures.json',Protocol::json(array_map(static fn($body)=>['json'=>$body,'sha256'=>hash('sha256',$body)],$bodies)));
$output=['runtime'=>['wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'php'=>PHP_VERSION,'hpos'=>\Magellan\V3\Admin::capabilities()['hpos']], 'merchant_source_sha256'=>hash_file('sha256',$source), 'scope'=>'Actual merchant order handler in isolated WordPress; simulated provider object; HTTP and mail disabled; no gateway transaction', 'results'=>$results];
file_put_contents(__DIR__.'/custom-checkout-results.json',wp_json_encode($output,JSON_PRETTY_PRINT));
$failed=array_filter($results,static fn($r)=>!$r['pass']);
if ($failed) { foreach($failed as $r){WP_CLI::warning($r['test']);} WP_CLI::error(count($failed).' custom checkout checks failed.'); }
WP_CLI::success(count($results).' custom checkout checks passed.');
