<?php
/**
 * Plugin settings storage, defaults and sanitization.
 *
 * @package MetaMask_Login
 * @since   3.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings helper.
 *
 * @since 3.0.0
 */
class MetaMask_Settings {

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enable_login_page'        => true,
			'button_text'              => '',
			'button_style'             => 'dark',
			'login_redirect'           => '',
			'enable_registration'      => false,
			'default_role'             => 'subscriber',
			'sign_statement'           => '',
			'rate_limit_enabled'       => true,
			'rate_limit_attempts'      => 5,
			'rate_limit_window'        => 300,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All options merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( MetaMask_Login_Addon::OPTIONS_KEY, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Get one option.
	 *
	 * @param string $key Key.
	 * @return mixed|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Available button styles.
	 *
	 * @return string[]
	 */
	public static function button_styles() {
		return array( 'dark', 'orange', 'light' );
	}

	/**
	 * Login button label, falling back to the translated default.
	 *
	 * @return string
	 */
	public static function button_text() {
		$text = trim( (string) self::get( 'button_text' ) );
		return '' !== $text ? $text : __( 'Log in with MetaMask', 'metamask-login-addon' );
	}

	/**
	 * Capabilities that make a role unsuitable for automatic sign-ups.
	 *
	 * @return string[]
	 */
	public static function privileged_caps() {
		$caps = array(
			'manage_options', 'unfiltered_html', 'unfiltered_upload',
			'create_users', 'edit_users', 'delete_users', 'promote_users', 'list_users', 'remove_users',
			'install_plugins', 'activate_plugins', 'edit_plugins', 'update_plugins', 'delete_plugins',
			'install_themes', 'switch_themes', 'edit_themes', 'edit_theme_options', 'update_themes', 'delete_themes',
			'update_core', 'import', 'export', 'edit_files', 'edit_dashboard', 'customize',
			'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_options', 'manage_network_plugins', 'manage_network_themes',
			'edit_others_posts', 'delete_others_posts', 'publish_pages', 'edit_others_pages', 'moderate_comments', 'manage_categories',
			'manage_woocommerce', 'view_woocommerce_reports',
		);

		/**
		 * Filter the capabilities that exclude a role from automatic sign-ups.
		 *
		 * @since 3.0.0
		 * @param string[] $caps Capabilities.
		 */
		return (array) apply_filters( 'metamask_login_privileged_caps', $caps );
	}

	/**
	 * Roles that wallet sign-ups may receive. Roles with any privileged
	 * capability (site management, users, plugins, others' content…) are
	 * never offered.
	 *
	 * @return array Role slug => name.
	 */
	public static function allowed_roles() {
		$roles      = array();
		$privileged = self::privileged_caps();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = isset( $role['capabilities'] ) ? array_keys( array_filter( (array) $role['capabilities'] ) ) : array();
			if ( array_intersect( $caps, $privileged ) ) {
				continue;
			}
			$roles[ $slug ] = translate_user_role( $role['name'] );
		}
		return $roles;
	}

	/**
	 * Sanitize the options array submitted from the settings screen.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array();

		foreach ( array( 'enable_login_page', 'enable_registration', 'rate_limit_enabled', 'delete_data_on_uninstall' ) as $key ) {
			$clean[ $key ] = ! empty( $input[ $key ] );
		}

		$clean['button_text'] = isset( $input['button_text'] ) ? sanitize_text_field( $input['button_text'] ) : '';

		$clean['button_style'] = isset( $input['button_style'] ) && in_array( $input['button_style'], self::button_styles(), true )
			? $input['button_style']
			: $defaults['button_style'];

		$redirect = isset( $input['login_redirect'] ) ? trim( (string) $input['login_redirect'] ) : '';
		if ( '' !== $redirect ) {
			$redirect = esc_url_raw( $redirect );
			// Only allow same-site destinations.
			$redirect = wp_validate_redirect( $redirect, '' );
		}
		$clean['login_redirect'] = $redirect;

		$role                  = isset( $input['default_role'] ) ? sanitize_key( $input['default_role'] ) : '';
		$clean['default_role'] = array_key_exists( $role, self::allowed_roles() ) ? $role : $defaults['default_role'];

		// SIWE statements must be a single line.
		$statement               = isset( $input['sign_statement'] ) ? sanitize_text_field( $input['sign_statement'] ) : '';
		$clean['sign_statement'] = substr( $statement, 0, 300 );

		$attempts                     = isset( $input['rate_limit_attempts'] ) ? absint( $input['rate_limit_attempts'] ) : $defaults['rate_limit_attempts'];
		$clean['rate_limit_attempts'] = max( 1, min( 50, $attempts ) );

		$window                     = isset( $input['rate_limit_window'] ) ? absint( $input['rate_limit_window'] ) : $defaults['rate_limit_window'];
		$clean['rate_limit_window'] = max( 60, min( DAY_IN_SECONDS, $window ) );

		return $clean;
	}

	/**
	 * Migrate options saved by older versions.
	 */
	public static function migrate() {
		$stored = get_option( MetaMask_Login_Addon::OPTIONS_KEY, null );
		if ( ! is_array( $stored ) ) {
			return;
		}

		if ( ! empty( $stored['custom_sign_message'] ) && empty( $stored['sign_statement'] ) ) {
			$stored['sign_statement'] = sanitize_text_field( $stored['custom_sign_message'] );
		}
		unset( $stored['custom_sign_message'], $stored['require_verification'], $stored['dev_mode'] );

		update_option( MetaMask_Login_Addon::OPTIONS_KEY, wp_parse_args( $stored, self::defaults() ) );
	}
}
