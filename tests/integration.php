<?php
/** Run only against a disposable WordPress/WooCommerce database with WP-CLI eval-file. */
use Magellan\V3\{Protocol,Config,Outbox,Capture,Admin,Privacy};
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || !str_contains(home_url(),'127.0.0.1:18783')) { throw new RuntimeException('Disposable local fixture required'); }
global $results; $results = [];
function check($name, $ok) { global $results; $results[]=['test'=>$name,'pass'=>(bool)$ok]; if (!$ok) { throw new RuntimeException('FAILED: '.$name); } }
global $wpdb;
Outbox::install(); $table=Outbox::table(); $wpdb->query('UPDATE '.Outbox::quota().' SET pending_rows=0,reserved_bytes=0'); $wpdb->query("TRUNCATE TABLE $table");
foreach (['magellan_v3_circuit','magellan_v3_auth_blocked','magellan_v3_budget','magellan_v3_capture_gap',Config::OPTION] as $key) { delete_option($key); }
$mode='challenge'; $requests=[]; $race_id=null;
add_filter('pre_http_request', function ($pre,$args,$url) use (&$mode,&$requests,&$race_id) {
    if (!str_starts_with($url,'https://example.com/magellan/')) { return $pre; }
    $requests[]=['url'=>$url,'args'=>$args]; $data=json_decode($args['body'] ?? '{}',true);
    if (str_ends_with($url,'challenge')) {
        return ['response'=>['code'=>200], 'headers'=>[], 'body'=>Protocol::json(['challenge'=>$data['challenge'],'installation_id'=>$data['installation_id'],'site_id'=>$data['site_id'],'environment'=>$data['environment'],'schema_version'=>'3.0.0','durable_intake_ready'=>true])];
    }
    $wire=json_decode($args['body'] ?? '{}');
    $code=$mode==='failure' ? 503 : ($mode==='auth' ? 401 : ($mode==='in_progress'||$mode==='conflict' ? 409 : 202)); $receipts=[];
    foreach (($data['events'] ?? []) as $index=>$event) {
        if ($mode==='failure'||$mode==='auth'||$mode==='in_progress'||($mode==='mixed'&&$index>0)) { continue; }
        if ($mode==='privacy_race' && $event['event_id']===$race_id) { global $wpdb; $wpdb->update(Outbox::table(),['state'=>'blocked','last_error'=>'privacy_erasure_pending'],['event_id'=>$race_id]); }
        $receipts[]=['event_id'=>$event['event_id'],'body_hash'=>$mode==='wrong_hash' ? str_repeat('0',64) : hash('sha256',Protocol::json($wire->events[$index])), 'status'=>$mode==='conflict' ? 'payload_conflict' : ($mode==='duplicate' ? 'duplicate' : 'accepted'), 'receipt_id'=>'receipt_'.$event['event_id'],'received_at'=>gmdate('c'),'code'=>null];
    }
    return ['response'=>['code'=>$code], 'headers'=>[], 'body'=>Protocol::json(['results'=>$receipts])];
},10,3);
$config=['site_id'=>'site_fixture','installation_id'=>'install_fixture','environment'=>'test','origin'=>Config::origin(),'events_url'=>'https://example.com/magellan/events','collect_url'=>'https://example.com/magellan/collect','challenge_url'=>'https://example.com/magellan/challenge','key_id'=>'fixture_1','signing_key'=>base64_encode(random_bytes(32)),'analytics_enabled'=>true];
check('reject short signing key',is_wp_error(Config::configure(array_merge($config,['signing_key'=>'YWJj']))));
check('reject local destination',is_wp_error(Config::configure(array_merge($config,['events_url'=>'http://127.0.0.1/']))));
check('reject clone origin',is_wp_error(Config::configure(array_merge($config,['origin'=>'https://other.example']))));
check('signed challenge before connected',!is_wp_error(Config::configure($config)) && Config::ready());
Capture::init(); Privacy::init();
$vectors=json_decode(file_get_contents(dirname(__DIR__).'/contracts/v3/fixtures/signatures.json'),true);
foreach ($vectors as $i=>$v) {
    $headers=Protocol::headers(['installation_id'=>$v['installation_id'],'key_id'=>'fixture','signing_key'=>$v['key_b64']],$v['method'],'https://example.com'.$v['target'],$v['body'],$v['timestamp'],$v['nonce']);
    check('signature vector '.$i,$headers['X-Magellan-Signature']===$v['signature']);
}
check('query pairs preserve duplicate keys',Protocol::target('https://example.com/a?cursor=a%20b&a=2&a=1')==='/a?a=1&a=2&cursor=a%20b');
foreach ([['1500.00','THB',2,'150000'],['123','JPY',0,'123'],['-1.234','KWD',3,'-1234'],['9007199254740993.00','USD',2,'900719925474099300']] as $v) { check('exact '.$v[1].' '.$v[0],Protocol::money($v[0],$v[1],$v[2])['amount_minor']===$v[3]); }
try { Protocol::money('1.001','THB',2); check('reject precision ambiguity',false); } catch (InvalidArgumentException $e) { check('reject precision ambiguity',true); }
$visitor=wp_generate_uuid4(); $session=wp_generate_uuid4();
$_COOKIE['_mgln_v3_context_site_fixture']=wp_slash(Protocol::json(['site_id'=>'site_fixture','visitor_id'=>$visitor,'session_id'=>$session,'updated_at'=>time(),'consent'=>array_merge(Protocol::consent(),['analytics'=>'granted','source'=>'fixture']), 'entry'=>['path'=>'/flowers/?token=private','source_evidence'=>['utm'=>['source'=>'google'],'click_ids'=>[],'referrer_domain'=>'www.google.com']]]));
$product=new WC_Product_Simple(); $product->set_name('Fixture flower'); $product->set_regular_price('100.00'); $product->save();
$order=wc_create_order(); $order->set_currency('THB'); $order->set_billing_email('privacy-fixture@example.invalid'); $order->add_product($product,2); $order->set_payment_method('bacs'); Capture::stamp($order); $order->calculate_totals(); $order->save(); Capture::flush();
$rows=$wpdb->get_results("SELECT * FROM $table WHERE entity_key='order:{$order->get_id()}'",ARRAY_A);
check('real WooCommerce order captured once',count($rows)===1);
$event=json_decode($rows[0]['payload'],true);
check('exact observed total and line IDs',$event['payload']['amounts']['total']['amount_minor']==='20000' && count($event['payload']['lines'])===1);
check('checkout context frozen on order',$event['session_id']===$session && $event['payload']['checkout_context']['entry']['path']==='/flowers/');
Capture::snapshot($order->get_id()); check('unchanged source revision deduplicated',(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE entity_key='order:{$order->get_id()}'")===1);
unset($_COOKIE['_mgln_v3_context_site_fixture']);
$refund=wc_create_refund(['order_id'=>$order->get_id(),'amount'=>'25.00','reason'=>'fixture','refund_payment'=>false,'restock_items'=>false]);
check('real Woo refund created',!is_wp_error($refund)); Capture::flush();
$refundRow=$wpdb->get_row("SELECT * FROM $table WHERE entity_key='refund:{$refund->get_id()}'",ARRAY_A);
check('refund exists before first purchase receipt',is_array($refundRow) && $refundRow['state']==='pending');
check('refund keeps saved session after browser leaves',json_decode($refundRow['payload'],true)['session_id']===$session);
$order=wc_get_order($order->get_id()); $order->update_status('cancelled'); Capture::flush();
check('cancellation captured independently',(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE payload LIKE '%\"event_type\":\"order_status_changed\"%'")>=1);
$before=$wpdb->get_results("SELECT event_id,body_hash,payload FROM $table ORDER BY seq",ARRAY_A);
$mode='failure'; Outbox::drain();
check('503 retained for explicit retry',(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE state='pending' AND attempts>0")>0);
check('retry keeps exact immutable bytes',$before===$wpdb->get_results("SELECT event_id,body_hash,payload FROM $table ORDER BY seq",ARRAY_A));
function due() { global $wpdb; $table=Outbox::table(); delete_option('magellan_v3_circuit'); $wpdb->query("UPDATE $table SET next_attempt_at=0 WHERE state='pending'"); }
due(); $mode='in_progress'; Outbox::drain(); check('409 in progress is not accepted',(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE state='accepted'")===0);
due(); $mode='wrong_hash'; Outbox::drain(); check('acknowledgement requires matching body hash',(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE state='accepted'")===0);
due(); $mode='mixed'; Outbox::drain(); check('mixed receipt accepts only named event',(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE state='accepted'")===1);
due(); $mode='duplicate'; Outbox::drain(); check('completed duplicate receipts settle remaining queue',(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE state<>'accepted'")===0);
$id=Outbox::capture('entity_deleted',['type'=>'page','id'=>'99']); due(); $mode='conflict'; Outbox::drain(); check('payload conflict quarantined',$wpdb->get_var($wpdb->prepare("SELECT state FROM $table WHERE event_id=%s",$id))==='dead_letter');
$id=Outbox::capture('entity_deleted',['type'=>'page','id'=>'100']); due(); $mode='auth'; Outbox::drain(); check('auth failure pauses installation',get_option('magellan_v3_auth_blocked') && $wpdb->get_var($wpdb->prepare("SELECT state FROM $table WHERE event_id=%s",$id))==='blocked');
$new=Outbox::capture('entity_deleted',['type'=>'page','id'=>'101']); check('capture continues without auth hammering',$wpdb->get_var($wpdb->prepare("SELECT state FROM $table WHERE event_id=%s",$new))==='blocked');
check('rebind with unresolved events rejected',is_wp_error(Config::configure(array_merge($config,['installation_id'=>'different_installation']))));
$mode='challenge'; check('key rotation resumes blocked delivery',!is_wp_error(Config::configure(array_merge($config,['key_id'=>'fixture_2','signing_key'=>base64_encode(random_bytes(32))]))) && !get_option('magellan_v3_auth_blocked'));
check('status never exposes key',!str_contains(Protocol::json(Admin::status()),'signing_key'));
check('optional site reading is explicitly disabled',Admin::capabilities()['site.read']===false);
$stored=Config::get(); update_option(Config::OPTION,array_merge($stored,['origin'=>'https://cloned.example'])); check('cloned site fails closed',!Config::ready()); update_option(Config::OPTION,$stored);
$export=Privacy::export('privacy-fixture@example.invalid'); check('WordPress privacy export finds links',count($export['data'])>=1);
$erasure=Privacy::erase('privacy-fixture@example.invalid'); check('erasure queued without deleting canonical finance',$erasure['items_removed'] && wc_get_order($order->get_id())->get_total()==='200.00');
check('local analytics link removed',!wc_get_order($order->get_id())->get_meta('_mgln_v3_context'));
// Real WooCommerce cart state and lifecycle; browser amounts/IDs cannot enter the source payload.
wc_load_cart(); WC()->cart->empty_cart(); WC()->cart->add_to_cart($product->get_id(), 2); WC()->cart->calculate_totals(); Capture::cart();
$cart1=Capture::cart_id(false);
$cartEvent=json_decode($wpdb->get_var("SELECT payload FROM $table WHERE payload LIKE '%\"event_type\":\"cart_snapshot\"%' ORDER BY seq DESC LIMIT 1"),true);
check('cart snapshot reads real WooCommerce basket',$cartEvent['payload']['items'][0]['quantity']==='2' && $cartEvent['payload']['merchandise_total']['amount_minor']==='20000');
WC()->cart->empty_cart(); Capture::cart();
check('last item removal emits explicit empty cart',(int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE payload LIKE '%\"event_type\":\"cart_emptied\"%'")>=1);
WC()->cart->add_to_cart($product->get_id(),1); WC()->cart->calculate_totals(); Capture::cart();
check('new basket has independent lifecycle',Capture::cart_id(false)!==$cart1);
$blocks=wc_create_order(); $blocks->add_product($product,1); do_action('woocommerce_store_api_checkout_update_order_from_request',$blocks,new WP_REST_Request()); $blocks->save(); do_action('woocommerce_store_api_checkout_order_processed',$blocks); Capture::flush();
check('Blocks hook stamps server-bound cart',(bool)wc_get_order($blocks->get_id())->get_meta('_mgln_v3_context')['cart_id']);
$blocks->payment_complete('test_provider_transaction'); Capture::flush();
$payment=json_decode($wpdb->get_var("SELECT payload FROM $table WHERE payload LIKE '%\"event_type\":\"payment_fact\"%' ORDER BY seq DESC LIMIT 1"),true);
check('payment callback is labeled channel evidence',$payment['payload']['settlement_verified']===false && $payment['payload']['transaction_id']==='test_provider_transaction');
$large=wc_create_order(); for($i=0;$i<51;$i++){ $large->add_product($product,1); } $large->calculate_totals(); $large->save(); Capture::flush();
$largeEvents=$wpdb->get_col($wpdb->prepare("SELECT payload FROM $table WHERE entity_key=%s",'order:'.$large->get_id()));
$largeEvents=array_map(static fn($body)=>json_decode($body,true),$largeEvents);
check('large order snapshot paginated without duplicated lines',count($largeEvents)===2 && count($largeEvents[0]['payload']['lines'])===50 && count($largeEvents[1]['payload']['lines'])===1 && $largeEvents[0]['payload']['snapshot_id']===$largeEvents[1]['payload']['snapshot_id']);
$race_id=Outbox::capture('entity_deleted',['type'=>'page','id'=>'privacy_race']); due(); $mode='privacy_race'; Outbox::drain();
check('late receipt cannot release a concurrent privacy hold',$wpdb->get_var($wpdb->prepare("SELECT state FROM $table WHERE event_id=%s",$race_id))==='blocked');
$quota=Outbox::quota(); $q=$wpdb->get_row("SELECT * FROM $quota WHERE id=1",ARRAY_A);
$wpdb->query("UPDATE $quota SET pending_rows=50000 WHERE id=1");
$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
check('capacity saturation preserves existing queue',Outbox::capture('entity_deleted',['type'=>'page','id'=>'capacity'])===null && (int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$count);
$wpdb->update($quota,['pending_rows'=>$q['pending_rows']],['id'=>1]);
$mode='accepted'; due(); Outbox::drain();
$leaseId=Outbox::capture('entity_deleted',['type'=>'page','id'=>'lease']);
$wpdb->query($wpdb->prepare("UPDATE $table SET state='leased',lease_token='crashed_worker',lease_until=1 WHERE event_id=%s",$leaseId));
Outbox::drain(); check('expired worker lease recovered',$wpdb->get_var($wpdb->prepare("SELECT state FROM $table WHERE event_id=%s",$leaseId))==='accepted');
$fixture_events=$wpdb->get_results("SELECT payload FROM $table WHERE payload<>'' ORDER BY seq",ARRAY_A);
$before=$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE state='accepted'");
$wpdb->query("UPDATE $table SET accepted_at=1 WHERE state='accepted'");
update_option('magellan_v3_next_health',time()+10000); Outbox::maintenance();
check('bounded cleanup prunes only accepted events',(int)$before>0 && (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE state='accepted'")===0 && (int)$wpdb->get_var("SELECT COUNT(*) FROM $table WHERE state='dead_letter'")>=1);
$times=[]; for($i=0;$i<100;$i++){ $begin=microtime(true); Outbox::capture('entity_deleted',['type'=>'page','id'=>'perf_'.$i]); $times[]=(microtime(true)-$begin)*1000; } sort($times);
// Process exactly our action using Action Scheduler's real execution path.
as_unschedule_all_actions(Outbox::HOOK, [], 'magellan-v3');
$scheduled=as_schedule_single_action(time()-1,Outbox::HOOK,[],'magellan-v3',true);
$mode='failure'; due(); ActionScheduler_QueueRunner::instance()->process_action($scheduled,'magellan-fixture');
check('failed drain schedules its successor after AS completion',ActionScheduler::store()->get_status($scheduled)==='complete' && as_has_scheduled_action(Outbox::HOOK,[],'magellan-v3'));
wp_set_current_user(0); check('anonymous configuration denied',Admin::permission()===false);
wp_set_current_user(1); check('authorized administrator can inspect diagnostics',Admin::permission()===true); wp_set_current_user(0);
file_put_contents(dirname(__DIR__).'/tests/capture-performance.json',wp_json_encode(['fixture'=>'100 small event captures, isolated local MySQL; not checkout/Core Web Vitals certification','p50_ms'=>$times[49],'p95_ms'=>$times[94],'max_ms'=>$times[99]],JSON_PRETTY_PRINT));
$all=array_merge($fixture_events,$wpdb->get_results("SELECT payload FROM $table WHERE payload<>'' ORDER BY seq",ARRAY_A));
file_put_contents(dirname(__DIR__).'/tests/event-byte-fixtures.json',Protocol::json(array_map(static fn($row)=>['json'=>$row['payload'],'sha256'=>hash('sha256',$row['payload'])],$all)));
file_put_contents(dirname(__DIR__).'/tests/captured-events.json',Protocol::json(array_map(static fn($row)=>json_decode($row['payload']),$all)));
file_put_contents(dirname(__DIR__).'/tests/integration-results.json',wp_json_encode(['runtime'=>['wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'php'=>PHP_VERSION,'mysql'=>$wpdb->db_version(),'hpos'=>Admin::capabilities()['hpos']],'transport'=>'mock responses; real WP/Woo/MySQL storage and hooks','results'=>$results],JSON_PRETTY_PRINT));
WP_CLI::success(count($results).' integration checks passed.');
