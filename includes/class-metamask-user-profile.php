<?php
/**
 * Wallet ↔ user linking and the "MetaMask Wallet" profile section.
 *
 * @package MetaMask_Login
 * @since   2.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User profile integration.
 *
 * @since 2.0.0
 */
class MetaMask_User_Profile {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_section' ), 5 );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_section' ), 5 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_profile_assets' ) );

		add_filter( 'manage_users_columns', array( __CLASS__, 'add_users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_users_column' ), 10, 3 );

		add_action( 'wp_ajax_metamask_connect_wallet', array( __CLASS__, 'ajax_connect_wallet' ) );
		add_action( 'wp_ajax_metamask_disconnect_wallet', array( __CLASS__, 'ajax_disconnect_wallet' ) );
	}

	/**
	 * Wallet address linked to a user (lowercase) or empty string.
	 *
	 * @since 3.0.0
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function get_wallet_address( $user_id ) {
		return (string) get_user_meta( $user_id, MetaMask_Login_Addon::USER_META_KEY, true );
	}

	/**
	 * Shorten an address for display: 0x1234…abcd.
	 *
	 * @since 3.0.0
	 * @param string $address Address.
	 * @return string
	 */
	public static function short_address( $address ) {
		if ( ! Eth_Sign_Verify::is_valid_address( $address ) ) {
			return (string) $address;
		}
		$checksum = Eth_Sign_Verify::to_checksum_address( $address );
		return substr( $checksum, 0, 6 ) . '…' . substr( $checksum, -4 );
	}

	/**
	 * Find the user linked to a wallet address.
	 *
	 * @since 2.0.0
	 * @param string $address Address.
	 * @return WP_User|false
	 */
	public static function get_user_by_wallet_address( $address ) {
		if ( ! Eth_Sign_Verify::is_valid_address( $address ) ) {
			return false;
		}

		$users = get_users(
			array(
				'meta_key'    => MetaMask_Login_Addon::USER_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => strtolower( $address ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'      => 1,
				'count_total' => false,
				'blog_id'     => 0,
			)
		);

		return empty( $users ) ? false : $users[0];
	}

	/**
	 * Link a wallet to a user after verifying a signed `link` challenge.
	 *
	 * @since 3.0.0
	 * @param int    $user_id   User ID.
	 * @param string $address   Address.
	 * @param string $nonce     Challenge nonce.
	 * @param string $signature Signature.
	 * @return true|WP_Error
	 */
	public static function link_wallet( $user_id, $address, $nonce, $signature ) {
		if ( MetaMask_Authentication::is_rate_limited( 'failure' ) ) {
			return new WP_Error( 'metamask_rate_limited', __( 'Too many attempts. Please wait a few minutes and try again.', 'metamask-login-addon' ), array( 'status' => 429 ) );
		}

		$verified = MetaMask_Authentication::verify_challenge( $address, $nonce, $signature, 'link', $user_id );
		if ( is_wp_error( $verified ) ) {
			MetaMask_Authentication::hit_rate_limit( 'failure' );
			return $verified;
		}

		$address  = strtolower( $address );
		$existing = self::get_user_by_wallet_address( $address );
		if ( $existing && (int) $existing->ID !== (int) $user_id ) {
			return new WP_Error( 'metamask_wallet_in_use', __( 'This wallet is already connected to a different account.', 'metamask-login-addon' ), array( 'status' => 409 ) );
		}

		$old = self::get_wallet_address( $user_id );
		update_user_meta( $user_id, MetaMask_Login_Addon::USER_META_KEY, $address );
		delete_user_meta( $user_id, MetaMask_Login_Addon::LEGACY_META_KEY );

		/**
		 * Fires when a wallet is linked to an account.
		 *
		 * @since 2.0.0
		 * @param int    $user_id     User ID.
		 * @param string $address     New address.
		 * @param string $old_address Previous address (may be empty).
		 */
		do_action( 'metamask_wallet_connected', $user_id, $address, $old );

		if ( '' !== $old && $old !== $address ) {
			/**
			 * Fires when a user's wallet address changes.
			 *
			 * @since 2.0.0
			 * @param int    $user_id     User ID.
			 * @param string $address     New address.
			 * @param string $old_address Old address.
			 */
			do_action( 'metamask_wallet_updated', $user_id, $address, $old );
		}

		return true;
	}

	/**
	 * Unlink the wallet from a user.
	 *
	 * @since 3.0.0
	 * @param int $user_id User ID.
	 * @return string Previously linked address.
	 */
	public static function unlink_wallet( $user_id ) {
		$old = self::get_wallet_address( $user_id );
		delete_user_meta( $user_id, MetaMask_Login_Addon::USER_META_KEY );
		delete_user_meta( $user_id, MetaMask_Login_Addon::LEGACY_META_KEY );

		/**
		 * Fires when a wallet is unlinked from an account.
		 *
		 * @since 2.0.0
		 * @param int    $user_id     User ID.
		 * @param string $old_address Removed address.
		 */
		do_action( 'metamask_wallet_disconnected', $user_id, $old );

		return $old;
	}

	/*
	 * --------------------------------------------------------------------
	 * AJAX
	 * --------------------------------------------------------------------
	 */

	/**
	 * Common checks for logged-in AJAX calls.
	 */
	private static function check_ajax() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in first.', 'metamask-login-addon' ) ), 401 );
		}
		if ( ! check_ajax_referer( 'metamask_login_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please reload the page and try again.', 'metamask-login-addon' ) ), 403 );
		}
	}

	/**
	 * AJAX: link the current user's wallet.
	 */
	public static function ajax_connect_wallet() {
		self::check_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in check_ajax().
		$address   = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : '';
		$challenge = MetaMask_Authentication::challenge_param(
			isset( $_POST['challenge'] ) ? sanitize_text_field( wp_unslash( $_POST['challenge'] ) ) : '',
			isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only used to extract the nonce line.
		);
		// phpcs:enable

		$result = self::link_wallet( get_current_user_id(), $address, $challenge, $signature );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				isset( $data['status'] ) ? (int) $data['status'] : 400
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Wallet connected to your account.', 'metamask-login-addon' ),
				'address' => strtolower( $address ),
			)
		);
	}

	/**
	 * AJAX: unlink a wallet (own account, or any account the user can edit).
	 */
	public static function ajax_disconnect_wallet() {
		self::check_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in check_ajax().
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this user.', 'metamask-login-addon' ) ), 403 );
		}

		self::unlink_wallet( $user_id );

		wp_send_json_success( array( 'message' => __( 'Wallet disconnected.', 'metamask-login-addon' ) ) );
	}

	/*
	 * --------------------------------------------------------------------
	 * wp-admin profile screen
	 * --------------------------------------------------------------------
	 */

	/**
	 * Enqueue assets on profile.php / user-edit.php.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue_profile_assets( $hook ) {
		if ( 'profile.php' !== $hook && 'user-edit.php' !== $hook ) {
			return;
		}
		MetaMask_Login_Addon::enqueue_assets();
	}

	/**
	 * Render the "MetaMask Wallet" section on the profile screen.
	 *
	 * @param WP_User $user User being edited.
	 */
	public static function render_profile_section( $user ) {
		$address = self::get_wallet_address( $user->ID );
		$is_self = get_current_user_id() === (int) $user->ID;
		?>
		<h2 id="metamask-wallet"><?php esc_html_e( 'MetaMask Wallet', 'metamask-login-addon' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Wallet login', 'metamask-login-addon' ); ?></th>
				<td>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output is escaped.
					echo self::wallet_card_html( $user->ID, $address, $is_self, true );
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Shared wallet status card (profile screen and [metamask_profile]).
	 *
	 * @since 3.0.0
	 * @param int    $user_id      User ID.
	 * @param string $address      Linked address.
	 * @param bool   $can_link     Whether the viewer may link a wallet (only for themselves).
	 * @param bool   $show_address Whether to show the full address.
	 * @return string
	 */
	public static function wallet_card_html( $user_id, $address, $can_link, $show_address = true ) {
		ob_start();
		?>
		<div class="metamask-card<?php echo $address ? ' is-connected' : ''; ?>" data-metamask-container>
			<div class="metamask-card__header">
				<img class="metamask-card__icon" src="<?php echo esc_url( METAMASK_LOGIN_PLUGIN_URL . 'assets/images/metamask-fox.svg' ); ?>" alt="" width="40" height="40" />
				<div class="metamask-card__summary">
					<?php if ( $address ) : ?>
						<span class="metamask-pill metamask-pill--ok"><?php esc_html_e( 'Connected', 'metamask-login-addon' ); ?></span>
						<?php if ( $show_address ) : ?>
							<code class="metamask-card__address" title="<?php echo esc_attr( Eth_Sign_Verify::to_checksum_address( $address ) ); ?>"><?php echo esc_html( Eth_Sign_Verify::to_checksum_address( $address ) ); ?></code>
						<?php endif; ?>
					<?php else : ?>
						<span class="metamask-pill"><?php esc_html_e( 'Not connected', 'metamask-login-addon' ); ?></span>
						<?php
						$legacy = (string) get_user_meta( $user_id, MetaMask_Login_Addon::LEGACY_META_KEY, true );
						if ( '' !== $legacy && $can_link ) :
							?>
							<span class="metamask-card__hint metamask-card__hint--legacy">
								<?php
								printf(
									/* translators: %s: wallet address */
									esc_html__( 'An earlier version saved %s without checking it. Connect it again to confirm you own it.', 'metamask-login-addon' ),
									'<code>' . esc_html( MetaMask_User_Profile::short_address( $legacy ) ) . '</code>'
								);
								?>
							</span>
						<?php endif; ?>
						<span class="metamask-card__hint">
							<?php
							echo $can_link
								? esc_html__( 'Connect your MetaMask wallet to log in with one click — no password needed.', 'metamask-login-addon' )
								: esc_html__( 'This user has not connected a wallet. Only they can connect one, because it requires their signature.', 'metamask-login-addon' );
							?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<div class="metamask-card__actions">
				<?php
				if ( $can_link ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button_html().
					echo MetaMask_Login_Addon::button_html(
						array(
							'action' => 'link',
							'text'   => $address ? __( 'Switch wallet', 'metamask-login-addon' ) : __( 'Connect MetaMask', 'metamask-login-addon' ),
							'style'  => $address ? 'light' : 'dark',
						)
					);
				}
				if ( $address && current_user_can( 'edit_user', $user_id ) ) {
					printf(
						'<button type="button" class="button-link metamask-link-danger" data-metamask-action="unlink" data-user-id="%d">%s</button>',
						(int) $user_id,
						esc_html__( 'Disconnect wallet', 'metamask-login-addon' )
					);
				}
				?>
			</div>
			<div class="metamask-status" role="status" aria-live="polite"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/*
	 * --------------------------------------------------------------------
	 * Users list column
	 * --------------------------------------------------------------------
	 */

	/**
	 * Add the "Wallet" column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_users_column( $columns ) {
		$columns['metamask_address'] = __( 'MetaMask Wallet', 'metamask-login-addon' );
		return $columns;
	}

	/**
	 * Render the "Wallet" column.
	 *
	 * @param string $value       Current value.
	 * @param string $column_name Column.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public static function render_users_column( $value, $column_name, $user_id ) {
		if ( 'metamask_address' !== $column_name ) {
			return $value;
		}

		$address = self::get_wallet_address( $user_id );
		if ( '' === $address ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not connected', 'metamask-login-addon' ) . '</span>';
		}

		return '<code title="' . esc_attr( Eth_Sign_Verify::to_checksum_address( $address ) ) . '">' . esc_html( self::short_address( $address ) ) . '</code>';
	}
}
