<?php
// Standalone contract tests. No WordPress database, network, or merchant site is used.
namespace Magellan\V3 {
    final class Outbox { public static function unresolved() { return 0; } public static function resume() {} }
}
namespace {
    define('ABSPATH', __DIR__);
    $options = []; $echo_policy = true; $checks = 0;
    function get_option($key, $default = []) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
    function delete_option($key) { unset($GLOBALS['options'][$key]); }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function home_url() { return 'https://shop.example'; }
    function wp_get_environment_type() { return 'local'; }
    function apply_filters($name, $value) { return $value; }
    function wp_http_validate_url($url) { return $url; }
    function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
    function wp_unslash($value) { return $value; }
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
    function wp_generate_uuid4() { return 'c5469c62-dda9-4c3d-b588-77c24bf55558'; }
    class WP_Error { public function __construct(public $code, public $message, public $data = []) {} }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function wp_safe_remote_post($url, $args) {
        $reply = json_decode($args['body'], true); $reply['durable_intake_ready'] = true;
        if (!$GLOBALS['echo_policy']) unset($reply['analytics_policy']);
        return ['code' => 200, 'body' => json_encode($reply)];
    }
    function wp_remote_retrieve_body($value) { return $value['body']; }
    function wp_remote_retrieve_response_code($value) { return $value['code']; }
    function check($condition, $description) {
        if (!$condition) throw new \RuntimeException($description);
        $GLOBALS['checks']++; echo "PASS $description\n";
    }
    foreach (['Config','Protocol','Capture'] as $name) require __DIR__.'/../magellan-for-woocommerce/includes/v3/'.$name.'.php';
    $input = ['site_id'=>'fixture_site','installation_id'=>'fixture_install','key_id'=>'fixture_key',
        'signing_key'=>base64_encode(str_repeat('x',32)), 'environment'=>'test','origin'=>home_url(),
        'events_url'=>'https://collector.example/events','collect_url'=>'https://collector.example/collect',
        'challenge_url'=>'https://collector.example/challenge','analytics_enabled'=>true,'collection_mode'=>'measurement_only'];
    $default = \Magellan\V3\Config::validate($input);
    check($default['analytics_policy']==='consent_required', 'default configuration retains the consent requirement');
    $input['analytics_policy']='store_enabled'; $echo_policy=false;
    check(is_wp_error(\Magellan\V3\Config::configure($input)), 'old receiver cannot silently activate store analytics');
    check(empty($options), 'failed policy agreement leaves settings unchanged');
    $echo_policy=true;
    check(!is_wp_error(\Magellan\V3\Config::configure($input)), 'matching signed policy challenge activates store setting');
    $consent = array_merge(\Magellan\V3\Protocol::consent(), ['analytics'=>'not_applicable','source'=>'store_policy','advertising'=>'denied']);
    $cookie = ['site_id'=>'fixture_site','visitor_id'=>wp_generate_uuid4(),'session_id'=>wp_generate_uuid4(),
        'updated_at'=>time(),'consent'=>$consent,'entry'=>['path'=>'/product/','source_evidence'=>['click_ids'=>['gclid'=>'must_discard']]]];
    $_COOKIE['_mgln_v3_context_fixture_site']=json_encode($cookie);
    $context=\Magellan\V3\Capture::context();
    check($context['visitor_id']===$cookie['visitor_id'], 'checkout retains a valid store-policy session link');
    check($context['consent']['analytics']==='not_applicable'&&$context['consent']['source']==='browser_asserted:store_policy', 'checkout preserves provenance without inventing visitor consent');
    check(empty($context['entry']['source_evidence']['click_ids']), 'checkout discards unapproved advertising identifiers');
    foreach ([['analytics'=>'denied'],['analytics'=>'unknown'],['gpc'=>true],['source'=>'unavailable']] as $change) {
        $bad=$cookie; $bad['consent']=array_merge($consent,$change); $_COOKIE['_mgln_v3_context_fixture_site']=json_encode($bad);
        check(\Magellan\V3\Capture::context()['visitor_id']===null, 'checkout refuses '.json_encode($change));
    }
    $options[\Magellan\V3\Config::OPTION]['analytics_policy']='consent_required';
    $_COOKIE['_mgln_v3_context_fixture_site']=json_encode($cookie);
    check(\Magellan\V3\Capture::context()['visitor_id']===null, 'policy rollback refuses a retained store-policy cookie');
    $input['analytics_policy']='invalid';
    try { \Magellan\V3\Config::validate($input); throw new \RuntimeException('invalid policy accepted'); }
    catch (\InvalidArgumentException $e) { check($e->getMessage()==='invalid_analytics_policy','unknown policies fail closed'); }
    echo "$checks standalone PHP contract checks passed\n";
}
