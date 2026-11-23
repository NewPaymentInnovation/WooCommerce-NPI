jQuery( document ).ready( function ( $ ) {

	// Submit the form when the page loads
	$( '#hosted_form_request' ).submit();

	// Listen for messages from the iframe
	window.addEventListener( 'message', function ( event ) {
		// For security reasons, check the origin of the message
		if ( event.origin !== window.location.origin ) {
			return;
		}
		// Handle the message data as needed
		if ( event.data.data.redirect ) {
			window.location.replace( event.data.data.redirect );
		} else {
			// Redirect back to checkout as fallback
			window.location.replace( wc_checkout_params.checkout_url );
		}
	} );

} );
