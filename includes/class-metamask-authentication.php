<?php
/**
 * Wallet authentication: challenges, signature checks, login and sign-up.
 *
 * @package MetaMask_Login
 * @since   2.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authentication service used by the AJAX handlers, REST API and templates.
 *
 * Flow:
 * 1. The browser asks for a challenge for its address (`create_challenge`).
 *    The server stores a one-time Sign-In with Ethereum (EIP-4361) message.
 * 2. The wallet signs that exact message with `personal_sign`.
 * 3. The browser sends back the challenge nonce + signature. The server
 *    consumes the stored message (single use, 5 minute lifetime) and
 *    recovers the signer with secp256k1 before logging in.
 *
 * @since 2.0.0
 */
class MetaMask_Authentication {

	const RATE_LIMIT_PREFIX = 'metamask_rate_limit_';
	const CHALLENGE_PREFIX  = 'metamask_challenge_';
	const CHALLENGE_TTL     = 300;
	const CHALLENGE_COOKIE  = 'wp_metamask_challenge';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'login_form', array( __CLASS__, 'render_login_form_button' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue_login_assets' ) );

		add_action( 'wp_ajax_nopriv_metamask_get_nonce', array( __CLASS__, 'ajax_get_challenge' ) );
		add_action( 'wp_ajax_metamask_get_nonce', array( __CLASS__, 'ajax_get_challenge' ) );
		add_action( 'wp_ajax_nopriv_metamask_authentication', array( __CLASS__, 'ajax_login' ) );
		add_action( 'wp_ajax_metamask_authentication', array( __CLASS__, 'ajax_login' ) );
	}

	/*
	 * --------------------------------------------------------------------
	 * Login page integration
	 * --------------------------------------------------------------------
	 */

	/**
	 * Whether the button should appear on wp-login.php.
	 *
	 * @return bool
	 */
	private static function login_page_enabled() {
		/**
		 * Filter whether the MetaMask button is shown on wp-login.php.
		 *
		 * @since 3.0.0
		 * @param bool $enabled Setting value.
		 */
		return (bool) apply_filters( 'metamask_login_show_on_login_page', (bool) MetaMask_Settings::get( 'enable_login_page' ) );
	}

	/**
	 * Enqueue assets on wp-login.php.
	 */
	public static function enqueue_login_assets() {
		if ( self::login_page_enabled() ) {
			MetaMask_Login_Addon::enqueue_assets();
		}
	}

	/**
	 * Output the button inside the core login form.
	 */
	public static function render_login_form_button() {
		if ( ! self::login_page_enabled() ) {
			return;
		}
		?>
		<div class="metamask-login metamask-login--wp-login" data-metamask-container hidden>
			<p class="metamask-login__or"><span><?php esc_html_e( 'or', 'metamask-login-addon' ); ?></span></p>
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button_html().
			echo MetaMask_Login_Addon::button_html(
				array(
					'action' => 'login',
					'text'   => MetaMask_Settings::button_text(),
				)
			);
			?>
			<div class="metamask-status" role="status" aria-live="polite"></div>
		</div>
		<?php
	}

	/*
	 * --------------------------------------------------------------------
	 * AJAX handlers
	 * --------------------------------------------------------------------
	 */

	/**
	 * Verify the WordPress nonce for AJAX requests.
	 *
	 * Logged-out requests are exempt when challenges are bound to a
	 * same-site cookie (the default): that binding already stops login CSRF,
	 * and skipping the nonce keeps the button working on cached pages.
	 */
	private static function check_ajax_nonce() {
		if ( ! is_user_logged_in() && self::bind_to_cookie() ) {
			return;
		}
		if ( ! check_ajax_referer( 'metamask_login_nonce', 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session has expired. Please reload the page and try again.', 'metamask-login-addon' ) ),
				403
			);
		}
	}

	/**
	 * Send a WP_Error as a JSON error response.
	 *
	 * @param WP_Error $error Error.
	 */
	private static function send_error( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		wp_send_json_error(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			$status
		);
	}

