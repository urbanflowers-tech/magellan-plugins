<?php
/**
 * Server-side order attribution.
 *
 * On woocommerce_checkout_order_created (every checkout type),
 * reads the _mgln first-party cookie from $_COOKIE and stamps
 * attribution metadata onto the order.
 *
 * Note: this only STAMPS attribution. The verified event SEND
 * happens later, on woocommerce_order_status_processing
 * (see class-magellan-sender.php).
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Magellan_Tracker {

	public static function init() {
		// Fires on classic checkout, Blocks checkout, and custom checkouts
		add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'stamp' ], 10, 1 );

		// Also stamp when an order is created via REST API (Magellan auto-install
		// can create test orders) — same hook fires.
	}

	/**
	 * Decode the _mgln cookie. Matches the JS encoding:
	 *   JS: btoa(encodeURIComponent(JSON.stringify(data)))
	 *   PHP: json_decode(urldecode(base64_decode(value)))
	 */
	public static function decode_cookie( string $raw ): ?array {
		if ( $raw === '' ) {
			return null;
		}
		$b = base64_decode( str_replace(' ', '+', rawurldecode($raw)), true );
		if ( $b === false ) {
			return null;
		}
		$u = urldecode( $b );
		$d = json_decode( $u, true );
		return is_array( $d ) ? $d : null;
	}

    public static function consent_state($order = null): string {
        if ($order instanceof WC_Order) { $saved = $order->get_meta('_mgln_analytics_consent'); if (in_array($saved, ['granted','denied','unknown'], true)) { return $saved; } }
        $raw = isset($_COOKIE['_mgln_consent']) ? wp_unslash($_COOKIE['_mgln_consent']) : '';
        $state = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($state) || abs(time() - (int) ($state['updated_at'] ?? 0)) > 1800) { return 'unknown'; }
        return in_array($state['analytics'] ?? '', ['granted','denied','unknown'], true) ? $state['analytics'] : 'unknown';
    }

	public static function stamp( $order ): void {
		if ( ! ( $order instanceof WC_Order ) ) {
			return;
		}

		// Don't re-stamp if already attributed
		if ( $order->get_meta( '_mgln_attributed_at' ) ) {
			return;
		}

		$consent = self::consent_state();
		$order->update_meta_data('_mgln_analytics_consent', $consent);
		if ($consent !== 'granted') { $order->update_meta_data('_mgln_attributed_at', time()); $order->save(); return; }

		// Cart token — links this order back to the anonymous cart captured
		// by magellan-cart.js. The JS mirrors its localStorage cart_token into
		// the `_mgln_cart_token` cookie so it's readable here at order
		// creation; stamping it onto the order lets the verified-event payload
		// carry it, which is what the backend uses to flip the matching cart
		// to 'converted' (and keep the abandonment janitor from marking a
		// completed purchase as abandoned). Captured regardless of the
		// attribution cookie below.
		$cart_token = isset( $_COOKIE['_mgln_cart_token'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['_mgln_cart_token'] ) )
			: '';
		if ( preg_match( '/^cart_[a-z0-9_]{8,64}$/i', $cart_token ) ) {
			$order->update_meta_data( '_mgln_cart_token', $cart_token );
		}

		$raw  = isset( $_COOKIE['_mgln'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_mgln'] ) ) : '';
		$data = self::decode_cookie( $raw );

		if ( ! $data ) {
			// No cookie — still stamp empty attribution so the row exists.
			// Source becomes 'direct' implicitly.
			$order->update_meta_data( '_mgln_attributed_at', time() );
			$order->update_meta_data( '_mgln_session_count', 1 );
			$order->save();
			return;
		}

		// Permanent attribution fields
		$order->update_meta_data( '_mgln_first_source',    self::s( $data, 'fs' ) );
		$order->update_meta_data( '_mgln_first_medium',    self::s( $data, 'fm' ) );
		$order->update_meta_data( '_mgln_first_campaign',  self::s( $data, 'fc' ) );
		$order->update_meta_data( '_mgln_first_content',   self::s( $data, 'fct' ) );
		$order->update_meta_data( '_mgln_first_term',      self::s( $data, 'ft_kw' ) );
		$order->update_meta_data( '_mgln_first_seen',      (int) ( $data['ft'] ?? 0 ) );
		$order->update_meta_data( '_mgln_first_landing',   self::s( $data, 'furl' ) );
		$order->update_meta_data( '_mgln_first_referrer',  self::s( $data, 'fref' ) );

		$order->update_meta_data( '_mgln_last_source',     self::s( $data, 'lsrc' ) );
		$order->update_meta_data( '_mgln_last_medium',     self::s( $data, 'lmed' ) );
		$order->update_meta_data( '_mgln_last_campaign',   self::s( $data, 'lcamp' ) );
		$order->update_meta_data( '_mgln_last_content',    self::s( $data, 'lcon' ) );
		$order->update_meta_data( '_mgln_last_seen',       (int) ( $data['lts'] ?? 0 ) );

		$order->update_meta_data( '_mgln_session_count',   (int) ( $data['sc'] ?? 1 ) );

		// Click IDs — JSON-encoded
		$click_ids = isset( $data['cids'] ) && is_array( $data['cids'] ) ? $data['cids'] : [];
		$sanitized = [];
		$allowed   = [ 'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid', 'twclid' ];
		foreach ( $allowed as $key ) {
			if ( isset( $click_ids[ $key ] ) && is_string( $click_ids[ $key ] ) ) {
				$sanitized[ $key ] = substr( sanitize_text_field( $click_ids[ $key ] ), 0, 500 );
			}
		}
		if ( ! empty( $sanitized ) ) {
			$order->update_meta_data( '_mgln_click_ids', wp_json_encode( $sanitized ) );
		}

		$order->update_meta_data( '_mgln_attributed_at', time() );
		$order->save();

		// Trigger identity stamping (email/phone hashes)
		do_action( 'magellan_stamp_identity', $order );
	}

	/**
	 * Safe string getter for cookie data.
	 */
	private static function s( array $data, string $key, int $max_len = 300 ): string {
		$v = $data[ $key ] ?? null;
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		return substr( sanitize_text_field( (string) $v ), 0, $max_len );
	}
}
