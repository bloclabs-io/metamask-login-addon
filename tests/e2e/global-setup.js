const { execSync } = require( 'child_process' );

/**
 * Optional: when WP_CLI is set (e.g. "wp --path=/var/www/html"), reset the
 * plugin state so the suite can be re-run against the same site.
 */
module.exports = async () => {
	const wp = process.env.WP_CLI;
	if ( ! wp ) {
		return;
	}
	const run = ( args ) => execSync( `${ wp } ${ args }`, { stdio: 'pipe' } ).toString();
	run( 'transient delete --all' );
	run( 'option delete metamask_login_options' );
	run( 'plugin deactivate metamask-login-addon' );
	run( 'plugin activate metamask-login-addon' );
};
