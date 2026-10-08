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
            // Immutable queued bodies are not rewritten: privacy tombstone suppresses replay at backend.
            $table = Outbox::table(); $wpdb->query($wpdb->prepare("UPDATE $table SET state='blocked',last_error='privacy_erasure_pending' WHERE entity_key=%s AND state IN ('pending','leased','dead_letter')", 'order:' . $order->get_id()));
        }
        return ['items_removed'=>true,'items_retained'=>true,'messages'=>['Local analytics links removed. Commerce evidence is retained; backend erasure and replay suppression require the Magellan privacy receipt.'],'done'=>count($orders)<25];
    }
}
