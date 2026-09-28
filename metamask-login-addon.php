<?php
/**
 * Plugin Name:       MetaMask Login Add-On
 * Plugin URI:        https://github.com/bloclabs-io/metamask-login-addon
 * Description:       Let people log in to WordPress with their MetaMask wallet — no passwords. Uses Sign-In with Ethereum (EIP-4361) with real on-server signature verification.
 * Version:           3.0.0
 * Requires at least: 6.5
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            blocLabs.io
 * Author URI:        https://bloclabs.io
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       metamask-login-addon
 * Domain Path:       /languages
 *
 * @package MetaMask_Login
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'METAMASK_LOGIN_VERSION', '3.0.0' );
define( 'METAMASK_LOGIN_PLUGIN_FILE', __FILE__ );
define( 'METAMASK_LOGIN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'METAMASK_LOGIN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'METAMASK_LOGIN_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once METAMASK_LOGIN_PLUGIN_DIR . 'includes/class-metamask-settings.php';
require_once METAMASK_LOGIN_PLUGIN_DIR . 'includes/utils/eth-sign-verify.php';
require_once METAMASK_LOGIN_PLUGIN_DIR . 'includes/class-metamask-user-profile.php';
require_once METAMASK_LOGIN_PLUGIN_DIR . 'includes/class-metamask-authentication.php';
require_once METAMASK_LOGIN_PLUGIN_DIR . 'includes/class-metamask-api.php';
require_once METAMASK_LOGIN_PLUGIN_DIR . 'includes/class-metamask-admin.php';

/**
 * Main plugin class.
 *
 * @since 2.0.0
 */
class MetaMask_Login_Addon {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = METAMASK_LOGIN_VERSION;

	/**
	 * User meta key holding the (lowercase) wallet address.
	 *
	 * @var string
	 */
	const USER_META_KEY = 'metamask_wallet_address';

	/**
	 * User meta key for addresses saved by 2.x, which were never verified.
	 *
	 * @var string
	 */
	const LEGACY_META_KEY = 'metamask_wallet_address_unverified';

	/**
	 * Plugin options key.
	 *
	 * @var string
	 */
	const OPTIONS_KEY = 'metamask_login_options';

	/**
	 * Option storing the installed version (for upgrade routines).
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'metamask_login_version';

	/**
	 * Script / style handle.
	 *
	 * @var string
	 */
	const HANDLE = 'metamask-login';

	/**
	 * Singleton instance.
	 *
	 * @var MetaMask_Login_Addon|null
	 */
	private static $instance = null;

