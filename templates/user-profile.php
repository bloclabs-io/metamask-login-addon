<?php
/**
 * Template: [metamask_profile] shortcode.
 *
 * Override by copying to `{your-theme}/metamask-login/user-profile.php`.
 *
 * @package MetaMask_Login
 *
 * @var array $atts {
 *     @type string $show_address Show the full address (true|false).
 *     @type string $show_balance Show the ETH balance read from the wallet (true|false).
 * }
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$metamask_user_id = get_current_user_id();
$metamask_address = MetaMask_User_Profile::get_wallet_address( $metamask_user_id );
?>
<div class="metamask-profile">
	<h3 class="metamask-profile__title"><?php esc_html_e( 'MetaMask Wallet', 'metamask-login-addon' ); ?></h3>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in wallet_card_html().
	echo MetaMask_User_Profile::wallet_card_html( $metamask_user_id, $metamask_address, true, 'false' !== $atts['show_address'] );
	?>
	<?php if ( $metamask_address && 'true' === $atts['show_balance'] ) : ?>
		<p class="metamask-profile__balance">
			<strong><?php esc_html_e( 'Balance:', 'metamask-login-addon' ); ?></strong>
			<span data-metamask-balance="<?php echo esc_attr( $metamask_address ); ?>"><?php esc_html_e( 'Open MetaMask to view your balance.', 'metamask-login-addon' ); ?></span>
		</p>
	<?php endif; ?>
</div>
