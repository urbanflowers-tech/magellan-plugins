<?php
namespace Magellan\V3;
defined('ABSPATH') || exit;

final class Config {
    const OPTION = 'magellan_v3_config';
    public static function get(): array { return (array) get_option(self::OPTION, []); }
    public static function origin(): string {
        $p = wp_parse_url(home_url());
        return strtolower(($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
    }
    public static function active(): bool { return !empty(self::get()['connected_at']); }
    public static function measurement_only(): bool { return (self::get()['collection_mode'] ?? 'full') === 'measurement_only'; }
    public static function legacy_enabled(): bool { return !self::active() || self::measurement_only(); }
    public static function ready(): bool {
        $c = self::get();
        return self::active() && ($c['origin'] ?? '') === self::origin() && ($c['wp_environment'] ?? '') === wp_get_environment_type();
    }
    public static function analytics(): bool { return self::ready() && !empty(self::get()['analytics_enabled']) && apply_filters('magellan_tracking_enabled', true); }
    public static function analytics_allowed(array $consent): bool {
        if (!self::analytics()) { return false; }
        return ($consent['analytics'] ?? '') === 'granted' || (
            (self::get()['analytics_policy'] ?? 'consent_required') === 'store_enabled' &&
            ($consent['analytics'] ?? '') === 'not_applicable' &&
            ($consent['source'] ?? '') === 'store_policy' && empty($consent['gpc'])
        );
    }
    public static function endpoint(string $url): bool {
        $p = wp_parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || isset($p['query'])) { return false; }
        return (bool) wp_http_validate_url($url);
    }
    public static function validate(array $p): array {
        foreach (['site_id', 'installation_id', 'key_id'] as $key) {
            if (!isset($p[$key]) || !is_string($p[$key]) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $p[$key])) { throw new \InvalidArgumentException('invalid_' . $key); }
        }
        $secret = base64_decode($p['signing_key'] ?? '', true);
        if ($secret === false || strlen($secret) !== 32) { throw new \InvalidArgumentException('invalid_signing_key'); }
        if (!in_array($p['environment'] ?? '', ['production','test','development'], true)) { throw new \InvalidArgumentException('invalid_environment'); }
        if (($p['origin'] ?? '') !== self::origin()) { throw new \InvalidArgumentException('origin_mismatch'); }
        if ($p['environment'] === 'production' && strpos($p['origin'], 'https://') !== 0) { throw new \InvalidArgumentException('production_requires_https'); }
        if ($p['environment'] === 'production' && wp_get_environment_type() !== 'production') { throw new \InvalidArgumentException('production_key_on_nonproduction_site'); }
        foreach (['events_url','collect_url','challenge_url'] as $key) {
            if (!is_string($p[$key] ?? null) || !self::endpoint($p[$key])) { throw new \InvalidArgumentException('invalid_' . $key); }
        }
        // One explicitly provisioned private API origin; the browser collector may use another.
        $a = wp_parse_url($p['events_url']); $b = wp_parse_url($p['challenge_url']);
        if ($a['host'] !== $b['host'] || ($a['port'] ?? 443) !== ($b['port'] ?? 443)) { throw new \InvalidArgumentException('api_origin_mismatch'); }
        $c = array_intersect_key($p, array_flip(['site_id','installation_id','key_id','signing_key','environment','origin','events_url','collect_url','challenge_url']));
        $c['analytics_enabled'] = !empty($p['analytics_enabled']);
        $c['collection_mode'] = $p['collection_mode'] ?? 'full';
        if (!in_array($c['collection_mode'], ['full','measurement_only'], true)) { throw new \InvalidArgumentException('invalid_collection_mode'); }
        $c['analytics_policy'] = $p['analytics_policy'] ?? 'consent_required';
        if (!in_array($c['analytics_policy'], ['consent_required','store_enabled'], true)) { throw new \InvalidArgumentException('invalid_analytics_policy'); }
        $c['policy_version'] = sanitize_key($p['policy_version'] ?? '1');
        $c['wp_environment'] = wp_get_environment_type();
        return $c;
    }
    public static function configure(array $input) {
        try {
            $c = self::validate($input); $old = self::get();
            if (!empty($old['installation_id']) && $old['installation_id'] !== $c['installation_id'] && Outbox::unresolved() > 0) {
                return new \WP_Error('unresolved_outbox', 'Resolve or export the old installation queue before rebinding this site.', ['status' => 409]);
            }
            $nonce = wp_generate_uuid4();
            $body = Protocol::json(['schema_version' => '3.0.0', 'installation_id' => $c['installation_id'], 'site_id' => $c['site_id'], 'environment' => $c['environment'], 'origin' => $c['origin'], 'collection_mode' => $c['collection_mode'], 'analytics_policy' => $c['analytics_policy'], 'challenge' => $nonce]);
            $response = wp_safe_remote_post($c['challenge_url'], ['body' => $body, 'headers' => Protocol::headers($c, 'POST', $c['challenge_url'], $body), 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 16384]);
            if (is_wp_error($response)) { throw new \RuntimeException('challenge_unreachable'); }
            $r = json_decode(wp_remote_retrieve_body($response), true);
            if (wp_remote_retrieve_response_code($response) !== 200 || !is_array($r) || ($r['challenge'] ?? '') !== $nonce || ($r['installation_id'] ?? '') !== $c['installation_id'] || ($r['site_id'] ?? '') !== $c['site_id'] || ($r['environment'] ?? '') !== $c['environment'] || ($r['schema_version'] ?? '') !== '3.0.0' || empty($r['durable_intake_ready'])) { throw new \RuntimeException('challenge_not_confirmed'); }
            if (($r['collection_mode'] ?? 'full') !== $c['collection_mode']) { throw new \RuntimeException('collection_mode_not_confirmed'); }
            if (($r['analytics_policy'] ?? 'consent_required') !== $c['analytics_policy']) { throw new \RuntimeException('analytics_policy_not_confirmed'); }
            $c['connected_at'] = gmdate('c');
            $c['tracking_started_at'] = $old['tracking_started_at'] ?? null;
            update_option(self::OPTION, $c, false);
            delete_option('magellan_v3_circuit');
            delete_option('magellan_v3_auth_blocked');
            Outbox::resume();
            if (self::measurement_only() && function_exists('magellan_schedule_legacy_jobs')) { magellan_schedule_legacy_jobs(); }
            if (!self::measurement_only()) {
                foreach (['magellan_historical_identity_sync','magellan_daily_cleanup','magellan_sync_check','magellan_health_check'] as $hook) { wp_clear_scheduled_hook($hook); }
                if (function_exists('as_unschedule_all_actions')) {
                    foreach (['magellan_send_verified_event','magellan_send_refund_event','magellan_send_cancel_event'] as $hook) { as_unschedule_all_actions($hook, null, 'magellan'); }
                }
            }
            return ['connected' => true, 'installation_id' => $c['installation_id'], 'schema_version' => '3.0.0'];
        } catch (\Throwable $e) {
            return new \WP_Error('magellan_configuration_failed', $e->getMessage(), ['status' => 400]);
        }
    }
}
