<?php
namespace Magellan\V3;
defined('ABSPATH') || exit;

/** Durable, immutable source events. Action Scheduler is only a wake-up mechanism. */
final class Outbox {
    const HOOK = 'magellan_v3_drain';
    public static function table(): string { global $wpdb; return $wpdb->prefix . 'magellan_v3_outbox'; }
    public static function quota(): string { global $wpdb; return $wpdb->prefix . 'magellan_v3_quota'; }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $collation = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            seq bigint unsigned NOT NULL AUTO_INCREMENT,
            event_id char(36) NOT NULL,
            installation_id varchar(128) NOT NULL,
            entity_key varchar(190) NOT NULL DEFAULT '',
            purpose varchar(24) NOT NULL DEFAULT 'commerce',
            payload longtext NOT NULL,
            reserved_bytes bigint unsigned NOT NULL DEFAULT 0,
            body_hash char(64) NOT NULL DEFAULT '',
            state varchar(16) NOT NULL DEFAULT 'building',
            attempts int unsigned NOT NULL DEFAULT 0,
            next_attempt_at bigint unsigned NOT NULL,
            lease_token char(36) NOT NULL DEFAULT '',
            lease_until bigint unsigned NOT NULL DEFAULT 0,
            created_at bigint unsigned NOT NULL,
            accepted_at bigint unsigned NOT NULL DEFAULT 0,
            receipt longtext NULL,
            last_error varchar(128) NOT NULL DEFAULT '',
            PRIMARY KEY  (seq),
            UNIQUE KEY event_id (event_id),
            KEY ready (state,next_attempt_at),
            KEY lease_expiry (state,lease_until),
            KEY entity_lookup (entity_key,seq),
            KEY installation (installation_id,state),
            KEY retention (state,accepted_at)
        ) $collation;");
        $quota = self::quota();
        dbDelta("CREATE TABLE $quota (
            id int NOT NULL,
            pending_rows bigint unsigned NOT NULL DEFAULT 0,
            reserved_bytes bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $collation;");
        $wpdb->query("INSERT IGNORE INTO $quota (id,pending_rows,reserved_bytes) SELECT 1,COALESCE(SUM(state<>'accepted'),0),COALESCE(SUM(OCTET_LENGTH(payload)+20),0) FROM $table");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) { update_option('magellan_v3_db_version', '2', false); }
    }
    public static function init(): void {
        add_action(self::HOOK, [self::class, 'drain']);
        add_action('action_scheduler_completed_action', static function ($id) { if (class_exists('ActionScheduler')) { $action = \ActionScheduler::store()->fetch_action($id); if ($action->get_hook() === self::HOOK) { self::next(); } } });
        add_action('magellan_v3_maintenance', [self::class, 'maintenance']);
        if (get_option('magellan_v3_db_version') !== '2') { self::install(); }
        if (!wp_next_scheduled('magellan_v3_maintenance')) { wp_schedule_event(time() + 300, 'hourly', 'magellan_v3_maintenance'); }
    }
    public static function gap(string $code): void {
        update_option('magellan_v3_capture_gap', ['id' => wp_generate_uuid4(), 'code' => $code, 'at' => gmdate('c'), 'requires_reconciliation' => true], false);
    }
    public static function capture(string $type, array $payload, ?array $entity = null, array $context = [], ?string $occurred = null, string $purpose = 'commerce'): ?string {
        if (!Config::ready()) { return null; }
        global $wpdb; $table = self::table(); $c = Config::get();
        try {
            $id = wp_generate_uuid4(); $now = time(); $seq = 1;
            $event = ['schema_version' => '3.0.0', 'plugin_version' => MAGELLAN_VERSION, 'event_id' => $id, 'event_type' => $type, 'site_id' => $c['site_id'], 'installation_id' => $c['installation_id'], 'environment' => $c['environment'], 'producer' => 'wordpress', 'evidence_class' => 'source_fact', 'occurred_at' => $occurred ?? gmdate('c'), 'captured_at' => gmdate('c'), 'visitor_id' => $context['visitor_id'] ?? null, 'session_id' => $context['session_id'] ?? null, 'cart_id' => $context['cart_id'] ?? null, 'entity' => $entity, 'origin_system' => 'woocommerce', 'operation_id' => null, 'causation_id' => null, 'sequence' => $seq, 'consent' => $context['consent'] ?? Protocol::consent(), 'payload' => $payload];
            // Only an authenticated command adapter may supply trusted operation provenance.
            $provenance = apply_filters('magellan_v3_command_provenance', [], $entity);
            if (!empty($provenance['verified']) && is_string($provenance['operation_id'] ?? null)) { $event['origin_system'] = 'magellan'; $event['operation_id'] = substr($provenance['operation_id'], 0, 128); $event['causation_id'] = isset($provenance['causation_id']) ? substr((string) $provenance['causation_id'], 0, 128) : null; }
            $body = Protocol::json($event);
            if (strlen($body) > 240000) { self::gap('oversize_capture'); return null; }
            $quota = self::quota(); $reserved = strlen($body) + 20;
            $limit_rows = $purpose === 'health' ? 45000 : 50000;
            $limit_bytes = $purpose === 'health' ? 94371840 : 104857600;
            $reserved_ok = $wpdb->query($wpdb->prepare("UPDATE $quota SET pending_rows=pending_rows+1,reserved_bytes=reserved_bytes+%d WHERE id=1 AND pending_rows<%d AND reserved_bytes+%d<=%d", $reserved, $limit_rows, $reserved, $limit_bytes));
            if ($reserved_ok !== 1) { self::gap('outbox_capacity_or_quota_unavailable'); return null; }
            $ok = $wpdb->insert($table, ['event_id' => $id, 'installation_id' => $c['installation_id'], 'entity_key' => $entity ? $entity['type'] . ':' . $entity['id'] : '', 'purpose' => $purpose, 'payload' => '', 'reserved_bytes' => $reserved, 'state' => 'building', 'next_attempt_at' => $now, 'created_at' => $now]);
            if (!$ok) {
                $wpdb->query($wpdb->prepare("UPDATE $quota SET pending_rows=GREATEST(0,CAST(pending_rows AS SIGNED)-1),reserved_bytes=GREATEST(0,CAST(reserved_bytes AS SIGNED)-%d) WHERE id=1", $reserved));
                self::gap('outbox_insert_failed'); return null;
            }
            $seq = (int) $wpdb->insert_id; $event['sequence'] = $seq; $body = Protocol::json($event);
            if (strlen($body) > 240000) { $wpdb->update($table, ['state' => 'dead_letter', 'last_error' => 'oversize_capture', 'payload' => $body, 'body_hash' => hash('sha256', $body)], ['seq' => $seq]); self::gap('oversize_capture'); return null; }
            if ($wpdb->update($table, ['payload' => $body, 'body_hash' => hash('sha256', $body), 'state' => get_option('magellan_v3_auth_blocked') ? 'blocked' : 'pending'], ['seq' => $seq]) === false) { self::gap('outbox_finalize_failed'); return null; }
            update_option('magellan_v3_last_capture', gmdate('c'), false);
            self::schedule(1);
            return $id;
        } catch (\Throwable $e) { self::gap('capture_exception'); return null; }
    }
    public static function schedule(int $delay): void {
        if (function_exists('as_schedule_single_action') && did_action('action_scheduler_init')) {
            as_schedule_single_action(time() + max(1, $delay), self::HOOK, [], 'magellan-v3', true);
        } elseif (!wp_next_scheduled(self::HOOK)) { wp_schedule_single_event(time() + max(1, $delay), self::HOOK); }
    }
    public static function unresolved(): int { global $wpdb; return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . " WHERE state <> 'accepted'"); }
    public static function resume(): void {
        global $wpdb; $table = self::table();
        $wpdb->query($wpdb->prepare("UPDATE $table SET state='pending',next_attempt_at=%d,last_error='' WHERE state='blocked' AND last_error<>'privacy_erasure_pending' AND installation_id=%s", time(), Config::get()['installation_id'] ?? ''));
        self::schedule(1);
    }
    public static function retry_delay(int $attempt, int $retry_after = 0): int {
        return min(21600, max($retry_after, (int) (min(21600, 30 * (2 ** min(10, max(0, $attempt - 1))))) + random_int(0, 15)));
    }
    public static function retry(array $rows, string $code, int $retry_after = 0): void {
        global $wpdb; $table = self::table(); $next = time() + 21600;
        foreach ($rows as $row) {
            $when = time() + self::retry_delay((int) $row['attempts'] + 1, $retry_after); $next = min($next, $when);
            $state = time() - (int) $row['created_at'] >= 604800 ? 'dead_letter' : 'pending';
            $wpdb->update($table, ['state' => $state, 'next_attempt_at' => $when, 'lease_until' => 0, 'lease_token' => '', 'last_error' => $code], ['seq' => $row['seq'], 'state' => 'leased', 'lease_token' => $row['lease_token']]);
        }
        update_option('magellan_v3_circuit', $next, false);
    }
    public static function drain(): void {
        global $wpdb; $table = self::table();
        update_option('magellan_v3_last_runner', gmdate('c'), false);
        if (!Config::ready() || get_option('magellan_v3_auth_blocked')) { return; }
        $c = Config::get(); $circuit = (int) get_option('magellan_v3_circuit', 0);
        if ($circuit > time()) { self::schedule($circuit - time()); return; }
        $wpdb->query($wpdb->prepare("UPDATE $table SET state='pending',lease_token='',lease_until=0 WHERE state='leased' AND lease_until<%d", time()));
        $lease = wp_generate_uuid4();
        $wpdb->query($wpdb->prepare("UPDATE $table SET state='leased',lease_token=%s,lease_until=%d WHERE state='pending' AND next_attempt_at<=%d AND installation_id=%s ORDER BY seq LIMIT 50", $lease, time() + 120, time(), $c['installation_id']));
        $claimed = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE lease_token=%s AND state='leased' ORDER BY seq", $lease), ARRAY_A);
        if (!$claimed) { self::next(); return; }
        $rows = []; $parts = []; $size = 13;
        foreach ($claimed as $row) {
            if ($size + strlen($row['payload']) + 1 > 262144) { $wpdb->update($table, ['state' => 'pending', 'lease_token' => '', 'lease_until' => 0], ['seq' => $row['seq'], 'lease_token' => $lease, 'state' => 'leased']); continue; }
            $rows[] = $row; $parts[] = $row['payload']; $size += strlen($row['payload']) + 1;
            $wpdb->query($wpdb->prepare("UPDATE $table SET attempts=attempts+1 WHERE seq=%d AND lease_token=%s", $row['seq'], $lease));
        }
        $body = '{"events":[' . implode(',', $parts) . ']}';
        try {
            $response = wp_safe_remote_post($c['events_url'], ['body' => $body, 'headers' => Protocol::headers($c, 'POST', $c['events_url'], $body), 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 65536]);
            if (is_wp_error($response)) { self::retry($rows, 'network_error'); return; }
            $status = (int) wp_remote_retrieve_response_code($response);
            if (in_array($status, [401,403,410], true)) {
                foreach ($rows as $row) { $wpdb->update($table, ['state' => 'blocked', 'last_error' => 'authentication_' . $status, 'lease_until' => 0], ['seq' => $row['seq'], 'lease_token' => $lease, 'state' => 'leased']); }
                $wpdb->query($wpdb->prepare("UPDATE $table SET state='blocked',last_error='authentication_paused' WHERE state='pending' AND installation_id=%s", $c['installation_id']));
                update_option('magellan_v3_auth_blocked', true, false); return;
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $receipts = [];
            if (in_array($status, [200,202,207,400,409,422], true) && is_array($data['results'] ?? null)) {
                foreach ($data['results'] as $r) { if (is_array($r) && isset($r['event_id'])) { $receipts[$r['event_id']] = $r; } }
            }
            $pending = [];
            foreach ($rows as $row) {
                $r = $receipts[$row['event_id']] ?? [];
                $match = is_string($r['body_hash'] ?? null) && hash_equals($row['body_hash'], $r['body_hash']);
                if (in_array($status, [200,202,207], true) && $match && in_array($r['status'] ?? '', ['accepted','duplicate'], true) && is_string($r['receipt_id'] ?? null) && $r['receipt_id'] !== '' && !empty($r['received_at']) && strtotime($r['received_at']) !== false) {
                    $accepted = $wpdb->update($table, ['state' => 'accepted', 'accepted_at' => time(), 'receipt' => Protocol::json($r), 'lease_until' => 0, 'lease_token' => '', 'last_error' => ''], ['seq' => $row['seq'], 'lease_token' => $lease, 'state' => 'leased']);
                    if ($accepted === 1) { $quota = self::quota(); $wpdb->query("UPDATE $quota SET pending_rows=GREATEST(0,CAST(pending_rows AS SIGNED)-1) WHERE id=1"); }
                    update_option('magellan_v3_last_receipt', $r['received_at'], false);
                } elseif ($match && in_array($r['status'] ?? '', ['payload_conflict','rejected'], true)) {
                    $wpdb->update($table, ['state' => 'dead_letter', 'receipt' => Protocol::json($r), 'last_error' => substr((string) ($r['code'] ?? $r['status']), 0, 128), 'lease_until' => 0, 'lease_token' => ''], ['seq' => $row['seq'], 'lease_token' => $lease, 'state' => 'leased']);
                } else { $pending[] = $row; }
            }
            if ($pending) {
                $retry = wp_remote_retrieve_header($response, 'retry-after');
                $seconds = is_numeric($retry) ? (int) $retry : max(0, (int) strtotime((string) $retry) - time());
                self::retry($pending, 'http_' . $status . '_unacknowledged', $seconds);
            } else { delete_option('magellan_v3_circuit'); }
        } catch (\Throwable $e) { self::retry($rows, 'transport_exception'); }
        finally { self::next(); }
    }
    public static function next(): void {
        global $wpdb; $table = self::table();
        $next = $wpdb->get_var($wpdb->prepare("SELECT MIN(next_attempt_at) FROM $table WHERE state='pending' AND installation_id=%s", Config::get()['installation_id'] ?? ''));
        if ($next !== null) { self::schedule(max(1, (int) $next - time(), (int) get_option('magellan_v3_circuit', 0) - time())); }
    }
    public static function stats(): array {
        global $wpdb; $table = self::table();
        return ['quota' => $wpdb->get_row('SELECT pending_rows,reserved_bytes FROM ' . self::quota() . ' WHERE id=1', ARRAY_A), 'states' => $wpdb->get_results("SELECT state,COUNT(*) AS count,MIN(created_at) AS oldest_at FROM $table GROUP BY state", ARRAY_A), 'recent_errors' => $wpdb->get_results("SELECT event_id,entity_key,state,last_error,attempts FROM $table WHERE state IN ('blocked','dead_letter') ORDER BY seq DESC LIMIT 10", ARRAY_A), 'bytes' => (int) $wpdb->get_var("SELECT COALESCE(SUM(OCTET_LENGTH(payload)),0) FROM $table"), 'last_capture' => get_option('magellan_v3_last_capture', null), 'last_receipt' => get_option('magellan_v3_last_receipt', null), 'last_runner' => get_option('magellan_v3_last_runner', null), 'capture_gap' => get_option('magellan_v3_capture_gap', null), 'auth_blocked' => (bool) get_option('magellan_v3_auth_blocked', false), 'runner' => function_exists('as_schedule_single_action') ? 'action_scheduler' : 'wp_cron', 'real_cron_verified' => false];
    }
    public static function maintenance(): void {
        global $wpdb; $table = self::table();
        $expired = $wpdb->get_results($wpdb->prepare("SELECT seq,reserved_bytes FROM $table WHERE state='accepted' AND accepted_at<%d ORDER BY seq LIMIT 500", time() - 259200), ARRAY_A);
        $quota = self::quota();
        foreach ($expired as $row) {
            if ($wpdb->delete($table, ['seq' => $row['seq'], 'state' => 'accepted']) === 1) { $wpdb->query($wpdb->prepare("UPDATE $quota SET reserved_bytes=GREATEST(0,CAST(reserved_bytes AS SIGNED)-%d) WHERE id=1", $row['reserved_bytes'])); }
        }
        $stale = $wpdb->query($wpdb->prepare("UPDATE $table SET state='dead_letter',last_error='capture_incomplete' WHERE state='building' AND created_at<%d", time() - 300));
        if ($stale) { self::gap('capture_incomplete'); }
        // Never recount live quota reservations: a crash can conservatively over-reserve, never under-reserve.
        if (!Config::ready()) { return; }
        if (time() >= (int) get_option('magellan_v3_next_health', 0)) {
            self::capture('health_report', ['plugin_version' => MAGELLAN_VERSION, 'queue' => self::stats(), 'capabilities' => Admin::capabilities(), 'tracking_started_at' => Config::get()['tracking_started_at'] ?? null], null, [], null, 'health');
            update_option('magellan_v3_next_health', time() + random_int(18360, 24840), false);
        }
        Capture::reconcile();
        self::schedule(1);
    }
}
