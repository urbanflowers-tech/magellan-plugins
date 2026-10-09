<?php
namespace Magellan\V3;
defined('ABSPATH') || exit;

final class Browser {
    public static function init(): void { add_action('wp_enqueue_scripts', [self::class, 'enqueue']); }
    public static function enqueue(): void {
        if (!Config::analytics() || is_admin()) { return; }
        $c = Config::get(); $id = get_queried_object_id(); $post = is_singular() ? get_post($id) : null;
        $type = 'unknown';
        if (is_front_page()) { $type = 'home'; }
        elseif (is_product()) { $type = 'product'; }
        elseif (is_product_category() || is_product_tag() || is_shop()) { $type = 'category'; }
        elseif (is_checkout() || is_cart() || is_account_page()) { $type = 'utility'; }
        elseif ($post) { $type = 'content'; }
        $override = $post ? get_post_meta($id, '_magellan_entry_type', true) : '';
        if (in_array($override, ['home','content','product','category','commercial_landing','utility','unknown'], true)) { $type = $override; }
        $served = apply_filters('magellan_v3_served_version', null, $post);
        $config = ['site_id' => $c['site_id'], 'installation_id' => $c['installation_id'], 'environment' => $c['environment'], 'endpoint' => $c['collect_url'], 'origin' => $c['origin'], 'plugin_version' => MAGELLAN_VERSION, 'policy_version' => $c['policy_version'], 'analytics_policy' => $c['analytics_policy'] ?? 'consent_required', 'page_id' => $post ? (string) $id : null, 'product_id' => is_product() ? (string) $id : null, 'entry_type' => $type, 'classification_version' => '1:' . substr(hash('sha256',$type . ':' . $override),0,12), 'storefront' => get_bloginfo('language'), 'served_version' => is_string($served) ? substr($served,0,128) : null];
        // Public, cache-safe site/page facts only. No visitor, cart, consent or nonce here.
        wp_enqueue_script('magellan-v3-pixel', MAGELLAN_PLUGIN_URL . 'assets/magellan-v3-pixel.js', [], MAGELLAN_VERSION, true);
        wp_add_inline_script('magellan-v3-pixel', 'window.MagellanV3Config=' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
        if (is_checkout() && !is_wc_endpoint_url('order-received')) {
            $deps = ['magellan-v3-pixel','jquery','wp-api-fetch']; // Includes checkout blocks rendered by a theme template.
            wp_enqueue_script('magellan-v3-checkout', MAGELLAN_PLUGIN_URL . 'assets/magellan-v3-checkout.js', $deps, MAGELLAN_VERSION, true);
        }
    }
}
