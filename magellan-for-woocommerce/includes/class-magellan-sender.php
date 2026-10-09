<?php
/**
 * Verified event sender. The only class that talks to Magellan's API.
 *
 * Critical design:
 *   - Verified event fires on woocommerce_order_status_processing
 *     (and _completed for gateways that skip processing).
 *     NEVER on woocommerce_checkout_order_created — avoids events
 *     for orders that fail (PromptPay timeouts, declined transfers).
 *   - HMAC-SHA256 signature on every request (Stripe-style header).
 *   - Action Scheduler background jobs. Thank-you page never blocks.
 *   - 200/201/202/409 = success (409 = idempotent duplicate).
 *   - 400/401/403/410 = terminal; do not retry, surface in admin.
 *   - 429/5xx = exception → Action Scheduler retries with backoff.
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Magellan_Sender {

	const SEND_DELAY_SECONDS = 10;

	public static function init() {
		// Primary: fire when order moves to 'processing' (payment confirmed / COD placed)
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'schedule_send' ], 10, 1 );

		// Secondary: some gateways skip 'processing' and go straight to 'completed'
		add_action( 'woocommerce_order_status_completed',  [ __CLASS__, 'schedule_send' ], 10, 1 );

		// Refunds — partial or full
		add_action( 'woocommerce_order_refunded',          [ __CLASS__, 'schedule_refund' ], 10, 2 );

		// Cancellation — only if a verified event was already sent
		add_action( 'woocommerce_order_status_cancelled',  [ __CLASS__, 'schedule_cancel' ], 10, 1 );

		// Background job handlers
		add_action( 'magellan_send_verified_event', [ __CLASS__, 'send_verified_event' ], 10, 1 );
		add_action( 'magellan_send_refund_event',   [ __CLASS__, 'send_refund_event' ], 10, 2 );
		add_action( 'magellan_send_cancel_event',   [ __CLASS__, 'send_cancel_event' ], 10, 1 );
	}

	// ----------------------------------------------------------------
	// Scheduling
	// ----------------------------------------------------------------

	public static function schedule_send( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! Magellan_Admin::is_configured() ) {
			return;
		}

		// Deduplicate — never schedule twice for the same order
		if ( $order->get_meta( '_mgln_event_sent' ) || (function_exists('as_has_scheduled_action') && as_has_scheduled_action('magellan_send_verified_event', [(int) $order_id], 'magellan')) || wp_next_scheduled('magellan_send_verified_event', [(int) $order_id]) ) {
			return;
		}

		// Ensure attribution stamped (it should have been on _created, but
		// belt-and-braces for orders that pre-date plugin activation)
		if ( ! $order->get_meta( '_mgln_attributed_at' ) ) {
			Magellan_Tracker::stamp( $order );
		}

		// Ensure identity stamped
		Magellan_Identity::stamp_identity( $order );

		$order->update_meta_data( '_mgln_event_scheduled', time() );
		$order->save();

		self::schedule_action( 'magellan_send_verified_event', [ $order_id ], self::SEND_DELAY_SECONDS );
	}

	public static function schedule_refund( $order_id, $refund_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! Magellan_Admin::is_configured() ) {
			return;
		}
		// Only refund if original event was sent
		if ( ! $order->get_meta( '_mgln_event_sent' ) ) {
			return;
		}
		self::schedule_action( 'magellan_send_refund_event', [ $order_id, $refund_id ], self::SEND_DELAY_SECONDS );
	}

	public static function schedule_cancel( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! Magellan_Admin::is_configured() ) {
			return;
		}
		// Only cancel-event if original event was sent (otherwise nothing to retract)
		if ( ! $order->get_meta( '_mgln_event_sent' ) ) {
			return;
		}
		self::schedule_action( 'magellan_send_cancel_event', [ $order_id ], self::SEND_DELAY_SECONDS );
	}

	private static function schedule_action( string $hook, array $args, int $delay ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, $hook, $args, 'magellan', true );
		} else {
			wp_schedule_single_event( time() + $delay, $hook, $args );
		}
	}

	// ----------------------------------------------------------------
	// Payload builders
	// ----------------------------------------------------------------

	public static function send_verified_event( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_mgln_event_sent' ) ) {
			return; // already sent — idempotent
		}

		$created    = $order->get_date_created();
		$timestamp  = $created ? $created->getTimestamp() : time();
		$order_key  = $order->get_order_key();
		$event_id   = 'evt_' . $order_id . '_' . substr( $order_key, -8 );

		$click_ids_raw = $order->get_meta( '_mgln_click_ids' );
		$click_ids     = $click_ids_raw ? (array) json_decode( $click_ids_raw, true ) : [];

		$first_seen = (int) $order->get_meta( '_mgln_first_seen' );
		$last_seen  = (int) $order->get_meta( '_mgln_last_seen' );

		$payload = [
			'event_id'          => $event_id,
			'event_type'        => 'order_placed',
			'occurred_at'       => gmdate( 'c', $timestamp ),

			'platform'          => 'woocommerce',
			'platform_order_id' => (string) $order_id,
			'order_number'      => (string) $order->get_order_number(),
			'order_status'      => $order->get_status(),

			// Links this order to the anonymous cart captured by
			// magellan-cart.js (stamped onto the order by Magellan_Tracker).
			// The backend flips the matching cart to 'converted' on this
			// value. Null when the shopper had no tracked cart token.
			'cart_token'        => $order->get_meta( '_mgln_cart_token' ) ?: null,

			'currency'          => $order->get_currency(),
			'subtotal'          => (float) $order->get_subtotal(),
			'shipping'          => (float) $order->get_shipping_total(),
			'tax'               => (float) $order->get_total_tax(),
			'discount'          => (float) $order->get_total_discount(),
			'total'             => (float) $order->get_total(),

			'items' => self::build_items( $order ),

			'identity' => [
				'email_hash' => $order->get_meta( '_mgln_identity_hash' ) ?: null,
				'phone_hash' => $order->get_meta( '_mgln_phone_hash' )    ?: null,
				'is_returning_customer' => self::is_returning_customer( $order ),
			],

			'attribution' => [
				'first_touch' => [
					'source'      => $order->get_meta( '_mgln_first_source' )   ?: null,
					'medium'      => $order->get_meta( '_mgln_first_medium' )   ?: null,
					'campaign'    => $order->get_meta( '_mgln_first_campaign' ) ?: null,
					'content'     => $order->get_meta( '_mgln_first_content' )  ?: null,
					'term'        => $order->get_meta( '_mgln_first_term' )     ?: null,
					'landing_url' => $order->get_meta( '_mgln_first_landing' )  ?: null,
					'referrer'    => $order->get_meta( '_mgln_first_referrer' ) ?: null,
					'occurred_at' => $first_seen ? gmdate( 'c', $first_seen ) : null,
				],
				'last_paid_touch' => [
					'source'      => $order->get_meta( '_mgln_last_source' )   ?: null,
					'medium'      => $order->get_meta( '_mgln_last_medium' )   ?: null,
					'campaign'    => $order->get_meta( '_mgln_last_campaign' ) ?: null,
					'content'     => $order->get_meta( '_mgln_last_content' )  ?: null,
					'occurred_at' => $last_seen ? gmdate( 'c', $last_seen ) : null,
				],
				'click_ids' => [
					'fbclid'  => $click_ids['fbclid']  ?? null,
					'gclid'   => $click_ids['gclid']   ?? null,
					'gbraid'  => $click_ids['gbraid']  ?? null,
					'wbraid'  => $click_ids['wbraid']  ?? null,
					'ttclid'  => $click_ids['ttclid']  ?? null,
					'msclkid' => $click_ids['msclkid'] ?? null,
					'twclid'  => $click_ids['twclid']  ?? null,
				],
				'session_count'  => (int) $order->get_meta( '_mgln_session_count' ),
				'first_visit_at' => $first_seen ? gmdate( 'c', $first_seen ) : null,
			],

			'context' => [
				'user_agent'    => $order->get_customer_user_agent() ?: null,
				'ip_address'    => $order->get_customer_ip_address() ?: null,
				'consent_state' => apply_filters( 'magellan_consent_state', Magellan_Tracker::consent_state($order), $order ),
			],
		];

		$ok = self::dispatch_signed( MAGELLAN_ENDPOINT_EVENT, $payload );

		if ( $ok ) {
			$order->update_meta_data( '_mgln_event_sent', time() );
			$order->save();
			update_option( Magellan_Admin::OPT_LAST_EVENT, time() );
			self::increment_counter( 'events_sent_24h' );
		}
	}

	public static function send_refund_event( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}

		// Dedup per refund
		$sent = $order->get_meta( '_mgln_refunds_sent' );
		$sent = is_array( $sent ) ? $sent : [];
		if ( in_array( (int) $refund_id, array_map( 'intval', $sent ), true ) ) {
			return;
		}

		$order_key        = $order->get_order_key();
		$original_event_id = 'evt_' . $order_id . '_' . substr( $order_key, -8 );

		$created   = $refund->get_date_created();
		$timestamp = $created ? $created->getTimestamp() : time();

		$payload = [
			'event_id'          => 'evt_refund_' . $refund_id,
			'event_type'        => 'order_refunded',
			'occurred_at'       => gmdate( 'c', $timestamp ),

			'platform'          => 'woocommerce',
			'platform_order_id' => (string) $order_id,
			'original_event_id' => $original_event_id,

			'refund_id'         => (string) $refund_id,
			'refund_amount'     => (float) $refund->get_amount(),
			'refund_reason'     => $refund->get_reason() ?: null,
			'currency'          => $order->get_currency(),
			'is_partial_refund' => (float) $refund->get_amount() < (float) $order->get_total(),

			'identity' => [
				'email_hash' => $order->get_meta( '_mgln_identity_hash' ) ?: null,
				'phone_hash' => $order->get_meta( '_mgln_phone_hash' )    ?: null,
			],

			'context' => [
				'consent_state' => apply_filters( 'magellan_consent_state', Magellan_Tracker::consent_state($order), $order ),
			],
		];

		$ok = self::dispatch_signed( MAGELLAN_ENDPOINT_EVENT, $payload );

		if ( $ok ) {
			$sent[] = (int) $refund_id;
			$order->update_meta_data( '_mgln_refunds_sent', $sent );
			$order->save();
		}
	}

	public static function send_cancel_event( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_mgln_cancel_sent' ) ) {
			return;
		}

		$order_key        = $order->get_order_key();
		$original_event_id = 'evt_' . $order_id . '_' . substr( $order_key, -8 );

		$payload = [
			'event_id'          => 'evt_cancel_' . $order_id . '_' . time(),
			'event_type'        => 'order_cancelled',
			'occurred_at'       => gmdate( 'c' ),

			'platform'          => 'woocommerce',
			'platform_order_id' => (string) $order_id,
			'original_event_id' => $original_event_id,

			'currency'          => $order->get_currency(),
			'total'             => (float) $order->get_total(),

			'identity' => [
				'email_hash' => $order->get_meta( '_mgln_identity_hash' ) ?: null,
				'phone_hash' => $order->get_meta( '_mgln_phone_hash' )    ?: null,
			],
		];

		$ok = self::dispatch_signed( MAGELLAN_ENDPOINT_EVENT, $payload );
		if ( $ok ) {
			$order->update_meta_data( '_mgln_cancel_sent', time() );
			$order->save();
		}
	}

	// ----------------------------------------------------------------
	// Identity batch sender — called from Magellan_Identity::run_historical_sync
	// ----------------------------------------------------------------

	public static function send_identity_batch( array $identities, string $sync_run_id, int $batch_num, int $batch_total, bool $is_final ): bool {
		if (!Magellan_Admin::is_configured()) { return false; }
        if (empty($identities)) { return true; }

		$payload = [
			'batch_id'      => sprintf( 'batch_%03d_of_%03d', $batch_num, max( $batch_total, 1 ) ),
			'sync_run_id'   => $sync_run_id,
			'occurred_at'   => gmdate( 'c' ),
			'platform'      => 'woocommerce',
			'identities'    => array_values( $identities ),
			'is_final_batch' => $is_final,
		];

		return self::dispatch_signed( MAGELLAN_ENDPOINT_IDENTS, $payload );
	}

	// ----------------------------------------------------------------
	// Cart email sender — called SYNCHRONOUSLY from the /cart-email REST
	// endpoint (checkout email capture; low frequency, user-typed).
	// ----------------------------------------------------------------

	public static function send_cart_email( array $payload ): bool {
		if ( ! Magellan_Admin::is_configured() ) {
			return false;
		}
		$payload['platform']    = 'woocommerce';
		if ( ! isset( $payload['occurred_at'] ) ) {
			$payload['occurred_at'] = gmdate( 'c' );
		}
		return self::dispatch_signed( MAGELLAN_ENDPOINT_CART, $payload );
	}

	// ----------------------------------------------------------------
	// Cart-state sender — server-side cart hooks (Magellan_Cart) capture the
	// snapshot and queue it here for ASYNC delivery, so the shopper's
	// add-to-cart response is never blocked by a call to Magellan.
	// ----------------------------------------------------------------

	/**
	 * Queue an async signed send of a captured cart snapshot. Prefers
	 * as_enqueue_async_action (runs ASAP on a loopback request) so capture is
	 * near-immediate without blocking; falls back to a 1s scheduled action,
	 * then WP-Cron. Same backend route as the email path (/cart-email).
	 */
	public static function schedule_cart_send( array $payload ): void {
		if ( ! Magellan_Admin::is_configured() ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'magellan_send_cart_event', [ $payload ], 'magellan' );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 1, 'magellan_send_cart_event', [ $payload ], 'magellan' );
		} else {
			wp_schedule_single_event( time() + 1, 'magellan_send_cart_event', [ $payload ] );
		}
	}

	/**
	 * Action Scheduler callback for a queued cart-state snapshot.
	 * dispatch_signed() throws on retryable (429/5xx) so AS retries with
	 * backoff; terminal codes are recorded and dropped.
	 */
	public static function send_cart_event( $payload ): void {
		if ( ! is_array( $payload ) || ! Magellan_Admin::is_configured() ) {
			return;
		}
		$payload['platform'] = 'woocommerce';
		if ( ! isset( $payload['occurred_at'] ) ) {
			$payload['occurred_at'] = gmdate( 'c' );
		}
		self::dispatch_signed( MAGELLAN_ENDPOINT_CART, $payload );
	}

	// ----------------------------------------------------------------
	// HMAC-signed dispatch (Stripe-style signature)
	// ----------------------------------------------------------------

	private static function dispatch_signed( string $endpoint, array $payload ): bool {
		$account_id    = Magellan_Admin::get_account_id();
		// CRITICAL: backend stores secrets as base64 of 32 random bytes
		// and HMAC-verifies using the raw DECODED bytes as the key.
		// We must base64-decode here before signing — otherwise every
		// signed request 401s. See class-magellan-admin.php.
		$signing_bytes = Magellan_Admin::get_signing_secret_bytes();
		if ( $account_id === '' || $signing_bytes === '' ) {
			return false;
		}

		$body      = wp_json_encode( $payload );
		$timestamp = time();
		$signed    = $timestamp . '.' . $body;
		$signature = hash_hmac( 'sha256', $signed, $signing_bytes );

		if (!\Magellan\V3\Config::endpoint($endpoint)) { Magellan_Admin::record_error('Invalid API endpoint: HTTPS with no redirect is required.'); return false; }
		$response = wp_safe_remote_post( $endpoint, [
			'redirection' => 0,
			'body'      => $body,
			'headers'   => [
				'Content-Type'              => 'application/json',
				'X-Magellan-Account'        => $account_id,
				'X-Magellan-Signature'      => 't=' . $timestamp . ',v1=' . $signature,
				'X-Magellan-Plugin-Version' => MAGELLAN_VERSION,
			],
			'blocking'   => true,
			'timeout'    => 15,
			'user-agent' => 'MagellanWooPlugin/' . MAGELLAN_VERSION . ' WordPress/' . get_bloginfo( 'version' ) . ' PHP/' . PHP_VERSION,
		] );

		if ( is_wp_error( $response ) ) {
			Magellan_Admin::record_error(
				sprintf(
					/* translators: %s: error message from the HTTP layer */
					__( 'Dispatch failed: %s', 'magellan-for-woocommerce' ),
					$response->get_error_message()
				)
			);
			// Throw so Action Scheduler retries
			throw new \Exception( 'Magellan dispatch failed: ' . esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );

		// Success codes — 200, 201, 202, 409 (idempotent duplicate)
		if ( in_array( $code, [ 200, 201, 202, 409 ], true ) ) {
			return true;
		}

		// Terminal errors — do not retry
		if ( in_array( $code, [ 400, 401, 403, 410 ], true ) ) {
			$msg = sprintf(
				/* translators: 1: HTTP status code, 2: API path */
				__( 'Terminal %1$d on %2$s. Check Magellan dashboard / reconnect.', 'magellan-for-woocommerce' ),
				$code,
				wp_parse_url( $endpoint, PHP_URL_PATH )
			);
			Magellan_Admin::record_error( $msg );
			return false;
		}

		// 429 / 5xx — throw exception so Action Scheduler retries with backoff
		throw new \Exception( 'Magellan retryable ' . $code . ' on ' . $endpoint );
	}

	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	private static function build_items( WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$qty     = (int) $item->get_quantity();
			$total   = (float) $item->get_total();
			$items[] = [
				'sku'        => $product ? (string) $product->get_sku() : '',
				'product_id' => (int) $item->get_product_id(),
				'name'       => (string) $item->get_name(),
				'quantity'   => $qty,
				'unit_price' => $qty > 0 ? round( $total / $qty, 4 ) : 0.0,
				'total'      => $total,
			];
		}
		return $items;
	}

	private static function is_returning_customer( WC_Order $order ): bool {
		$customer_id = $order->get_customer_id();
		if ( $customer_id > 0 && function_exists( 'wc_get_customer_order_count' ) ) {
			return wc_get_customer_order_count( $customer_id ) > 1;
		}
		// Guest checkout: check by email
		$email = $order->get_billing_email();
		if ( ! $email ) {
			return false;
		}
		$prior = wc_get_orders( [
			'limit'         => 2,
			'billing_email' => $email,
			'status'        => [ 'wc-completed', 'wc-processing', 'wc-refunded' ],
			'return'        => 'ids',
		] );
		return is_array( $prior ) && count( $prior ) > 1;
	}

	private static function increment_counter( string $key ): void {
		$counters = (array) get_option( 'magellan_counters', [] );
		$day      = gmdate( 'Y-m-d' );
		if ( ! isset( $counters[ $day ] ) ) {
			$counters = [ $day => [] ];
		}
		$counters[ $day ][ $key ] = ( $counters[ $day ][ $key ] ?? 0 ) + 1;
		update_option( 'magellan_counters', $counters );
	}
}
