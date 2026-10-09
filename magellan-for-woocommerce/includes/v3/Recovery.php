<?php
namespace Magellan\V3;
defined('ABSPATH') || exit;

/** Failed source captures have their own retry cursor; one order cannot stop the scan. */
final class Recovery {
    const HOOK = 'magellan_v3_reconcile';
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'magellan_v3_recovery'; }
    public static function init(): void {
        global $wpdb;
        if (get_option('magellan_v3_recovery_version') !== '1') {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $table = self::table(); $collation = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE $table (
                order_id bigint unsigned NOT NULL,
                attempts int unsigned NOT NULL DEFAULT 0,
                next_attempt_at bigint unsigned NOT NULL DEFAULT 0,
                last_error varchar(128) NOT NULL DEFAULT '',
                PRIMARY KEY  (order_id),
                KEY due (next_attempt_at)
            ) $collation;");
            update_option('magellan_v3_recovery_version', '1', false);
        }
        add_action(self::HOOK, [Capture::class, 'reconcile']);
        add_action('action_scheduler_after_execute', static function ($id, $action) {
            if ($action->get_hook() === self::HOOK) {
                // A running action prevents unique scheduling until it is complete.
                add_action('action_scheduler_completed_action', static function ($completed) use ($id) { if ($completed === $id && !empty(get_option('magellan_v3_reconcile', [])['until'])) { self::schedule(); } });
            }
        }, 10, 2);
    }
    public static function mark(int $id, string $error): void {
        global $wpdb; $table = self::table();
        $wpdb->query($wpdb->prepare("INSERT INTO $table (order_id,attempts,next_attempt_at,last_error) VALUES (%d,1,%d,%s) ON DUPLICATE KEY UPDATE attempts=attempts+1,next_attempt_at=VALUES(next_attempt_at),last_error=VALUES(last_error)", $id, time() + 300, substr($error, 0, 128)));
    }
    public static function clear(int $id): void { global $wpdb; $wpdb->delete(self::table(), ['order_id' => $id]); }
    public static function due(): array { global $wpdb; return $wpdb->get_col($wpdb->prepare('SELECT order_id FROM ' . self::table() . ' WHERE next_attempt_at<=%d ORDER BY next_attempt_at LIMIT 10', time())); }
    public static function stats(): array {
        global $wpdb; $table = self::table();
        return ['failed_orders' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"), 'recent_failures' => $wpdb->get_results("SELECT order_id,attempts,next_attempt_at,last_error FROM $table ORDER BY next_attempt_at LIMIT 10", ARRAY_A)];
    }
    public static function schedule(): void {
        if (function_exists('as_schedule_single_action') && did_action('action_scheduler_init')) {
            if (!as_has_scheduled_action(self::HOOK, [], 'magellan-v3')) { as_schedule_single_action(time() + 10, self::HOOK, [], 'magellan-v3', true); }
        } elseif (!wp_next_scheduled(self::HOOK)) { wp_schedule_single_event(time() + 10, self::HOOK); }
    }
}
