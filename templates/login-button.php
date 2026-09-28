<?php
/**
 * Template: [metamask_login] shortcode and "MetaMask Login" block.
 *
 * Override by copying to `{your-theme}/metamask-login/login-button.php`.
 *
 * @package MetaMask_Login
 *
 * @var bool  $preview Rendering the block editor preview.
 * @var array $atts {
 *     @type string $redirect     Redirect URL after login.
 *     @type string $button_text  Button label.
 *     @type string $button_class Extra CSS classes.
 *     @type string $style        dark|orange|light.
 * }
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$metamask_redirect = '' !== $atts['redirect'] ? wp_validate_redirect( esc_url_raw( $atts['redirect'] ), '' ) : '';

if ( is_user_logged_in() && empty( $preview ) ) :
	$metamask_user = wp_get_current_user();
	?>
	<div class="metamask-login metamask-login--logged-in">
		<p>
			<?php
			printf(
				/* translators: %s: user display name */
				esc_html__( 'You are logged in as %s.', 'metamask-login-addon' ),
				'<strong>' . esc_html( $metamask_user->display_name ) . '</strong>'
			);
			?>
			<a href="<?php echo esc_url( wp_logout_url( $metamask_redirect ? $metamask_redirect : get_permalink() ) ); ?>"><?php esc_html_e( 'Log out', 'metamask-login-addon' ); ?></a>
		</p>
	</div>
	<?php
	return;
endif;
?>
<div class="metamask-login" data-metamask-container>
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button_html().
	echo MetaMask_Login_Addon::button_html(
		array(
			'action' => 'login',
			'text'   => '' !== trim( $atts['button_text'] ) ? $atts['button_text'] : MetaMask_Settings::button_text(),
			'style'  => '' !== $atts['style'] ? $atts['style'] : MetaMask_Settings::get( 'button_style' ),
			'class'  => $atts['button_class'],
			'data'   => $metamask_redirect ? array( 'redirect' => $metamask_redirect ) : array(),
		)
	);
	?>
	<div class="metamask-status" role="status" aria-live="polite"></div>
</div>
