<?php
/**
 * REST API endpoints (namespace `metamask-login/v1`).
 *
 * @package MetaMask_Login
 * @since   2.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API.
 *
 * @since 2.0.0
 */
class MetaMask_API {

	const NAMESPACE_V1 = 'metamask-login/v1';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Shared argument schema.
	 *
	 * @param string[] $keys Keys to include.
	 * @return array
	 */
	private static function args( array $keys ) {
		$all = array(
			'address'     => array(
				'type'              => 'string',
				'required'          => true,
				'pattern'           => '^0x[0-9a-fA-F]{40}$',
				'description'       => __( 'Wallet address.', 'metamask-login-addon' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'chain_id'    => array(
				'type'              => array( 'integer', 'string' ),
				'default'           => 1,
				'description'       => __( 'Chain id reported by the wallet.', 'metamask-login-addon' ),
			),
			'purpose'     => array(
				'type'        => 'string',
				'enum'        => array( 'login', 'link' ),
				'default'     => 'login',
				'description' => __( 'What the challenge will be used for.', 'metamask-login-addon' ),
			),
			'signature'   => array(
				'type'              => 'string',
				'required'          => true,
				'pattern'           => '^(0x)?[0-9a-fA-F]{130}$',
				'description'       => __( 'personal_sign signature.', 'metamask-login-addon' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'nonce'       => array(
				'type'              => 'string',
				'default'           => '',
				'description'       => __( 'Challenge nonce returned by /nonce.', 'metamask-login-addon' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'message'     => array(
				'type'        => 'string',
				'default'     => '',
				'description' => __( 'Deprecated: the signed message. Send `nonce` instead.', 'metamask-login-addon' ),
			),
			'remember'    => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'redirect_to' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
			),
		);

		return array_intersect_key( $all, array_flip( $keys ) );
	}

	/**
	 * Register the routes.
	 */
	public static function register_routes() {
		$logged_in = static function () {
			return is_user_logged_in();
		};

		register_rest_route(
			self::NAMESPACE_V1,
			'/nonce',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'get_nonce' ),
				'permission_callback' => '__return_true',
				'args'                => self::args( array( 'address', 'chain_id', 'purpose' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/auth',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'authenticate' ),
				'permission_callback' => '__return_true',
				'args'                => self::args( array( 'address', 'signature', 'nonce', 'message', 'remember', 'redirect_to' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/link',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'link_wallet' ),
				'permission_callback' => $logged_in,
				'args'                => self::args( array( 'address', 'signature', 'nonce', 'message' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/unlink',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'unlink_wallet' ),
				'permission_callback' => $logged_in,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_me' ),
				'permission_callback' => $logged_in,
			)
		);
	}

	/**
	 * POST /nonce — issue a sign-in challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_nonce( WP_REST_Request $request ) {
		$purpose = $request->get_param( 'purpose' );
		if ( 'link' === $purpose && ! is_user_logged_in() ) {
			return new WP_Error( 'metamask_not_logged_in', __( 'Please log in first.', 'metamask-login-addon' ), array( 'status' => 401 ) );
		}

		$challenge = MetaMask_Authentication::create_challenge(
			$request->get_param( 'address' ),
			$request->get_param( 'chain_id' ),
			$purpose,
			get_current_user_id()
		);

		if ( is_wp_error( $challenge ) ) {
			return $challenge;
		}

		return rest_ensure_response( array( 'success' => true ) + $challenge );
	}

	/**
	 * POST /auth — log in with a signed challenge (sets the auth cookie).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ) {
		$user = MetaMask_Authentication::login_with_signature(
			$request->get_param( 'address' ),
			MetaMask_Authentication::challenge_param( (string) $request->get_param( 'nonce' ), $request->get_param( 'message' ) ),
			$request->get_param( 'signature' ),
			(bool) $request->get_param( 'remember' )
		);

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'message'  => __( 'Login successful!', 'metamask-login-addon' ),
				'user_id'  => $user->ID,
				'redirect' => MetaMask_Authentication::get_redirect_url( $user, (string) $request->get_param( 'redirect_to' ) ),
			)
		);
	}

	/**
	 * POST /link — link a wallet to the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function link_wallet( WP_REST_Request $request ) {
		$result = MetaMask_User_Profile::link_wallet(
			get_current_user_id(),
			$request->get_param( 'address' ),
			MetaMask_Authentication::challenge_param( (string) $request->get_param( 'nonce' ), $request->get_param( 'message' ) ),
			$request->get_param( 'signature' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Wallet connected to your account.', 'metamask-login-addon' ),
				'address' => strtolower( $request->get_param( 'address' ) ),
			)
		);
	}

	/**
	 * POST /unlink — unlink the current user's wallet.
	 *
	 * @return WP_REST_Response
	 */
	public static function unlink_wallet() {
		MetaMask_User_Profile::unlink_wallet( get_current_user_id() );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Wallet disconnected.', 'metamask-login-addon' ),
			)
		);
	}

	/**
	 * GET /me — wallet status of the current user.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_me() {
		$address = MetaMask_User_Profile::get_wallet_address( get_current_user_id() );

		return rest_ensure_response(
			array(
				'user_id'   => get_current_user_id(),
				'connected' => '' !== $address,
				'address'   => '' !== $address ? Eth_Sign_Verify::to_checksum_address( $address ) : null,
			)
		);
	}
}