	/**
	 * Read a string parameter from the POST body.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function post_string( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by callers.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
	}

	/**
	 * AJAX: issue a sign-in challenge.
	 */
	public static function ajax_get_challenge() {
		self::check_ajax_nonce();

		$purpose = 'link' === self::post_string( 'purpose' ) ? 'link' : 'login';
		if ( 'link' === $purpose && ! is_user_logged_in() ) {
			self::send_error( new WP_Error( 'metamask_not_logged_in', __( 'Please log in first.', 'metamask-login-addon' ), array( 'status' => 401 ) ) );
		}

		$challenge = self::create_challenge(
			self::post_string( 'address' ),
			self::post_string( 'chain_id' ),
			$purpose,
			get_current_user_id()
		);

		if ( is_wp_error( $challenge ) ) {
			self::send_error( $challenge );
		}

		wp_send_json_success( $challenge );
	}

	/**
	 * AJAX: log in with a signed challenge.
	 */
	public static function ajax_login() {
		self::check_ajax_nonce();

		$user = self::login_with_signature(
			self::post_string( 'address' ),
			self::challenge_param( self::post_string( 'challenge' ), isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- only used to extract the nonce line.
			self::post_string( 'signature' ),
			'0' !== self::post_string( 'remember' )
		);

		if ( is_wp_error( $user ) ) {
			self::send_error( $user );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Login successful!', 'metamask-login-addon' ),
				'redirect' => self::get_redirect_url( $user, self::post_string( 'redirect_to' ) ),
			)
		);
	}

	/*
	 * --------------------------------------------------------------------
	 * Challenges
	 * --------------------------------------------------------------------
	 */

