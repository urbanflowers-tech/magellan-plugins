<?php
/**
 * Admin settings page + credential accessors + auto-install REST endpoint.
 *
 * Provisioning paths:
 *   A) Auto-install — Magellan POSTs to /wp-json/magellan/v1/configure
 *      using a WordPress Application Password (collected during the
 *      OAuth saga). Credentials land in wp_options.
 *   B) Manual — merchant pastes Account ID, plugin fetches the signing
 *      secret via the backend's /bootstrap endpoint using a one-time
 *      install token shown in the Magellan dashboard.
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Magellan_Admin {

	const OPT_ACCOUNT_ID     = 'magellan_account_id';
	const OPT_SIGNING_SECRET = 'magellan_signing_secret';
	const OPT_API_BASE       = 'magellan_api_base';
	const OPT_CONFIGURED_AT  = 'magellan_configured_at';
	const OPT_LAST_EVENT     = 'magellan_last_event_sent';
	const OPT_LAST_ERROR     = 'magellan_last_error';
	const OPT_HEALTH_DATA    = 'magellan_health_data';

	// Install action constants (Path B manual bootstrap submission)
	const NONCE_BOOTSTRAP_ACTION = 'magellan_bootstrap_submit';
	const NONCE_BOOTSTRAP_FIELD  = '_magellan_bootstrap_nonce';

	public static function init() {
		add_action( 'admin_menu',     [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init',     [ __CLASS__, 'register_settings' ] );
		add_action( 'rest_api_init',  [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'admin_notices',  [ __CLASS__, 'maybe_notice' ] );

		// Path B manual-install handler — admin-post.php form target
		add_action( 'admin_post_' . self::NONCE_BOOTSTRAP_ACTION, [ __CLASS__, 'handle_bootstrap_submit' ] );
	}

	// -------------------------------------------------------------
	// Accessors used by every other class
	// -------------------------------------------------------------

	public static function get_account_id(): string {
		return (string) get_option( self::OPT_ACCOUNT_ID, '' );
	}

	public static function get_signing_secret(): string {
		return (string) get_option( self::OPT_SIGNING_SECRET, '' );
	}

	/**
	 * Backend stores signing secrets as base64 of 32 random bytes (see
	 * `account_signing_secrets.secret_b64`). HMAC verification on the
	 * backend uses the DECODED raw bytes as the key. The plugin must do
	 * the same — passing the base64 STRING to hash_hmac() would silently
	 * produce a different signature and every request would 401.
	 *
	 * Returns the raw bytes, or '' if not configured / not decodable.
	 */
	public static function get_signing_secret_bytes(): string {
		$b64 = self::get_signing_secret();
		if ( $b64 === '' ) {
			return '';
		}
		$bytes = base64_decode( $b64, true );
		return $bytes === false ? '' : $bytes;
	}

	/**
	 * Resolved API base URL (no trailing slash). Priority (2.4.2+):
	 *   1. Stored option `magellan_api_base` — the base the BACKEND pushes
	 *      at provision time (Path A configure callback / Path B bootstrap).
	 *      AUTHORITATIVE: the store uses whichever backend installed it.
	 *   2. wp-config.php constant `MAGELLAN_API_BASE` — pre-provisioning
	 *      bootstrap / manual fallback, used only when nothing is provisioned.
	 *   3. Default `https://api.magellan.app/v1/pixel`.
	 *
	 * Matches the MAGELLAN_API_BASE_EFFECTIVE resolution in the main plugin
	 * file. NOTE: the provisioned option now wins over the constant (it did
	 * not before 2.4.2) — a stale wp-config constant can no longer pin a
	 * dev-provisioned store to production.
	 */
	public static function get_api_base(): string {
		$opt = (string) get_option( self::OPT_API_BASE, '' );
		if ( $opt !== '' ) {
			return rtrim( $opt, '/' );
		}
		if ( defined( 'MAGELLAN_API_BASE' ) ) {
			return rtrim( (string) MAGELLAN_API_BASE, '/' );
		}
		return 'https://api.magellan.app/v1/pixel';
	}

	public static function is_configured(): bool {
		return self::get_account_id() !== '' && self::get_signing_secret() !== '';
	}

	public static function record_error( string $msg ): void {
		update_option( self::OPT_LAST_ERROR, [
			'message' => $msg,
			'time'    => time(),
		] );
	}

	// -------------------------------------------------------------
	// REST: /wp-json/magellan/v1/configure  (Path A — auto-install)
	// -------------------------------------------------------------

	public static function register_rest_routes() {
		register_rest_route( 'magellan/v1', '/configure', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_configure' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		register_rest_route( 'magellan/v1', '/status', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_status' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );
	}

	public static function handle_configure( WP_REST_Request $req ) {
		$params     = $req->get_json_params();
		$account_id = isset( $params['account_id'] ) ? (string) $params['account_id'] : '';
		$secret     = isset( $params['signing_secret'] ) ? (string) $params['signing_secret'] : '';
		$api_base   = isset( $params['api_base'] ) ? (string) $params['api_base'] : '';

		if ( ! preg_match( '/^mgln_(live|test|dev)_[a-km-np-z2-9]{14}$/', $account_id ) ) {
			return new WP_Error(
				'magellan_bad_account_id',
				__( 'Invalid Account ID format. Expected mgln_(live|test|dev)_<14 base32 chars>.', 'magellan-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}
		if ( strlen( $secret ) < 32 ) {
			return new WP_Error(
				'magellan_bad_secret',
				__( 'Signing secret too short. Minimum 32 chars.', 'magellan-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}
		// Sanity: the secret must be base64-decodable and yield a
		// reasonable key length (backend mints exactly 32 raw bytes →
		// 44 base64 chars). If decode fails we still accept (defensive
		// — backend may evolve secret encoding), but we log it so
		// support has a paper trail when HMAC signatures are mismatched.
		$decoded = base64_decode( $secret, true );
		if ( $decoded === false || strlen( $decoded ) < 16 ) {
			self::record_error( __( 'configure: signing_secret is not valid base64 of at least 16 bytes — verify backend contract.', 'magellan-for-woocommerce' ) );
		}

		if ($api_base !== '' && !\Magellan\V3\Config::endpoint($api_base)) { return new WP_Error('invalid_api_base', 'A public HTTPS API URL is required.', ['status' => 400]); }
		update_option( self::OPT_ACCOUNT_ID,     $account_id );
		update_option( self::OPT_SIGNING_SECRET, $secret );
		update_option( self::OPT_CONFIGURED_AT,  time() );

		// Persist api_base when supplied. The wp-config constant takes
		// priority on read; the stored option is a fallback. Strip any
		// trailing slash so concatenation with /event etc. is clean.
		if ( $api_base !== '' ) {
			$clean = rtrim( esc_url_raw( $api_base ), '/' );
			if ( $clean !== '' ) {
				update_option( self::OPT_API_BASE, $clean );
			}
		}

		return new WP_REST_Response( [
			'ok'           => true,
			'account_id'   => $account_id,
			'api_base'     => self::get_api_base(),
			'plugin_ver'   => MAGELLAN_VERSION,
			'wc_ver'       => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'wp_ver'       => get_bloginfo( 'version' ),
			'configured_at' => time(),
		], 200 );
	}

	public static function handle_status( WP_REST_Request $req ) {
		return new WP_REST_Response( [
			'configured'      => self::is_configured(),
			'account_id'      => self::get_account_id(),
			'plugin_version'  => MAGELLAN_VERSION,
			'wc_version'      => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'wp_version'      => get_bloginfo( 'version' ),
			'configured_at'   => (int) get_option( self::OPT_CONFIGURED_AT, 0 ),
			'last_event_at'   => (int) get_option( self::OPT_LAST_EVENT, 0 ),
		], 200 );
	}

	// -------------------------------------------------------------
	// Path B — manual bootstrap. Admin pastes Account ID + one-time
	// install_token (issued from Magellan dashboard), plugin POSTs
	// to MAGELLAN_API_BASE/bootstrap to retrieve signing_secret.
	// -------------------------------------------------------------

	/**
	 * Form submission handler for the "Install with token" form. POSTs
	 * `{account_id, install_token}` to MAGELLAN_API_BASE/bootstrap,
	 * persists the returned signing_secret + api_base, and redirects
	 * back to the settings page with a transient flash message.
	 *
	 * The bootstrap response is the only HTTP path through which the
	 * plugin learns its signing secret on a fresh install. Backend
	 * contract (spec §5.5):
	 *   POST /bootstrap
	 *     body:  { account_id, install_token }
	 *     200:   { ok: true, account_id, signing_secret, api_base, received_at }
	 *     401:   { ok: false, error: { code: 'unknown_account' | 'unknown_token'
	 *              | 'expired_token' | 'already_consumed' | 'bad_install_token_format' } }
	 */
	public static function handle_bootstrap_submit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Insufficient permissions.', 'magellan-for-woocommerce' ),
				esc_html__( 'Magellan', 'magellan-for-woocommerce' ),
				[ 'response' => 403 ]
			);
		}
		check_admin_referer( self::NONCE_BOOTSTRAP_ACTION, self::NONCE_BOOTSTRAP_FIELD );

		$account_id    = isset( $_POST['account_id'] ) ? trim( (string) wp_unslash( $_POST['account_id'] ) ) : '';
		$install_token = isset( $_POST['install_token'] ) ? trim( (string) wp_unslash( $_POST['install_token'] ) ) : '';

		if ( ! preg_match( '/^mgln_(live|test|dev)_[a-km-np-z2-9]{14}$/', $account_id ) ) {
			self::flash_bootstrap_result( 'error', __( 'Account ID format invalid. Expected mgln_(live|test|dev)_<14 base32 chars>.', 'magellan-for-woocommerce' ) );
			self::redirect_back();
		}
		if ( $install_token === '' ) {
			self::flash_bootstrap_result( 'error', __( 'Install token is required.', 'magellan-for-woocommerce' ) );
			self::redirect_back();
		}

		$endpoint = self::get_api_base() . '/bootstrap';
		if (!\Magellan\V3\Config::endpoint($endpoint)) { self::flash_bootstrap_result('error', 'A public HTTPS API URL is required.'); self::redirect_back(); }
		$response = wp_safe_remote_post( $endpoint, [
			'redirection' => 0,
			'body'      => wp_json_encode( [
				'account_id'    => $account_id,
				'install_token' => $install_token,
			] ),
			'headers'   => [
				'Content-Type'              => 'application/json',
				'X-Magellan-Plugin-Version' => MAGELLAN_VERSION,
			],
			'timeout'    => 15,
			'user-agent' => 'MagellanWooPlugin/' . MAGELLAN_VERSION . ' WordPress/' . get_bloginfo( 'version' ) . ' PHP/' . PHP_VERSION,
		] );

		if ( is_wp_error( $response ) ) {
			self::flash_bootstrap_result(
				'error',
				sprintf(
					/* translators: %s: error message from the HTTP layer */
					__( 'Bootstrap request failed: %s', 'magellan-for-woocommerce' ),
					$response->get_error_message()
				)
			);
			self::redirect_back();
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$body_raw = (string) wp_remote_retrieve_body( $response );
		$body     = json_decode( $body_raw, true );

		if ( $code !== 200 || ! is_array( $body ) || empty( $body['ok'] ) ) {
			$err = is_array( $body ) && isset( $body['error']['code'] )
				? (string) $body['error']['code']
				: 'http_' . $code;
			$friendly = self::friendly_bootstrap_error( $err );
			self::flash_bootstrap_result(
				'error',
				sprintf(
					/* translators: 1: human-readable error reason, 2: machine error code from the API */
					__( 'Bootstrap failed: %1$s (%2$s).', 'magellan-for-woocommerce' ),
					$friendly,
					$err
				)
			);
			self::redirect_back();
		}

		$secret    = isset( $body['signing_secret'] ) ? (string) $body['signing_secret'] : '';
		$api_base  = isset( $body['api_base'] )       ? (string) $body['api_base']       : '';
		$echoed_id = isset( $body['account_id'] )     ? (string) $body['account_id']     : '';

		if ( $secret === '' || strlen( $secret ) < 32 ) {
			self::flash_bootstrap_result( 'error', __( 'Bootstrap response missing or short signing_secret.', 'magellan-for-woocommerce' ) );
			self::redirect_back();
		}
		// Defence in depth: bootstrap should never echo a different ID
		// than the one we sent (backend enforces this, but trust nothing).
		if ( $echoed_id !== '' && $echoed_id !== $account_id ) {
			self::flash_bootstrap_result( 'error', __( 'Bootstrap response account_id did not match submission. Aborting.', 'magellan-for-woocommerce' ) );
			self::redirect_back();
		}

		if ($api_base !== '' && !\Magellan\V3\Config::endpoint($api_base)) { self::flash_bootstrap_result('error', 'Bootstrap returned an invalid API URL.'); self::redirect_back(); }
		update_option( self::OPT_ACCOUNT_ID,     $account_id );
		update_option( self::OPT_SIGNING_SECRET, $secret );
		update_option( self::OPT_CONFIGURED_AT,  time() );
		if ( $api_base !== '' ) {
			$clean = rtrim( esc_url_raw( $api_base ), '/' );
			if ( $clean !== '' ) {
				update_option( self::OPT_API_BASE, $clean );
			}
		}

		self::flash_bootstrap_result( 'success', __( 'Connected. Signing secret stored — verified events will start sending on the next order.', 'magellan-for-woocommerce' ) );
		self::redirect_back();
	}

	private static function friendly_bootstrap_error( string $code ): string {
		switch ( $code ) {
			case 'unknown_account':            return __( 'Account ID not recognized by Magellan', 'magellan-for-woocommerce' );
			case 'account_suspended':          return __( 'Truth Layer is disabled on this account', 'magellan-for-woocommerce' );
			case 'unknown_token':              return __( 'Install token not found', 'magellan-for-woocommerce' );
			case 'expired_token':              return __( 'Install token has expired — request a new one', 'magellan-for-woocommerce' );
			case 'already_consumed':           return __( 'Install token has already been used', 'magellan-for-woocommerce' );
			case 'bad_install_token_format':   return __( 'Install token format invalid', 'magellan-for-woocommerce' );
			case 'bad_account_format':         return __( 'Account ID format invalid', 'magellan-for-woocommerce' );
			case 'missing_account':            return __( 'Account ID missing', 'magellan-for-woocommerce' );
			case 'missing_install_token':      return __( 'Install token missing', 'magellan-for-woocommerce' );
			case 'invalid_json':               return __( 'Plugin sent malformed request body (bug — please report)', 'magellan-for-woocommerce' );
			case 'internal_error':             return __( 'Magellan internal error — retry in a moment', 'magellan-for-woocommerce' );
		}
		/* translators: %s: untranslated error code returned by the API */
		return sprintf( __( 'Unexpected response: %s', 'magellan-for-woocommerce' ), $code );
	}

	private static function flash_bootstrap_result( string $kind, string $message ): void {
		set_transient(
			'magellan_bootstrap_flash_' . get_current_user_id(),
			[ 'kind' => $kind, 'message' => $message ],
			60
		);
	}

	public static function consume_bootstrap_flash(): ?array {
		$key   = 'magellan_bootstrap_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( $flash ) {
			delete_transient( $key );
			return is_array( $flash ) ? $flash : null;
		}
		return null;
	}

	private static function redirect_back(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=magellan' ) );
		exit;
	}

	// -------------------------------------------------------------
	// Settings page registration
	// -------------------------------------------------------------

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Magellan', 'magellan-for-woocommerce' ),
			__( 'Magellan', 'magellan-for-woocommerce' ),
			'manage_options',
			'magellan',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings() {
		register_setting(
			'magellan_settings',
			self::OPT_ACCOUNT_ID,
			[
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					$value = trim( (string) $value );
					if ( preg_match( '/^mgln_(live|test|dev)_[a-km-np-z2-9]{14}$/', $value ) ) {
						return $value;
					}
					if ( $value === '' ) {
						return '';
					}
					add_settings_error(
						self::OPT_ACCOUNT_ID,
						'magellan_bad_id',
						__( 'Account ID format invalid. Expected mgln_(live|test|dev)_<14 base32 chars>.', 'magellan-for-woocommerce' ),
						'error'
					);
					return get_option( self::OPT_ACCOUNT_ID, '' );
				},
				'default'           => '',
			]
		);
	}

	public static function maybe_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id === 'woocommerce_page_magellan' ) {
			return;
		}
		if ( ! self::is_configured() && current_user_can( 'manage_options' ) ) {
			$url = admin_url( 'admin.php?page=magellan' );
			printf(
				'<div class="notice notice-info is-dismissible"><p><strong>%1$s</strong> &mdash; %2$s <a href="%3$s">%4$s &rarr;</a></p></div>',
				esc_html__( 'Magellan', 'magellan-for-woocommerce' ),
				esc_html__( 'enter your Account ID to activate the verified attribution pixel.', 'magellan-for-woocommerce' ),
				esc_url( $url ),
				esc_html__( 'Set it up', 'magellan-for-woocommerce' )
			);
		}
	}

	// -------------------------------------------------------------
	// Settings page render — minimal, one field
	// -------------------------------------------------------------

	public static function render_page() {
		$account_id   = self::get_account_id();
		$has_secret   = self::get_signing_secret() !== '';
		$health       = (array) get_option( self::OPT_HEALTH_DATA, [] );
		$conflicts    = isset( $health['conflicts'] ) && is_array( $health['conflicts'] ) ? $health['conflicts'] : [];
		$last_event   = (int) get_option( self::OPT_LAST_EVENT, 0 );
		$last_error   = (array) get_option( self::OPT_LAST_ERROR, [] );
		$configured_at = (int) get_option( self::OPT_CONFIGURED_AT, 0 );
		$api_base     = self::get_api_base();
		$flash        = self::consume_bootstrap_flash();
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px;">
				<svg width="22" height="22" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<path d="M3 16 L7.5 6 L10 10 L12.5 6 L17 16" stroke="#0d1f3c" stroke-width="2" stroke-linecap="square" stroke-linejoin="miter" fill="none"/>
					<line x1="10" y1="10" x2="10" y2="16" stroke="#0d1f3c" stroke-width="1.5"/>
					<line x1="8" y1="13" x2="12" y2="13" stroke="#0d1f3c" stroke-width="1.5"/>
				</svg>
				<?php echo esc_html__( 'Magellan for WooCommerce', 'magellan-for-woocommerce' ); ?>
			</h1>

			<?php settings_errors(); ?>

			<?php if ( $flash ) : ?>
				<div class="notice notice-<?php echo esc_attr( $flash['kind'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
					<p><?php echo esc_html( $flash['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! self::is_configured() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php echo esc_html__( 'Enter your Magellan Account ID to activate the verified attribution pixel.', 'magellan-for-woocommerce' ); ?>
						<a href="https://app.magellan.app/settings/connections" target="_blank" rel="noopener">
							<?php echo esc_html__( 'Find it in Magellan → Settings → Connections', 'magellan-for-woocommerce' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! $has_secret ) : ?>
				<h2><?php echo esc_html__( 'Install with token (Path B)', 'magellan-for-woocommerce' ); ?></h2>
				<p class="description">
					<?php echo esc_html__( 'If your Magellan dashboard already installed this plugin for you via the WordPress Application Password flow (Path A), you can skip this section — your credentials are already stored. Otherwise paste your one-time install token below.', 'magellan-for-woocommerce' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE_BOOTSTRAP_ACTION ); ?>" />
					<?php wp_nonce_field( self::NONCE_BOOTSTRAP_ACTION, self::NONCE_BOOTSTRAP_FIELD ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="mgln_bootstrap_account_id"><?php echo esc_html__( 'Account ID', 'magellan-for-woocommerce' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="mgln_bootstrap_account_id"
									name="account_id"
									value="<?php echo esc_attr( $account_id ); ?>"
									class="regular-text code"
									placeholder="mgln_live_xxxxxxxxxxxxxx"
									autocomplete="off"
									spellcheck="false"
									required
								/>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="mgln_bootstrap_install_token"><?php echo esc_html__( 'Install token', 'magellan-for-woocommerce' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="mgln_bootstrap_install_token"
									name="install_token"
									value=""
									class="regular-text code"
									placeholder="<?php echo esc_attr__( 'one-time token from Magellan dashboard', 'magellan-for-woocommerce' ); ?>"
									autocomplete="off"
									spellcheck="false"
									required
								/>
								<p class="description">
									<?php echo esc_html__( 'Tokens are one-time use and expire after 24 hours. Generate a fresh one from', 'magellan-for-woocommerce' ); ?>
									<a href="https://app.magellan.app/settings/connections" target="_blank" rel="noopener">
										<?php echo esc_html__( 'Magellan → Settings → Connections', 'magellan-for-woocommerce' ); ?>
									</a>.
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Connect to Magellan', 'magellan-for-woocommerce' ), 'primary', 'submit', false ); ?>
				</form>
				<hr style="margin:24px 0;" />
			<?php endif; ?>

			<?php if ( ! empty( $conflicts ) ) : ?>
				<div class="notice notice-error inline">
					<p>
						<strong><?php echo esc_html__( 'Tracking conflict detected:', 'magellan-for-woocommerce' ); ?></strong>
						<?php echo esc_html( $conflicts[0]['plugin'] ?? __( 'Unknown plugin', 'magellan-for-woocommerce' ) ); ?>
						&mdash;
						<?php echo esc_html( $conflicts[0]['note'] ?? __( 'See Magellan dashboard.', 'magellan-for-woocommerce' ) ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $last_error['message'] ) ) : ?>
				<div class="notice notice-error inline">
					<p>
						<strong><?php echo esc_html__( 'Last error:', 'magellan-for-woocommerce' ); ?></strong>
						<?php echo esc_html( $last_error['message'] ); ?>
						<?php
						printf(
							/* translators: %s: human-readable time difference, e.g. "5 minutes" */
							' (' . esc_html__( '%s ago', 'magellan-for-woocommerce' ) . ')',
							esc_html( human_time_diff( (int) $last_error['time'], time() ) )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'magellan_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="magellan_account_id"><?php echo esc_html__( 'Magellan Account ID', 'magellan-for-woocommerce' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="magellan_account_id"
								name="<?php echo esc_attr( self::OPT_ACCOUNT_ID ); ?>"
								value="<?php echo esc_attr( $account_id ); ?>"
								class="regular-text code"
								placeholder="mgln_live_xxxxxxxxxxxxxx"
								autocomplete="off"
								spellcheck="false"
							/>
							<p class="description">
								<?php echo esc_html__( '24-character identifier. Find this in', 'magellan-for-woocommerce' ); ?>
								<a href="https://app.magellan.app/settings/connections" target="_blank" rel="noopener">
									<?php echo esc_html__( 'Magellan → Settings → Connections', 'magellan-for-woocommerce' ); ?>
								</a>.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Account ID', 'magellan-for-woocommerce' ) ); ?>
			</form>

			<h2><?php echo esc_html__( 'Status', 'magellan-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'API base', 'magellan-for-woocommerce' ); ?></th>
					<td>
						<code><?php echo esc_html( $api_base ); ?></code>
						<?php
						$mgln_base_opt = (string) get_option( self::OPT_API_BASE, '' );
						if ( $mgln_base_opt !== '' ) {
							$mgln_base_src = __( '(provisioned by the backend)', 'magellan-for-woocommerce' );
						} elseif ( defined( 'MAGELLAN_API_BASE' ) ) {
							$mgln_base_src = __( '(from MAGELLAN_API_BASE constant — fallback; nothing provisioned yet)', 'magellan-for-woocommerce' );
						} else {
							$mgln_base_src = __( '(default)', 'magellan-for-woocommerce' );
						}
						?>
						<span class="description" style="margin-left:8px;"><?php echo esc_html( $mgln_base_src ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Pixel', 'magellan-for-woocommerce' ); ?></th>
					<td>
						<?php if ( self::is_configured() ) : ?>
							<span style="color:#057a55;font-weight:600;">&#10003; <?php echo esc_html__( 'Active', 'magellan-for-woocommerce' ); ?></span>
						<?php elseif ( $account_id !== '' ) : ?>
							<span style="color:#b45309;font-weight:600;">&#9888; <?php echo esc_html__( 'Account ID set, signing secret missing — reconnect from Magellan dashboard', 'magellan-for-woocommerce' ); ?></span>
						<?php else : ?>
							<span style="color:#b45309;font-weight:600;">&#9888; <?php echo esc_html__( 'Not configured', 'magellan-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $configured_at ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Configured', 'magellan-for-woocommerce' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: %s: human-readable time difference, e.g. "5 minutes" */
							esc_html__( '%s ago', 'magellan-for-woocommerce' ),
							esc_html( human_time_diff( $configured_at, time() ) )
						);
						?>
					</td>
				</tr>
				<?php endif; ?>
				<?php if ( $last_event ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Last verified event sent', 'magellan-for-woocommerce' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: %s: human-readable time difference, e.g. "5 minutes" */
							esc_html__( '%s ago', 'magellan-for-woocommerce' ),
							esc_html( human_time_diff( $last_event, time() ) )
						);
						?>
					</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Action Scheduler', 'magellan-for-woocommerce' ); ?></th>
					<td>
						<?php if ( function_exists( 'as_schedule_single_action' ) ) : ?>
							<span style="color:#057a55;font-weight:600;">&#10003; <?php echo esc_html__( 'Available', 'magellan-for-woocommerce' ); ?></span>
						<?php else : ?>
							<span style="color:#b45309;font-weight:600;">&#9888; <?php echo esc_html__( 'Not available — using WP-Cron fallback', 'magellan-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'HPOS', 'magellan-for-woocommerce' ); ?></th>
					<td>
						<?php
						$hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
							&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
						if ( $hpos_enabled ) {
							echo '<span style="color:#057a55;font-weight:600;">&#10003; ' . esc_html__( 'Enabled', 'magellan-for-woocommerce' ) . '</span>';
						} else {
							echo '<span>' . esc_html__( 'Disabled', 'magellan-for-woocommerce' ) . '</span>';
						}
						?>
					</td>
				</tr>
				<?php if ( isset( $health['magellan_plugin']['events_sent_24h'] ) ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Events sent (24h)', 'magellan-for-woocommerce' ); ?></th>
					<td><?php echo (int) $health['magellan_plugin']['events_sent_24h']; ?></td>
				</tr>
				<?php endif; ?>
			</table>

			<p class="description" style="margin-top:24px;color:#666;">
				<?php echo esc_html__( 'All ad-platform credentials (Meta, Google, TikTok, Klaviyo) live in Magellan, not in WordPress. This plugin sends only verified order events.', 'magellan-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}
}
