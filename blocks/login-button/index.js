/**
 * "MetaMask Login" block editor script.
 *
 * Written without JSX so it runs without a build step. The block is rendered
 * on the server, so the editor preview matches the front end exactly.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'metamask-login/login-button', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Button', 'metamask-login-addon' ) },
						el( TextControl, {
							label: __( 'Button text', 'metamask-login-addon' ),
							help: __( 'Leave empty to use the text from Settings → MetaMask Login.', 'metamask-login-addon' ),
							value: attributes.buttonText,
							onChange: function ( value ) {
								setAttributes( { buttonText: value } );
							},
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} ),
						el( SelectControl, {
							label: __( 'Style', 'metamask-login-addon' ),
							value: attributes.buttonStyle,
							options: [
								{ label: __( 'Default (from settings)', 'metamask-login-addon' ), value: '' },
								{ label: __( 'Dark', 'metamask-login-addon' ), value: 'dark' },
								{ label: __( 'MetaMask orange', 'metamask-login-addon' ), value: 'orange' },
								{ label: __( 'Light', 'metamask-login-addon' ), value: 'light' },
							],
							onChange: function ( value ) {
								setAttributes( { buttonStyle: value } );
							},
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} ),
						el( TextControl, {
							label: __( 'Redirect after login', 'metamask-login-addon' ),
							help: __( 'Optional. Same-site URL, e.g. /my-account. Defaults to the current page.', 'metamask-login-addon' ),
							value: attributes.redirect,
							onChange: function ( value ) {
								setAttributes( { redirect: value } );
							},
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} )
					)
				),
				el(
					'div',
					{ style: { pointerEvents: 'none' } },
					el( ServerSideRender, {
						block: 'metamask-login/login-button',
						attributes: attributes,
						skipBlockSupportAttributes: true,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
