jQuery( document ).ready( function ( $ ) {

	if ( localizeHostedClassicVars.type !== 'modal' ) {
		return;
	}

	let isProcessing = false;

	const toggleCheckoutOverlay = ( formElement, show ) => {
		if ( ! formElement ) {
			return;
		}

		if ( ! document.getElementById( 'checkout-overlay-style' ) ) {
			const style = document.createElement( 'style' );
			style.id = 'checkout-overlay-style';
			style.textContent = '@keyframes checkout-overlay-spin { to { transform: rotate(360deg); } }';
			document.head.appendChild( style );
		}

		let overlay = document.getElementById( 'checkout-processing-overlay' );

		if ( show ) {
			if ( ! overlay ) {
				overlay = document.createElement( 'div' );
				overlay.id = 'checkout-processing-overlay';
				overlay.setAttribute( 'style', 'position:fixed;inset:0;background:rgba(255,255,255,0.75);z-index:999999;display:flex;align-items:center;justify-content:center;' );

				const spinner = document.createElement( 'div' );
				spinner.setAttribute( 'style', 'width:36px;height:36px;border:3px solid #d0d0d0;border-top-color:#2271b1;border-radius:50%;animation:checkout-overlay-spin .8s linear infinite;' );
				overlay.appendChild( spinner );
				document.body.appendChild( overlay );
			}

			isProcessing = true;
			formElement.setAttribute( 'aria-busy', 'true' );
			const placeOrderButton = document.getElementById( 'place_order' );
			if ( placeOrderButton ) {
				placeOrderButton.disabled = true;
			}
			return;
		}

		if ( overlay && overlay.parentNode ) {
			overlay.parentNode.removeChild( overlay );
		}

		isProcessing = false;
		formElement.setAttribute( 'aria-busy', 'false' );
		const placeOrderButton = document.getElementById( 'place_order' );
		if ( placeOrderButton ) {
			placeOrderButton.disabled = false;
		}
	};

	const showCheckoutError = ( messageOrHtml ) => {
		const checkoutForm = $( 'form.checkout' );

		const normalizeErrorMessage = ( rawMessage ) => {
			if ( typeof rawMessage !== 'string' ) {
				return '';
			}

			const withoutTags = rawMessage.replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
			if ( ! withoutTags ) {
				return '';
			}

			try {
				const textarea = document.createElement( 'textarea' );
				textarea.innerHTML = withoutTags;
				return ( textarea.value || '' ).trim();
			} catch ( error ) {
				return withoutTags;
			}
		};

		const escapeHtml = ( value ) => String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /\"/g, '&quot;' )
			.replace( /'/g, '&#039;' );

		const normalizedMessage = normalizeErrorMessage( messageOrHtml ) || 'Payment failed. Please try again.';
		const messageHtml = `\n<div class="wc-block-components-notice-banner is-error" role="alert">\n\t<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">\n\t\t<path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path>\n\t</svg>\n\t<div class="wc-block-components-notice-banner__content">\n\t\t${escapeHtml( normalizedMessage )}\t</div>\n</div>\n`;

		$( '.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message' ).remove();

		const noticeGroup = $( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"></div>' );
		noticeGroup.html( messageHtml );

		if ( checkoutForm.length ) {
			checkoutForm.prepend( noticeGroup );
		} else {
			$( '.woocommerce-notices-wrapper' ).first().html( noticeGroup );
		}

		$( 'html, body' ).animate( {
			scrollTop: ( noticeGroup.offset() ? noticeGroup.offset().top : 0 ) - 120,
		}, 250 );

		$( document.body ).trigger( 'checkout_error', [ messageHtml ] );
	};

	const submitClassicCheckoutData = async ( formElement, paymentData ) => {
		const formData = new FormData( formElement );
		const urlEncodedBody = new URLSearchParams();
		const isOrderPayPage = document.body.classList.contains( 'woocommerce-order-pay' ) || !! window.document.forms.order_review;
		const shouldSavePayment = !! ( paymentData && ( paymentData.shouldSavePayment === true || paymentData.shouldSavePayment === 'true' ) );

		formData.set( 'payment_method', localizeHostedClassicVars.id );
		formData.set( 'paymentdata', JSON.stringify( paymentData ) );
		formData.set( 'shouldsavepayment', shouldSavePayment ? 'true' : 'false' );

		for ( const [ key, value ] of formData.entries() ) {
			urlEncodedBody.append( key, value );
		}

		const checkoutUrl = isOrderPayPage
			? ( () => {
				const actionUrl = formElement.getAttribute( 'action' ) || window.location.href;
				const url = new URL( actionUrl, window.location.origin );
				url.searchParams.set( 'ajax', '1' );
				return url.toString();
			} )()
			: `${window.location.origin}/?wc-ajax=checkout`;

		const response = await fetch( checkoutUrl, {
			method: 'POST',
			body: urlEncodedBody.toString(),
			headers: {
				Accept: 'application/json, text/javascript, */*; q=0.01',
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'Cache-Control': 'no-cache',
				'X-Requested-With': 'XMLHttpRequest',
			},
			credentials: 'same-origin',
		} );

		const contentType = ( response.headers.get( 'content-type' ) || '' ).toLowerCase();
		if ( contentType.includes( 'application/json' ) || contentType.includes( 'text/javascript' ) ) {
			const jsonResponse = await response.json();
			return jsonResponse;
		}

		const responseText = await response.text();

		return {
			result: response.ok ? 'success' : 'failure',
			responseText,
		};
	};

	const openDialog = ( contentElement, options ) => {
		const settings = Object.assign( {
			title: '',
			width: 406,
			height: 700,
			onOpen: null,
			onClose: null,
		}, options || {} );

		const overlay = document.createElement( 'div' );
		overlay.setAttribute( 'role', 'presentation' );
		overlay.style.position = 'fixed';
		overlay.style.inset = '0';
		overlay.style.background = 'rgba(0, 0, 0, 0.5)';
		overlay.style.zIndex = '0';
		overlay.style.opacity = '0';
		overlay.style.transition = 'opacity 180ms ease';

		const dialog = document.createElement( 'div' );
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.style.position = 'fixed';
		dialog.style.left = '50%';
		dialog.style.top = '50%';
		dialog.style.transform = 'translate(-50%, -50%)';
		dialog.style.background = '#fff';
		dialog.style.border = '1px solid #888';
		dialog.style.boxShadow = '0 8px 24px rgba(0,0,0,0.2)';
		dialog.style.width = settings.width + 'px';
		dialog.style.height = settings.height + 'px';
		dialog.style.display = 'flex';
		dialog.style.flexDirection = 'column';
		dialog.style.opacity = '0';
		dialog.style.transition = 'opacity 180ms ease';

		const body = document.createElement( 'div' );
		body.style.flex = '1';
		body.style.padding = '3px';
		body.appendChild( contentElement );

		dialog.appendChild( body );

		document.body.appendChild( overlay );
		document.body.appendChild( dialog );

		const closeDialog = () => {
			overlay.style.opacity = '0';
			dialog.style.opacity = '0';

			const finalizeClose = () => {
				if ( dialog.parentNode ) {
					dialog.parentNode.removeChild( dialog );
				}
				if ( overlay.parentNode ) {
					overlay.parentNode.removeChild( overlay );
				}
				if ( typeof settings.onClose === 'function' ) {
					settings.onClose();
				}
			};

			dialog.addEventListener( 'transitionend', finalizeClose, { once: true } );
		};

		requestAnimationFrame( function () {
			overlay.style.opacity = '1';
			dialog.style.opacity = '1';
		} );

		if ( typeof settings.onOpen === 'function' ) {
			settings.onOpen();
		}

		return {
			close: closeDialog,
		};
	};

	const isSameOriginUrl = ( url ) => {
		try {
			const parsed = new URL( url, window.location.origin );
			return parsed.origin === window.location.origin;
		} catch ( e ) {
			return false;
		}
	};

	$( document.body ).on( 'click', '#place_order', async function ( event ) {

		const paymentRadio = document.getElementById( `payment_method_${localizeHostedClassicVars.id}` );
		if ( ! paymentRadio || ! paymentRadio.checked ) {
			return;
		}

		const formEle = $( this ).closest( 'form' )[ 0 ];

		try {
			if ( isProcessing ) {
				event.preventDefault();
				event.stopPropagation();
				return;
			}

			event.preventDefault();
			event.stopPropagation();
			toggleCheckoutOverlay( formEle, true );

			const resultData = {
				paymentType: 'hosted_modal_order_payment',
			};

			const checkoutResponse = await submitClassicCheckoutData( formEle, resultData );

			toggleCheckoutOverlay( formEle, false );

			if ( checkoutResponse.result === 'failure' ) {
				const errorHtml = checkoutResponse.messages || checkoutResponse.message || null;
				showCheckoutError( errorHtml );
				return;
			}

			if ( checkoutResponse.paymentResult !== 'requires_action' ) {
				showCheckoutError( 'Unexpected payment response. Please try again.' );
				return;
			}

			const hostedFormData = JSON.parse( checkoutResponse.hostedFormData || '{}' );

			// Create hosted form modal elements.
			const hostedModalContainer = document.createElement( 'div' );
			const hostedModalIframe = document.createElement( 'iframe' );
			const hostedModalForm = document.createElement( 'form' );
			const threeDSLoading = document.createElement( 'div' );
			const threeDSLoadingSpinner = document.createElement( 'div' );
			const threeDSLoadingText = document.createElement( 'div' );
			let loadTimeoutId = null;

			hostedModalContainer.setAttribute( 'id', 'three-ds-container' );
			hostedModalContainer.setAttribute( 'style', 'height:100%; width:100%; border:none; position:relative;' );

			threeDSLoading.setAttribute( 'style', 'position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:#fff; z-index:1; flex-direction:column; gap:10px;' );
			threeDSLoadingSpinner.setAttribute( 'style', 'width:32px;height:32px;border:3px solid #cfcfcf;border-top-color:#333;border-radius:50%;animation:three-ds-spin 0.8s linear infinite' );
			threeDSLoadingText.setAttribute( 'style', 'font-size:13px; color:#555;' );
			threeDSLoadingText.textContent = 'Loading...';
			threeDSLoading.appendChild( threeDSLoadingSpinner );
			threeDSLoading.appendChild( threeDSLoadingText );

			hostedModalIframe.setAttribute( 'name', 'hosted-modal-hosted-iframe' );
			hostedModalIframe.setAttribute( 'id', 'hosted-modal-iframe' );
			hostedModalIframe.setAttribute( 'allow', 'payment *' );
			hostedModalIframe.setAttribute( 'style', 'height:100%; width:100%;border:none;' );

			hostedModalForm.setAttribute( 'method', 'POST' );
			hostedModalForm.setAttribute( 'action', hostedFormData.paymentFormURL );
			hostedModalForm.setAttribute( 'name', 'hosted-modal' );
			hostedModalForm.setAttribute( 'id', 'hosted-modal-form' );
			hostedModalForm.setAttribute( 'target', 'hosted-modal-hosted-iframe' );

			for ( const [ key, value ] of Object.entries( hostedFormData.hostedRequestFields ) ) {
				const hiddenInput = document.createElement( 'input' );
				hiddenInput.setAttribute( 'type', 'hidden' );
				hiddenInput.setAttribute( 'name', key );
				hiddenInput.setAttribute( 'value', value );
				hostedModalForm.appendChild( hiddenInput );
			}

			hostedModalContainer.appendChild( threeDSLoading );
			hostedModalContainer.appendChild( hostedModalIframe );
			hostedModalContainer.appendChild( hostedModalForm );

			function setHostedFormLoading( isLoading, message ) {
				if ( typeof message === 'string' ) {
					threeDSLoadingText.textContent = message;
				} else if ( isLoading ) {
					threeDSLoadingText.textContent = 'Loading...';
				}
				threeDSLoading.style.display = isLoading ? 'flex' : 'none';
			}

			hostedModalIframe.addEventListener( 'load', function () {
				clearTimeout( loadTimeoutId );
				setHostedFormLoading( false );
			} );

			let messageHandler = null;

			const hostedModalDialog = openDialog( hostedModalContainer, {
				title: 'Hosted Payment Form',
				width: 450,
				height: 825,
				onClose: function () {
					if ( messageHandler ) {
						window.removeEventListener( 'message', messageHandler, false );
					}
				},
				onOpen: function () {
					setHostedFormLoading( true );
					$( '#hosted-modal-form' ).submit();

					messageHandler = function receiveMessage( messageEvent ) {
						if ( messageEvent.origin !== window.location.origin ) {
							return;
						}

						let sourceName;
						try {
							sourceName = messageEvent.source?.name;
						} catch ( e ) {
							return;
						}

						if ( sourceName !== 'hosted-modal-hosted-iframe' ) {
							return;
						}

						const data = messageEvent.data && messageEvent.data.data;
						if ( ! data ) {
							return;
						}

						if ( (data.paymentResult === 'captured' || data.paymentResult === 'verified') && data.redirect && isSameOriginUrl( data.redirect ) ) {
							hostedModalDialog.close();
							window.location.href = data.redirect;
						} else {
							const errorHtml = data.messages || data.message;
							showCheckoutError( errorHtml );
							toggleCheckoutOverlay( formEle, false );
							hostedModalDialog.close();
						}
					};

					window.addEventListener( 'message', messageHandler, false );
				},
			} );

		} catch ( error ) {
			showCheckoutError( error && error.message ? error.message : null );
			toggleCheckoutOverlay( formEle, false );
		}

	} );

} );
