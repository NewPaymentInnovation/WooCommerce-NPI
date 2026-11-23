( function () {
	var methodId = localizeCardVars.id;
	var tokenName = 'wc-' + methodId + '-payment-token';

	function updateCvvVisibility( selectedTokenId ) {
		var cvvInputs = document.querySelectorAll( 'input[id^="wc-' + methodId + '-payment-token-cvv-"]' );
		cvvInputs.forEach( function ( input ) {
			input.style.display = 'none';
		} );

		if ( ! selectedTokenId || selectedTokenId === 'new' ) {
			return;
		}

		var selectedCvvInput = document.getElementById( 'wc-' + methodId + '-payment-token-cvv-' + selectedTokenId );
		if ( selectedCvvInput ) {
			selectedCvvInput.style.display = 'inline-block';
		}
	}

	function syncCvvFromCheckedToken() {
		var selectedToken = document.querySelector( 'input[name="' + tokenName + '"]:checked' );
		updateCvvVisibility( selectedToken ? selectedToken.value : null );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target && event.target.name === tokenName ) {
			updateCvvVisibility( event.target.value || null );
		}
	} );

	// Sync after WooCommerce AJAX replaces the payment section.
	if ( typeof jQuery !== 'undefined' ) {
		jQuery( function () {
			syncCvvFromCheckedToken();
			jQuery( document.body ).on( 'updated_checkout', function () {
				setTimeout( syncCvvFromCheckedToken, 0 );
			} );
		} );
	} else {
		document.addEventListener( 'DOMContentLoaded', syncCvvFromCheckedToken );
	}
} )();

