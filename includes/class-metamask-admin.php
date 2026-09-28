<?php
/**
 * Settings screen (Settings → MetaMask Login) and setup notice.
 *
 * @package MetaMask_Login
 * @since   2.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI.
 *
 * @since 2.0.0
 */
class MetaMask_Admin {

	const PAGE_SLUG    = 'metamask-login';
	const OPTION_GROUP = 'metamask_login_settings';

	/**
	 * Known-good signature used by the self-test.
	 */
	const SELF_TEST_MESSAGE   = 'MetaMask Login self-test';
	const SELF_TEST_ADDRESS   = '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23';
	const SELF_TEST_SIGNATURE = '0xbe121216b0ec97090060669eaa784abf432bb434476cff7a736590463f821c60270f76e665756f9857f47dbfaa5d424c8a91cc448fddccd286f10a0909d2d9301b';

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	/**
	 * Settings page URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Add Settings → MetaMask Login.
	 */
	public static function add_settings_page() {
		self::$hook = (string) add_options_page(
			__( 'MetaMask Login', 'metamask-login-addon' ),
			__( 'MetaMask Login', 'metamask-login-addon' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 */
	public static function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			MetaMask_Login_Addon::OPTIONS_KEY,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( 'MetaMask_Settings', 'sanitize' ),
				'default'           => MetaMask_Settings::defaults(),
			)
		);
	}

	/**
	 * Enqueue assets on the settings page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		MetaMask_Login_Addon::enqueue_assets();

		wp_enqueue_style(
			'metamask-login-admin',
			METAMASK_LOGIN_PLUGIN_URL . 'assets/css/metamask-login-admin.css',
			array( MetaMask_Login_Addon::HANDLE ),
			MetaMask_Login_Addon::VERSION
		);

		wp_enqueue_script(
			'metamask-login-admin',
			METAMASK_LOGIN_PLUGIN_URL . 'assets/js/metamask-login-admin.js',
			array( MetaMask_Login_Addon::HANDLE ),
			MetaMask_Login_Addon::VERSION,
			array( 'in_footer' => true )
		);

		wp_localize_script(
			'metamask-login-admin',
			'MetaMaskLoginAdmin',
			array(
				'defaultButtonText' => __( 'Log in with MetaMask', 'metamask-login-addon' ),
			)
		);
	}

	/**
	 * One-time "finish setup" notice after activation.
	 */
	public static function setup_notice() {
		if ( ! get_option( 'metamask_login_show_setup_notice' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'MetaMask Login is active.', 'metamask-login-addon' ); ?></strong>
				<?php esc_html_e( 'Connect your wallet and check the setup in under a minute.', 'metamask-login-addon' ); ?>
				<a class="button button-primary" style="margin-left:8px" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Finish setup', 'metamask-login-addon' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Health checks shown on the settings page.
	 *
	 * @return array[] Each: status (ok|warn|error), label, detail.
	 */
	public static function health_checks() {
		$checks  = array();
		$options = MetaMask_Settings::all();

		// Signature verification engine.
		if ( PHP_INT_SIZE < 8 ) {
			$checks[] = array(
				'status' => 'error',
				'label'  => __( 'Signature verification', 'metamask-login-addon' ),
				'detail' => __( 'A 64-bit PHP build is required. Ask your host to switch to 64-bit PHP.', 'metamask-login-addon' ),
			);
		} else {
			$start  = microtime( true );
			$works  = MetaMask_Authentication::verify_signature( self::SELF_TEST_MESSAGE, self::SELF_TEST_SIGNATURE, self::SELF_TEST_ADDRESS );
			$millis = (int) round( ( microtime( true ) - $start ) * 1000 );

			$checks[] = array(
				'status' => $works ? 'ok' : 'error',
				'label'  => __( 'Signature verification', 'metamask-login-addon' ),
				'detail' => $works
					/* translators: %d: milliseconds */
					? sprintf( __( 'Self-test passed in %d ms. No extra PHP extensions needed.', 'metamask-login-addon' ), $millis )
					: __( 'Self-test failed. Check for a plugin filtering metamask_login_custom_signature_verify.', 'metamask-login-addon' ),
			);
		}

		// HTTPS.
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$is_local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || ( is_string( $host ) && '.local' === substr( $host, -6 ) );
		$checks[] = array(
			'status' => ( is_ssl() || $is_local ) ? 'ok' : 'warn',
			'label'  => __( 'HTTPS', 'metamask-login-addon' ),
			'detail' => is_ssl()
				? __( 'Your site uses HTTPS.', 'metamask-login-addon' )
				: ( $is_local
					? __( 'Local development site — HTTPS is not required.', 'metamask-login-addon' )
					: __( 'Use HTTPS so wallet sign-in cannot be intercepted and login cookies stay secure.', 'metamask-login-addon' ) ),
		);

		// Login page button.
		$checks[] = array(
			'status' => $options['enable_login_page'] ? 'ok' : 'warn',
			'label'  => __( 'Login page button', 'metamask-login-addon' ),
			'detail' => $options['enable_login_page']
				? __( 'Shown on the WordPress login screen.', 'metamask-login-addon' )
				: __( 'Hidden. Turn on "Show on the login screen" below, or use the block / shortcode.', 'metamask-login-addon' ),
			'link'   => $options['enable_login_page'] ? wp_login_url() : '',
		);

		// Current admin's wallet.
		$address  = MetaMask_User_Profile::get_wallet_address( get_current_user_id() );
		$checks[] = array(
			'status' => $address ? 'ok' : 'warn',
			'label'  => __( 'Your wallet', 'metamask-login-addon' ),
			'detail' => $address
				/* translators: %s: short wallet address */
				? sprintf( __( 'Connected (%s). You can log in with MetaMask.', 'metamask-login-addon' ), MetaMask_User_Profile::short_address( $address ) )
				: __( 'Not connected yet — use the button in step 1 above.', 'metamask-login-addon' ),
		);

		return $checks;
	}

	/**
	 * Number of users with a linked wallet.
	 *
	 * @return int
	 */
	private static function linked_user_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", MetaMask_Login_Addon::USER_META_KEY )
		);
	}

