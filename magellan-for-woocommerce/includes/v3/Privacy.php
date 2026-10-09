<?php
namespace Magellan\V3;
defined('ABSPATH') || exit;

final class Privacy {
    public static function init(): void {
        add_filter('wp_privacy_personal_data_exporters', static function ($exporters) { $exporters['magellan-v3'] = ['exporter_friendly_name' => 'Magellan website evidence', 'callback' => [self::class,'export']]; return $exporters; });
        add_filter('wp_privacy_personal_data_erasers', static function ($erasers) { $erasers['magellan-v3'] = ['eraser_friendly_name' => 'Magellan analytics links', 'callback' => [self::class,'erase']]; return $erasers; });
    }
    private static function orders(string $email, int $page): array { return wc_get_orders(['billing_email' => $email, 'limit' => 25, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'type' => 'shop_order']); }
    public static function export(string $email, int $page = 1): array {
        $orders = self::orders($email,$page); $data = [];
        foreach ($orders as $order) {
            $context = $order->get_meta('_mgln_v3_context'); if (!$context) { continue; }
            $data[] = ['group_id' => 'magellan-v3', 'group_label' => 'Magellan website evidence', 'item_id' => 'order-' . $order->get_id(), 'data' => [['name' => 'Order ID', 'value' => (string) $order->get_id()], ['name' => 'Website link and consent evidence', 'value' => Protocol::json($context)]]];
        }
        return ['data' => $data, 'done' => count($orders) < 25];
    }
    public static function erase(string $email, int $page = 1): array {
        global $wpdb; $orders = self::orders($email,$page); $ids = []; $visitors = []; $sessions = [];
        foreach ($orders as $order) {
            $ids[] = (string) $order->get_id(); $c = (array) $order->get_meta('_mgln_v3_context');
            if (!empty($c['visitor_id'])) { $visitors[] = $c['visitor_id']; }
            if (!empty($c['session_id'])) { $sessions[] = $c['session_id']; }
        }
        if (!$ids) { return ['items_removed' => false,'items_retained' => false,'messages' => [],'done' => true]; }
        $event = Outbox::capture('privacy_erasure_requested', ['request_id' => wp_generate_uuid4(), 'order_ids' => $ids, 'visitor_ids' => array_values(array_unique($visitors)), 'session_ids' => array_values(array_unique($sessions)), 'scope' => 'analytics'], null, [], null, 'privacy');
        if (!$event) { return ['items_removed'=>false,'items_retained'=>true,'messages'=>['Magellan erasure could not be queued. Reconnect and retry.'],'done'=>true]; }
        foreach ($orders as $order) {
            $order->delete_meta_data('_mgln_v3_context'); $order->update_meta_data('_mgln_v3_analytics_erased', gmdate('c')); $order->save_meta_data();
            $order->delete_meta_data('_mgln_v3_snapshot_hash'); $order->save_meta_data();
            foreach ($order->get_refunds() as $refund) { $refund->delete_meta_data('_mgln_v3_refund_hash'); $refund->save_meta_data(); }
            Recovery::mark($order->get_id(), 'privacy_recapture');
        }
        // This infrequent erasure scan also finds older alpha events that had no
        // entity_key (status/payment/cart), using complete serialized key/value pairs.
        $matches = [];
        foreach ($ids as $id) { $matches[] = $wpdb->prepare('payload LIKE %s', '%' . $wpdb->esc_like('"order_id":' . Protocol::json($id)) . '%'); }
        foreach (array_unique(array_merge($visitors, $sessions)) as $id) { $matches[] = $wpdb->prepare('payload LIKE %s', '%' . $wpdb->esc_like(Protocol::json($id)) . '%'); }
        $table = Outbox::table();
        $wpdb->query("UPDATE $table SET state='blocked',last_error='privacy_erasure_pending',lease_token='',lease_until=0 WHERE purpose<>'privacy' AND (" . implode(' OR ', $matches) . ')');
        self::process(); Outbox::schedule(1);
        return ['items_removed'=>true,'items_retained'=>true,'messages'=>['Local analytics links removed; queue erasure is processed in bounded batches. Orders and refunds are recaptured without analytics links. Backend erasure and replay suppression require Magellan processing; an intake receipt alone does not prove erasure completion.'],'done'=>count($orders)<25];
    }
    public static function process(): void {
        global $wpdb; $table = Outbox::table(); $quota = Outbox::quota();
        $rows = $wpdb->get_results("SELECT * FROM $table WHERE state='blocked' AND last_error='privacy_erasure_pending' ORDER BY seq LIMIT 100", ARRAY_A);
        foreach ($rows as $row) {
            $event = json_decode($row['payload'], true);
            if (!$event) { Outbox::remove_row($row); continue; }
            $type = $event['event_type'];
            if (in_array($type, ['order_snapshot','refund_recorded'], true)) {
                $id = (int) ($event['payload']['order_id'] ?? 0); $order = wc_get_order($id);
                if ($order) {
                    $order->delete_meta_data('_mgln_v3_context'); $order->update_meta_data('_mgln_v3_analytics_erased', gmdate('c'));
                    $order->delete_meta_data('_mgln_v3_snapshot_hash'); $order->save_meta_data();
                    foreach ($order->get_refunds() as $refund) { $refund->delete_meta_data('_mgln_v3_refund_hash'); $refund->save_meta_data(); }
                    Recovery::mark($id, 'privacy_recapture');
                }
            }
            if ((int) $row['accepted_at'] > 0 || !in_array($type, ['order_status_changed','payment_fact'], true)) { Outbox::remove_row($row); continue; }
            // Preserve the observed operational transition, with a NEW immutable event ID.
            $event['event_id'] = wp_generate_uuid4();
            $event['visitor_id'] = null; $event['session_id'] = null; $event['cart_id'] = null;
            $event['consent'] = Protocol::consent(); $event['consent']['analytics'] = 'denied'; $event['consent']['source'] = 'wordpress_privacy_erasure';
            $body = Protocol::json($event); $reserved = strlen($body) + 20;
            if ($reserved > (int) $row['reserved_bytes']) { Outbox::gap('privacy_redaction_size'); continue; }
            $changed = $wpdb->update($table, ['event_id' => $event['event_id'], 'payload' => $body, 'body_hash' => hash('sha256', $body), 'reserved_bytes' => $reserved, 'state' => get_option('magellan_v3_auth_blocked') ? 'blocked' : 'pending', 'next_attempt_at' => time(), 'attempts' => 0, 'last_error' => '', 'receipt' => null], ['seq' => $row['seq'], 'event_id' => $row['event_id'], 'state' => 'blocked', 'last_error' => 'privacy_erasure_pending']);
            if ($changed === 1) { $wpdb->query($wpdb->prepare("UPDATE $quota SET reserved_bytes=GREATEST(0,CAST(reserved_bytes AS SIGNED)-%d) WHERE id=1", (int) $row['reserved_bytes'] - $reserved)); }
        }
    }
}
