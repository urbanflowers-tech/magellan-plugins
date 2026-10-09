<?php
/**
 * Email + phone hashing. Country-aware E.164 normalization for phones.
 *
 * Raw email and phone NEVER leave WordPress. Only SHA-256 hashes
 * are stored on the order or transmitted to Magellan.
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Magellan_Identity {

	/**
	 * Country-code lookup for E.164 normalization.
	 * Covers UrbanFlowers' primary markets + the most common international.
	 */
	const COUNTRY_DIAL_CODES = [
		'TH' => '66',  // Thailand (UrbanFlowers home)
		'US' => '1',
		'CA' => '1',
		'GB' => '44',
		'AU' => '61',
		'NZ' => '64',
		'SG' => '65',
		'MY' => '60',
		'ID' => '62',
		'VN' => '84',
		'PH' => '63',
		'KH' => '855',
		'LA' => '856',
		'MM' => '95',
		'JP' => '81',
		'KR' => '82',
		'CN' => '86',
		'HK' => '852',
		'TW' => '886',
		'IN' => '91',
		'DE' => '49',
		'FR' => '33',
		'IT' => '39',
		'ES' => '34',
		'NL' => '31',
		'SE' => '46',
		'NO' => '47',
		'DK' => '45',
		'FI' => '358',
		'PL' => '48',
		'CH' => '41',
		'AT' => '43',
		'BE' => '32',
		'IE' => '353',
		'PT' => '351',
		'BR' => '55',
		'MX' => '52',
		'AR' => '54',
		'AE' => '971',
		'SA' => '966',
		'IL' => '972',
		'ZA' => '27',
	];

	public static function init() {
		// Stamp identity on order placement (called from Tracker)
		add_action( 'magellan_stamp_identity', [ __CLASS__, 'stamp_identity' ], 10, 1 );

		// Historical sync handler — fires 60s after activation
		add_action( 'magellan_historical_identity_sync', [ __CLASS__, 'run_historical_sync' ] );
	}

	/**
	 * Hash an email. Lowercased + trimmed before hashing.
	 * Returns a string like "sha256:<64 hex>" so Magellan can
	 * distinguish hash algorithm if we ever rotate.
	 */
	public static function hash_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( $email === '' ) {
			return '';
		}
		return 'sha256:' . hash( 'sha256', $email );
	}

	/**
	 * Hash a phone. Country-aware E.164 normalization:
	 *   - Strip all non-digits
	 *   - If starts with leading 0 and we have a country code, replace 0 with code
	 *   - If doesn't start with country code and we have one, prepend
	 *   - Hash the resulting E.164 number
	 *
	 * Example: TH 0812345678 -> 66812345678 -> sha256:...
	 *          Same hash as if customer entered +66 81 234 5678 next time.
	 */
	public static function hash_phone( string $phone, string $country_iso = '' ): string {
		$digits = preg_replace( '/[^0-9]/', '', $phone );
		if ( $digits === '' ) {
			return '';
		}

		$country_iso = strtoupper( trim( $country_iso ) );
		$dial_code   = self::COUNTRY_DIAL_CODES[ $country_iso ] ?? '';

		if ( $dial_code !== '' ) {
			// Leading 0 + national number: replace 0 with dial code
			if ( strlen( $digits ) > 0 && $digits[0] === '0' ) {
				$digits = $dial_code . substr( $digits, 1 );
			} elseif ( strpos( $digits, $dial_code ) !== 0 ) {
				// Doesn't already start with dial code: prepend
				$digits = $dial_code . $digits;
			}
		}

		return 'sha256:' . hash( 'sha256', $digits );
	}

	/**
	 * Stamp identity hashes onto an order's meta.
	 * Called by the tracker after attribution stamping.
	 */
	public static function stamp_identity( $order ): void {
		if ( ! ( $order instanceof WC_Order ) ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( $email ) {
			$hash = self::hash_email( $email );
			if ( $hash !== '' ) {
				$order->update_meta_data( '_mgln_identity_hash', $hash );
			}
		}

		$phone   = $order->get_billing_phone();
		$country = $order->get_billing_country();
		if ( $phone ) {
			$hash = self::hash_phone( $phone, $country );
			if ( $hash !== '' ) {
				$order->update_meta_data( '_mgln_phone_hash', $hash );
			}
		}

		$order->save();
	}

	/**
	 * Historical sync — runs once after activation.
	 *
	 * Two phases:
	 *   1. Registered WP/WC customers (from users + customer meta)
	 *   2. Guest checkout emails from historical orders
	 *
	 * Batches of 500 sent to Magellan via /v1/pixel/identities.
	 * Within each batch, deduplicated by hash.
	 */
    public static function run_historical_sync(): void {
        if (!Magellan_Admin::is_configured()) { self::continue_sync(3600); return; }
        $size = 100;
        $cursor = (array) get_option('magellan_identity_sync_cursor', []);
        if (!$cursor) {
            $users = new WP_User_Query(['role'=>'customer','number'=>1,'fields'=>'ID','count_total'=>true]);
            $guests = wc_get_orders(['customer_id'=>0,'status'=>['wc-completed','wc-processing','wc-refunded'],'limit'=>1,'paginate'=>true]);
            $cursor = ['run'=>'sync_'.bin2hex(random_bytes(6)), 'batch'=>1, 'user_pages'=>(int)ceil($users->get_total()/$size), 'guest_pages'=>(int)ceil($guests->total/$size)];
            update_option('magellan_identity_sync_cursor',$cursor,false);
        }
        $total = $cursor['user_pages'] + $cursor['guest_pages'];
        $batch = $cursor['batch']; $identities = [];
        if ($batch > $total) { delete_option('magellan_identity_sync_cursor'); update_option('magellan_identity_sync_completed',gmdate('c'),false); return; }
        if ($batch <= $cursor['user_pages']) {
            $query = new WP_User_Query(['role'=>'customer','number'=>$size,'paged'=>$batch,'orderby'=>'ID','order'=>'ASC','fields'=>['ID','user_email','user_registered'],'count_total'=>false]);
            foreach ($query->get_results() as $user) {
                if (!$user->user_email) { continue; }
                $identities[] = ['email_hash'=>self::hash_email($user->user_email),'phone_hash'=>null,'first_seen_at'=>$user->user_registered,'last_seen_at'=>$user->user_registered,'order_count'=>function_exists('wc_get_customer_order_count')?(int)wc_get_customer_order_count($user->ID):0,'external_customer_id'=>'wp_user_'.$user->ID];
            }
        } else {
            $orders = wc_get_orders(['customer_id'=>0,'status'=>['wc-completed','wc-processing','wc-refunded'],'limit'=>$size,'page'=>$batch-$cursor['user_pages'],'orderby'=>'ID','order'=>'ASC']);
            foreach ($orders as $order) {
                if (!$order->get_billing_email()) { continue; }
                $created = $order->get_date_created(); $at = $created ? gmdate('c',$created->getTimestamp()) : gmdate('c');
                $identities[] = ['email_hash'=>self::hash_email($order->get_billing_email()),'phone_hash'=>self::hash_phone($order->get_billing_phone(),$order->get_billing_country()) ?: null,'first_seen_at'=>$at,'last_seen_at'=>$at,'order_count'=>1,'external_customer_id'=>null];
            }
        }
        try {
            if (!Magellan_Sender::send_identity_batch($identities,$cursor['run'],$batch,$total,$batch===$total)) { self::continue_sync(3600); return; }
            $cursor['batch']++; update_option('magellan_identity_sync_cursor',$cursor,false);
            if ($cursor['batch']>$total) { delete_option('magellan_identity_sync_cursor'); update_option('magellan_identity_sync_completed',gmdate('c'),false); }
            else { self::continue_sync(60); }
        } catch (\Throwable $e) { Magellan_Admin::record_error('Historical identity sync will retry its current page.'); self::continue_sync(3600); }
    }
    private static function continue_sync(int $delay): void {
        if (!wp_next_scheduled('magellan_historical_identity_sync')) { wp_schedule_single_event(time()+$delay,'magellan_historical_identity_sync'); }
    }
}
