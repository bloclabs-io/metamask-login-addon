/**
 * MetaMask Login Add-On — settings screen helpers.
 *
 * Live button preview, copy-to-clipboard buttons and dependent fields.
 */
( function () {
	'use strict';

	const settings = window.MetaMaskLoginAdmin || {};
	const i18n = ( window.MetaMaskLogin && window.MetaMaskLogin.i18n ) || {};

	function updatePreview() {
		const preview = document.querySelector( '#metamask-preview .metamask-button' );
		if ( ! preview ) {
			return;
		}
		const text = document.getElementById( 'metamask-button-text' );
		const style = document.querySelector( 'input[name="metamask_login_options[button_style]"]:checked' );

		preview.querySelector( '.metamask-button__label' ).textContent =
			( text && text.value.trim() ) || settings.defaultButtonText || '';

		preview.classList.remove( 'metamask-button--dark', 'metamask-button--orange', 'metamask-button--light' );
		preview.classList.add( 'metamask-button--' + ( style ? style.value : 'dark' ) );
	}

	function updateDependencies() {
		document.querySelectorAll( '[data-depends-on]' ).forEach( ( field ) => {
			const toggle = document.getElementById( field.dataset.dependsOn );
			const enabled = ! toggle || toggle.checked;
			field.classList.toggle( 'is-disabled', ! enabled );
			field.querySelectorAll( 'input, select, textarea' ).forEach( ( input ) => {
				input.disabled = ! enabled;
			} );
		} );
	}

	async function copy( button ) {
		const text = button.dataset.copy;
		try {
			await navigator.clipboard.writeText( text );
		} catch ( e ) {
			const area = document.createElement( 'textarea' );
			area.value = text;
			document.body.appendChild( area );
			area.select();
			document.execCommand( 'copy' );
			area.remove();
		}
		const original = button.textContent;
		button.textContent = i18n.copied || 'Copied!';
		window.setTimeout( () => {
			button.textContent = original;
		}, 1500 );
	}

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest && event.target.closest( '[data-copy]' );
		if ( button ) {
			event.preventDefault();
			copy( button );
		}
	} );

	document.addEventListener( 'input', updatePreview );
	document.addEventListener( 'change', () => {
		updatePreview();
		updateDependencies();
	} );

	// Disabled inputs are not submitted; re-enable them so values are kept.
	document.addEventListener( 'submit', ( event ) => {
		event.target.querySelectorAll( '[data-depends-on] :disabled' ).forEach( ( input ) => {
			input.disabled = false;
		} );
	} );

	updatePreview();
	updateDependencies();
} )();
