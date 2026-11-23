jQuery( document ).ready( function ( $ ) {

	// Hosted Fields instance.
	let hostedFieldsInstance = false;
	let hostedFieldTargets = null;
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

		formData.set( 'payment_method', localizeCardVars.id );
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

	setupHostedFields();

	/**
	* Setup Hosted Fields.
	*/
	function setupHostedFields() {
		let retryTimer = null;
		let clickHandlerBound = false;

		function getHostedFieldTargets( formEle ) {
			return {
				number: formEle.querySelector( '#card-number-field' ),
				expiry: formEle.querySelector( '#card-expiry-field' ),
				cvv: formEle.querySelector( '#card-cvc-field' ),
			};
		}

		function hasAllHostedFieldTargets( targets ) {
			return !! ( targets && targets.number && targets.expiry && targets.cvv );
		}

		function isStaleHostedFieldTargets( previousTargets, currentTargets ) {
			if ( ! previousTargets ) {
				return false;
			}

			if ( ! previousTargets.number.isConnected || ! previousTargets.expiry.isConnected || ! previousTargets.cvv.isConnected ) {
				return true;
			}

			return previousTargets.number !== currentTargets.number
				|| previousTargets.expiry !== currentTargets.expiry
				|| previousTargets.cvv !== currentTargets.cvv;
		}

		function resetHostedFieldsInstance() {
			if ( hostedFieldsInstance ) {
				try {
					if ( typeof hostedFieldsInstance.destroy === 'function' ) {
						hostedFieldsInstance.destroy();
					} else if ( typeof hostedFieldsInstance.teardown === 'function' ) {
						hostedFieldsInstance.teardown();
					}
				} catch ( error ) {
					// Keep going; we'll still attempt to recreate below.
				}
			}

			hostedFieldsInstance = false;
			hostedFieldTargets = null;
		}

		function initFields() {
			clearTimeout( retryTimer );
			const formEle = window.document.forms.order_review || window.document.forms.checkout;

			// Check if form element exists.
			if ( ! formEle ) {
				return;
			}

			const currentTargets = getHostedFieldTargets( formEle );

			// If card fields are not present in the DOM yet, wait for the next checkout refresh.
			if ( ! hasAllHostedFieldTargets( currentTargets ) ) {
				return;
			}

			if ( hostedFieldsInstance && isStaleHostedFieldTargets( hostedFieldTargets, currentTargets ) ) {
				resetHostedFieldsInstance();
			}

			if ( hostedFieldsInstance === false ) {
				try {
					// Initialize Hosted Fields.
					hostedFieldsInstance = new window.hostedFields.classes.Form( formEle, {
						// Auto setup the payment fields.
						autoSetup: true,
						// Disable auto submit.
						autoSubmit: false,
						merchantID: localizeCardVars.merchantID,
						fields: {
							any: {
								style: 'font-family: Helvetica, Arial, sans-serif; font-size: 18px; line-height: 1.4;',
							},
						},
					} );
					hostedFieldTargets = currentTargets;
				} catch ( error ) {
					if ( error && typeof error.message === 'string' && error.message.includes( 'already been created for this element' ) ) {
						return;
					}
					throw error;
				}
			}

			$( document.body ).off( 'checkout_error.pmCardClassic' ).on( 'checkout_error.pmCardClassic', function () {
				toggleCheckoutOverlay( formEle, false );
			} );

			// Only bind the click handler once — use event delegation so it
			// survives DOM replacements by WooCommerce.
			if ( ! clickHandlerBound ) {
				clickHandlerBound = true;

				$( document.body ).on( 'click', '#place_order', async function ( event ) {

					if ( ! document.getElementById( `payment_method_${localizeCardVars.id}` ).checked ) {
						return;
					}

					const currentForm = window.document.forms.order_review || window.document.forms.checkout;

					try {
						if ( isProcessing ) {
							event.preventDefault();
							event.stopPropagation();
							return;
						}

						// Temporarily prevent the default event actions.
						event.preventDefault();
						event.stopPropagation();
						toggleCheckoutOverlay( currentForm, true );

						const deviceInformation = {
							deviceChannel: 'browser',
							deviceIdentity: navigator.userAgent ?? null,
							deviceTimeZone: new Date().getTimezoneOffset(),
							deviceScreenResolution: `${window.screen.width}x${window.screen.height}x${window.screen.colorDepth}`,
							deviceAcceptLanguage: navigator.languages ?? null,
						};

						const selectedToken = document.querySelector( `input[name="wc-${localizeCardVars.id}-payment-token"]:checked` );
						const isSavedCard = !! ( selectedToken && selectedToken.value && selectedToken.value !== 'new' );
						const saveCardCheckbox = document.getElementById( `wc-${localizeCardVars.id}-new-payment-method` )
							|| document.querySelector( `input[name="wc-${localizeCardVars.id}-new-payment-method"]` );
						const shouldSavePayment = !! ( saveCardCheckbox && saveCardCheckbox.checked );

						let resultData = null;

						if ( isSavedCard ) {
							const selectedTokenId = selectedToken.value;
							const selectedTokenCvvInput = document.getElementById( `wc-${localizeCardVars.id}-payment-token-cvv-${selectedTokenId}` );
							const cardCVV = selectedTokenCvvInput ? ( selectedTokenCvvInput.value || '' ).trim() : '';

							if ( ! cardCVV ) {
								if ( selectedTokenCvvInput ) {
									selectedTokenCvvInput.focus();
								}
								showCheckoutError( 'Please enter CVV for the selected saved card.' );
								toggleCheckoutOverlay( currentForm, false );
								return;
							}

							resultData = {
								paymentType: 'saved_card_order_payment',
								selectedTokenId,
								cardCVV,
								deviceInformation,
								shouldSavePayment: false,
							};
						} else {
							const result = await hostedFieldsInstance.getPaymentDetails( {}, true );
							resultData = {
								paymentType: 'card_order_payment',
								cardData: result,
								deviceInformation,
								shouldSavePayment,
							};
						}

						const checkoutResponse = await submitClassicCheckoutData( currentForm, resultData );

						if ( checkoutResponse && checkoutResponse.redirect ) {
							window.location.href = checkoutResponse.redirect;
							return checkoutResponse;
						}

						if ( checkoutResponse && checkoutResponse.threeDSData ) {
							const threeDSData = typeof checkoutResponse.threeDSData === 'string'
								? JSON.parse( checkoutResponse.threeDSData )
								: checkoutResponse.threeDSData;
							toggleCheckoutOverlay( currentForm, false );
							const threeDSOutcome = await handle3DS( threeDSData );

							if ( threeDSOutcome.redirect ) {
								window.location.href = threeDSOutcome.redirect;
							} else {
								// No redirect URL, likely an error.
								showCheckoutError( threeDSOutcome.message );
								toggleCheckoutOverlay( currentForm, false );
							}
						} else {
							if ( checkoutResponse && checkoutResponse.paymentResult !== 'captured' && checkoutResponse.paymentResult !== 'verified' ) {
								const errorHtml = checkoutResponse.messages || checkoutResponse.message;
								showCheckoutError( errorHtml );
								toggleCheckoutOverlay( currentForm, false );
								return checkoutResponse;
							}
						}

						if ( ! checkoutResponse || ! checkoutResponse.redirect ) {
							toggleCheckoutOverlay( currentForm, false );
						}

					} catch ( error ) {
						showCheckoutError( error && error.message ? error.message : null );
						toggleCheckoutOverlay( currentForm, false );
					}

				} );
			}
		}

		// On the order-pay page the form is already complete — initialize immediately.
		if ( window.document.forms.order_review ) {
			initFields();
		}

		// For the regular checkout page, WooCommerce replaces the payment section
		// via AJAX on load and whenever shipping/coupons change. Hosted fields must
		// be (re-)initialized after each replacement.
		$( document.body ).on( 'updated_checkout', function () {
			clearTimeout( retryTimer );
			retryTimer = setTimeout( initFields, 500 );
		} );

		// Re-check initialization when user switches payment method back to card.
		$( document.body ).on( 'change', 'input[name="payment_method"]', function () {
			if ( this.value !== localizeCardVars.id ) {
				return;
			}

			clearTimeout( retryTimer );
			retryTimer = setTimeout( initFields, 100 );
		} );
	}

	/**
	* Handle 3D Secure authentication.
	*
	* @param {object} threeDSData 3DS data from the checkout response.
	*/
	const handle3DS = ( threeDSData ) => {
		const acsOrigin = window.location.origin;

		return new Promise( function ( resolve ) {
			let threeDSDialog = null;
			let messageHandler = null;

			if ( ! document.getElementById( 'three-ds-spinner-style' ) ) {
				const spinnerStyle = document.createElement( 'style' );
				spinnerStyle.id = 'three-ds-spinner-style';
				spinnerStyle.textContent = '@keyframes three-ds-spin { to { transform: rotate(360deg); } }';
				document.head.appendChild( spinnerStyle );
			}

			// Create 3DS HTML elements.
			const threeDSContainer = document.createElement( 'div' );
			const threeDSiFrame = document.createElement( 'iframe' );
			const threeDSForm = document.createElement( 'form' );
			const threeDSLoading = document.createElement( 'div' );
			const threeDSLoadingSpinner = document.createElement( 'div' );
			const threeDSLoadingText = document.createElement( 'div' );
			let loadTimeoutId = null;

			threeDSContainer.setAttribute( 'id', 'three-ds-container' );
			threeDSContainer.setAttribute( 'style', 'height:100%; width:100%; border:none; position:relative;' );

			threeDSLoading.setAttribute( 'style', 'position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:#fff; z-index:1; flex-direction:column; gap:10px;' );
			threeDSLoadingSpinner.setAttribute( 'style', 'width:32px;height:32px;border:3px solid #cfcfcf;border-top-color:#333;border-radius:50%;animation:three-ds-spin 0.8s linear infinite' );
			threeDSLoadingText.setAttribute( 'style', 'font-size:13px; color:#555;' );
			threeDSLoadingText.textContent = 'Loading...';
			threeDSLoading.appendChild( threeDSLoadingSpinner );
			threeDSLoading.appendChild( threeDSLoadingText );

			// Set 3DS iFrame attributes.
			threeDSiFrame.setAttribute( 'name', 'threedsiframe' );
			threeDSiFrame.setAttribute( 'id', 'threedsiframe' );
			threeDSiFrame.setAttribute( 'style', 'height:100%; width:100%;border:none;' );

			// Set 3DS form attributes.
			threeDSForm.setAttribute( 'method', 'POST' );
			threeDSForm.setAttribute( 'action', threeDSData.acsURL );
			threeDSForm.setAttribute( 'name', 'three-ds-form' );
			threeDSForm.setAttribute( 'id', 'three-ds-form' );
			threeDSForm.setAttribute( 'target', 'threedsiframe' );

			for ( const [ key, value ] of Object.entries( threeDSData.threeDSRequest ) ) {
				const hiddenInput = document.createElement( 'input' );
				hiddenInput.setAttribute( 'type', 'hidden' );
				hiddenInput.setAttribute( 'name', key );
				hiddenInput.setAttribute( 'value', value );
				threeDSForm.appendChild( hiddenInput );
			}

			threeDSContainer.appendChild( threeDSLoading );
			threeDSContainer.appendChild( threeDSiFrame );
			threeDSContainer.appendChild( threeDSForm );

			function setThreeDsLoading( isLoading, message ) {
				if ( typeof message === 'string' ) {
					threeDSLoadingText.textContent = message;
				} else if ( isLoading ) {
					threeDSLoadingText.textContent = 'Loading...';
				}
				threeDSLoading.style.display = isLoading ? 'flex' : 'none';
			}

			threeDSiFrame.addEventListener( 'load', function () {
				clearTimeout( loadTimeoutId );
				setThreeDsLoading( false );
			} );

			threeDSDialog = openDialog( threeDSContainer, {
				title: '3D Secure',
				width: 406,
				height: 420,
				onClose: function () {
					// Remove post message event listener.
					if ( messageHandler ) {
						window.removeEventListener( 'message', messageHandler, false );
					}
				},
				onOpen: function () {
					// Submit 3DS form.
					setThreeDsLoading( true );
					$( '#three-ds-form' ).submit();

					// Add post message event listener.
					messageHandler = function receiveMessage( messageEvent ) {
						// Validate origin to prevent cross-site message spoofing.
						if ( messageEvent.origin !== acsOrigin ) {
							return;
						}
						if ( messageEvent.source?.name === 'threedsiframe' ) {
							resolve( messageEvent.data.data );
							window.removeEventListener( 'message', messageHandler, false );
							// Close the dialog.
							if ( threeDSDialog && typeof threeDSDialog.close === 'function' ) {
								threeDSDialog.close();
							}
						}
					};

					window.addEventListener( 'message', messageHandler, false );
				},
			} );
		} );
	};

	const openDialog = ( contentElement, options ) => {
		const settings = Object.assign( {
			title: '',
			width: 406,
			height: 520,
			onOpen: null,
			onClose: null,
		}, options || {} );

		const overlay = document.createElement( 'div' );
		overlay.setAttribute( 'role', 'presentation' );
		overlay.style.position = 'fixed';
		overlay.style.inset = '0';
		overlay.style.background = 'rgba(0, 0, 0, 0.5)';
		overlay.style.zIndex = '1000000';
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
		dialog.style.zIndex = '1000001';
		dialog.style.opacity = '0';
		dialog.style.transition = 'opacity 180ms ease';
		dialog.style.borderRadius = '8px';

		// Body.
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

} );
