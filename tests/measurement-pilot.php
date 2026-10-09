<?php
/** Run only after integration.php in the disposable local WordPress fixture. */
use Magellan\V3\{Config,Protocol,Admin};
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || !str_contains(home_url(),'127.0.0.1:18783')) { throw new RuntimeException('Disposable local fixture required'); }
$results=[];
$check=static function($name,$pass) use (&$results) { $results[]=['test'=>$name,'pass'=>(bool)$pass]; if (!$pass) { throw new RuntimeException('FAILED: '.$name); } };
$original=Config::get();
if (!Config::ready()) { throw new RuntimeException('Run integration.php first'); }
$config=array_merge($original,['collection_mode'=>'measurement_only']);
$legacyOptions=['magellan_api_base','magellan_account_id','magellan_signing_secret'];
// A configured old feed is the important case; empty settings do not prove it.
foreach (array_combine($legacyOptions,['https://example.invalid/api/v1/pixel','fixture_legacy_account','fixture_legacy_secret']) as $option=>$value) { update_option($option,$value); }
$before=array_map('get_option',$legacyOptions);
$responseMode=null;
add_filter('pre_wp_mail',static fn()=>true);
add_filter('pre_http_request',static function($pre,$args,$url) use (&$responseMode) {
    if ($url!=='https://example.com/magellan/challenge') { return new WP_Error('fixture_network_disabled','No real network in this fixture'); }
    $body=json_decode($args['body'],true);
    $r=['schema_version'=>'3.0.0','installation_id'=>$body['installation_id'],'site_id'=>$body['site_id'],'environment'=>$body['environment'],'origin'=>$body['origin'],'challenge'=>$body['challenge'],'durable_intake_ready'=>true];
    if ($responseMode!==null) { $r['collection_mode']=$responseMode; }
    return ['response'=>['code'=>200],'headers'=>[],'body'=>Protocol::json($r)];
},99,3);
$check('unrecognized pilot mode rejected',is_wp_error(Config::configure(array_merge($config,['collection_mode'=>'unknown']))));
$check('old receiver cannot accidentally activate measurement mode',is_wp_error(Config::configure($config)) && Config::get()===$original);
$responseMode='full';
$check('receiver mode mismatch leaves prior connection unchanged',is_wp_error(Config::configure($config)) && Config::get()===$original);
wp_schedule_single_event(time()+3600,'magellan_health_check');
$healthBefore=wp_next_scheduled('magellan_health_check');
$action=as_schedule_single_action(time()+3600,'magellan_send_verified_event',[999999999],'magellan');
$responseMode='measurement_only';
$check('explicit matching mode connects',!is_wp_error(Config::configure($config)) && Config::ready() && Config::measurement_only());
$check('pilot retains legacy boot path',Config::legacy_enabled());
$check('pilot preserves legacy scheduled health',wp_next_scheduled('magellan_health_check')===$healthBefore);
$check('pilot preserves queued legacy order dispatch',ActionScheduler::store()->get_status($action)==='pending');
$check('pilot preserves legacy connection settings',array_map('get_option',$legacyOptions)===$before);
$check('status distinguishes measurement and legacy coexistence',Admin::status()['mode']==='v3_measurement_with_legacy' && Admin::status()['legacy_enabled']===true);
file_put_contents(dirname(__DIR__).'/tests/measurement-pilot-results.json',wp_json_encode(['runtime'=>['wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'hpos'=>Admin::capabilities()['hpos']],'scope'=>'Real WP/Woo/Action Scheduler with synthetic challenge; follow with fresh-process boot assertion','results'=>$results],JSON_PRETTY_PRINT));
// Leave the pilot configuration for the runner's fresh-process boot assertion.
as_unschedule_action('magellan_send_verified_event',[999999999],'magellan');
WP_CLI::success(count($results).' measurement pilot checks passed.');
