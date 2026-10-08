/**
 * Settings → Postilio: sends the test email and shows Postilio's answer.
 *
 * @package PostilioWp
 */
( function () {
	'use strict';

	const form = document.getElementById( 'postilio-test-form' );
	const result = document.getElementById( 'postilio-test-result' );
	const config = window.postilioAdmin;
	if ( ! form || ! result || ! config ) {
		return;
	}
	const button = document.getElementById( 'postilio-test-send' );

	const element = ( tag, text, className ) => {
		const node = document.createElement( tag );
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}
		if ( className ) {
			node.className = className;
		}
		return node;
	};

	const notice = ( kind, text ) => {
		const box = element( 'div', null, 'notice inline notice-' + kind );
		box.append( element( 'p', text ) );
		return box;
	};

	const show = ( answer ) => {
		const data = answer && answer.data ? answer.data : {};
		if ( ! answer || ! answer.success ) {
			const box = notice( 'error', data.message || config.i18n.failed );
			if ( data.code ) {
				box.append( element( 'p', config.i18n.code + ': ' + data.code ) );
			}
			result.replaceChildren( box );
			return;
		}
		const box = notice( 'success', data.message );
		const list = element( 'dl' );
		const row = ( term, value ) => {
			list.append( element( 'dt', term ), element( 'dd', value ) );
		};
		( data.ids || [] ).forEach( ( id ) => row( config.i18n.messageId, id ) );
		if ( data.suppressed && data.suppressed.length ) {
			row( config.i18n.suppressed, data.suppressed.join( ', ' ) );
		}
		if ( data.status ) {
			row( config.i18n.status, data.status );
		}
		if ( data.events && data.events.length ) {
			const events = element( 'ul' );
			data.events.forEach( ( event ) => {
				const parts = [ event.occurredAt, event.type, event.smtpCode, event.reason, event.response ].filter( ( part ) => part !== null && part !== undefined && part !== '' );
				events.append( element( 'li', parts.join( ' · ' ) ) );
			} );
			const term = element( 'dt', config.i18n.events );
			const value = element( 'dd' );
			value.append( events );
			list.append( term, value );
		}
		box.append( list );
		if ( data.note ) {
			box.append( element( 'p', data.note ) );
		}
		result.replaceChildren( box );
	};

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		button.disabled = true;
		form.setAttribute( 'aria-busy', 'true' );
		result.replaceChildren( element( 'p', config.i18n.sending ) );
		try {
			const body = new FormData();
			body.append( 'action', 'postilio_send_test' );
			body.append( 'nonce', config.nonce );
			body.append( 'to', form.elements.to.value );
			const response = await fetch( config.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } );
			show( await response.json() );
		} catch ( error ) {
			show( null );
		} finally {
			button.disabled = false;
			form.removeAttribute( 'aria-busy' );
		}
	} );
}() );