	/**
	 * Render a toggle (checkbox) row.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Label.
	 * @param string $description Help text.
	 */
	private static function toggle( $key, $label, $description ) {
		$id = 'metamask-' . str_replace( '_', '-', $key );
		?>
		<div class="metamask-field metamask-field--toggle">
			<input type="hidden" name="<?php echo esc_attr( MetaMask_Login_Addon::OPTIONS_KEY . '[' . $key . ']' ); ?>" value="0" />
			<input type="checkbox" class="metamask-toggle" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( MetaMask_Login_Addon::OPTIONS_KEY . '[' . $key . ']' ); ?>" value="1" <?php checked( (bool) MetaMask_Settings::get( $key ) ); ?> />
			<label for="<?php echo esc_attr( $id ); ?>">
				<span class="metamask-field__label"><?php echo esc_html( $label ); ?></span>
				<span class="metamask-field__help"><?php echo esc_html( $description ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Field name helper.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function name( $key ) {
		return MetaMask_Login_Addon::OPTIONS_KEY . '[' . $key . ']';
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_option( 'metamask_login_show_setup_notice' );

		$options = MetaMask_Settings::all();
		$checks  = self::health_checks();
		$address = MetaMask_User_Profile::get_wallet_address( get_current_user_id() );
		$step2   = (bool) $options['enable_login_page'];
		?>
		<div class="wrap metamask-admin">
			<header class="metamask-admin__header">
				<img src="<?php echo esc_url( METAMASK_LOGIN_PLUGIN_URL . 'assets/images/metamask-fox.svg' ); ?>" alt="" width="48" height="48" />
				<div>
					<h1><?php esc_html_e( 'MetaMask Login', 'metamask-login-addon' ); ?> <span class="metamask-admin__version">v<?php echo esc_html( MetaMask_Login_Addon::VERSION ); ?></span></h1>
					<p><?php esc_html_e( 'Passwordless, phishing-resistant login with Sign-In with Ethereum.', 'metamask-login-addon' ); ?></p>
				</div>
			</header>

			<section class="metamask-panel metamask-setup" aria-labelledby="metamask-setup-title">
				<h2 id="metamask-setup-title"><?php esc_html_e( 'Get started in 3 steps', 'metamask-login-addon' ); ?></h2>
				<ol class="metamask-steps">
					<li class="metamask-step<?php echo $address ? ' is-done' : ''; ?>">
						<div class="metamask-step__body">
							<h3><?php esc_html_e( 'Connect your wallet to your account', 'metamask-login-addon' ); ?></h3>
							<p><?php esc_html_e( 'MetaMask will ask you to sign a message. It is free and does not send a transaction.', 'metamask-login-addon' ); ?></p>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in wallet_card_html().
							echo MetaMask_User_Profile::wallet_card_html( get_current_user_id(), $address, true, true );
							?>
						</div>
					</li>
					<li class="metamask-step<?php echo $step2 ? ' is-done' : ''; ?>">
						<div class="metamask-step__body">
							<h3><?php esc_html_e( 'Choose where the button appears', 'metamask-login-addon' ); ?></h3>
							<p>
								<?php esc_html_e( 'The button is added to the WordPress login screen automatically. To add it to any page, use the "MetaMask Login" block or a shortcode:', 'metamask-login-addon' ); ?>
							</p>
							<div class="metamask-copy">
								<code>[metamask_login]</code>
								<button type="button" class="button button-small" data-copy="[metamask_login]"><?php esc_html_e( 'Copy', 'metamask-login-addon' ); ?></button>
							</div>
							<div class="metamask-copy">
								<code>[metamask_profile]</code>
								<button type="button" class="button button-small" data-copy="[metamask_profile]"><?php esc_html_e( 'Copy', 'metamask-login-addon' ); ?></button>
							</div>
						</div>
					</li>
					<li class="metamask-step">
						<div class="metamask-step__body">
							<h3><?php esc_html_e( 'Try it', 'metamask-login-addon' ); ?></h3>
							<p><?php esc_html_e( 'Open the login screen in a private window and click the MetaMask button.', 'metamask-login-addon' ); ?></p>
							<a class="button" href="<?php echo esc_url( wp_login_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open login screen', 'metamask-login-addon' ); ?> <span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'metamask-login-addon' ); ?></span></a>
						</div>
					</li>
				</ol>
			</section>

			<div class="metamask-admin__grid">
				<form method="post" action="options.php" class="metamask-admin__main">
					<?php settings_fields( self::OPTION_GROUP ); ?>

					<section class="metamask-panel">
						<h2><?php esc_html_e( 'Login button', 'metamask-login-addon' ); ?></h2>
						<?php self::toggle( 'enable_login_page', __( 'Show on the login screen', 'metamask-login-addon' ), __( 'Adds the button below the username and password form on wp-login.php.', 'metamask-login-addon' ) ); ?>

						<div class="metamask-field">
							<label class="metamask-field__label" for="metamask-button-text"><?php esc_html_e( 'Button text', 'metamask-login-addon' ); ?></label>
							<input type="text" class="regular-text" id="metamask-button-text" name="<?php echo esc_attr( self::name( 'button_text' ) ); ?>" value="<?php echo esc_attr( $options['button_text'] ); ?>" placeholder="<?php esc_attr_e( 'Log in with MetaMask', 'metamask-login-addon' ); ?>" />
						</div>

						<fieldset class="metamask-field">
							<legend class="metamask-field__label"><?php esc_html_e( 'Button style', 'metamask-login-addon' ); ?></legend>
							<div class="metamask-style-picker">
								<?php
								$styles = array(
									'dark'   => __( 'Dark', 'metamask-login-addon' ),
									'orange' => __( 'MetaMask orange', 'metamask-login-addon' ),
									'light'  => __( 'Light', 'metamask-login-addon' ),
								);
								foreach ( $styles as $style => $label ) :
									?>
									<label class="metamask-style-picker__option">
										<input type="radio" name="<?php echo esc_attr( self::name( 'button_style' ) ); ?>" value="<?php echo esc_attr( $style ); ?>" <?php checked( $options['button_style'], $style ); ?> />
										<span><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</fieldset>

						<div class="metamask-field">
							<span class="metamask-field__label"><?php esc_html_e( 'Preview', 'metamask-login-addon' ); ?></span>
							<div class="metamask-preview" id="metamask-preview">
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button_html().
								echo MetaMask_Login_Addon::button_html(
									array(
										'action' => 'preview',
										'text'   => MetaMask_Settings::button_text(),
									)
								);
								?>
							</div>
						</div>

						<div class="metamask-field">
							<label class="metamask-field__label" for="metamask-login-redirect"><?php esc_html_e( 'After login, go to', 'metamask-login-addon' ); ?></label>
							<input type="url" class="regular-text" id="metamask-login-redirect" name="<?php echo esc_attr( self::name( 'login_redirect' ) ); ?>" value="<?php echo esc_attr( $options['login_redirect'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/my-account/' ) ); ?>" />
							<p class="metamask-field__help"><?php esc_html_e( 'Leave empty to behave like normal WordPress logins (Dashboard, or the page the user came from).', 'metamask-login-addon' ); ?></p>
						</div>
					</section>

					<section class="metamask-panel">
						<h2><?php esc_html_e( 'New users', 'metamask-login-addon' ); ?></h2>
						<?php self::toggle( 'enable_registration', __( 'Create accounts for new wallets', 'metamask-login-addon' ), __( 'When a wallet that is not connected to any account signs in, create a new account for it automatically.', 'metamask-login-addon' ) ); ?>
						<div class="metamask-field" data-depends-on="metamask-enable-registration">
							<label class="metamask-field__label" for="metamask-default-role"><?php esc_html_e( 'Role for new accounts', 'metamask-login-addon' ); ?></label>
							<select id="metamask-default-role" name="<?php echo esc_attr( self::name( 'default_role' ) ); ?>">
								<?php foreach ( MetaMask_Settings::allowed_roles() as $slug => $name ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $options['default_role'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="metamask-field__help"><?php esc_html_e( 'Roles that can manage the site or other users are never offered.', 'metamask-login-addon' ); ?></p>
						</div>
					</section>

					<section class="metamask-panel">
						<h2><?php esc_html_e( 'Security', 'metamask-login-addon' ); ?></h2>
						<?php self::toggle( 'rate_limit_enabled', __( 'Limit failed attempts', 'metamask-login-addon' ), __( 'Temporarily block an IP address after repeated failed wallet logins.', 'metamask-login-addon' ) ); ?>
						<div class="metamask-field metamask-field--inline" data-depends-on="metamask-rate-limit-enabled">
							<label for="metamask-rate-attempts"><?php esc_html_e( 'Allow', 'metamask-login-addon' ); ?></label>
							<input type="number" class="small-text" id="metamask-rate-attempts" name="<?php echo esc_attr( self::name( 'rate_limit_attempts' ) ); ?>" value="<?php echo esc_attr( $options['rate_limit_attempts'] ); ?>" min="1" max="50" />
							<label for="metamask-rate-window"><?php esc_html_e( 'failed attempts per', 'metamask-login-addon' ); ?></label>
							<select id="metamask-rate-window" name="<?php echo esc_attr( self::name( 'rate_limit_window' ) ); ?>">
								<?php
								$windows = array(
									60    => __( '1 minute', 'metamask-login-addon' ),
									300   => __( '5 minutes', 'metamask-login-addon' ),
									900   => __( '15 minutes', 'metamask-login-addon' ),
									3600  => __( '1 hour', 'metamask-login-addon' ),
									86400 => __( '24 hours', 'metamask-login-addon' ),
								);
								if ( ! isset( $windows[ (int) $options['rate_limit_window'] ] ) ) {
									/* translators: %d: seconds */
									$windows[ (int) $options['rate_limit_window'] ] = sprintf( __( '%d seconds', 'metamask-login-addon' ), (int) $options['rate_limit_window'] );
								}
								foreach ( $windows as $seconds => $label ) :
									?>
									<option value="<?php echo esc_attr( $seconds ); ?>" <?php selected( (int) $options['rate_limit_window'], $seconds ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="metamask-field">
							<label class="metamask-field__label" for="metamask-sign-statement"><?php esc_html_e( 'Sign-in message', 'metamask-login-addon' ); ?></label>
							<input type="text" class="large-text" id="metamask-sign-statement" name="<?php echo esc_attr( self::name( 'sign_statement' ) ); ?>" value="<?php echo esc_attr( $options['sign_statement'] ); ?>" maxlength="300" placeholder="<?php echo esc_attr( sprintf( /* translators: %s: site name */ __( 'Sign in to %s. This request will not trigger a blockchain transaction or cost any gas fees.', 'metamask-login-addon' ), get_bloginfo( 'name' ) ) ); ?>" />
							<p class="metamask-field__help"><?php esc_html_e( 'The sentence users see in MetaMask when signing in. Your domain, a one-time code and an expiry time are always added.', 'metamask-login-addon' ); ?></p>
						</div>
					</section>

					<section class="metamask-panel">
						<h2><?php esc_html_e( 'Data', 'metamask-login-addon' ); ?></h2>
						<?php self::toggle( 'delete_data_on_uninstall', __( 'Delete wallet links when the plugin is deleted', 'metamask-login-addon' ), __( 'Off by default, so reinstalling keeps every user\'s connected wallet.', 'metamask-login-addon' ) ); ?>
					</section>

					<?php submit_button( __( 'Save changes', 'metamask-login-addon' ) ); ?>
				</form>

				<aside class="metamask-admin__side">
					<section class="metamask-panel">
						<h2><?php esc_html_e( 'Status', 'metamask-login-addon' ); ?></h2>
						<ul class="metamask-checks">
							<?php foreach ( $checks as $check ) : ?>
								<li class="metamask-check metamask-check--<?php echo esc_attr( $check['status'] ); ?>">
									<span class="metamask-check__icon dashicons <?php echo esc_attr( 'ok' === $check['status'] ? 'dashicons-yes-alt' : ( 'warn' === $check['status'] ? 'dashicons-warning' : 'dashicons-dismiss' ) ); ?>" aria-hidden="true"></span>
									<span class="screen-reader-text"><?php echo esc_html( 'ok' === $check['status'] ? __( 'OK:', 'metamask-login-addon' ) : __( 'Needs attention:', 'metamask-login-addon' ) ); ?></span>
									<span>
										<strong><?php echo esc_html( $check['label'] ); ?></strong><br />
										<?php echo esc_html( $check['detail'] ); ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
						<p class="metamask-muted">
							<?php
							$linked = self::linked_user_count();
							printf(
								/* translators: %s: number of users */
								esc_html( _n( '%s user has a connected wallet.', '%s users have a connected wallet.', $linked, 'metamask-login-addon' ) ),
								esc_html( number_format_i18n( $linked ) )
							);
							?>
							<a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php esc_html_e( 'View users', 'metamask-login-addon' ); ?></a>
						</p>
					</section>

					<section class="metamask-panel">
						<h2><?php esc_html_e( 'Shortcode options', 'metamask-login-addon' ); ?></h2>
						<dl class="metamask-docs">
							<dt><code>[metamask_login redirect="/account" button_text="Sign in" style="orange"]</code></dt>
							<dd><?php esc_html_e( 'Login button. All attributes are optional.', 'metamask-login-addon' ); ?></dd>
							<dt><code>[metamask_profile show_address="true"]</code></dt>
							<dd><?php esc_html_e( 'Lets logged-in users connect or disconnect their wallet from the front end.', 'metamask-login-addon' ); ?></dd>
						</dl>
						<p><a href="https://github.com/bloclabs-io/metamask-login-addon#readme" target="_blank" rel="noopener"><?php esc_html_e( 'Full documentation', 'metamask-login-addon' ); ?></a></p>
					</section>
				</aside>
			</div>
		</div>
		<?php
	}
}
