<?php
use Magellan\V3\{Config,Outbox,Recovery,Protocol,Admin};
if (!defined('WP_CLI') || !WP_CLI || wp_get_environment_type() !== 'local' || !str_contains(home_url(),'127.0.0.1:18783')) { throw new RuntimeException('Disposable local fixture required'); }
global $wpdb;$results=[];$check=static function($name,$pass) use (&$results) { $results[]=['test'=>$name,'pass'=>(bool)$pass];if(!$pass)throw new RuntimeException('FAILED: '.$name); };
$runtime=['wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'hpos'=>Admin::capabilities()['hpos']];
$orders=wc_get_orders(['limit'=>1,'paginate'=>true])->total;
$id=Outbox::capture('entity_deleted',['type'=>'page','id'=>'uninstall-fixture']);$check('fixture has a recoverable local event',(bool)$id);
define('WP_UNINSTALL_PLUGIN',true);delete_option('magellan_v3_uninstall_data');require MAGELLAN_PLUGIN_DIR.'uninstall.php';
$check('default uninstall retains queued evidence but removes credentials',(bool)$wpdb->get_var($wpdb->prepare('SELECT event_id FROM '.Outbox::table().' WHERE event_id=%s',$id)) && !get_option(Config::OPTION) && !get_option('magellan_signing_secret'));
update_option('magellan_v3_uninstall_data','delete');require MAGELLAN_PLUGIN_DIR.'uninstall.php';
$check('explicit uninstall preference deletes local v3 tables',!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like(Outbox::table()))) && !get_option('magellan_v3_db_version') && !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like(Recovery::table()))));
$check('uninstall never deletes WooCommerce orders',wc_get_orders(['limit'=>1,'paginate'=>true])->total===$orders);
file_put_contents(__DIR__.'/uninstall-regressions-results.json',Protocol::json(['runtime'=>$runtime,'scope'=>'Destructive uninstall tested only on disposable fixture','results'=>$results]));WP_CLI::success(count($results).' uninstall checks passed.');
