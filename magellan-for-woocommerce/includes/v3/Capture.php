<?php
namespace Magellan\V3;
defined('ABSPATH') || exit;

final class Capture {
    private static array $orders = [];
    private static array $entities = [];
    private static bool $cart_dirty = false;
    private static bool $saving = false;
    public static function init(): void {
        foreach (['woocommerce_new_order','woocommerce_update_order'] as $hook) { add_action($hook, [self::class, 'dirty_order'], 30); }
        add_action('woocommerce_checkout_create_order', [self::class, 'stamp'], 20);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [self::class, 'stamp'], 20);
        add_action('woocommerce_checkout_order_created', [self::class, 'converted'], 30);
        add_action('woocommerce_store_api_checkout_order_processed', [self::class, 'converted'], 30);
        add_action('woocommerce_order_status_changed', [self::class, 'status'], 30, 4);
        add_action('woocommerce_payment_complete', [self::class, 'payment'], 30);
        add_action('woocommerce_order_refunded', [self::class, 'refund'], 30, 2);
        foreach (['woocommerce_add_to_cart','woocommerce_cart_item_removed','woocommerce_after_cart_item_quantity_update','woocommerce_cart_emptied','woocommerce_cart_updated'] as $hook) { add_action($hook, [self::class, 'dirty_cart'], 30); }
        add_action('save_post', [self::class, 'dirty_entity'], 30, 2);
        add_action('before_delete_post', [self::class, 'deleted'], 10, 2);
        add_action('shutdown', [self::class, 'flush'], 5);
    }
    public static function dirty_order($id): void { if (!self::$saving) { self::$orders[(int) $id] = true; } }
    public static function dirty_cart(): void { self::$cart_dirty = true; }
    public static function saved_context($order): array { $context = $order->get_meta('_mgln_v3_context'); return is_array($context) ? $context : []; }
    public static function context(): array {
        $base = ['visitor_id' => null, 'session_id' => null, 'cart_id' => self::cart_id(false), 'consent' => Protocol::consent(), 'link_provenance' => 'unavailable'];
        if (!Config::analytics()) { return $base; }
        $cookie_name = '_mgln_v3_context_' . (Config::get()['site_id'] ?? '');
        $raw = isset($_COOKIE[$cookie_name]) ? wp_unslash($_COOKIE[$cookie_name]) : '';
        if (!is_string($raw) || strlen($raw) > 3800) { return $base; }
        $c = json_decode($raw, true);
        if (!is_array($c) || ($c['consent']['analytics'] ?? '') !== 'granted' || ($c['site_id'] ?? '') !== (Config::get()['site_id'] ?? '') || !is_numeric($c['updated_at'] ?? null) || abs(time() - (int) $c['updated_at']) > 1800) { return $base; }
        foreach (['analytics','advertising','email_marketing','sms_marketing'] as $purpose) {
            $v = $c['consent'][$purpose] ?? 'unknown'; $base['consent'][$purpose] = in_array($v, ['granted','denied','unknown','not_applicable'], true) ? $v : 'unknown';
        }
        $base['consent']['source'] = 'browser_asserted:' . substr(sanitize_key($c['consent']['source'] ?? 'custom'), 0, 64);
        $base['consent']['epoch'] = max(0, (int) ($c['consent']['epoch'] ?? 0));
        $base['consent']['policy_version'] = substr(sanitize_key($c['consent']['policy_version'] ?? 'unknown'), 0, 64);
        $base['consent']['gpc'] = !empty($c['consent']['gpc']);
        foreach (['visitor_id','session_id'] as $key) { $base[$key] = Protocol::id($c[$key] ?? null); }
        $base['link_provenance'] = 'browser_asserted_checkout_cookie';
        $base['checkout_attempt_id'] = Protocol::id($c['checkout_attempt_id'] ?? null);
        $base['entry'] = self::source($c['entry'] ?? []);
        if ($base['consent']['advertising'] !== 'granted') { $base['entry']['source_evidence']['click_ids'] = []; }
        return $base;
    }
    private static function source($source): array {
        $out = ['path' => null, 'source_evidence' => ['utm' => [], 'click_ids' => [], 'click_id_types' => [], 'referrer_domain' => null]];
        if (!is_array($source)) { return $out; }
        $path = wp_parse_url((string) ($source['path'] ?? ''), PHP_URL_PATH);
        $out['path'] = $path ? substr($path, 0, 512) : null;
        foreach (['utm' => ['source','medium','campaign','content','term'], 'click_ids' => ['gclid','gbraid','wbraid','fbclid','msclkid','ttclid','twclid']] as $group => $keys) {
            foreach ($keys as $key) { $v = $source['source_evidence'][$group][$key] ?? ''; if (is_string($v) && preg_match('/^[\pL\pN _.,:+\/=-]{1,128}$/uD', $v)) { $out['source_evidence'][$group][$key] = $v; } }
        }
        $types = $source['source_evidence']['click_id_types'] ?? [];
        if (is_array($types)) { $out['source_evidence']['click_id_types'] = array_values(array_intersect($types, ['gclid','gbraid','wbraid','fbclid','msclkid','ttclid','twclid'])); }
        $domain = $source['source_evidence']['referrer_domain'] ?? '';
        if (is_string($domain) && preg_match('/^[a-z0-9.-]{1,253}$/D', $domain)) { $out['source_evidence']['referrer_domain'] = $domain; }
        return $out;
    }
    public static function cart_id(bool $create = true): ?string {
        if (!function_exists('WC') || !WC()->session) { return null; }
        $id = WC()->session->get('magellan_v3_cart_id');
        if (!$id && $create) { $id = wp_generate_uuid4(); WC()->session->set('magellan_v3_cart_id', $id); }
        return Protocol::id($id);
    }
    public static function stamp($order): void {
        if (!$order instanceof \WC_Order) { return; }
        if (!$order->get_meta('_mgln_v3_context') && !$order->get_meta('_mgln_v3_analytics_erased')) {
            self::cart_id(); $order->update_meta_data('_mgln_v3_context', self::context());
            $order->update_meta_data('_mgln_v3_origin', 'web_checkout');
            $order->update_meta_data('_mgln_v3_currency_exponent', self::exponent($order->get_currency()));
        }
    }
    public static function converted($order): void {
        if (!$order instanceof \WC_Order) { $order = wc_get_order($order); }
        if (!$order) { return; }
        self::stamp($order);
        self::$saving = true; $order->save_meta_data(); self::$saving = false;
        $context = self::saved_context($order);
        if (!empty($context['cart_id']) && !$order->get_meta('_mgln_v3_cart_converted')) {
            if (Outbox::capture('cart_converted', ['cart_id' => $context['cart_id'], 'order_id' => (string) $order->get_id(), 'lifecycle' => 'converted'], null, $context)) {
                $order->update_meta_data('_mgln_v3_cart_converted', true); $order->save_meta_data();
                if (WC()->session) { WC()->session->set('magellan_v3_cart_id', null); WC()->session->set('magellan_v3_cart_hash', null); }
            }
        }
        self::dirty_order($order->get_id());
    }
    public static function exponent(string $currency): int {
        $zero = ['BIF','CLP','DJF','GNF','ISK','JPY','KMF','KRW','PYG','RWF','UGX','UYI','VND','VUV','XAF','XOF','XPF'];
        $three = ['BHD','IQD','JOD','KWD','LYD','OMR','TND'];
        $exp = in_array($currency, $zero, true) ? 0 : (in_array($currency, $three, true) ? 3 : (in_array($currency, ['CLF','UYW'], true) ? 4 : 2));
        return (int) apply_filters('magellan_v3_currency_exponent', $exp, $currency);
    }
    public static function date($date): ?string { return $date ? gmdate('c', $date->getTimestamp()) : null; }
    public static function lines($items, string $currency, int $exp): array {
        $lines = [];
        foreach ($items as $item) {
            $product = $item->get_product();
            $lines[] = ['line_id' => (string) $item->get_id(), 'refunded_line_id' => $item->get_meta('_refunded_item_id') ? (string) $item->get_meta('_refunded_item_id') : null, 'product_id' => (string) $item->get_product_id(), 'variation_id' => $item->get_variation_id() ? (string) $item->get_variation_id() : null, 'sku' => $product ? substr((string) $product->get_sku(), 0, 128) : '', 'quantity' => (string) $item->get_quantity(), 'unit' => 'item', 'subtotal' => Protocol::money($item->get_subtotal(), $currency, $exp), 'total' => Protocol::money($item->get_total(), $currency, $exp), 'tax' => Protocol::money($item->get_total_tax(), $currency, $exp)];
        }
        return $lines;
    }
    public static function snapshot(int $id): void {
        $order = wc_get_order($id);
        if (!$order || $order instanceof \WC_Order_Refund || $order->get_type() !== 'shop_order' || $order->get_status() === 'auto-draft' || $order->get_status() === 'checkout-draft') { return; }
        try {
            $currency = $order->get_currency(); $stored = $order->get_meta('_mgln_v3_currency_exponent'); $exp = $stored === '' ? self::exponent($currency) : (int) $stored;
            $money = static fn($amount) => Protocol::money($amount, $currency, $exp);
            $context = self::saved_context($order);
            $native = [];
            foreach (['source_type','utm_source','utm_medium','utm_campaign','utm_content','utm_term'] as $key) {
                $v = (string) $order->get_meta('_wc_order_attribution_' . $key);
                if ($v !== '' && strlen($v) <= 128 && strpos($v, '@') === false) { $native[$key] = sanitize_text_field($v); }
            }
            $fees = []; foreach ($order->get_items('fee') as $item) { $fees[] = ['line_id' => (string) $item->get_id(), 'total' => $money($item->get_total()), 'tax' => $money($item->get_total_tax())]; }
            $taxes = []; foreach ($order->get_items('tax') as $item) { $taxes[] = ['line_id' => (string) $item->get_id(), 'rate_id' => (string) $item->get_rate_id(), 'total' => $money($item->get_tax_total()), 'shipping' => $money($item->get_shipping_tax_total())]; }
            $subs = ['adapter' => 'unavailable'];
            if (function_exists('wcs_order_contains_renewal')) { $subs = ['adapter' => 'woocommerce_subscriptions', 'renewal' => wcs_order_contains_renewal($order), 'subscription_order' => wcs_order_contains_subscription($order)]; }
            $p = ['source_system' => 'woocommerce', 'order_id' => (string) $id, 'status' => $order->get_status(), 'created_at' => self::date($order->get_date_created()), 'modified_at' => self::date($order->get_date_modified()), 'currency' => $currency, 'exponent' => $exp, 'amounts' => ['subtotal' => $money($order->get_subtotal()), 'discount' => $money($order->get_discount_total()), 'shipping' => $money($order->get_shipping_total()), 'tax' => $money($order->get_total_tax()), 'total' => $money($order->get_total())], 'lines' => self::lines($order->get_items(), $currency, $exp), 'fees' => $fees, 'taxes' => $taxes, 'payment_method' => $order->get_payment_method(), 'transaction_id' => $order->get_transaction_id() ?: null, 'paid_at' => self::date($order->get_date_paid()), 'checkout_context' => $context ?: (object) [], 'native_attribution' => $native ? ['provenance' => 'woocommerce_metadata_only', 'values' => $native] : (object) [], 'channel_origin' => $order->get_meta('_mgln_v3_origin') === 'web_checkout' ? 'web_checkout' : ($order->get_created_via() === 'admin' ? 'other' : 'unknown'), 'subscriptions' => $subs];
            $revision = hash('sha256', Protocol::json($p));
            if ($order->get_meta('_mgln_v3_snapshot_hash') === $revision) { return; }
            $p['source_revision'] = $revision; $p['snapshot_id'] = wp_generate_uuid4();
            $chunks = array_chunk($p['lines'], 50) ?: [[]]; $p['pages'] = count($chunks);
            $entity = ['source_system' => 'woocommerce', 'type' => 'order', 'id' => (string) $id, 'revision' => $revision];
            foreach ($chunks as $i => $lines) { $p['lines'] = $lines; $p['page'] = $i + 1; if (!Outbox::capture('order_snapshot', $p, $entity, $context, self::date($order->get_date_modified()))) { return; } }
            self::$saving = true; $order->update_meta_data('_mgln_v3_snapshot_hash', $revision); $order->save_meta_data(); self::$saving = false;
        } catch (\Throwable $e) { self::$saving = false; Outbox::gap('order_snapshot_' . ($e instanceof \InvalidArgumentException ? $e->getMessage() : 'failed')); }
    }
    public static function status($id, $from, $to, $order): void {
        $p = ['order_id' => (string) $id, 'from_status' => (string) $from, 'to_status' => (string) $to, 'source_revision' => (string) (self::date($order->get_date_modified()) ?? gmdate('c'))];
        Outbox::capture('order_status_changed', $p, null, self::saved_context($order)); self::dirty_order($id);
    }
    public static function payment($id): void {
        $order = wc_get_order($id); if (!$order) { return; }
        $p = ['order_id' => (string) $id, 'payment_method' => $order->get_payment_method(), 'transaction_id' => $order->get_transaction_id() ?: null, 'source_state' => 'woocommerce_payment_complete', 'paid_at' => self::date($order->get_date_paid()), 'settlement_verified' => false];
        Outbox::capture('payment_fact', $p, null, self::saved_context($order), self::date($order->get_date_paid())); self::dirty_order($id);
    }
    public static function refund($id, $refund_id): void {
        try {
            $order = wc_get_order($id); $refund = wc_get_order($refund_id); if (!$order || !$refund instanceof \WC_Order_Refund) { return; }
            $currency = $order->get_currency(); $exp = self::exponent($currency); $lines = self::lines($refund->get_items(), $currency, $exp);
            $p = ['order_id' => (string) $id, 'refund_id' => (string) $refund_id, 'source_revision' => hash('sha256', Protocol::json([$refund->get_amount(), $lines])), 'status' => $refund->get_status(), 'amount' => Protocol::money($refund->get_amount(), $currency, $exp), 'created_at' => self::date($refund->get_date_created()), 'lines' => $lines, 'allocations_available' => !empty($lines), 'provider_refund_id' => null];
            if ($refund->get_meta('_mgln_v3_refund_hash') !== $p['source_revision']) {
                $chunks = array_chunk($lines, 50) ?: [[]]; $p['pages'] = count($chunks); $p['snapshot_id'] = wp_generate_uuid4();
                foreach ($chunks as $i => $chunk) {
                    $p['page'] = $i + 1; $p['lines'] = $chunk;
                    if (!Outbox::capture('refund_recorded', $p, ['source_system' => 'woocommerce', 'type' => 'refund', 'id' => (string) $refund_id, 'revision' => $p['source_revision']], self::saved_context($order), $p['created_at'])) { return; }
                }
                $refund->update_meta_data('_mgln_v3_refund_hash', $p['source_revision']); $refund->save_meta_data();
            }
            self::dirty_order($id);
        } catch (\Throwable $e) { Outbox::gap('refund_capture_failed'); }
    }
    public static function cart(): void {
        if (!function_exists('WC') || !WC()->cart || !WC()->session) { return; }
        $cart = WC()->cart; $id = self::cart_id(!$cart->is_empty()); if (!$id) { return; }
        try {
            $currency = get_woocommerce_currency(); $exp = self::exponent($currency); $items = [];
            foreach ($cart->get_cart() as $key => $item) {
                $product = $item['data'];
                $items[] = ['line_id' => (string) $key, 'refunded_line_id' => null, 'product_id' => (string) $item['product_id'], 'variation_id' => $item['variation_id'] ? (string) $item['variation_id'] : null, 'sku' => substr((string) $product->get_sku(), 0, 128), 'quantity' => (string) $item['quantity'], 'unit' => 'item', 'subtotal' => Protocol::money($item['line_subtotal'] ?? '0', $currency, $exp), 'total' => Protocol::money($item['line_total'] ?? '0', $currency, $exp), 'tax' => Protocol::money($item['line_tax'] ?? '0', $currency, $exp)];
            }
            $revision = hash('sha256', Protocol::json($items)); if (WC()->session->get('magellan_v3_cart_hash') === $revision) { return; }
            $type = $items ? 'cart_snapshot' : 'cart_emptied';
            $p = $items ? ['cart_id' => $id, 'revision' => $revision, 'lifecycle' => 'open', 'currency' => $currency, 'exponent' => $exp, 'merchandise_total' => Protocol::money($cart->get_cart_contents_total(), $currency, $exp), 'items' => $items] : ['cart_id' => $id, 'revision' => $revision, 'lifecycle' => 'empty', 'reason' => 'woocommerce_cart_empty'];
            if ($items) {
                $chunks = array_chunk($items, 50); $p['pages'] = count($chunks); $p['snapshot_id'] = wp_generate_uuid4();
                foreach ($chunks as $i => $chunk) { $p['page'] = $i + 1; $p['items'] = $chunk; if (!Outbox::capture($type, $p, null, self::context())) { return; } }
            } elseif (!Outbox::capture($type, $p, null, self::context())) { return; }
            WC()->session->set('magellan_v3_cart_hash', $revision); if (!$items) { WC()->session->set('magellan_v3_cart_id', null); }
        } catch (\Throwable $e) { Outbox::gap('cart_capture_failed'); }
    }
    public static function dirty_entity($id, $post): void {
        if (in_array($post->post_type, ['page','post','product','product_variation'], true) && $post->post_status === 'publish' && !wp_is_post_revision($id)) { self::$entities[(int) $id] = true; }
    }
    public static function deleted($id, $post): void {
        if (in_array($post->post_type, ['page','post','product','product_variation'], true)) { Outbox::capture('entity_deleted', ['type' => $post->post_type, 'id' => (string) $id]); }
    }
    public static function flush(): void {
        if (!Config::ready()) { return; }
        foreach (array_keys(self::$orders) as $id) { self::snapshot($id); } self::$orders = [];
        if (self::$cart_dirty) { self::cart(); self::$cart_dirty = false; }
        foreach (array_keys(self::$entities) as $id) {
            $post = get_post($id); if (!$post) { continue; }
            $path = (string) wp_parse_url(get_permalink($id), PHP_URL_PATH);
            // Presentation hash deliberately excludes stock, prices and private metadata.
            $revision = hash('sha256', Protocol::json([$post->post_title,$post->post_content,$post->post_excerpt,$path]));
            if (get_post_meta($id, '_mgln_v3_presentation', true) === $revision) { continue; }
            if (Outbox::capture('entity_changed', ['type' => $post->post_type, 'id' => (string) $id, 'revision' => $revision, 'path' => substr($path, 0, 512), 'previous_path' => get_post_meta($id, '_mgln_v3_path', true) ?: null])) { update_post_meta($id, '_mgln_v3_presentation', $revision); update_post_meta($id, '_mgln_v3_path', $path); }
        } self::$entities = [];
    }
    public static function reconcile(): void {
        // Fixed upper boundary + overlapping completed windows; never advances on partial capture.
        $cursor = (array) get_option('magellan_v3_reconcile', []);
        $since = $cursor['since'] ?? max(0, time() - 86400);
        $until = $cursor['until'] ?? time(); $page = $cursor['page'] ?? 1;
        $before_gap = get_option('magellan_v3_capture_gap');
        $orders = wc_get_orders(['type' => 'shop_order', 'limit' => 25, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'date_modified' => $since . '...' . $until]);
        foreach ($orders as $order) { self::snapshot($order->get_id()); foreach ($order->get_refunds() as $refund) { self::refund($order->get_id(), $refund->get_id()); } }
        if (get_option('magellan_v3_capture_gap') !== $before_gap) { return; }
        $next = count($orders) === 25 ? ['since' => $since, 'until' => $until, 'page' => $page + 1] : ['since' => max(0, $until - 300), 'page' => 1];
        update_option('magellan_v3_reconcile', $next, false);
    }
}
