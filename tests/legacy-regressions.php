<?php
use Magellan\V3\{Protocol,Config,Outbox,Capture,Admin,Browser};
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || !str_contains(home_url(),'127.0.0.1:18783') || !str_starts_with(MAGELLAN_ENDPOINT_IDENTS,'https://example.com/')) { throw new RuntimeException('Disposable local fixture with synthetic legacy endpoint required'); }
$results=[]; $check=static function($name,$pass) use (&$results) { $results[]=['test'=>$name,'pass'=>(bool)$pass]; if (!$pass) { throw new RuntimeException('FAILED: '.$name); } };
update_option('magellan_signing_secret',base64_encode(random_bytes(32)));
add_filter('pre_wp_mail',static fn()=>true);
$sent=[];$mode='accepted';
add_filter('pre_http_request',static function($pre,$args,$url) use (&$sent,&$mode) {
    $sent[]=['url'=>$url,'body'=>json_decode($args['body']??'{}',true),'redirection'=>$args['redirection']??null];
    return ['response'=>['code'=>$mode==='failure'?503:200],'headers'=>[],'body'=>'{}'];
},999,3);
for($n=0;$n<102;$n++) { $login='magellan_review_'.$n; if (!username_exists($login)) { $u=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.invalid','role'=>'customer','user_pass'=>wp_generate_password()]); } }
if (!username_exists('magellan_review_subscriber')) { wp_insert_user(['user_login'=>'magellan_review_subscriber','user_email'=>'excluded-subscriber@example.invalid','role'=>'subscriber','user_pass'=>wp_generate_password()]); }
delete_option('magellan_identity_sync_cursor'); Magellan_Identity::run_historical_sync();
$identities=array_values(array_filter($sent,static fn($s)=>str_ends_with($s['url'],'/identities')));
$check('historical identity sync sends one bounded customer page',count($identities)===1 && count($identities[0]['body']['identities'])===100 && get_option('magellan_identity_sync_cursor')['batch']===2);
$check('historical customer sync excludes subscriber-only users',!str_contains(Protocol::json($identities),Magellan_Identity::hash_email('excluded-subscriber@example.invalid')));
$cursor=get_option('magellan_identity_sync_cursor');$mode='failure';Magellan_Identity::run_historical_sync();
$check('failed identity batch retains the exact source cursor',get_option('magellan_identity_sync_cursor')===$cursor);
$mode='accepted';Magellan_Identity::run_historical_sync();
$check('retried identity batch advances after acknowledgement',(get_option('magellan_identity_sync_cursor')['batch']??3)===3);
$sent=[]; $order=wc_create_order(); $order->set_total('10'); $order->save(); Magellan_Sender::send_verified_event($order->get_id());
$check('operational order still sends without analytics consent',count($sent)===1 && $sent[0]['body']['event_type']==='order_placed' && $sent[0]['body']['context']['consent_state']==='unknown');
$check('legacy signed requests do not follow redirects',$sent[0]['redirection']===0);
$before=get_option('magellan_account_id');$req=new WP_REST_Request('POST');$req->set_header('content-type','application/json');$req->set_body(Protocol::json(['account_id'=>'mgln_test_abcdefghjkmnpq','signing_secret'=>base64_encode(random_bytes(32)),'api_base'=>'http://example.com/pixel']));
$check('insecure legacy configure rejected before changing settings',is_wp_error(Magellan_Admin::handle_configure($req)) && get_option('magellan_account_id')===$before);
// A block-theme checkout can have no block in the post itself.
$page=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Checkout template fixture','post_content'=>'']); $oldCheckout=get_option('woocommerce_checkout_page_id'); update_option('woocommerce_checkout_page_id',$page);
global $wp_query; $oldQuery=$wp_query; $wp_query=new WP_Query(['page_id'=>$page]);
// Simulate a frontend request: Woo 11 only resolves page conditions after the wp action.
do_action('wp');
// Clear request-local memoization from the preceding CLI checks.
$utils='Automattic\\WooCommerce\\Blocks\\Utils\\CartCheckoutUtils';
if (property_exists($utils,'is_checkout_page')) { $cached=new ReflectionProperty($utils,'is_checkout_page'); $cached->setAccessible(true); $cached->setValue(null,null); }
Browser::enqueue(); $deps=wp_scripts()->registered['magellan-v3-checkout']->deps??[];

$check('template checkout declares jQuery and Store API dependencies',in_array('jquery',$deps,true)&&in_array('wp-api-fetch',$deps,true));
$wp_query=$oldQuery;update_option('woocommerce_checkout_page_id',$oldCheckout);wp_delete_post($page,true);
// Verify accepted-row cleanup and explicit dead-letter disposal release the reservation.
global $wpdb;$table=Outbox::table();$quota=Outbox::quota();
$beforeBytes=(int)$wpdb->get_var("SELECT reserved_bytes FROM $quota WHERE id=1");$id=Outbox::capture('entity_deleted',['type'=>'page','id'=>'disposal-fixture']);
$wpdb->update($table,['state'=>'dead_letter','last_error'=>'fixture_rejected'],['event_id'=>$id]);
$export=WP_CLI::runcommand('magellan queue export',['return'=>'stdout','exit_error'=>true]);
$check('queue export is bounded JSONL and contains event',str_contains($export,$id)&&count(explode("\n",trim($export)))<=500);
WP_CLI::runcommand('magellan queue discard --event-id='.$id.' --confirm-discard='.$id,['return'=>'stdout','exit_error'=>true]);
$check('explicit discard removes one dead letter and releases bytes',!$wpdb->get_var($wpdb->prepare("SELECT event_id FROM $table WHERE event_id=%s",$id)) && (int)$wpdb->get_var("SELECT reserved_bytes FROM $quota WHERE id=1")===$beforeBytes);
$wpdb->query('TRUNCATE TABLE '.\Magellan\V3\Recovery::table());$gap=get_option('magellan_v3_capture_gap');
WP_CLI::runcommand('magellan acknowledge-gap --gap-id='.$gap['id'].' --note=disposable-fixture-reviewed',['return'=>'stdout','exit_error'=>true]);wp_cache_delete('magellan_v3_capture_gap','options');wp_cache_delete('notoptions','options');
$check('capture-gap acknowledgement clears alarm with a retained review',!get_option('magellan_v3_capture_gap') && get_option('magellan_v3_last_gap_review'));
file_put_contents(__DIR__.'/legacy-regressions-results.json',Protocol::json(['runtime'=>['wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'hpos'=>Admin::capabilities()['hpos']],'scope'=>'Actual WordPress/WooCommerce, all HTTP/mail intercepted','results'=>$results]));
WP_CLI::success(count($results).' legacy and operator regression checks passed.');
