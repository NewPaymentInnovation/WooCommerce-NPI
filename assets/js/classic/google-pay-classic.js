jQuery( document ).ready( function ( $ ) {

	const baseRequest = {
		apiVersion: 2,
		apiVersionMinor: 0
	};

	const allowedCardNetworks = localizeGoogleClassicVars.allowedCardNetworks || [ 'AMEX', 'DISCOVER', 'JCB', 'MASTERCARD', 'VISA' ];

	const allowedCardAuthMethods = [ 'PAN_ONLY', 'CRYPTOGRAM_3DS' ];

	const tokenizationSpecification = {
		type: 'PAYMENT_GATEWAY',
		parameters: {
			'gateway': localizeGoogleClassicVars.gateway || 'example',
			'gatewayMerchantId': localizeGoogleClassicVars.gatewayMerchantId || ''
		}
	};

	const baseCardPaymentMethod = {
		type: 'CARD',
		parameters: {
			allowedAuthMethods: allowedCardAuthMethods,
			allowedCardNetworks: allowedCardNetworks,
			billingAddressRequired: true,
			billingAddressParameters: {
				format: 'FULL',
				phoneNumberRequired: (Boolean)( localizeGoogleClassicVars.billingPhoneRequired || false )
			}
		}
	};

	const cardPaymentMethod = Object.assign(
		{},
		baseCardPaymentMethod,
		{
			tokenizationSpecification: tokenizationSpecification
		}
	);

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

	const onPaymentDataChanged = ( intermediatePaymentData ) => {
		return new Promise( ( resolve ) => {
			( async () => {
				try {
					if ( intermediatePaymentData.callbackTrigger === 'INITIALIZE' || intermediatePaymentData.callbackTrigger === 'SHIPPING_ADDRESS' ) {
						let cart = await updateCustomerShippingAddress( intermediatePaymentData.shippingAddress || {} );

						const initializeOptionId =
							intermediatePaymentData.callbackTrigger === 'INITIALIZE' &&
							intermediatePaymentData.shippingOptionData
								? intermediatePaymentData.shippingOptionData.id
								: null;

						if ( initializeOptionId ) {
							cart = await selectShippingRate( initializeOptionId );
						}

						const shippingOptions = buildGooglePayShippingOptions( cart );
						const transactionInfo = buildGooglePayTransactionInfo( cart );

						if ( shippingOptions === null && cart && cart.needs_shipping ) {
							resolve( {
								error: getGoogleUnserviceableAddressError(),
								newTransactionInfo: transactionInfo,
							} );
							return;
						}

						resolve( {
							newTransactionInfo: transactionInfo,
							newShippingOptionParameters: shippingOptions || undefined,
						} );
						return;
					}

					if ( intermediatePaymentData.callbackTrigger === 'SHIPPING_OPTION' ) {
						const optionId = intermediatePaymentData.shippingOptionData
							? intermediatePaymentData.shippingOptionData.id
							: null;

						const cart = await selectShippingRate( optionId );
						resolve( {
							newTransactionInfo: buildGooglePayTransactionInfo( cart ),
						} );
						return;
					}

					resolve( {} );
				} catch ( error ) {
					resolve( {} );
				}
			} )();
		} );
	};

	const namedFormElement = window.document.forms.order_review || window.document.forms.checkout;
	const formElement = namedFormElement ? $( namedFormElement ) : $( 'form.checkout' ).first();
	if ( ! formElement.length ) {
		return;
	}

	const storeApiBase = ( () => {
		if ( localizeGoogleClassicVars.storeApiEndpoint ) {
			return localizeGoogleClassicVars.storeApiEndpoint.replace( /\/$/, '' );
		}
		const settings = window.wc && window.wc.wcSettings;
		const base = settings && settings.getSetting && settings.getSetting( 'storeApiEndpoint' )
			? settings.getSetting( 'storeApiEndpoint' )
			: '/wp-json/wc/store/v1';
		return base.replace( /\/$/, '' );
	} )();

	const storeApiNonce = localizeGoogleClassicVars.storeApiNonce || null;

	const getButtonContainer = () => document.getElementById( 'google-pay-button-container' );

	const toggleCheckoutOverlay = ( checkoutForm, show ) => {
		if ( ! checkoutForm ) {
			return;
		}

		const checkoutFormElement = checkoutForm.jquery ? checkoutForm.get( 0 ) : checkoutForm;
		if ( ! checkoutFormElement ) {
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

			checkoutFormElement.setAttribute( 'aria-busy', 'true' );
			const placeOrderButton = document.getElementById( 'place_order' );
			if ( placeOrderButton ) {
				placeOrderButton.disabled = true;
			}
			return;
		}

		if ( overlay && overlay.parentNode ) {
			overlay.parentNode.removeChild( overlay );
		}

		checkoutFormElement.setAttribute( 'aria-busy', 'false' );
		const placeOrderButton = document.getElementById( 'place_order' );
		if ( placeOrderButton ) {
			placeOrderButton.disabled = false;
		}
	};

	const showCheckoutError = ( checkoutForm, messageOrHtml ) => {

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

		const response = await fetch( `${storeApiBase}${path}`, Object.assign( {}, options, { headers } ) );
		if ( ! response.ok ) {
			let message = `Store API error: ${response.status}`;
			try {
				const responseBody = await response.json();
				message = ( responseBody && ( responseBody.message || responseBody.code ) )
					? String( responseBody.message || responseBody.code )
					: message;
			} catch ( error ) {
				try {
					const responseText = await response.text();
					if ( responseText ) {
						message = responseText;
					}
				} catch ( ignored ) {
				}
			}
			throw new Error( message );
		}

		return response.json();
	};

	let latestCart = null;

	const getCart = async () => {
		latestCart = await storeApiFetch( '/cart' );
		return latestCart;
	};

	const selectShippingRate = async ( optionId ) => {
		if ( ! optionId || optionId.indexOf( '::' ) === -1 ) {
			return latestCart || getCart();
		}

		const [ packageId, rateId ] = optionId.split( '::' );

		latestCart = await storeApiFetch( '/cart/select-shipping-rate', {
			method: 'POST',
			body: JSON.stringify( {
				package_id: packageId,
				rate_id: rateId,
			} ),
		} );

		return latestCart;
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
			.filter( ( line ) => typeof line === 'string' && line.trim() !== '' )
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
			email: email,
			phone: address.phoneNumber || address.phone || '',
		};
	};

	const submitStoreApiCheckout = async ( paymentPayload ) => {
		const googlePayData = ( paymentPayload && paymentPayload.googlePayData ) || {};
		const billingSource = googlePayData.paymentMethodData
			&& googlePayData.paymentMethodData.info
			&& googlePayData.paymentMethodData.info.billingAddress
			? googlePayData.paymentMethodData.info.billingAddress
			: null;
		const shippingSource = googlePayData.shippingAddress || paymentPayload.shippingAddress || null;
		const email = googlePayData.email || '';
		const billingAddress = mapGooglePayAddressToWooAddress( billingSource || shippingSource, email );
		const shippingAddress = mapGooglePayAddressToWooAddress( shippingSource || billingSource, email );

		return storeApiFetch( '/checkout', {
			method: 'POST',
			body: JSON.stringify( {
				payment_method: localizeGoogleClassicVars.gatewayId,
				billing_email: billingAddress.email || undefined,
				billing_address: billingAddress,
				shipping_address: shippingAddress,
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
			} ),
		} );
	};

	function keyValueArrayToObject( items ) {
		return ( Array.isArray( items ) ? items : [] ).reduce( ( obj, item ) => {
			if ( ! item || typeof item.key !== 'string' ) {
				return obj;
			}

			let value = item.value;

			// Parse JSON-encoded strings.
			if ( typeof value === 'string' ) {
				const trimmed = value.trim();
				if (
					( trimmed.startsWith( '{' ) && trimmed.endsWith( '}' ) ) ||
					( trimmed.startsWith( '[' ) && trimmed.endsWith( ']' ) )
				) {
					try {
						value = JSON.parse( trimmed );
					} catch ( error ) {
						console.error( 'Error parsing JSON:', error );
					}
				}
			}

			obj[ item.key ] = value;
			return obj;
		}, {} );
	}

	const processPayment = async ( paymentData ) => {
		try {
			const selectedMethod = document.getElementById( `payment_method_${localizeGoogleClassicVars.gatewayId}` );
			if ( selectedMethod ) {
				selectedMethod.checked = true;
			}

			const checkoutPayload = {
				paymentType: 'google_pay_order_payment',
				googlePayData: paymentData,
				shippingAddress: paymentData.shippingAddress,
				shippingOptionData: paymentData.shippingOptionData || null,
				deviceInformation: {
					deviceChannel: 'browser',
					deviceIdentity: navigator.userAgent || null,
					deviceTimeZone: new Date().getTimezoneOffset(),
					deviceScreenResolution: `${window.screen.width}x${window.screen.height}x${window.screen.colorDepth}`,
					deviceAcceptLanguage: navigator.languages || null,
				},
			};

			const checkoutResponse = await submitStoreApiCheckout( checkoutPayload );

			return checkoutResponse;
		} catch ( error ) {
			console.error( 'Error during payment processing:', error );
		}
	};

	const createAndRenderPayButton = () => {
		const container = getButtonContainer();
		if ( ! container || ! googlePayClient ) {
			return;
		}

		// Prevent creating multiple buttons in case of multiple events firing.
		if ( container.querySelector( 'button' ) ) {
			return;
		}

		if ( googlePayClient ) {
			const googlePayButton = googlePayClient.createButton( {
				buttonColor: 'default',
				buttonType: 'pay',
				buttonRadius: 0,
				buttonBorderType: 'default_border',
				buttonSizeMode: 'fill',
				onClick: onGooglePaymentButtonClicked
			} );

			getButtonContainer().appendChild( googlePayButton );
		}
	};

	let latestCheckoutResponse = null;

	const onPaymentAuthorized = ( paymentData ) => {
		return new Promise( ( resolve ) => {
			processPayment( paymentData )
				.then( ( checkoutResponse ) => {
					latestCheckoutResponse = checkoutResponse;
					resolve( { transactionState: 'SUCCESS' } );
				} )
				.catch( ( error ) => {
					const message = error && error.message ? error.message : 'Payment failed';
					showCheckoutError( formElement, message );
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
			const cart = await storeApiFetch( '/cart' );
			const paymentDataRequest = getGooglePaymentDataRequest( cart );

			// Build a client whose paymentDataCallbacks match the request's callbackIntents.
			// Google Pay rejects a client that registers onPaymentDataChanged when no
			// SHIPPING_ADDRESS / SHIPPING_OPTION intent is set, and vice versa.
			const needsShipping = cart && cart.needs_shipping !== undefined ? cart.needs_shipping : true;
			const paymentDataCallbacks = needsShipping
				? { onPaymentAuthorized: onPaymentAuthorized, onPaymentDataChanged: onPaymentDataChanged }
				: { onPaymentAuthorized: onPaymentAuthorized };
			const checkoutClient = new google.payments.api.PaymentsClient( {
				environment: localizeGoogleClassicVars.environment === 'TEST' ? 'TEST' : 'PRODUCTION',
				merchantInfo: {
					merchantName: localizeGoogleClassicVars.merchantName || 'Example Merchant',
					merchantId: localizeGoogleClassicVars.merchantId,
				},
				paymentDataCallbacks: paymentDataCallbacks,
			} );

			// Google Pay sheet is open during this await.
			await checkoutClient.loadPaymentData( paymentDataRequest );

			toggleCheckoutOverlay( formElement, true );

			if ( latestCheckoutResponse ) {
				let checkoutResponse = latestCheckoutResponse;

				checkoutResponse = keyValueArrayToObject( checkoutResponse.payment_result.payment_details );

				if ( checkoutResponse && checkoutResponse.redirect ) {
					window.location.href = checkoutResponse.redirect;
					return checkoutResponse;
				}

				if ( checkoutResponse && checkoutResponse.threeDSData ) {
					const threeDSData = typeof checkoutResponse.threeDSData === 'string'
						? JSON.parse( checkoutResponse.threeDSData )
						: checkoutResponse.threeDSData;

					toggleCheckoutOverlay( formElement, false );
					const threeDSOutcome = await handle3DS( threeDSData );

					if ( threeDSOutcome && threeDSOutcome.redirect ) {
						window.location.href = threeDSOutcome.redirect;
						return threeDSOutcome;
					}

					throw new Error( ( threeDSOutcome && threeDSOutcome.message ) || '3D Secure authentication failed.' );
				}

				if ( ! checkoutResponse || ( checkoutResponse.paymentResult !== 'captured' && checkoutResponse.paymentResult !== 'verified' ) ) {
					throw new Error( ( checkoutResponse && ( checkoutResponse.message || checkoutResponse.messages ) ) || 'Payment failed.' );
				}
			}
		} catch ( error ) {
			showCheckoutError( formElement, error.message || 'An error occurred after payment. Please contact support.' );
		} finally {
			toggleCheckoutOverlay( formElement, false );
		}
	};

	/**
	* Configure support for the Google Pay API.
	*
	* @param {object} cart WooCommerce cart object.
	* @returns {object} PaymentDataRequest fields.
	*/
	function getGooglePaymentDataRequest( cart ) {
		const needsShipping = cart && cart.needs_shipping !== undefined ? cart.needs_shipping : true;
		const paymentDataRequest = Object.assign( {}, baseRequest );
		paymentDataRequest.allowedPaymentMethods = [ cardPaymentMethod ];
		paymentDataRequest.transactionInfo = buildGooglePayTransactionInfo( cart );
		paymentDataRequest.merchantInfo = {
			merchantId: localizeGoogleClassicVars.merchantId,
			merchantName: localizeGoogleClassicVars.merchantName
		};
		paymentDataRequest.emailRequired = true;

		// Only register shipping callbacks when the cart actually needs shipping.
		paymentDataRequest.callbackIntents = needsShipping
			? [ 'SHIPPING_ADDRESS', 'SHIPPING_OPTION', 'PAYMENT_AUTHORIZATION' ]
			: [ 'PAYMENT_AUTHORIZATION' ];
		paymentDataRequest.shippingAddressRequired = needsShipping;

		if ( needsShipping ) {
			paymentDataRequest.shippingAddressParameters = getGoogleShippingAddressParameters();
			paymentDataRequest.shippingOptionRequired = true;
		}

		return paymentDataRequest;
	}

	/**
	* Provide Google Pay API with shipping address parameters when using dynamic buy flow.
	*
	* @returns {object} Shipping address details, suitable for use as shippingAddressParameters property of PaymentDataRequest.
	*/
	function getGoogleShippingAddressParameters() {
		return {
			phoneNumberRequired: true
		};
	}

	const buildGooglePayTransactionInfo = ( cart ) => {
		const totals = getCartTotals( cart );
		const cartShipping = getShippingOptionsFromCart( cart );
		const selectedOption = cartShipping.options.find( ( option ) => option.selected ) || cartShipping.options[ 0 ];
		const shippingAmount = selectedOption ? selectedOption.amount : totals.shippingTotal;

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

		if ( Number( shippingAmount ) ) {
			displayItems.push( {
				label: 'Shipping',
				type: 'LINE_ITEM',
				price: formatMoney( shippingAmount, totals.minorUnit ),
				status: selectedOption ? 'FINAL' : 'PENDING',
			} );
		}

		if ( Number( totals.taxTotal ) ) {
			displayItems.push( {
				label: 'Tax',
				type: 'TAX',
				price: formatMoney( totals.taxTotal, totals.minorUnit ),
			} );
		}

		return {
			displayItems,
			countryCode: 'GB',
			currencyCode: totals.currency,
			totalPriceStatus: selectedOption ? 'FINAL' : 'ESTIMATED',
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
			description: `${formatMoney( option.amount, totals.minorUnit )} ${totals.currency}`,
		} ) );

		if ( ! shippingOptions.length ) {
			return null;
		}

		const selectedOptionId = cartShipping.selectedIds[ 0 ] || shippingOptions[ 0 ].id;

		return {
			defaultSelectedOptionId: selectedOptionId,
			shippingOptions,
		};
	};

	const getGoogleUnserviceableAddressError = () => ( {
		reason: 'SHIPPING_ADDRESS_UNSERVICEABLE',
		message: 'Cannot ship to the selected address',
		intent: 'SHIPPING_ADDRESS',
	} );

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

	const getShippingOptionsFromCart = ( cart ) => {
		const packages = Array.isArray( cart && cart.shipping_rates ) ? cart.shipping_rates : [];
		const options = [];
		const selectedIds = [];

		packages.forEach( ( pkg ) => {
			const packageId = pkg.package_id != null ? String( pkg.package_id ) : '0';
			const rates = pkg.shipping_rates || pkg.rates || [];
			rates.forEach( ( rate ) => {
				const rateId = rate.rate_id || rate.id;
				if ( ! rateId ) {
					return;
				}
				const optionId = `${packageId}::${rateId}`;
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

	const formatMoney = ( amountMinor, minorUnit ) => {
		const value = Number( amountMinor || 0 ) / Math.pow( 10, minorUnit );
		return value.toFixed( minorUnit );
	};

	const getLineItemsFromCart = ( cart, minorUnit ) => {
		const items = Array.isArray( cart && cart.items ) ? cart.items : [];
		return items.map( ( item ) => {
			const name = item.name || item.product_name || 'Item';
			const quantity = item.quantity || 1;
			const lineTotal = item.totals && ( item.totals.line_total || item.totals.total )
				? ( item.totals.line_total || item.totals.total )
				: item.totals || 0;
			return {
				label: quantity > 1 ? `${name} x${quantity}` : name,
				type: 'LINE_ITEM',
				price: formatMoney( lineTotal, minorUnit ),
				status: 'FINAL',
			};
		} );
	};

	const googlePayClient = new google.payments.api.PaymentsClient( {
		environment: localizeGoogleClassicVars.environment === 'TEST' ? 'TEST' : 'PRODUCTION',
		merchantInfo: {
			merchantName: localizeGoogleClassicVars.merchantName || 'Example Merchant',
			merchantId: localizeGoogleClassicVars.merchantId,
		},
		paymentDataCallbacks: {
			onPaymentAuthorized: onPaymentAuthorized,
			onPaymentDataChanged: onPaymentDataChanged
		}
	} );

	const handle3DS = ( threeDSData ) => {
		return new Promise( function ( resolve ) {
			let threeDSDialog = null;
			let messageHandler = null;

			if ( ! document.getElementById( 'three-ds-spinner-style' ) ) {
				const spinnerStyle = document.createElement( 'style' );
				spinnerStyle.id = 'three-ds-spinner-style';
				spinnerStyle.textContent = '@keyframes three-ds-spin { to { transform: rotate(360deg); } }';
				document.head.appendChild( spinnerStyle );
			}

			const threeDSContainer = document.createElement( 'div' );
			const threeDSiFrame = document.createElement( 'iframe' );
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

			threeDSiFrame.setAttribute( 'name', 'threedsiframe' );
			threeDSiFrame.setAttribute( 'id', 'threedsiframe' );
			threeDSiFrame.setAttribute( 'style', 'height:100%; width:100%;border:none;' );

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
			threeDSContainer.appendChild( threeDSiFrame );
			threeDSContainer.appendChild( threeDSForm );

			threeDSiFrame.addEventListener( 'load', function () {
				threeDSLoading.style.display = 'none';
			} );

			threeDSDialog = openDialog( threeDSContainer, {
				title: '3D Secure',
				width: 406,
				height: 420,
				onClose: function () {
					if ( messageHandler ) {
						window.removeEventListener( 'message', messageHandler, false );
					}
				},
				onOpen: function () {
					$( '#three-ds-form' ).submit();

					messageHandler = function receiveMessage( messageEvent ) {
						if ( messageEvent.source?.name === 'threedsiframe' ) {
							resolve( messageEvent.data.data || {} );
							window.removeEventListener( 'message', messageHandler, false );
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
		body.style.padding = '5px';
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

	googlePayClient.isReadyToPay(
		Object.assign(
			{},
			baseRequest,
			{
				allowedPaymentMethods: [ baseCardPaymentMethod ]
			}
		)
	)
		.then( ( res ) => {
			if ( res.result ) {
				createAndRenderPayButton();
			} else {
				console.warn( 'Google Pay is not available for the current user or environment. Button will not be displayed.' );
			}
		} )
		.catch( function ( err ) {
			console.error( 'Error determining readiness to use Google Pay: ', err );
		} );

	$( document.body ).on( 'checkout_error updated_checkout', function () {
		createAndRenderPayButton();
	} );

	$( document.body ).on( 'payment_method_selected', function () {
		createAndRenderPayButton();
	} );

	$( document ).on( 'change', 'input[name="payment_method"]', function () {
		createAndRenderPayButton();
	} );

	createAndRenderPayButton();

} );