	/**
	 * Resolve the challenge nonce, accepting a legacy full `message` too.
	 *
	 * @param string $challenge Nonce.
	 * @param mixed  $message   Legacy signed message.
	 * @return string
	 */
	public static function challenge_param( $challenge, $message = '' ) {
		if ( '' !== $challenge ) {
			return $challenge;
		}
		if ( is_string( $message ) && preg_match( '/^Nonce: ([A-Za-z0-9]{8,64})\r?$/m', $message, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Create and store a one-time sign-in challenge.
	 *
	 * @since 3.0.0
	 * @param string     $address  Wallet address.
	 * @param int|string $chain_id Chain id reported by the wallet (decimal or 0x hex).
	 * @param string     $purpose  `login` or `link`.
	 * @param int        $user_id  User the challenge is bound to (for `link`).
	 * @return array|WP_Error { message, nonce, expires }.
	 */
	public static function create_challenge( $address, $chain_id = 1, $purpose = 'login', $user_id = 0 ) {
		if ( ! Eth_Sign_Verify::is_valid_address( $address ) ) {
			return new WP_Error( 'metamask_invalid_address', __( 'Invalid wallet address.', 'metamask-login-addon' ), array( 'status' => 400 ) );
		}

		if ( self::is_rate_limited( 'challenge' ) ) {
			return self::rate_limit_error();
		}
		self::hit_rate_limit( 'challenge' );

		$chain_id = self::normalize_chain_id( $chain_id );
		$nonce    = wp_generate_password( 17, false, false );
		$issued   = time();
		$expires  = $issued + self::CHALLENGE_TTL;
		$message  = self::build_message( $address, $chain_id, $nonce, $issued, $expires, $purpose );

		$record = array(
			'address' => strtolower( $address ),
			'message' => $message,
			'purpose' => $purpose,
			'user_id' => (int) $user_id,
			'expires' => $expires,
			'cookie'  => '',
		);

		if ( self::bind_to_cookie() ) {
			if ( headers_sent() ) {
				return new WP_Error( 'metamask_cookie_failed', __( 'Could not start a secure sign-in. Please reload the page and try again.', 'metamask-login-addon' ), array( 'status' => 500 ) );
			}
			$secret           = wp_generate_password( 32, false, false );
			$record['cookie'] = hash( 'sha256', $secret );
			setcookie(
				self::CHALLENGE_COOKIE . '_' . $nonce,
				$secret,
				array(
					'expires'  => $expires,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Strict',
				)
			);
		}

		set_transient( self::CHALLENGE_PREFIX . $nonce, $record, self::CHALLENGE_TTL );

		return array(
			'message' => $message,
			'nonce'   => $nonce,
			'expires' => $expires,
		);
	}

	/**
	 * Whether challenges are bound to a same-site cookie in the requesting browser.
	 *
	 * @return bool
	 */
	private static function bind_to_cookie() {
		/**
		 * Whether challenges are bound to a same-site cookie in the browser
		 * that requested them. This prevents login CSRF. Disable it for
		 * headless / native clients that do not keep cookies.
		 *
		 * @since 3.0.0
		 * @param bool $bind Default true.
		 */
		return (bool) apply_filters( 'metamask_login_bind_challenge_to_cookie', true );
	}

	/**
	 * Build the EIP-4361 (Sign-In with Ethereum) message.
	 *
	 * @param string $address  Address.
	 * @param int    $chain_id Chain id.
	 * @param string $nonce    Nonce.
	 * @param int    $issued   Issued-at timestamp.
	 * @param int    $expires  Expiration timestamp.
	 * @param string $purpose  login|link.
	 * @return string
	 */
	private static function build_message( $address, $chain_id, $nonce, $issued, $expires, $purpose ) {
		$home   = home_url( '/' );
		$parts  = wp_parse_url( $home );
		$domain = isset( $parts['host'] ) ? $parts['host'] : '';
		if ( isset( $parts['port'] ) ) {
			$domain .= ':' . $parts['port'];
		}

		/**
		 * Filter the SIWE domain. It must match the host the user sees in the
		 * address bar, or MetaMask will show a phishing warning.
		 *
		 * @since 3.0.0
		 * @param string $domain Domain (host[:port]).
		 */
		$domain = apply_filters( 'metamask_login_siwe_domain', $domain );

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( 'link' === $purpose ) {
			/* translators: %s: site name */
			$statement = sprintf( __( 'Connect this wallet to your account on %s.', 'metamask-login-addon' ), $site );
		} else {
			$statement = trim( (string) MetaMask_Settings::get( 'sign_statement' ) );
			if ( '' === $statement ) {
				/* translators: %s: site name */
				$statement = sprintf( __( 'Sign in to %s. This request will not trigger a blockchain transaction or cost any gas fees.', 'metamask-login-addon' ), $site );
			}
		}
		// SIWE statements are a single line.
		$statement = trim( preg_replace( '/[\r\n]+/', ' ', $statement ) );

		$lines = array(
			$domain . ' wants you to sign in with your Ethereum account:',
			Eth_Sign_Verify::to_checksum_address( $address ),
			'',
			$statement,
			'',
			'URI: ' . $home,
			'Version: 1',
			'Chain ID: ' . $chain_id,
			'Nonce: ' . $nonce,
			'Issued At: ' . gmdate( 'Y-m-d\TH:i:s\Z', $issued ),
			'Expiration Time: ' . gmdate( 'Y-m-d\TH:i:s\Z', $expires ),
		);

		$message = implode( "\n", $lines );

		/**
		 * Filter the message the wallet signs. If you change it, keep the
		 * `Nonce: …` line so legacy clients can still be matched.
		 *
		 * @since 2.0.0
		 * @param string $message Message.
		 * @param string $address Address.
		 */
		return (string) apply_filters( 'metamask_login_sign_message', $message, $address );
	}

	/**
	 * Parse a chain id (decimal or 0x-hex); defaults to Ethereum mainnet.
	 *
	 * @param mixed $chain_id Raw value.
	 * @return int
	 */
	private static function normalize_chain_id( $chain_id ) {
		if ( is_string( $chain_id ) && preg_match( '/^0x[0-9a-fA-F]{1,13}$/', $chain_id ) ) {
			$chain_id = hexdec( substr( $chain_id, 2 ) );
		}
		$chain_id = is_numeric( $chain_id ) ? (int) $chain_id : 1;
		return ( $chain_id > 0 && $chain_id < 9007199254740991 ) ? $chain_id : 1;
	}

	/**
	 * Consume a challenge and verify its signature.
	 *
	 * The challenge is deleted before verification, so every challenge can
	 * be used exactly once whatever the outcome.
	 *
	 * @since 3.0.0
	 * @param string $address   Claimed address.
	 * @param string $nonce     Challenge nonce.
	 * @param string $signature Signature (0x hex).
	 * @param string $purpose   login|link.
	 * @param int    $user_id   Current user for `link`.
	 * @return true|WP_Error
	 */
	public static function verify_challenge( $address, $nonce, $signature, $purpose = 'login', $user_id = 0 ) {
		$expired = new WP_Error(
			'metamask_challenge_expired',
			__( 'This sign-in request has expired or was already used. Please try again.', 'metamask-login-addon' ),
			array( 'status' => 400 )
		);

		if ( ! Eth_Sign_Verify::is_valid_address( $address ) ) {
			return new WP_Error( 'metamask_invalid_address', __( 'Invalid wallet address.', 'metamask-login-addon' ), array( 'status' => 400 ) );
		}
		if ( ! is_string( $nonce ) || ! preg_match( '/^[A-Za-z0-9]{8,64}$/', $nonce ) ) {
			return $expired;
		}

		$key    = self::CHALLENGE_PREFIX . $nonce;
		$record = get_transient( $key );

		// Only the request that actually deletes the challenge may use it, so
		// two concurrent requests with the same signature cannot both pass.
		if ( ! is_array( $record ) || ! delete_transient( $key ) ) {
			return $expired;
		}

		if ( empty( $record['message'] ) || (int) $record['expires'] < time() ) {
			return $expired;
		}

		if ( ! hash_equals( $record['address'], strtolower( $address ) ) || $record['purpose'] !== $purpose ) {
			return $expired;
		}

		if ( 'link' === $purpose && (int) $record['user_id'] !== (int) $user_id ) {
			return $expired;
		}

		if ( '' !== $record['cookie'] ) {
			$name   = self::CHALLENGE_COOKIE . '_' . $nonce;
			$cookie = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
			if ( '' === $cookie || ! hash_equals( $record['cookie'], hash( 'sha256', $cookie ) ) ) {
				return $expired;
			}
		}

		if ( ! self::verify_signature( $record['message'], $signature, $address ) ) {
			return new WP_Error( 'metamask_bad_signature', __( 'The signature could not be verified. Please try again.', 'metamask-login-addon' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Verify a personal_sign signature.
	 *
	 * @since 2.0.0
	 * @param string $message   Message.
	 * @param string $signature Signature.
	 * @param string $address   Address.
	 * @return bool
	 */
	public static function verify_signature( $message, $signature, $address ) {
		$verifier = new Eth_Sign_Verify();
		return $verifier->verify( $message, $signature, $address );
	}

	/**
	 * Build a SIWE message for backwards compatibility with 2.x callers.
	 * Note: messages created this way are not stored and cannot be used to log in.
	 *
	 * @since 2.0.0
	 * @deprecated 3.0.0 Use MetaMask_Authentication::create_challenge().
	 * @param string $address Address.
	 * @return string
	 */
	public static function generate_sign_message( $address ) {
		_deprecated_function( __METHOD__, '3.0.0', 'MetaMask_Authentication::create_challenge()' );
		$challenge = self::create_challenge( $address );
		return is_wp_error( $challenge ) ? '' : $challenge['message'];
	}

	/*
	 * --------------------------------------------------------------------
	 * Login & registration
	 * --------------------------------------------------------------------
	 */

	/**
	 * Verify a signed challenge and log the wallet owner in.
	 *
	 * @since 3.0.0
	 * @param string $address   Wallet address.
	 * @param string $nonce     Challenge nonce.
	 * @param string $signature Signature.
	 * @param bool   $remember  Persistent login cookie.
	 * @return WP_User|WP_Error
	 */
	public static function login_with_signature( $address, $nonce, $signature, $remember = true ) {
		if ( self::is_rate_limited( 'failure' ) ) {
			return self::rate_limit_error();
		}

		$verified = self::verify_challenge( $address, $nonce, $signature, 'login' );
		if ( is_wp_error( $verified ) ) {
			self::hit_rate_limit( 'failure' );
			do_action( 'metamask_login_failed', $address, $verified );
			return $verified;
		}

		$user = MetaMask_User_Profile::get_user_by_wallet_address( $address );

		if ( ! $user ) {
			if ( MetaMask_Settings::get( 'enable_registration' ) ) {
				$user = self::register_user( $address );
				if ( is_wp_error( $user ) ) {
					return $user;
				}
			} else {
				self::hit_rate_limit( 'failure' );
				$error = new WP_Error(
					'metamask_no_account',
					__( 'This wallet is not connected to an account yet. Log in with your password, then connect your wallet from your profile.', 'metamask-login-addon' ),
					array( 'status' => 404 )
				);
				do_action( 'metamask_login_failed', $address, $error );
				return $error;
			}
		}

		/**
		 * Last chance to block a wallet login (e.g. require 2FA, banned users).
		 * Return a WP_Error to deny the login.
		 *
		 * @since 3.0.0
		 * @param WP_User $user    User about to be logged in.
		 * @param string  $address Wallet address.
		 */
		$user = apply_filters( 'metamask_login_authenticate', $user, strtolower( $address ) );

		if ( $user instanceof WP_User ) {
			// Let account-status plugins (bans, approval, lockouts) veto the login
			// exactly as they would a password login. There is no password.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
			$user = apply_filters( 'wp_authenticate_user', $user, '' );
		}

		if ( $user instanceof WP_User && is_multisite() && is_user_spammy( $user ) ) {
			$user = new WP_Error( 'spammer_account', __( 'Your account has been marked as a spammer.', 'metamask-login-addon' ), array( 'status' => 403 ) );
		}

		if ( ! $user instanceof WP_User ) {
			if ( is_wp_error( $user ) ) {
				$data = $user->get_error_data();
				if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
					$user->add_data( array( 'status' => 403 ) );
				}
				return $user;
			}
			return new WP_Error( 'metamask_login_denied', __( 'Login denied.', 'metamask-login-addon' ), array( 'status' => 403 ) );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, (bool) $remember, is_ssl() );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		do_action( 'wp_login', $user->user_login, $user );

		self::clear_rate_limit( 'failure' );

		/**
		 * Fires after a successful wallet login.
		 *
		 * @since 2.0.0
		 * @param int    $user_id User ID.
		 * @param string $address Wallet address.
		 */
		do_action( 'metamask_login_successful', $user->ID, strtolower( $address ) );

		return $user;
	}

	/**
	 * Create a WordPress account for a new wallet.
	 *
	 * @param string $address Wallet address.
	 * @return WP_User|WP_Error
	 */
	private static function register_user( $address ) {
		$address = strtolower( $address );
		$base    = 'wallet_' . substr( $address, 2, 6 ) . substr( $address, -4 );

		/**
		 * Filter the username given to wallet sign-ups.
		 *
		 * @since 3.0.0
		 * @param string $username Username.
		 * @param string $address  Wallet address.
		 */
		$base     = sanitize_user( apply_filters( 'metamask_login_new_username', $base, $address ), true );
		$username = $base;
		$suffix   = 1;
		while ( username_exists( $username ) ) {
			++$suffix;
			$username = $base . '_' . $suffix;
		}

		$role = MetaMask_Settings::get( 'default_role' );
		if ( ! array_key_exists( $role, MetaMask_Settings::allowed_roles() ) ) {
			$role = 'subscriber';
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => '',
				'display_name' => substr( Eth_Sign_Verify::to_checksum_address( $address ), 0, 6 ) . '…' . substr( $address, -4 ),
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, MetaMask_Login_Addon::USER_META_KEY, $address );

		/**
		 * Fires after a new account was created for a wallet.
		 *
		 * @since 3.0.0
		 * @param int    $user_id User ID.
		 * @param string $address Wallet address.
		 */
		do_action( 'metamask_user_registered', $user_id, $address );

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Where to send a user after logging in.
	 *
	 * Mirrors wp-login.php: honours `redirect_to` (same-site only), the
	 * configured default and the core `login_redirect` filter.
	 *
	 * @since 3.0.0
	 * @param WP_User $user      User.
	 * @param string  $requested Requested redirect.
	 * @return string
	 */
	public static function get_redirect_url( WP_User $user, $requested = '' ) {
		$default = (string) MetaMask_Settings::get( 'login_redirect' );
		if ( '' === $default ) {
			if ( $user->has_cap( 'edit_posts' ) ) {
				$default = admin_url();
			} elseif ( $user->has_cap( 'read' ) ) {
				$default = admin_url( 'profile.php' );
			} else {
				$default = home_url( '/' );
			}
		}

		/**
		 * Filter the fallback redirect when none is requested.
		 *
		 * @since 2.0.0
		 * @param string $url Default redirect.
		 */
		$default = apply_filters( 'metamask_login_default_redirect', $default );

		// wp-login.php pre-fills redirect_to with the Dashboard URL; treat that as "no preference".
		$is_default_request = '' === $requested || in_array( untrailingslashit( $requested ), array( untrailingslashit( admin_url() ), untrailingslashit( admin_url( 'index.php' ) ) ), true );
		$redirect           = $is_default_request ? $default : wp_validate_redirect( $requested, $default );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$redirect = apply_filters( 'login_redirect', $redirect, $requested, $user );

		/**
		 * Filter the redirect after a wallet login.
		 *
		 * @since 2.0.0
		 * @param string  $redirect Redirect URL.
		 * @param WP_User $user     User.
		 */
		$redirect = apply_filters( 'metamask_login_redirect', $redirect, $user );

		return wp_validate_redirect( $redirect, admin_url() );
	}

	/*
	 * --------------------------------------------------------------------
	 * Rate limiting
	 * --------------------------------------------------------------------
	 */

	/**
	 * Client IP used for rate limiting.
	 *
	 * Uses REMOTE_ADDR only, because forwarding headers can be spoofed. Sites
	 * behind a trusted proxy/CDN can supply the real IP via the filter.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter the client IP used for rate limiting.
		 *
		 * @since 3.0.0
		 * @param string $ip IP address.
		 */
		$ip = apply_filters( 'metamask_login_client_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Transient key for a rate-limit bucket.
	 *
	 * @param string $bucket challenge|failure.
	 * @return string
	 */
	private static function rate_key( $bucket ) {
		return self::RATE_LIMIT_PREFIX . $bucket . '_' . md5( self::get_client_ip() );
	}

	/**
	 * Allowed hits for a bucket in one window.
	 *
	 * @param string $bucket Bucket.
	 * @return int
	 */
	private static function rate_max( $bucket ) {
		$attempts = (int) MetaMask_Settings::get( 'rate_limit_attempts' );
		// Every login attempt needs one challenge; allow headroom for retries.
		return 'challenge' === $bucket ? max( 10, $attempts * 6 ) : $attempts;
	}

	/**
	 * Whether the client exceeded a bucket.
	 *
	 * @param string $bucket Bucket.
	 * @return bool
	 */
	public static function is_rate_limited( $bucket ) {
		if ( ! MetaMask_Settings::get( 'rate_limit_enabled' ) ) {
			return false;
		}
		$state = get_transient( self::rate_key( $bucket ) );
		return is_array( $state ) && (int) $state['reset'] > time() && (int) $state['count'] >= self::rate_max( $bucket );
	}

	/**
	 * Record one hit.
	 *
	 * @param string $bucket challenge|failure.
	 */
	public static function hit_rate_limit( $bucket ) {
		if ( ! MetaMask_Settings::get( 'rate_limit_enabled' ) ) {
			return;
		}
		$key    = self::rate_key( $bucket );
		$window = (int) MetaMask_Settings::get( 'rate_limit_window' );
		$state  = get_transient( $key );

		// Fixed window: the expiry is set by the first hit and not extended.
		if ( ! is_array( $state ) || (int) $state['reset'] <= time() ) {
			$state = array(
				'count' => 0,
				'reset' => time() + $window,
			);
		}
		++$state['count'];
		set_transient( $key, $state, max( 1, (int) $state['reset'] - time() ) );
	}

	/**
	 * Reset a bucket.
	 *
	 * @param string $bucket Bucket.
	 */
	private static function clear_rate_limit( $bucket ) {
		delete_transient( self::rate_key( $bucket ) );
	}

	/**
	 * Standard rate-limit error.
	 *
	 * @return WP_Error
	 */
	private static function rate_limit_error() {
		return new WP_Error(
			'metamask_rate_limited',
			__( 'Too many attempts. Please wait a few minutes and try again.', 'metamask-login-addon' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Delete all plugin transients (challenges and rate limits).
	 */
	public static function delete_transients() {
		global $wpdb;
		foreach ( array( self::RATE_LIMIT_PREFIX, self::CHALLENGE_PREFIX ) as $prefix ) {
			foreach ( array( '_transient_', '_transient_timeout_' ) as $type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
						$wpdb->esc_like( $type . $prefix ) . '%'
					)
				);
			}
		}
	}
}
