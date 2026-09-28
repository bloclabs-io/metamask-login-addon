<?php
/**
 * Uninstall MetaMask Login Add-On.
 *
 * Removes settings and temporary data. Wallet links stored in user meta are
 * only deleted when "Delete wallet links when the plugin is deleted" is on.
 *
 * @package MetaMask_Login
 * @since   2.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove this site's plugin options and transients.
 *
 * @return bool Whether this site asked for wallet links to be deleted.
 */
function metamask_login_uninstall_site() {
	global $wpdb;

	$options     = get_option( 'metamask_login_options', array() );
	$delete_data = is_array( $options ) && ! empty( $options['delete_data_on_uninstall'] );

	delete_option( 'metamask_login_options' );
	delete_option( 'metamask_login_version' );
	delete_option( 'metamask_login_show_setup_notice' );

	foreach ( array( '_transient_metamask_rate_limit_', '_transient_timeout_metamask_rate_limit_', '_transient_metamask_challenge_', '_transient_timeout_metamask_challenge_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}

	return $delete_data;
}

$metamask_delete_links = false;

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $metamask_site_id ) {
		switch_to_blog( $metamask_site_id );
		$metamask_delete_links = metamask_login_uninstall_site() || $metamask_delete_links;
		restore_current_blog();
	}
} else {
	$metamask_delete_links = metamask_login_uninstall_site();
}

// Wallet links are stored per user (network-wide), so they are only removed
// when a site opted in.
if ( $metamask_delete_links ) {
	delete_metadata( 'user', 0, 'metamask_wallet_address', '', true );
	delete_metadata( 'user', 0, 'metamask_wallet_address_unverified', '', true );
}