	/**
	 * Get the plugin instance.
	 *
	 * @return MetaMask_Login_Addon
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook everything up.
	 */
	private function __construct() {
		self::maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_action( 'init', array( $this, 'register_block' ) );

		add_shortcode( 'metamask_login', array( $this, 'login_shortcode' ) );
		add_shortcode( 'metamask_profile', array( $this, 'profile_shortcode' ) );

		MetaMask_User_Profile::init();
		MetaMask_Authentication::init();
		MetaMask_API::init();

		if ( is_admin() ) {
			MetaMask_Admin::init();
		}

		add_filter( 'plugin_action_links_' . METAMASK_LOGIN_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
	}

	/**
	 * Load bundled translations (WordPress.org language packs load automatically).
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'metamask-login-addon', false, dirname( METAMASK_LOGIN_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Run data migrations when the stored version is older than the code.
	 *
	 * @since 3.0.0
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::VERSION_OPTION, '' );
		if ( self::VERSION === $installed ) {
			return;
		}

		MetaMask_Settings::migrate();

		// 3.0.0: 2.x let users type any address into their profile without
		// proving ownership, and never verified signatures. Those links are
		// set aside as unverified; users confirm them by connecting again.
		if ( '' === $installed || version_compare( $installed, '3.0.0', '<' ) ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::USER_META_KEY )
			);
			if ( $user_ids ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->usermeta} SET meta_key = %s, meta_value = LOWER(meta_value) WHERE meta_key = %s",
						self::LEGACY_META_KEY,
						self::USER_META_KEY
					)
				);
				foreach ( $user_ids as $user_id ) {
					wp_cache_delete( (int) $user_id, 'user_meta' );
				}
			}
			// phpcs:enable
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Register front-end, login-page and profile assets.
	 */
	public function register_assets() {
		wp_register_style(
			self::HANDLE,
			METAMASK_LOGIN_PLUGIN_URL . 'assets/css/metamask-login.css',
			array(),
			self::VERSION
		);

		wp_register_script(
			self::HANDLE,
			METAMASK_LOGIN_PLUGIN_URL . 'assets/js/metamask-login.js',
			array(),
			self::VERSION,
			array( 'in_footer' => true )
		);

		wp_localize_script( self::HANDLE, 'MetaMaskLogin', self::get_script_data() );
	}

	/**
	 * Data passed to the browser script.
	 *
	 * @return array
	 */
	public static function get_script_data() {
		return array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'metamask_login_nonce' ),
			'installUrl'  => 'https://metamask.io/download/',
			'deepLinkUrl' => 'https://metamask.app.link/dapp/',
			'i18n'        => array(
				'connecting'        => __( 'Connecting to your wallet…', 'metamask-login-addon' ),
				'preparing'         => __( 'Preparing a secure sign-in message…', 'metamask-login-addon' ),
				'signing'           => __( 'Please confirm the signature request in MetaMask.', 'metamask-login-addon' ),
				'verifying'         => __( 'Verifying your signature…', 'metamask-login-addon' ),
				'loginSuccess'      => __( 'Success! Logging you in…', 'metamask-login-addon' ),
				'linkSuccess'       => __( 'Wallet connected to your account.', 'metamask-login-addon' ),
				'unlinkSuccess'     => __( 'Wallet disconnected.', 'metamask-login-addon' ),
				'confirmUnlink'     => __( 'Disconnect this wallet? It will no longer be able to log in to this account.', 'metamask-login-addon' ),
				'noWallet'          => __( 'MetaMask was not detected in this browser.', 'metamask-login-addon' ),
				'installMetaMask'   => __( 'Install MetaMask', 'metamask-login-addon' ),
				'openInApp'         => __( 'Open in the MetaMask app', 'metamask-login-addon' ),
				'rejected'          => __( 'You cancelled the request in MetaMask.', 'metamask-login-addon' ),
				'pending'           => __( 'MetaMask already has a request waiting. Open the MetaMask extension to continue.', 'metamask-login-addon' ),
				'noAccounts'        => __( 'No account was shared. Unlock MetaMask and try again.', 'metamask-login-addon' ),
				'genericError'      => __( 'Something went wrong. Please try again.', 'metamask-login-addon' ),
				'networkError'      => __( 'Could not reach the site. Check your connection and try again.', 'metamask-login-addon' ),
				'accountChanged'    => __( 'Your active MetaMask account changed.', 'metamask-login-addon' ),
				'copied'            => __( 'Copied!', 'metamask-login-addon' ),
			),
		);
	}

	/**
	 * Enqueue the front-end assets.
	 */
	public static function enqueue_assets() {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			self::get_instance()->register_assets();
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Register the "MetaMask Login" block.
	 *
	 * @since 3.0.0
	 */
	public function register_block() {
		register_block_type(
			METAMASK_LOGIN_PLUGIN_DIR . 'blocks/login-button',
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Server-side render for the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$attributes = wp_parse_args(
			$attributes,
			array(
				'buttonText'  => '',
				'redirect'    => '',
				'buttonStyle' => '',
			)
		);

		self::enqueue_assets();

		$html = self::render_template(
			'login-button.php',
			array(
				'atts'    => array(
					'button_text'  => (string) $attributes['buttonText'],
					'redirect'     => (string) $attributes['redirect'],
					'style'        => (string) $attributes['buttonStyle'],
					'button_class' => '',
				),
				// The editor preview is always rendered for a logged-in user,
				// so show the button there instead of the "logged in" notice.
				'preview' => wp_is_serving_rest_request(),
			)
		);

		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * [metamask_login] shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function login_shortcode( $atts ) {
		self::enqueue_assets();

		$atts = shortcode_atts(
			array(
				'redirect'     => '',
				'button_text'  => '',
				'button_class' => '',
				'style'        => '',
			),
			$atts,
			'metamask_login'
		);

		return self::render_template( 'login-button.php', array( 'atts' => $atts ) );
	}

	/**
	 * [metamask_profile] shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function profile_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p class="metamask-notice">' . esc_html__( 'Please log in to manage your wallet.', 'metamask-login-addon' ) . '</p>';
		}

		self::enqueue_assets();

		$atts = shortcode_atts(
			array(
				'show_address' => 'true',
				'show_balance' => 'false',
			),
			$atts,
			'metamask_profile'
		);

		return self::render_template( 'user-profile.php', array( 'atts' => $atts ) );
	}

	/**
	 * Render a template file. Themes can override templates by copying them
	 * to `{theme}/metamask-login/{name}`.
	 *
	 * @since 3.0.0
	 * @param string $name Template file name.
	 * @param array  $vars Variables exposed to the template.
	 * @return string
	 */
	public static function render_template( $name, array $vars = array() ) {
		$path = locate_template( 'metamask-login/' . $name );
		if ( ! $path ) {
			$path = METAMASK_LOGIN_PLUGIN_DIR . 'templates/' . $name;
		}

		/**
		 * Filter the template path used to render plugin output.
		 *
		 * @since 3.0.0
		 * @param string $path Template path.
		 * @param string $name Template name.
		 */
		$path = apply_filters( 'metamask_login_template', $path, $name );

		if ( ! file_exists( $path ) ) {
			return '';
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );
		include $path;
		return (string) ob_get_clean();
	}

	/**
	 * Markup for a MetaMask button.
	 *
	 * @since 3.0.0
	 * @param array $args {
	 *     @type string $action Button action: login, link or unlink.
	 *     @type string $text   Label.
	 *     @type string $style  Visual style: dark, orange or light.
	 *     @type string $class  Extra CSS classes.
	 *     @type array  $data   Extra data-* attributes.
	 * }
	 * @return string
	 */
	public static function button_html( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'action' => 'login',
				'text'   => '',
				'style'  => MetaMask_Settings::get( 'button_style' ),
				'class'  => '',
				'data'   => array(),
			)
		);

		$style = in_array( $args['style'], MetaMask_Settings::button_styles(), true ) ? $args['style'] : 'dark';

		$classes = array( 'metamask-button', 'metamask-button--' . $style );
		foreach ( preg_split( '/\s+/', (string) $args['class'] ) as $class ) {
			if ( '' !== $class ) {
				$classes[] = sanitize_html_class( $class );
			}
		}

		$data = '';
		foreach ( $args['data'] as $key => $value ) {
			$data .= sprintf( ' data-%s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}

		return sprintf(
			'<button type="button" class="%1$s" data-metamask-action="%2$s"%3$s><img class="metamask-button__icon" src="%4$s" alt="" width="24" height="24" aria-hidden="true" /><span class="metamask-button__label">%5$s</span></button>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $args['action'] ),
			$data,
			esc_url( METAMASK_LOGIN_PLUGIN_URL . 'assets/images/metamask-fox.svg' ),
			esc_html( $args['text'] )
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( MetaMask_Admin::page_url() ),
				esc_html__( 'Settings', 'metamask-login-addon' )
			)
		);
		return $links;
	}

	/**
	 * Plugin directory path (kept for backwards compatibility).
	 *
	 * @return string
	 */
	public function get_plugin_dir() {
		return METAMASK_LOGIN_PLUGIN_DIR;
	}

	/**
	 * Plugin URL (kept for backwards compatibility).
	 *
	 * @return string
	 */
	public function get_plugin_url() {
		return METAMASK_LOGIN_PLUGIN_URL;
	}

	/**
	 * Get a plugin option (kept for backwards compatibility).
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get_option( $key, $default = null ) {
		$value = MetaMask_Settings::get( $key );
		return null === $value ? $default : $value;
	}
}

/**
 * Activation: store defaults and flag the setup notice.
 */
function metamask_login_activate() {
	if ( false === get_option( MetaMask_Login_Addon::OPTIONS_KEY ) ) {
		add_option( MetaMask_Login_Addon::OPTIONS_KEY, MetaMask_Settings::defaults() );
	}
	MetaMask_Login_Addon::maybe_upgrade();
	update_option( 'metamask_login_show_setup_notice', 1 );
}
register_activation_hook( __FILE__, 'metamask_login_activate' );

/**
 * Deactivation: clear short-lived data.
 */
function metamask_login_deactivate() {
	MetaMask_Authentication::delete_transients();
}
register_deactivation_hook( __FILE__, 'metamask_login_deactivate' );

/**
 * Boot the plugin.
 *
 * @return MetaMask_Login_Addon
 */
function metamask_login_addon_init() {
	return MetaMask_Login_Addon::get_instance();
}
add_action( 'plugins_loaded', 'metamask_login_addon_init' );
