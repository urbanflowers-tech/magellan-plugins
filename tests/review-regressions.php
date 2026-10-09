<?php
/** Includes the disposable integration fixture; never run on a merchant site. */
require __DIR__ . '/integration.php';
use Magellan\V3\{Protocol,Config,Outbox,Capture,Privacy,Recovery,Admin,Browser};
$results=[];
$wpdb->query('TRUNCATE TABLE '.Recovery::table());
foreach ([['934.579439',2,'93458'],['65.420561',2,'6542'],['9.999',2,'1000'],['-9.995',2,'-1000'],['0.0049',2,'0'],['0.005',2,'1'],['1e-7',2,'0'],['1.2345',3,'1235'],['999.5',0,'1000']] as $m) { check('decimal half-up '.$m[0], Protocol::money($m[0],'THB',$m[1])['amount_minor']===$m[2]); }
foreach (['NaN','INF','1,00','invalid'] as $bad) { try { Protocol::money($bad,'THB',2); check('reject corrupt money '.$bad,false); } catch (InvalidArgumentException $e) { check('reject corrupt money '.$bad,true); } }
// A real WooCommerce line carrying unrounded inclusive-tax values.
$taxOrder=wc_create_order(); $taxOrder->set_currency('THB');
$item=new WC_Order_Item_Product(); $item->set_product($product); $item->set_quantity(1); $item->set_subtotal('934.579439'); $item->set_total('934.579439'); $item->set_taxes(['total'=>[1=>'65.420561'],'subtotal'=>[1=>'65.420561']]); $taxOrder->add_item($item); $taxOrder->set_total('1000'); $taxOrder->save();
check('tax-inclusive WooCommerce order does not fail capture',Capture::snapshot($taxOrder->get_id()));
$taxEvent=json_decode($wpdb->get_var($wpdb->prepare("SELECT payload FROM $table WHERE entity_key=%s ORDER BY seq DESC LIMIT 1",'order:'.$taxOrder->get_id())),true);
check('tax-inclusive amounts preserve observed total',$taxEvent['payload']['lines'][0]['subtotal']['amount_minor']==='93458' && $taxEvent['payload']['lines'][0]['tax']['amount_minor']==='6542' && $taxEvent['payload']['amounts']['total']['amount_minor']==='100000');
// Exercise actual cart calculation at 7% inclusive tax, then restore merchant-independent defaults.
$oldOptions=[]; foreach (['woocommerce_calc_taxes','woocommerce_prices_include_tax','woocommerce_currency','woocommerce_tax_based_on','woocommerce_default_country'] as $key) { $oldOptions[$key]=get_option($key); }
update_option('woocommerce_calc_taxes','yes'); update_option('woocommerce_prices_include_tax','yes'); update_option('woocommerce_currency','THB'); update_option('woocommerce_tax_based_on','base'); update_option('woocommerce_default_country','TH');
$rate=WC_Tax::_insert_tax_rate(['tax_rate_country'=>'TH','tax_rate_state'=>'','tax_rate'=>'7.0000','tax_rate_name'=>'Fixture VAT','tax_rate_priority'=>1,'tax_rate_compound'=>0,'tax_rate_shipping'=>1,'tax_rate_order'=>0,'tax_rate_class'=>'']);
$inclusive=new WC_Product_Simple(); $inclusive->set_name('Inclusive fixture'); $inclusive->set_regular_price('1000'); $inclusive->set_tax_status('taxable'); $inclusive->save();
WC()->cart->empty_cart(); WC()->cart->add_to_cart($inclusive->get_id(),1); WC()->cart->calculate_totals(); Capture::cart();
$cartEvent=json_decode($wpdb->get_var("SELECT payload FROM $table WHERE payload LIKE '%\"event_type\":\"cart_snapshot\"%' ORDER BY seq DESC LIMIT 1"),true);
check('real tax-inclusive cart captures sub-minor decimals',$cartEvent['payload']['items'][0]['subtotal']['amount_minor']==='93458' && $cartEvent['payload']['merchandise_total']['amount_minor']==='93458');
WC()->cart->empty_cart(); WC_Tax::_delete_tax_rate($rate); foreach ($oldOptions as $key=>$value) { update_option($key,$value); }
// Isolate transport assertions from the fixture's intentionally rejected events.
$wpdb->query("TRUNCATE TABLE $table"); $wpdb->query('UPDATE '.Outbox::quota().' SET pending_rows=0,reserved_bytes=0'); delete_option('magellan_v3_circuit');
$poison=Outbox::capture('entity_deleted',['type'=>'page','id'=>'poison']); $mode='wrong_hash'; Outbox::drain();
check('per-event receipt failure does not trip store circuit',!get_option('magellan_v3_circuit') && $wpdb->get_var($wpdb->prepare("SELECT state FROM $table WHERE event_id=%s",$poison))==='pending');
$healthy=Outbox::capture('entity_deleted',['type'=>'page','id'=>'healthy']); $mode='accepted'; Outbox::drain();
check('new healthy event delivers while poison event backs off',$wpdb->get_var($wpdb->prepare("SELECT state FROM $table WHERE event_id=%s",$healthy))==='accepted');
$mode='failure'; due(); Outbox::drain(); check('503 still applies store transport backoff',(int)get_option('magellan_v3_circuit')>time());
// Optional data must leave reserved headroom for order and erasure evidence.
$quota=Outbox::quota(); $savedQuota=$wpdb->get_row("SELECT * FROM $quota WHERE id=1",ARRAY_A);
$wpdb->update($quota,['pending_rows'=>40000],['id'=>1]);
check('optional cart data sheds before canonical order evidence',Outbox::capture('cart_emptied',['cart_id'=>wp_generate_uuid4(),'revision'=>'fixture','lifecycle'=>'empty','reason'=>'fixture'])===null && Outbox::capture('order_status_changed',['order_id'=>'123','from_status'=>'pending','to_status'=>'processing','source_revision'=>'fixture'])!==null);
$wpdb->update($quota,['pending_rows'=>49000],['id'=>1]);
check('privacy has reserved capacity after ordinary commerce fills',Outbox::capture('order_status_changed',['order_id'=>'123','from_status'=>'pending','to_status'=>'processing','source_revision'=>'fixture'])===null && Outbox::capture('privacy_erasure_requested',['request_id'=>wp_generate_uuid4(),'order_ids'=>[],'visitor_ids'=>[],'session_ids'=>[],'scope'=>'analytics'],null,[],null,'privacy')!==null);
// Restore actual counters after the synthetic capacity boundary.
$wpdb->query("UPDATE $quota SET pending_rows=(SELECT COUNT(*) FROM $table WHERE accepted_at=0),reserved_bytes=(SELECT SUM(reserved_bytes) FROM $table) WHERE id=1");
// Retention scales with the worker; it must not be limited to 500 rows/hour.
$baseBytes=(int)$wpdb->get_var("SELECT reserved_bytes FROM $quota WHERE id=1");
for($n=0;$n<1001;$n++) { $wpdb->insert($table,['event_id'=>wp_generate_uuid4(),'installation_id'=>'install_fixture','entity_key'=>'cleanup:fixture','purpose'=>'health','payload'=>'{}','body_hash'=>hash('sha256','{}'),'reserved_bytes'=>22,'state'=>'accepted','created_at'=>1,'accepted_at'=>1,'next_attempt_at'=>0]); }
$wpdb->query("UPDATE $quota SET reserved_bytes=reserved_bytes+22022 WHERE id=1"); Outbox::cleanup();
check('cleanup drains a bounded thousand accepted rows per worker pass',(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE entity_key='cleanup:fixture'")===1);
Outbox::cleanup();check('cleanup continuation releases all expired reservations',(int)$wpdb->get_var("SELECT reserved_bytes FROM $quota WHERE id=1")===$baseBytes);
// A failed source order must not keep later pages behind it.
$broken=wc_create_order(); $broken->add_product($product,1); $broken->set_total('100'); $broken->update_meta_data('_mgln_v3_currency_exponent',99); $broken->save();
$last=null; for($n=0;$n<27;$n++) { $last=wc_create_order(); $last->add_product($product,1); $last->set_total('100'); $last->save(); }
update_option('magellan_v3_reconcile',['since'=>time()-86400,'until'=>time()+60,'page'=>1]);
for($n=0;$n<8 && !wc_get_order($last->get_id())->get_meta('_mgln_v3_snapshot_hash');$n++) { Capture::reconcile(); }
check('bad order cannot stall later reconciliation pages',(bool)wc_get_order($last->get_id())->get_meta('_mgln_v3_snapshot_hash') && Recovery::stats()['failed_orders']>=1);
$broken=wc_get_order($broken->get_id()); $broken->update_meta_data('_mgln_v3_currency_exponent',2); $broken->save(); Capture::reconcile_order($broken->get_id());
check('fixed source order exits independent retry queue',!(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Recovery::table().' WHERE order_id=%d',$broken->get_id())));
// Privacy must remove every related local body and preserve operational source facts.
$wpdb->query("TRUNCATE TABLE $table"); $wpdb->query("UPDATE $quota SET pending_rows=0,reserved_bytes=0");
$private=wc_create_order(); $private->set_billing_email('review-erase@example.invalid'); $private->add_product($product,1); $private->set_total('100');
$privateVisitor=wp_generate_uuid4(); $privateSession=wp_generate_uuid4();
$private->update_meta_data('_mgln_v3_context',['visitor_id'=>$privateVisitor,'session_id'=>$privateSession,'cart_id'=>wp_generate_uuid4(),'consent'=>array_merge(Protocol::consent(),['analytics'=>'granted']),'entry'=>['path'=>'/flowers/']]); $private->save();
Capture::snapshot($private->get_id()); Capture::status($private->get_id(),'pending','processing',$private); Capture::payment($private->get_id());
$privateRefund=wc_create_refund(['order_id'=>$private->get_id(),'amount'=>'10','refund_payment'=>false,'restock_items'=>false]); Capture::refund($private->get_id(),$privateRefund->get_id());
$oldIds=$wpdb->get_col("SELECT event_id FROM $table"); $erased=Privacy::erase('review-erase@example.invalid');
Capture::reconcile_order($private->get_id());
$bodies=$wpdb->get_col("SELECT payload FROM $table WHERE purpose<>'privacy'");
check('erasure removes visitor/session from all operational queue bodies',$erased['items_removed'] && !str_contains(implode('',$bodies),$privateVisitor) && !str_contains(implode('',$bodies),$privateSession));
$remainingIds=$wpdb->get_col("SELECT event_id FROM $table");
check('redaction uses new IDs and leaves no frozen privacy rows',count(array_intersect($oldIds,$remainingIds))===0 && (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE last_error='privacy_erasure_pending'")===0);
$types=array_column(array_map(static fn($b)=>json_decode($b,true),$bodies),'event_type');
check('erasure keeps order refund status and payment observations',count(array_diff(['order_snapshot','refund_recorded','order_status_changed','payment_fact'],$types))===0 && wc_get_order($private->get_id())->get_total()==='100.00');
check('privacy queue replacement maintains quota',(int)$wpdb->get_var("SELECT pending_rows FROM $quota WHERE id=1")===(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE accepted_at=0") && (int)$wpdb->get_var("SELECT reserved_bytes FROM $quota WHERE id=1")===(int)$wpdb->get_var("SELECT SUM(reserved_bytes) FROM $table"));
// Real scheduler guarantees, including sites running only wp action-scheduler run.
Outbox::ensure_maintenance(); check('maintenance shares the Action Scheduler runner',as_has_scheduled_action('magellan_v3_maintenance',[],'magellan-v3') && !wp_next_scheduled('magellan_v3_maintenance'));
as_unschedule_all_actions(Outbox::HOOK,[],'magellan-v3'); for($n=0;$n<20;$n++) { Outbox::schedule(1); }
check('capture burst keeps one pending drain action',count(as_get_scheduled_actions(['hook'=>Outbox::HOOK,'group'=>'magellan-v3','status'=>'pending','per_page'=>50],'ids'))===1);
$before=Config::get(); $pilot=array_merge($before,['collection_mode'=>'measurement_only']);
add_filter('pre_http_request',static function($pre,$args,$url) { if (!str_ends_with($url,'challenge')) { return $pre; } $b=json_decode($args['body'],true); return ['response'=>['code'=>200],'headers'=>[],'body'=>Protocol::json(array_merge($b,['durable_intake_ready'=>true]))]; },20,3);
wp_clear_scheduled_hook('magellan_sync_check'); wp_clear_scheduled_hook('magellan_health_check');
check('full to measurement-only restores missing legacy cron',!is_wp_error(Config::configure($pilot)) && wp_next_scheduled('magellan_sync_check') && wp_next_scheduled('magellan_health_check'));
// Load the actual legacy implementations without contacting any service.
foreach (['admin','tracker','identity','sender','cart'] as $legacy) { require_once MAGELLAN_PLUGIN_DIR.'includes/class-magellan-'.$legacy.'.php'; }
$encoded=base64_encode(rawurlencode(json_encode(['fs'=>'google','fc'=>'summer + flowers'])));
check('legacy cookie supports browser percent encoding',Magellan_Tracker::decode_cookie(rawurlencode($encoded))['fs']==='google');
check('legacy consent defaults to unknown',Magellan_Tracker::consent_state()==='unknown');
$ip=new ReflectionMethod(Magellan_Cart::class,'client_ip'); $ip->setAccessible(true); $_SERVER['REMOTE_ADDR']='192.0.2.10'; $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.1'; $_SERVER['HTTP_CF_CONNECTING_IP']='198.51.100.2'; check('rate limit ignores spoofable forwarded IP headers',$ip->invoke(null)==='192.0.2.10');
update_option('magellan_account_id','fixture_legacy_account'); update_option('magellan_signing_secret','fixture_legacy_secret');
$lost=wc_create_order(); $lost->update_meta_data('_mgln_event_scheduled',time()-1000); $lost->save(); Magellan_Sender::schedule_send($lost->get_id());
check('legacy missed-order recovery repairs a lost scheduled job',as_has_scheduled_action('magellan_send_verified_event',[$lost->get_id()],'magellan'));
file_put_contents(__DIR__.'/review-regressions-results.json',Protocol::json(['runtime'=>['wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'hpos'=>Admin::capabilities()['hpos']],'scope'=>'Disposable actual WordPress/WooCommerce/MySQL/Action Scheduler, synthetic transport','results'=>$results]));
file_put_contents(__DIR__.'/review-events.json',Protocol::json(array_map(static fn($b)=>json_decode($b),$wpdb->get_col("SELECT payload FROM $table WHERE payload<>''"))));
file_put_contents(__DIR__.'/review-byte-fixtures.json',Protocol::json(array_map(static fn($b)=>['json'=>$b,'sha256'=>hash('sha256',$b)],$wpdb->get_col("SELECT payload FROM $table WHERE payload<>''"))));
WP_CLI::success(count($results).' review regression checks passed.');
