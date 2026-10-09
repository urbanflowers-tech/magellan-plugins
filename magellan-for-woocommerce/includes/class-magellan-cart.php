<?php
/**
 * Cart tracking and abandonment.
 *
 * Three responsibilities:
 *   1. Server-side cart-state capture — hook WC core cart events
 *      (add_to_cart / item removed / qty updated / emptied), snapshot the
 *      final cart once per request at shutdown, and queue an async signed
 *      send. This is the canonical, universal capture path (replaces the old
 *      browser-side magellan-cart.js listener); it needs no client JS, fires
 *      only on real changes (not page loads), and works on any theme.
 *   2. Register REST endpoint POST /wp-json/magellan/v1/cart-email — called
 *      by checkout JS when the shopper types their email, to attach a
 *      (hashed) identity to the cart before an order exists. Email-before-
 *      order is browser-only, so this one path stays client-side.
 *   3. Webhook reliability backup — every 5 minutes, scan for orders with
 *      verified events not yet sent.
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Magellan_Cart {

	const TRANSIENT_RATE_LIMIT = 'mgln_cart_rl_';
	const RATE_LIMIT_PER_MIN   = 10;

	/** Per-request flag: a cart mutation occurred; snapshot once at shutdown. */
	private static $cart_dirty = false;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'magellan_sync_check', [ __CLASS__, 'sync_check' ] );

		// --- Server-side cart capture (the canonical path) ---
		// Every cart mutation goes through WC core, which fires these hooks
		// exactly once per change — and NOT on page loads / session restore
		// (which uses set_cart_contents(), firing only
		// woocommerce_cart_loaded_from_session). We flag the request dirty and
		// snapshot the FINAL cart once at shutdown: universal across
		// themes / Blocks / custom add-to-cart, no client JS, no caching
		// dependency. Replaces the old browser-side magellan-cart.js listener.
		//
		// We deliberately do NOT hook woocommerce_cart_emptied: WooCommerce
		// empties the cart on ORDER COMPLETION, so capturing it would send a
		// 0-item snapshot for the just-converted cart. We don't record carts
		// going empty at all (an empty cart isn't an abandoned cart) — see the
		// empty-snapshot skip in maybe_capture_cart().
		$mark = [ __CLASS__, 'mark_cart_dirty' ];
		add_action( 'woocommerce_add_to_cart',                     $mark );
		add_action( 'woocommerce_cart_item_removed',               $mark );
		add_action( 'woocommerce_cart_item_restored',              $mark );
		add_action( 'woocommerce_after_cart_item_quantity_update', $mark );
		add_action( 'shutdown', [ __CLASS__, 'maybe_capture_cart' ] );

		// Async delivery of the captured snapshot (Action Scheduler / WP-Cron).
		add_action( 'magellan_send_cart_event', [ 'Magellan_Sender', 'send_cart_event' ], 10, 1 );
	}

	public static function mark_cart_dirty() {
		self::$cart_dirty = true;
	}

	/**
	 * Runs on `shutdown` (after the response is sent — zero shopper latency).
	 * If the cart changed this request, snapshot the FINAL cart state and queue
	 * an async signed send. cart_token + attribution come from the first-party
	 * cookies the pixel sets on page load. One send per request regardless of
	 * how many mutations fired.
	 */
	public static function maybe_capture_cart() {
		if ( ! self::$cart_dirty || ! Magellan_Admin::is_configured() || Magellan_Tracker::consent_state() !== 'granted' ) {
			return;
		}
		$cart_token = self::cart_token_from_cookie();
		if ( $cart_token === '' ) {
			return; // pixel sets it on page load; absent => nothing to key on
		}
		try {
			// WC()->cart is loaded in a cart-mutation request, so this does NOT
			// hit the custom-REST-context wc_load_cart() fatal that the old
			// /cart route did. Guarded anyway.
			$snapshot = self::current_cart_snapshot();
		} catch ( \Throwable $e ) {
			Magellan_Admin::record_error( 'Cart hook snapshot failed: ' . $e->getMessage() );
			return;
		}
		// Don't send an empty snapshot: an empty cart isn't an abandoned cart,
		// and the backend preserves captured items on an empty event anyway.
		// (Covers a shopper removing their last item; order-completion empties
		// aren't hooked at all — see init().)
		if ( empty( $snapshot['items'] ) ) {
			return;
		}
		$payload = [
			'cart_token'    => $cart_token,
			'occurred_at'   => gmdate( 'c' ),
			// Cart-state event — anonymous (no email). Identity is attached
			// later by the checkout-email event sharing the same cart_token.
			'identity'      => [ 'email_hash' => null, 'phone_hash' => null ],
			'attribution'   => self::attribution_from_cookie(),
			'cart'          => $snapshot,
			'consent_state' => apply_filters( 'magellan_consent_state', Magellan_Tracker::consent_state(), null ),
		];
		Magellan_Sender::schedule_cart_send( $payload );
	}

	private static function cart_token_from_cookie(): string {
		$t = isset( $_COOKIE['_mgln_cart_token'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_mgln_cart_token'] ) ) : '';
		return preg_match( '/^cart_[a-z0-9_]{8,64}$/i', $t ) ? $t : '';
	}

	/**
	 * Build the backend-contract attribution block from the `_mgln` cookie the
	 * pixel set — reuses Magellan_Tracker::decode_cookie() and the same
	 * short-key mapping the old client buildPayload used.
	 */
	private static function attribution_from_cookie(): array {
		$raw  = isset( $_COOKIE['_mgln'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_mgln'] ) ) : '';
		$data = Magellan_Tracker::decode_cookie( $raw );
		if ( ! is_array( $data ) ) {
			return [ 'first_touch' => null, 'last_paid_touch' => null, 'click_ids' => [], 'session_count' => 1 ];
		}
		$ft  = isset( $data['ft'] ) ? (int) $data['ft'] : 0;
		$lts = isset( $data['lts'] ) ? (int) $data['lts'] : 0;
		$first = ! empty( $data['fs'] ) ? [
			'source'      => self::cstr( $data, 'fs' ),
			'medium'      => self::cstr( $data, 'fm' ),
			'campaign'    => self::cstr( $data, 'fc' ),
			'occurred_at' => $ft ? gmdate( 'c', $ft ) : null,
		] : null;
		$last = ! empty( $data['lsrc'] ) ? [
			'source'      => self::cstr( $data, 'lsrc' ),
			'medium'      => self::cstr( $data, 'lmed' ),
			'campaign'    => self::cstr( $data, 'lcamp' ),
			'occurred_at' => $lts ? gmdate( 'c', $lts ) : null,
		] : null;
		$click_ids = [];
		$allowed   = [ 'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid', 'twclid' ];
		$cids = isset( $data['cids'] ) && is_array( $data['cids'] ) ? $data['cids'] : [];
		foreach ( $allowed as $k ) {
			if ( isset( $cids[ $k ] ) && is_string( $cids[ $k ] ) ) {
				$click_ids[ $k ] = substr( sanitize_text_field( $cids[ $k ] ), 0, 500 );
			}
		}
		return [
			'first_touch'     => $first,
			'last_paid_touch' => $last,
			'click_ids'       => $click_ids,
			'session_count'   => isset( $data['sc'] ) ? (int) $data['sc'] : 1,
		];
	}

	private static function cstr( array $data, string $key ): ?string {
		$v = $data[ $key ] ?? null;
		return is_string( $v ) && $v !== '' ? substr( sanitize_text_field( (string) $v ), 0, 500 ) : null;
	}

	// -----------------------------------------------------------
	// REST endpoint — receives hashed email from checkout JS
	// -----------------------------------------------------------

	public static function register_rest_routes() {
		// Checkout email capture (email REQUIRED) — called by checkout JS
		// when the shopper types their email.
		register_rest_route( 'magellan/v1', '/cart-email', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_cart_email' ],
			'permission_callback' => '__return_true',
		] );

		// NOTE: the old anonymous cart-state route (POST /magellan/v1/cart,
		// driven by magellan-cart.js) is gone — cart state is now captured
		// server-side via WC hooks (see init()). Only the checkout-email
		// capture remains client-side (email-before-order is browser-only).
	}

	/** Checkout email path — email required. */
	public static function handle_cart_email( WP_REST_Request $req ) {
		return self::handle_cart_event( $req, true );
	}

	/**
	 * Shared handler for both cart routes. Validates, builds the signed
	 * payload, and forwards to Magellan.
	 *
	 * @param bool $require_email When true (checkout path) a valid
	 *                            email_hash must be present; when false
	 *                            (cart-state path) it is optional and the
	 *                            cart is recorded anonymously.
	 */
	private static function handle_cart_event( WP_REST_Request $req, bool $require_email ) {
		if ( ! Magellan_Admin::is_configured() ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'not_configured' ], 200 );
		}

		if (Magellan_Tracker::consent_state() !== 'granted') { return new WP_REST_Response(['ok'=>false,'reason'=>'analytics_consent_required'], 200); }

		// Rate limit per IP — 10/min, with a SEPARATE budget per route so the
		// high-volume anonymous /cart traffic can't starve the higher-value
		// checkout /cart-email capture (priority inversion).
		$ip  = self::client_ip();
		$key = self::TRANSIENT_RATE_LIMIT . ( $require_email ? 'email_' : 'cart_' ) . md5( $ip );
		$cnt = (int) get_transient( $key );
		if ( $cnt >= self::RATE_LIMIT_PER_MIN ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'rate_limited' ], 429 );
		}
		set_transient( $key, $cnt + 1, MINUTE_IN_SECONDS );

		$params = $req->get_json_params();

		// Validate cart_token format (always required — it's the dedup key).
		$cart_token = isset( $params['cart_token'] ) ? (string) $params['cart_token'] : '';
		if ( ! preg_match( '/^cart_[a-z0-9_]{8,64}$/i', $cart_token ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'bad_token' ], 400 );
		}

		// Email hash: required on the checkout path, optional+validated-if-
		// present on the anonymous cart-state path.
		$identity   = is_array( $params['identity'] ?? null ) ? $params['identity'] : [];
		$email_raw  = isset( $identity['email_hash'] ) ? (string) $identity['email_hash'] : '';
		$has_email  = $email_raw !== '';
		$email_hash = null;
		if ( $has_email ) {
			if ( ! preg_match( '/^sha256:[a-f0-9]{64}$/', $email_raw ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'reason' => 'bad_hash' ], 400 );
			}
			$email_hash = $email_raw;
		} elseif ( $require_email ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'bad_hash' ], 400 );
		}

		// Whitelist attribution + click IDs to prevent payload abuse
		$attribution = is_array( $params['attribution'] ?? null ) ? $params['attribution'] : [];
		$click_ids   = is_array( $attribution['click_ids'] ?? null ) ? $attribution['click_ids'] : [];
		$allowed_cid = [ 'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid', 'twclid' ];
		$click_clean = [];
		foreach ( $allowed_cid as $k ) {
			if ( isset( $click_ids[ $k ] ) && is_string( $click_ids[ $k ] ) ) {
				$click_clean[ $k ] = substr( sanitize_text_field( $click_ids[ $k ] ), 0, 500 );
			}
		}

		$payload = [
			'cart_token' => $cart_token,
			'occurred_at' => isset( $params['occurred_at'] ) ? sanitize_text_field( (string) $params['occurred_at'] ) : gmdate( 'c' ),
			'identity' => [
				'email_hash' => $email_hash,
				'phone_hash' => null,
			],
			'attribution' => [
				'first_touch'     => self::sanitize_touch( $attribution['first_touch'] ?? null ),
				'last_paid_touch' => self::sanitize_touch( $attribution['last_paid_touch'] ?? null ),
				'click_ids'       => $click_clean,
				'session_count'   => isset( $attribution['session_count'] ) ? (int) $attribution['session_count'] : 1,
			],
			'cart' => self::resolve_cart_snapshot( $params ),
			'consent_state' => apply_filters( 'magellan_consent_state', Magellan_Tracker::consent_state(), null ),
		];

		// Forward to Magellan (signed). Same backend route for both — the
		// backend treats email as optional.
		//
		// CRITICAL: this is a SYNCHRONOUS REST handler. The sender throws on
		// connection failure / 5xx so the ORDER path (Action Scheduler) can
		// retry — but here an uncaught throw becomes an HTTP 500. The cart
		// listener only records its dedup hash on a 2xx, so a 500 makes it
		// retry the same event forever and the cart never reaches Magellan.
		// Swallow transport errors: cart capture is fire-and-forget.
		try {
			Magellan_Sender::send_cart_email( $payload );
		} catch ( \Throwable $e ) {
			Magellan_Admin::record_error( 'Cart forward failed: ' . $e->getMessage() );
		}

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Resolve the cart snapshot for the outbound payload.
	 *
	 * Source priority:
	 *   1. Client-supplied `cart` — the listener reads it from WooCommerce's
	 *      own Store API (`/wp-json/wc/store/v1/cart`) in the browser, which
	 *      is REST-safe and authoritative. Sanitized before use.
	 *   2. Server-side read via {@see current_cart_snapshot()} — wrapped in a
	 *      \Throwable guard so a fatal in `wc_load_cart()` / cart totals (which
	 *      can happen in a custom REST context where WC()->customer / session
	 *      isn't initialized) degrades to an empty snapshot instead of
	 *      returning HTTP 500 for the whole request.
	 */
	private static function resolve_cart_snapshot( array $params ): array {
		$client = isset( $params['cart'] ) && is_array( $params['cart'] ) ? $params['cart'] : null;
		if ( $client !== null ) {
			$clean = self::sanitize_client_cart( $client );
			if ( $clean !== null ) {
				return $clean;
			}
		}

		try {
			return self::current_cart_snapshot();
		} catch ( \Throwable $e ) {
			Magellan_Admin::record_error( 'Cart snapshot failed: ' . $e->getMessage() );
			return [ 'items' => [], 'subtotal' => 0.0, 'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ];
		}
	}

	/**
	 * Sanitize a browser-supplied cart snapshot (sourced from the WC Store
	 * API). Bounds every field so a tampered payload can't bloat the request
	 * — abandoned-cart figures are analytics only; the verified ORDER remains
	 * the financial source of truth, so a spoofed pre-purchase cart has no
	 * downstream financial effect.
	 *
	 * @return array|null Null when the structure is unusable (caller then
	 *                    falls back to the server-side snapshot).
	 */
	private static function sanitize_client_cart( array $cart ): ?array {
		$items_in = isset( $cart['items'] ) && is_array( $cart['items'] ) ? $cart['items'] : null;
		if ( $items_in === null ) {
			return null;
		}
		$items = [];
		foreach ( array_slice( $items_in, 0, 100 ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$items[] = [
				'sku'        => isset( $it['sku'] ) ? substr( sanitize_text_field( (string) $it['sku'] ), 0, 100 ) : '',
				'product_id' => isset( $it['product_id'] ) ? max( 0, (int) $it['product_id'] ) : 0,
				'quantity'   => isset( $it['quantity'] ) ? max( 0, min( 100000, (int) $it['quantity'] ) ) : 0,
			];
		}
		$subtotal = isset( $cart['subtotal'] ) ? (float) $cart['subtotal'] : 0.0;
		$subtotal = max( 0.0, min( $subtotal, 1.0e12 ) );
		$currency = isset( $cart['currency'] ) && is_string( $cart['currency'] ) && $cart['currency'] !== ''
			? substr( sanitize_text_field( $cart['currency'] ), 0, 8 )
			: ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' );
		return [ 'items' => $items, 'subtotal' => $subtotal, 'currency' => $currency ];
	}

	private static function sanitize_touch( $touch ): ?array {
		if ( ! is_array( $touch ) ) {
			return null;
		}
		$allowed = [ 'source', 'medium', 'campaign', 'content', 'term', 'occurred_at', 'landing_url', 'referrer' ];
		$out = [];
		foreach ( $allowed as $k ) {
			if ( isset( $touch[ $k ] ) && is_string( $touch[ $k ] ) ) {
				$out[ $k ] = substr( sanitize_text_field( $touch[ $k ] ), 0, 500 );
			}
		}
		return $out ?: null;
	}

	private static function current_cart_snapshot(): array {
		// In a custom REST request (the cart-state route fires site-wide,
		// not just on checkout), WC()->cart is not auto-initialized.
		// wc_load_cart() loads the cart + session for the current request
		// from the visitor's session cookie, so the snapshot reflects the
		// real cart on any page. No-op when the cart is already loaded.
		if ( function_exists( 'WC' ) && function_exists( 'wc_load_cart' ) && ( ! WC()->cart ) ) {
			wc_load_cart();
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [ 'items' => [], 'subtotal' => 0.0, 'currency' => get_woocommerce_currency() ];
		}
		$items = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			$items[] = [
				'sku'        => $product && method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
				'product_id' => (int) ( $item['product_id'] ?? 0 ),
				'quantity'   => (int) ( $item['quantity'] ?? 0 ),
			];
		}
		return [
			'items'    => $items,
			'subtotal' => (float) WC()->cart->get_subtotal(),
			'currency' => get_woocommerce_currency(),
		];
	}

	// -----------------------------------------------------------
	// Webhook reliability backup
	// Every 5 min, find orders that should have sent a verified
	// event but haven't, and re-schedule.
	// -----------------------------------------------------------

	public static function sync_check() {
		if ( ! Magellan_Admin::is_configured() ) {
			return;
		}

		$orders = wc_get_orders( [
			'status'       => [ 'wc-processing', 'wc-completed' ],
			'date_created' => '>' . ( time() - 2 * HOUR_IN_SECONDS ),
			'limit'        => 25,
			'return'       => 'objects',
			'meta_query'   => [
				[
					'key'     => '_mgln_event_sent',
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			// Re-trigger the send via the normal path
			Magellan_Sender::schedule_send( $order->get_id() );
		}
	}

	private static function client_ip(): string {
		$candidates = [ 'REMOTE_ADDR' ]; // Proxy headers are untrusted without an explicit trusted-proxy configuration.
		foreach ( $candidates as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
				$ip = trim( explode( ',', $ip )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
