<?php
namespace Magellan\V3;
defined('ABSPATH') || exit;

final class Admin {
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_menu', static function () { add_submenu_page('woocommerce', 'Magellan v3', 'Magellan v3', 'manage_options', 'magellan-v3', [self::class, 'page']); });
        add_action('admin_post_magellan_v3_settings', [self::class, 'save']);
        add_action('admin_notices', static function () {
            if (!current_user_can('manage_options')) { return; }
            if (Config::active() && !Config::ready()) { echo '<div class="notice notice-error"><p>Magellan v3 sending is paused: this site’s origin or environment has changed. Reconnect this installation.</p></div>'; }
            if (get_option('magellan_v3_auth_blocked')) { echo '<div class="notice notice-error"><p>Magellan v3 authentication failed. Events are retained. Reconnect or rotate the installation key.</p></div>'; }
            if (get_option('magellan_v3_capture_gap')) { echo '<div class="notice notice-warning"><p>Magellan v3 has a capture gap requiring reconciliation. Inspect WooCommerce → Magellan v3.</p></div>'; }
        });
        register_post_meta('page', '_magellan_entry_type', ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => [self::class, 'classification'], 'auth_callback' => static function () { return current_user_can('edit_pages'); }]);
        add_action('add_meta_boxes', static function () { add_meta_box('magellan-page-type', 'Magellan measurement', [self::class, 'page_type'], 'page', 'side'); });
        add_action('save_post_page', [self::class, 'save_page_type']);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('magellan drain', static function () { Outbox::drain(); \WP_CLI::success('Bounded drain completed; inspect magellan status for receipts.'); });
            \WP_CLI::add_command('magellan status', static function () { \WP_CLI::line(Protocol::json(self::status())); });
            \WP_CLI::add_command('magellan reconcile', static function () { Capture::reconcile(); \WP_CLI::success('One bounded reconciliation page completed.'); });
            \WP_CLI::add_command('magellan maintenance', static function () { Outbox::maintenance(); \WP_CLI::success('Bounded maintenance completed.'); });
        }
    }
    public static function permission(): bool { return current_user_can('manage_options'); }
    public static function routes(): void {
        foreach (['capabilities','status'] as $route) { register_rest_route('magellan/v3', '/' . $route, ['methods' => 'GET', 'permission_callback' => [self::class,'permission'], 'callback' => static fn() => rest_ensure_response(self::$route())]); }
        register_rest_route('magellan/v3', '/configure', ['methods' => 'POST', 'permission_callback' => [self::class,'permission'], 'callback' => static function ($request) { return rest_ensure_response(Config::configure((array) $request->get_json_params())); }]);
        register_rest_route('magellan/v3', '/replay', ['methods' => 'POST', 'permission_callback' => [self::class,'permission'], 'callback' => [self::class,'replay']]);
    }
    public static function capabilities(): array {
        $hpos = class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        return ['plugin_version' => MAGELLAN_VERSION, 'protocol' => '3', 'schemas' => ['3.0.0'], 'wordpress' => get_bloginfo('version'), 'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null, 'php' => PHP_VERSION, 'timezone' => wp_timezone_string(), 'hpos' => $hpos, 'commerce.read' => true, 'analytics.collect' => Config::analytics(), 'health.read' => true, 'checkout' => ['classic' => 'implemented', 'blocks' => 'implemented', 'errors' => 'observed_unknown_category_only'], 'consent_adapters' => ['explicit_event_v1','cookiebot_v1'], 'site.read' => false, 'page.publish' => false, 'theme.write' => false, 'web_vitals' => false, 'experiments' => false, 'identity_import' => false, 'reason_optional_unavailable' => 'Outside the v3.0 measurement release', 'limits' => ['cart_lines_per_page' => 50, 'refund_lines_per_page' => 50, 'order_lines_per_page' => 50], 'compatibility_status' => 'development_candidate_requires_store_certification'];
    }
    public static function status(): array {
        $c = Config::get();
        return ['connected' => Config::ready(), 'mode' => Config::active() ? (Config::measurement_only() ? 'v3_measurement_with_legacy' : 'v3') : 'legacy_or_unconfigured', 'collection_mode' => $c['collection_mode'] ?? 'full', 'legacy_enabled' => Config::legacy_enabled(), 'site_id' => $c['site_id'] ?? null, 'installation_id' => $c['installation_id'] ?? null, 'environment' => $c['environment'] ?? null, 'origin' => Config::origin(), 'analytics_enabled' => Config::analytics(), 'tracking_started_at' => $c['tracking_started_at'] ?? null, 'queue' => Outbox::stats(), 'reporting' => ['state' => 'backend_verification_required', 'note' => 'A receipt proves durable intake, not report application.'], 'capabilities' => self::capabilities()];
    }
    public static function replay($request) {
        global $wpdb; $id = Protocol::id($request['event_id']);
        if (!$id) { return new \WP_Error('invalid_id','A valid event UUID is required.',['status'=>400]); }
        $table = Outbox::table();
        $result = $wpdb->query($wpdb->prepare("UPDATE $table SET state='pending',next_attempt_at=%d,lease_until=0,lease_token='' WHERE event_id=%s AND installation_id=%s AND state IN ('dead_letter','blocked') AND last_error NOT IN ('oversize_capture','capture_incomplete','privacy_erasure_pending')", time(), $id, Config::get()['installation_id'] ?? ''));
        Outbox::schedule(1); return rest_ensure_response(['requeued' => (bool) $result, 'event_id' => $id]);
    }
    public static function save(): void {
        if (!self::permission()) { wp_die('Forbidden', '', ['response' => 403]); }
        check_admin_referer('magellan_v3_settings');
        if (isset($_POST['configuration'])) {
            $raw = $_POST['configuration'];
            $p = is_string($raw) && strlen($raw) <= 16384 ? json_decode(wp_unslash($raw), true) : null;
            $result = is_array($p) ? Config::configure($p) : new \WP_Error('bad_json','Invalid configuration JSON.');
            set_transient('magellan_v3_notice_' . get_current_user_id(), is_wp_error($result) ? $result->get_error_message() : 'Connection verified. Purge the page cache before testing the new pixel.', 60);
        } elseif (isset($_POST['analytics'])) {
            $c = Config::get(); $c['analytics_enabled'] = $_POST['analytics'] === 'enable'; update_option(Config::OPTION, $c, false);
            set_transient('magellan_v3_notice_' . get_current_user_id(), 'Analytics setting saved. Purge the page cache to apply it to cached pages.', 60);
        }
        wp_safe_redirect(admin_url('admin.php?page=magellan-v3')); exit;
    }
    public static function page(): void {
        if (!self::permission()) { return; }
        echo '<div class="wrap"><h1>Magellan v3</h1><p>Magellan owns stock, orders and finance. This plugin sends website and checkout evidence.</p>';
        $notice = get_transient('magellan_v3_notice_' . get_current_user_id());
        if ($notice) { echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; delete_transient('magellan_v3_notice_' . get_current_user_id()); }
        echo '<h2>Connection and delivery</h2><pre style="max-height:450px;overflow:auto;background:#fff;padding:16px">' . esc_html(wp_json_encode(self::status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '<h2>Connect or rotate credentials</h2><p>Paste the installation configuration issued by Magellan. It must identify this exact store and environment. The signing key is never displayed again.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="magellan_v3_settings">'; wp_nonce_field('magellan_v3_settings');
        echo '<textarea name="configuration" rows="9" cols="90" autocomplete="off" spellcheck="false" aria-label="Magellan installation configuration"></textarea>'; submit_button('Verify and connect'); echo '</form>';
        if (Config::active()) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="magellan_v3_settings">'; wp_nonce_field('magellan_v3_settings');
            echo '<input type="hidden" name="analytics" value="' . (Config::analytics() ? 'disable' : 'enable') . '">'; submit_button(Config::analytics() ? 'Disable browser analytics' : 'Enable consent-controlled analytics', 'secondary'); echo '</form>';
        }
        echo '<p>For low-traffic stores, run <code>wp magellan drain</code> from real cron. Scheduling alone does not prove the runner is executing.</p></div>';
    }
    public static function classification($value): string { return in_array($value, ['home','content','product','category','commercial_landing','utility','unknown'], true) ? $value : ''; }
    public static function page_type($post): void {
        wp_nonce_field('magellan_page_type','magellan_page_type_nonce');
        echo '<label for="magellan-entry-type">Page type</label><select id="magellan-entry-type" name="magellan_entry_type"><option value="">Automatic</option>';
        foreach (['home','content','product','category','commercial_landing','utility','unknown'] as $type) { echo '<option value="' . esc_attr($type) . '" ' . selected(get_post_meta($post->ID,'_magellan_entry_type',true),$type,false) . '>' . esc_html(ucwords(str_replace('_',' ',$type))) . '</option>'; }
        echo '</select><p>Classification applies before conversion is measured. Purge cached pages after changing it.</p>';
    }
    public static function save_page_type($id): void {
        if (!isset($_POST['magellan_page_type_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['magellan_page_type_nonce'])), 'magellan_page_type') || !current_user_can('edit_post',$id) || wp_is_post_autosave($id) || wp_is_post_revision($id)) { return; }
        update_post_meta($id,'_magellan_entry_type',self::classification(sanitize_text_field(wp_unslash($_POST['magellan_entry_type'] ?? ''))));
    }
}
