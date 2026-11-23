const googlePaySettings = window.wc.wcSettings.getSetting( localizeGoogleBlockVars.pluginID + '_data', {} );

const GooglePayBlockContent = ( props ) => {
	const { useEffect, useRef } = wp.element;
	const googlePayButtonContainer = useRef();
	const isPaymentActive = useRef( false );
	const latestCheckoutResponse = useRef( null );

	const notifyPaymentMethodsUpdated = () => {
		if ( window.wp && window.wp.hooks && 'function' === typeof window.wp.hooks.doAction ) {
			window.wp.hooks.doAction( 'experimental__woocommerce_blocks-payment-methods-updated' );
		}
	};

	const clearStaleActiveGooglePayMethod = () => {
		const select = window.wp && window.wp.data && 'function' === typeof window.wp.data.select
			? window.wp.data.select( 'wc/store/payment' )
			: null;
		const dispatch = window.wp && window.wp.data && 'function' === typeof window.wp.data.dispatch
			? window.wp.data.dispatch( 'wc/store/payment' )
			: null;

		if ( ! select || ! dispatch || 'function' !== typeof select.getActivePaymentMethod ) {
			return;
		}

		const activeMethod = select.getActivePaymentMethod();

		if ( activeMethod !== googlePaySettings.gatewayId ) {
			return;
		}

		if ( 'function' === typeof dispatch.setActivePaymentMethod ) {
			dispatch.setActivePaymentMethod( '' );
		} else if ( 'function' === typeof dispatch.__internalSetActivePaymentMethod ) {
			dispatch.__internalSetActivePaymentMethod( '' );
		}

		notifyPaymentMethodsUpdated();
	};

	const closeExpressFlow = () => {
		isPaymentActive.current = false;
		props.onClose();
		clearStaleActiveGooglePayMethod();
	};

	const normalizeErrorMessage = ( rawMessage ) => {
		if ( 'string' !== typeof rawMessage ) {
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

	const showCheckoutError = ( messageOrHtml ) => {
		const escapeHtml = ( value ) => String( value )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );

		const normalizedMessage = normalizeErrorMessage( messageOrHtml ) || 'Payment failed. Please try again.';
		const messageHtml = '\n<div class="wc-block-components-notice-banner is-error" role="alert">\n\t<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">\n\t\t<path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path>\n\t</svg>\n\t<div class="wc-block-components-notice-banner__content">\n\t\t' + escapeHtml( normalizedMessage ) + '\t</div>\n</div>\n';

		if ( 'function' === typeof window.jQuery ) {
			const $ = window.jQuery;

			$( '.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message' ).remove();

			const noticeGroup = $( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"></div>' );
			noticeGroup.html( messageHtml );

			const noticeTarget = $( '.wc-block-components-notices, .woocommerce-notices-wrapper, form.checkout' ).first();

			if ( noticeTarget.length ) {
				noticeTarget.prepend( noticeGroup );
			}

			$( 'html, body' ).animate(
				{
					scrollTop: ( noticeGroup.offset() ? noticeGroup.offset().top : 0 ) - 120,
				},
				250
			);
		}
	};

	const keyValueArrayToObject = ( items ) => {
		return ( Array.isArray( items ) ? items : [] ).reduce( ( obj, item ) => {
			if ( ! item || 'string' !== typeof item.key ) {
				return obj;
			}

			let value = item.value;

			if ( 'string' === typeof value ) {
				const trimmed = value.trim();

				if (
					( trimmed.startsWith( '{' ) && trimmed.endsWith( '}' ) ) ||
					( trimmed.startsWith( '[' ) && trimmed.endsWith( ']' ) )
				) {
					try {
						value = JSON.parse( trimmed );
					} catch ( error ) {
						value = item.value;
					}
				}
			}

			obj[ item.key ] = value;
			return obj;
		}, {} );
	};

	const mapGooglePayAddressToWooAddress = ( googleAddress, email ) => {
		const address = googleAddress || {};
		const fullName = String( address.name || address.recipientName || '' ).trim();
		const nameParts = fullName ? fullName.split( /\s+/ ) : [];
		const firstName = nameParts.length ? nameParts.shift() : '';
		const lastName = nameParts.length ? nameParts.join( ' ' ) : '';
		const lines = Array.isArray( address.addressLines ) ? address.addressLines : [];
		const address1 = address.address1 || lines[ 0 ] || '';
		const address2 = [ address.address2, address.address3, lines[ 1 ], lines[ 2 ] ]
			.filter( ( line ) => 'string' === typeof line && '' !== line.trim() )
			.join( ' ' );

		return {
			first_name: firstName,
			last_name: lastName,
			company: '',
			address_1: address1,
			address_2: address2,
			city: address.locality || '',
			state: address.administrativeArea || '',
			postcode: address.postalCode || '',
			country: address.countryCode || '',
			email,
			phone: address.phoneNumber || address.phone || '',
		};
	};

	const submitStoreApiCheckout = async ( paymentPayload ) => {
		const googlePayData = ( paymentPayload && paymentPayload.googlePayData ) || {};
		const billingSource = googlePayData.paymentMethodData &&
			googlePayData.paymentMethodData.info &&
			googlePayData.paymentMethodData.info.billingAddress
			? googlePayData.paymentMethodData.info.billingAddress
			: null;
		const shippingSource = googlePayData.shippingAddress || paymentPayload.shippingAddress || null;
		const email = googlePayData.email || '';
		const billingAddress = mapGooglePayAddressToWooAddress( billingSource || shippingSource, email );
		const body = {
			payment_method: googlePaySettings.gatewayId,
			billing_email: billingAddress.email || undefined,
			billing_address: billingAddress,
			payment_data: [
				{
					key: 'paymentType',
					value: 'google_pay_order_payment',
				},
				{
					key: 'paymentdata',
					value: JSON.stringify( paymentPayload ),
				},
			],
		};

		if ( shippingSource ) {
			body.shipping_address = mapGooglePayAddressToWooAddress( shippingSource, email );
		}

		return storeApiFetch( '/checkout', {
			method: 'POST',
			body: JSON.stringify( body ),
		} );
	};

	const process3DS = ( threeDSData ) => {
		if ( 'string' === typeof threeDSData ) {
			threeDSData = JSON.parse( threeDSData );
		}

		return new Promise( function ( resolve, reject ) {
			if ( ! threeDSData || ! threeDSData.acsURL ) {
				reject( { message: '3DS data is invalid.' } );
				return;
			}

			const allowedOrigins = [ window.location.origin ];

			try {
				const acsOrigin = new URL( threeDSData.acsURL, window.location.origin ).origin;

				if ( ! allowedOrigins.includes( acsOrigin ) ) {
					allowedOrigins.push( acsOrigin );
				}
			} catch ( error ) {
				reject( { message: '3DS ACS URL is invalid.' } );
				return;
			}

			let threeDSDialog = null;
			const threeDSContainer = document.createElement( 'div' );
			const threeDSIFrame = document.createElement( 'iframe' );
			const threeDSForm = document.createElement( 'form' );
			const threeDSLoading = document.createElement( 'div' );
			const threeDSLoadingSpinner = document.createElement( 'div' );
			const threeDSLoadingText = document.createElement( 'div' );

			threeDSContainer.setAttribute( 'id', 'three-ds-container' );
			threeDSContainer.setAttribute( 'style', 'height:100%; width:100%; border:none; position:relative;' );

			threeDSLoading.setAttribute( 'style', 'position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:#fff; z-index:1; flex-direction:column; gap:10px;' );
			threeDSLoadingSpinner.setAttribute( 'style', 'width:32px;height:32px;border:3px solid #cfcfcf;border-top-color:#333;border-radius:50%;animation:three-ds-spin 0.8s linear infinite' );
			threeDSLoadingText.setAttribute( 'style', 'font-size:13px; color:#555;' );
			threeDSLoadingText.textContent = 'Loading...';
			threeDSLoading.appendChild( threeDSLoadingSpinner );
			threeDSLoading.appendChild( threeDSLoadingText );

			threeDSIFrame.setAttribute( 'name', 'threedsiframe' );
			threeDSIFrame.setAttribute( 'id', 'threedsiframe' );
			threeDSIFrame.setAttribute( 'style', 'height:100%; width:100%;border:none;' );

			threeDSForm.setAttribute( 'method', 'POST' );
			threeDSForm.setAttribute( 'action', threeDSData.acsURL );
			threeDSForm.setAttribute( 'name', 'three-ds-form' );
			threeDSForm.setAttribute( 'id', 'three-ds-form' );
			threeDSForm.setAttribute( 'target', 'threedsiframe' );

			for ( const [ key, value ] of Object.entries( threeDSData.threeDSRequest || {} ) ) {
				const hiddenInput = document.createElement( 'input' );

				hiddenInput.setAttribute( 'type', 'hidden' );
				hiddenInput.setAttribute( 'name', key );
				hiddenInput.setAttribute( 'value', value );
				threeDSForm.appendChild( hiddenInput );
			}

			threeDSContainer.appendChild( threeDSLoading );
			threeDSContainer.appendChild( threeDSIFrame );
			threeDSContainer.appendChild( threeDSForm );

			threeDSIFrame.addEventListener( 'load', function () {
				threeDSLoading.style.display = 'none';
			} );

			const handleMessage = ( event ) => {
				if ( ! allowedOrigins.includes( event.origin ) ) {
					return;
				}

				if ( ! event.data || 'object' !== typeof event.data ) {
					return;
				}

				window.removeEventListener( 'message', handleMessage );

				if ( threeDSDialog && 'function' === typeof threeDSDialog.close ) {
					threeDSDialog.close();
				}

				if ( 'success' === event.data.result ) {
					resolve( event.data );
				} else {
					reject( event.data );
				}
			};

			if ( ! document.getElementById( 'three-ds-spinner-style' ) ) {
				const spinnerStyle = document.createElement( 'style' );

				spinnerStyle.id = 'three-ds-spinner-style';
				spinnerStyle.textContent = '@keyframes three-ds-spin { to { transform: rotate(360deg); } }';
				document.head.appendChild( spinnerStyle );
			}

			threeDSDialog = openDialog( threeDSContainer, {
				width: 406,
				height: 420,
				onClose: function () {
					window.removeEventListener( 'message', handleMessage );
				},
				onOpen: function () {
					document.getElementById( 'three-ds-form' ).submit();
					window.addEventListener( 'message', handleMessage );
				},
			} );
		} );
	};

	const openDialog = ( contentElement, options ) => {
		const settings = Object.assign(
			{
				width: 406,
				height: 520,
				onOpen: null,
				onClose: null,
			},
			options || {}
		);

		const overlay = document.createElement( 'div' );

		overlay.setAttribute( 'role', 'presentation' );
		overlay.style.position = 'fixed';
		overlay.style.inset = '0';
		overlay.style.background = 'rgba(0, 0, 0, 0.5)';
		overlay.style.zIndex = '2147483646';
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
		dialog.style.zIndex = '2147483647';
		dialog.style.transition = 'opacity 180ms ease';
        dialog.style.borderRadius = '8px';

		const header = document.createElement( 'div' );

		header.style.display = 'flex';
		header.style.alignItems = 'center';
		header.style.justifyContent = 'space-between';
		header.style.padding = '8px 12px';
		header.style.borderBottom = '1px solid #eee';

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

				if ( 'function' === typeof settings.onClose ) {
					settings.onClose();
				}
			};

			dialog.addEventListener( 'transitionend', finalizeClose, { once: true } );
		};

		requestAnimationFrame( function () {
			overlay.style.opacity = '1';
			dialog.style.opacity = '1';
		} );

		if ( 'function' === typeof settings.onOpen ) {
			settings.onOpen();
		}

		return {
			close: closeDialog,
		};
	};

	const onPaymentAuthorized = ( paymentData ) => {
		return new Promise( function ( resolve ) {
			processPayment( paymentData )
				.then( function ( checkoutResponse ) {
					latestCheckoutResponse.current = checkoutResponse;
					resolve( { transactionState: 'SUCCESS' } );
				} )
				.catch( function ( error ) {
					const message = normalizeErrorMessage( error && error.message ? error.message : 'Payment failed' ) || 'Payment failed';

					resolve( {
						transactionState: 'ERROR',
						error: {
							intent: 'PAYMENT_AUTHORIZATION',
							message,
							reason: 'PAYMENT_DATA_INVALID',
						},
					} );
				} );
		} );
	};

	const onGooglePaymentButtonClicked = async () => {
		try {
			props.onClick();
			isPaymentActive.current = true;
			latestCheckoutResponse.current = null;

			const cart = await storeApiFetch( '/cart' );
			const paymentDataRequest = getGooglePaymentDataRequest( cart );

			const needsShipping = cart && undefined !== cart.needs_shipping ? cart.needs_shipping : true;
			const paymentDataCallbacks = needsShipping
				? { onPaymentAuthorized, onPaymentDataChanged }
				: { onPaymentAuthorized };
			const checkoutClient = new google.payments.api.PaymentsClient( {
				environment: 'TEST' === googlePaySettings.environment ? 'TEST' : 'PRODUCTION',
				merchantInfo: {
					merchantName: googlePaySettings.merchantName || 'Example Merchant',
					merchantId: googlePaySettings.merchantId,
				},
				paymentDataCallbacks,
			} );

			await checkoutClient.loadPaymentData( paymentDataRequest );

			if ( ! latestCheckoutResponse.current ) {
				closeExpressFlow();
				return;
			}

			let checkoutResponse = latestCheckoutResponse.current;

			if (
				checkoutResponse &&
				checkoutResponse.payment_result &&
				Array.isArray( checkoutResponse.payment_result.payment_details )
			) {
				checkoutResponse = keyValueArrayToObject( checkoutResponse.payment_result.payment_details );
			}

			if ( checkoutResponse && checkoutResponse.redirect ) {
				closeExpressFlow();
				window.location.replace( checkoutResponse.redirect );
				return;
			}

			if ( checkoutResponse && checkoutResponse.threeDSData ) {
				const threeDSOutcome = await process3DS( checkoutResponse.threeDSData );

				if ( threeDSOutcome && threeDSOutcome.data && ('captured' === threeDSOutcome.data.paymentResult || 'verified' === threeDSOutcome.data.paymentResult) && threeDSOutcome.data.redirect ) {
					closeExpressFlow();
					window.location.replace( threeDSOutcome.data.redirect );
					return;
				}

				throw new Error( ( threeDSOutcome && threeDSOutcome.data && threeDSOutcome.data.message ) || '3D Secure authentication failed.' );
			}

			if ( ! checkoutResponse || ('captured' !== checkoutResponse.paymentResult && 'verified' !== checkoutResponse.paymentResult) ) {
				throw new Error( ( checkoutResponse && ( checkoutResponse.message || checkoutResponse.messages ) ) || 'Payment failed.' );
			}
		} catch ( error ) {
			const message = normalizeErrorMessage( error && error.data && error.data.message ? error.data.message : null ) || 'Payment failed. Please try again.';
			showCheckoutError( message );
			closeExpressFlow();
		}
	};

	function getGooglePaymentDataRequest( cart ) {
		const needsShipping = cart && undefined !== cart.needs_shipping ? cart.needs_shipping : true;
		const paymentDataRequest = Object.assign( {}, baseRequest );

		paymentDataRequest.allowedPaymentMethods = [ cardPaymentMethod ];
		paymentDataRequest.transactionInfo = buildGooglePayTransactionInfo( cart );
		paymentDataRequest.merchantInfo = {
			merchantId: googlePaySettings.merchantId,
			merchantName: googlePaySettings.merchantName,
		};

		paymentDataRequest.callbackIntents = needsShipping
			? [ 'SHIPPING_ADDRESS', 'SHIPPING_OPTION', 'PAYMENT_AUTHORIZATION' ]
			: [ 'PAYMENT_AUTHORIZATION' ];
		paymentDataRequest.emailRequired = true;
		paymentDataRequest.shippingAddressRequired = needsShipping;

		if ( needsShipping ) {
			paymentDataRequest.shippingAddressParameters = getGoogleShippingAddressParameters();
			paymentDataRequest.shippingOptionRequired = true;
		}

		return paymentDataRequest;
	}

	const onPaymentDataChanged = ( intermediatePaymentData ) => {
		return new Promise( function ( resolve ) {
			( async () => {
				try {
					const shippingAddress = intermediatePaymentData.shippingAddress;

					if ( 'INITIALIZE' === intermediatePaymentData.callbackTrigger ) {
						if ( ! shippingAddress ) {
							const cart = await storeApiFetch( '/cart' );
							resolve( { newTransactionInfo: buildGooglePayTransactionInfo( cart ) } );
							return;
						}

						let cart = await updateCustomerShippingAddress( shippingAddress );
						const initializeOptionId = intermediatePaymentData.shippingOptionData ? intermediatePaymentData.shippingOptionData.id : null;

						if ( initializeOptionId ) {
							cart = await selectShippingRate( initializeOptionId );
						}

						const shippingOptions = buildGooglePayShippingOptions( cart );
						const transactionInfo = buildGooglePayTransactionInfo( cart, shippingAddress );

						if ( null === shippingOptions ) {
							console.warn( 'No shipping options available, returning error to Google Pay' );
							resolve( {
								error: getGoogleUnserviceableAddressError(),
								newTransactionInfo: transactionInfo,
							} );
						} else {
							resolve( {
								newTransactionInfo: transactionInfo,
								newShippingOptionParameters: shippingOptions || undefined,
							} );
						}

						return;
					}

					if ( 'SHIPPING_ADDRESS' === intermediatePaymentData.callbackTrigger ) {
						if ( ! shippingAddress ) {
							resolve( {} );
							return;
						}

						const cart = await updateCustomerShippingAddress( shippingAddress );
						const shippingOptions = buildGooglePayShippingOptions( cart );
						const transactionInfo = buildGooglePayTransactionInfo( cart, shippingAddress );

						if ( null === shippingOptions ) {
							console.warn( 'No shipping options available, returning error to Google Pay', shippingOptions, transactionInfo );
							resolve( {
								error: getGoogleUnserviceableAddressError(),
								newTransactionInfo: transactionInfo || undefined,
							} );
						} else {
							resolve( {
								newTransactionInfo: transactionInfo,
								newShippingOptionParameters: shippingOptions || undefined,
							} );
						}

						return;
					}

					if ( 'SHIPPING_OPTION' === intermediatePaymentData.callbackTrigger ) {
						const optionId = intermediatePaymentData.shippingOptionData ? intermediatePaymentData.shippingOptionData.id : null;
						const cart = await selectShippingRate( optionId );
						const transactionInfo = buildGooglePayTransactionInfo( cart );

						resolve( { newTransactionInfo: transactionInfo } );
						return;
					}

					resolve( {} );
				} catch ( error ) {
					console.error( 'onPaymentDataChanged error:', error );
					resolve( {} );
				}
			} )();
		} );
	};

	const buildGooglePayTransactionInfo = ( cart ) => {
		const totals = getCartTotals( cart );
		const needsShipping = cart && undefined !== cart.needs_shipping ? cart.needs_shipping : true;
		const cartShipping = getShippingOptionsFromCart( cart );
		const selectedOption = cartShipping.options.find( ( option ) => option.selected ) || cartShipping.options[ 0 ];
		const shippingAmount = needsShipping && selectedOption ? selectedOption.amount : 0;

		const displayItems = [];
		const lineItems = getLineItemsFromCart( cart, totals.minorUnit );

		if ( lineItems.length ) {
			displayItems.push( ...lineItems );
		}

		if ( Number( totals.itemsTotal ) ) {
			displayItems.push( {
				label: 'Subtotal',
				type: 'SUBTOTAL',
				price: formatMoney( totals.itemsTotal, totals.minorUnit ),
			} );
		}

		if ( needsShipping && Number( shippingAmount ) ) {
			displayItems.push( {
				label: 'Shipping',
				type: 'LINE_ITEM',
				price: formatMoney( shippingAmount, totals.minorUnit ),
				status: selectedOption ? 'FINAL' : 'PENDING',
			} );
		}

		return {
			displayItems,
			countryCode: googlePaySettings.countryCode || 'GB',
			currencyCode: totals.currency,
			totalPriceStatus: needsShipping && ! selectedOption ? 'ESTIMATED' : 'FINAL',
			totalPrice: formatMoney( totals.totalPrice, totals.minorUnit ),
			totalPriceLabel: 'Total',
		};
	};

	const buildGooglePayShippingOptions = ( cart ) => {
		const totals = getCartTotals( cart );
		const cartShipping = getShippingOptionsFromCart( cart );
		const shippingOptions = cartShipping.options.map( ( option ) => ( {
			id: option.id,
			label: option.label,
			description: `${ formatMoney( option.amount, totals.minorUnit ) } ${ totals.currency }`,
		} ) );

		if ( ! shippingOptions.length ) {
			console.warn( 'No shipping options available to build Google Pay shipping options.' );
			return null;
		}

		const selectedOptionId = cartShipping.selectedIds[ 0 ] || shippingOptions[ 0 ].id;

		return {
			defaultSelectedOptionId: selectedOptionId,
			shippingOptions,
		};
	};

	const selectShippingRate = async ( optionId ) => {
		if ( ! optionId || -1 === optionId.indexOf( '::' ) ) {
			return storeApiFetch( '/cart' );
		}

		const [ packageId, rateId ] = optionId.split( '::' );

		return storeApiFetch( '/cart/select-shipping-rate', {
			method: 'POST',
			body: JSON.stringify( {
				package_id: packageId,
				rate_id: rateId,
			} ),
		} );
	};

	const updateCustomerShippingAddress = async ( address ) => {
		const shippingAddress = {
			country: address.countryCode || '',
			state: address.administrativeArea || '',
			postcode: address.postalCode || '',
			city: address.locality || '',
			address_1: Array.isArray( address.addressLines ) ? ( address.addressLines[ 0 ] || '' ) : '',
			address_2: Array.isArray( address.addressLines ) ? ( address.addressLines[ 1 ] || '' ) : '',
		};

		return storeApiFetch( '/cart/update-customer', {
			method: 'POST',
			body: JSON.stringify( { shipping_address: shippingAddress } ),
		} );
	};

	const storeApiBase = ( () => {
		if ( googlePaySettings.storeApiUrl ) {
			return googlePaySettings.storeApiUrl.replace( /\/$/, '' );
		}
		const settings = window.wc && window.wc.wcSettings;
		const base = settings && settings.getSetting && settings.getSetting( 'storeApiEndpoint' )
			? settings.getSetting( 'storeApiEndpoint' )
			: '/wp-json/wc/store/v1';

		return base.replace( /\/$/, '' );
	} )();

	const storeApiNonce = ( () => {
		return googlePaySettings.storeApiNonce || '';
	} )();

	const storeApiFetch = async ( path, options = {} ) => {
		const headers = Object.assign(
			{
				'Content-Type': 'application/json',
			},
			options.headers || {}
		);

		if ( storeApiNonce ) {
			headers.nonce = storeApiNonce;
		}

		const response = await fetch( `${ storeApiBase }${ path }`, Object.assign( {}, options, { headers } ) );

		if ( ! response.ok ) {
			throw new Error( `Store API error: ${ response.status }` );
		}

		return response.json();
	};

	const formatMoney = ( amountMinor, minorUnit ) => {
		const value = Number( amountMinor || 0 ) / Math.pow( 10, minorUnit );
		return value.toFixed( minorUnit );
	};

	const getCartTotals = ( cart ) => {
		const totals = cart && cart.totals ? cart.totals : {};
		const minorUnit = Number( totals.currency_minor_unit || 2 );
		const currency = totals.currency_code;
		const itemsTotal = totals.total_items || totals.items_total || totals.items_total_price || 0;
		const shippingTotal = totals.total_shipping || totals.shipping_total || 0;
		const taxTotal = totals.total_tax || 0;
		const totalPrice = totals.total_price || ( Number( itemsTotal ) + Number( shippingTotal ) + Number( taxTotal ) );

		return {
			currency,
			minorUnit,
			itemsTotal,
			shippingTotal,
			taxTotal,
			totalPrice,
		};
	};

	const getLineItemsFromCart = ( cart, minorUnit ) => {
		const items = Array.isArray( cart && cart.items ) ? cart.items : [];

		return items.map( ( item ) => {
			const name = item.name || item.product_name || 'Item';
			const quantity = item.quantity || 1;
			const lineTotal = item.totals && ( item.totals.line_total || item.totals.total ) ? ( item.totals.line_total || item.totals.total ) : item.totals || 0;

			return {
				label: quantity > 1 ? `${ name } x${ quantity }` : name,
				type: 'LINE_ITEM',
				price: formatMoney( lineTotal, minorUnit ),
				status: 'FINAL',
			};
		} );
	};

	const getShippingOptionsFromCart = ( cart ) => {
		const packages = Array.isArray( cart && cart.shipping_rates ) ? cart.shipping_rates : [];
		const options = [];
		const selectedIds = [];

		packages.forEach( ( pkg ) => {
			const packageId = null != pkg.package_id ? String( pkg.package_id ) : '0';
			const rates = pkg.shipping_rates || pkg.rates || [];

			rates.forEach( ( rate ) => {
				const rateId = rate.rate_id || rate.id;

				if ( ! rateId ) {
					return;
				}

				const optionId = `${ packageId }::${ rateId }`;
				const label = rate.name || rate.label || 'Shipping';
				const amount = rate.price || rate.cost || rate.amount || 0;
				const isSelected = !! rate.selected;

				options.push( {
					id: optionId,
					label,
					amount,
					packageId,
					rateId,
					selected: isSelected,
				} );

				if ( isSelected ) {
					selectedIds.push( optionId );
				}
			} );
		} );

		return { options, selectedIds };
	};

	const baseRequest = {
		apiVersion: 2,
		apiVersionMinor: 0,
	};

	const allowedCardNetworks = [ 'AMEX', 'DISCOVER', 'JCB', 'MASTERCARD', 'VISA' ];
	const allowedCardAuthMethods = [ 'PAN_ONLY', 'CRYPTOGRAM_3DS' ];

	const tokenizationSpecification = {
		type: 'PAYMENT_GATEWAY',
		parameters: {
			gateway: googlePaySettings.gateway || 'example',
			gatewayMerchantId: googlePaySettings.gatewayMerchantId || '',
		},
	};

	const baseCardPaymentMethod = {
		type: 'CARD',
		parameters: {
			allowedAuthMethods: allowedCardAuthMethods,
			allowedCardNetworks,
			billingAddressRequired: true,
			billingAddressParameters: {
				format: 'FULL',
				phoneNumberRequired: (Boolean)( googlePaySettings.billingPhoneRequired || false ),
			},
		},
	};

	const cardPaymentMethod = Object.assign( {}, baseCardPaymentMethod, {
		tokenizationSpecification,
	} );

	function getGoogleShippingAddressParameters() {
		return {
			phoneNumberRequired: true,
		};
	}

	function getGoogleUnserviceableAddressError() {
		return {
			reason: 'SHIPPING_ADDRESS_UNSERVICEABLE',
			message: 'Cannot ship to the selected address',
			intent: 'SHIPPING_ADDRESS',
		};
	}

	const googlePayClient = new google.payments.api.PaymentsClient( {
		environment: 'TEST' === googlePaySettings.environment ? 'TEST' : 'PRODUCTION',
		merchantInfo: {
			merchantName: googlePaySettings.merchantName || 'Example Merchant',
			merchantId: googlePaySettings.merchantId,
		},
	} );

	const createAndRenderPayButton = () => {
		if ( ! googlePayButtonContainer.current ) {
			return;
		}

		if ( googlePayButtonContainer.current.querySelector( 'button' ) ) {
			return;
		}

		if ( googlePayClient ) {
			const googlePayButton = googlePayClient.createButton( {
				buttonColor: 'default',
				buttonType: 'pay',
				buttonRadius: 8,
				buttonBorderType: 'default_border',
				buttonSizeMode: 'fill',
				onClick: onGooglePaymentButtonClicked,
			} );
			googlePayButtonContainer.current.appendChild( googlePayButton );
		}
	};

	useEffect( () => {
		clearStaleActiveGooglePayMethod();

		const handleBeforeUnload = () => {
			if ( isPaymentActive.current ) {
				closeExpressFlow();
			}
		};

		window.addEventListener( 'beforeunload', handleBeforeUnload );

		return () => {
			window.removeEventListener( 'beforeunload', handleBeforeUnload );

			if ( isPaymentActive.current ) {
				closeExpressFlow();
			}
		};
	}, [] );

	useEffect( () => {
		googlePayClient.isReadyToPay(
			Object.assign( {}, baseRequest, {
				allowedPaymentMethods: [ baseCardPaymentMethod ],
			} )
		)
			.then( ( res ) => {
				if ( res.result ) {
					createAndRenderPayButton();
				} else {
					alert( 'Unable to pay using Google Pay' );
				}
			} )
			.catch( function ( err ) {
				console.error( 'Error determining readiness to use Google Pay: ', err );
			} );
	}, [] );

	function processPayment( paymentData ) {
		const checkoutPayload = {
			paymentType: 'google_pay_order_payment',
			googlePayData: paymentData,
			shippingAddress: paymentData ? paymentData.shippingAddress : null,
			shippingOptionData: paymentData ? ( paymentData.shippingOptionData || null ) : null,
			deviceInformation: {
				deviceChannel: 'browser',
				deviceIdentity: navigator.userAgent || null,
				deviceTimeZone: new Date().getTimezoneOffset(),
				deviceScreenResolution: `${ window.screen.width }x${ window.screen.height }x${ window.screen.colorDepth }`,
				deviceAcceptLanguage: navigator.languages || null,
			},
		};

		return submitStoreApiCheckout( checkoutPayload );
	}

	return React.createElement( 'div', {
		style: {
			width: '100%',
			height: '60px',
			display: 'flex',
			justifyContent: 'center',
			alignItems: 'center',
		},
		ref: googlePayButtonContainer,
	} );
};

const DirectGooglePayBlock = {
	name: googlePaySettings.gatewayId,
	content: Object( window.wp.element.createElement )( GooglePayBlockContent, null ),
	edit: Object( window.wp.element.createElement )( GooglePayBlockContent, null ),
	canMakePayment: () => true,
	gatewayId: googlePaySettings.gatewayId,
	paymentMethodID: googlePaySettings.gatewayId,
	supports: {
		features: googlePaySettings.supports,
	},
};

window.wc.wcBlocksRegistry.registerExpressPaymentMethod( DirectGooglePayBlock );
