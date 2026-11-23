const applepaysetting = window.wc.wcSettings.getSetting( localizeAppleBlockVars.pluginID + '_data', {} );

const storeApiFetch = async ( path, options = {} ) => {
	const headers = {
		'Content-Type': 'application/json',
		...( options.headers || {} ),
	};
	if ( applepaysetting.storeApiNonce ) {
		headers[ 'nonce' ] = applepaysetting.storeApiNonce;
	}
	if ( options.applePayShippingQuote ) {
		headers[ 'X-Payment-Module-Apple-Pay-Quote' ] = '1';
	}
	const storeApiBase = applepaysetting.storeApiUrl || '/wp-json/wc/store/v1';
	const response = await fetch( `${ storeApiBase }${ path }`, { ...options, headers } );
	if ( ! response.ok ) {
		const errorData = await response.json().catch( () => ( { message: 'Unknown error' } ) );
		throw new Error( `${ errorData.message }` );
	}
	return response.json();
};

const formatMoney = ( amountMinor, minorUnit ) => {
	const value = Number( amountMinor || 0 ) / Math.pow( 10, minorUnit );
	return value.toFixed( minorUnit );
};

const toApplePayDetails = ( details ) => ( {
	total: {
		label: details.total.label,
		amount: details.total.amount.value,
		type: 'final',
	},
	lineItems: ( details.displayItems || [] ).map( ( item ) => ( {
		label: item.label,
		amount: item.amount.value,
		type: 'final',
	} ) ),
	shippingMethods: ( details.shippingOptions || [] ).map( ( option ) => ( {
		identifier: option.id,
		label: option.label,
		amount: option.amount.value,
		detail: option.label,
	} ) ),
} );

const mapApplePayContactToWooAddress = ( contact, fallbackContact ) => {
	const primary = contact || {};
	const fallback = fallbackContact || {};
	const primaryLines = Array.isArray( primary.addressLines ) ? primary.addressLines : [];
	const fallbackLines = Array.isArray( fallback.addressLines ) ? fallback.addressLines : [];

	return {
		first_name: primary.givenName || fallback.givenName || '',
		last_name: primary.familyName || fallback.familyName || '',
		company: '',
		address_1: primaryLines[ 0 ] || fallbackLines[ 0 ] || '',
		address_2: primaryLines[ 1 ] || fallbackLines[ 1 ] || '',
		city: primary.locality || fallback.locality || '',
		state: primary.administrativeArea || fallback.administrativeArea || '',
		postcode: primary.postalCode || fallback.postalCode || '',
		country: primary.countryCode || fallback.countryCode || '',
		email: primary.emailAddress || fallback.emailAddress || '',
		phone: primary.phoneNumber || fallback.phoneNumber || '',
	};
};

const keyValueArrayToObject = ( items ) => {
	return ( Array.isArray( items ) ? items : [] ).reduce( ( obj, item ) => {
		if ( ! item || typeof item.key !== 'string' ) {
			return obj;
		}
		let value = item.value;
		if ( typeof value === 'string' ) {
			const trimmed = value.trim();
			if (
				( trimmed.startsWith( '{' ) && trimmed.endsWith( '}' ) ) ||
				( trimmed.startsWith( '[' ) && trimmed.endsWith( ']' ) )
			) {
				try {
					value = JSON.parse( trimmed );
				} catch ( e ) {
					/* keep original */
				}
			}
		}
		obj[ item.key ] = value;
		return obj;
	}, {} );
};

const getCartTotals = ( cart ) => {
	const totals = cart && cart.totals ? cart.totals : {};
	const minorUnit = Number( totals.currency_minor_unit || 2 );
	const currency = totals.currency_code;
	const itemsTotal = totals.items_total ?? totals.items_total_price;
	const shippingTotal = totals.shipping_total || 0;
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
		const packageId = pkg.package_id !== null && pkg.package_id !== undefined ? String( pkg.package_id ) : '0';
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

const buildPaymentDetailsFromCart = ( cart, selectedOptionId ) => {
	const totals = getCartTotals( cart );
	const cartShipping = getShippingOptionsFromCart( cart );
	const selected = selectedOptionId || cartShipping.selectedIds[ 0 ];
	const selectedRate = cartShipping.options.find( ( option ) => option.id === selected );
	const shippingAmount = selectedRate ? selectedRate.amount : totals.shippingTotal;

	const displayItems = [];
	if ( Number( totals.itemsTotal ) ) {
		displayItems.push( {
			label: 'Subtotal',
			amount: {
				value: formatMoney( totals.itemsTotal, totals.minorUnit ),
				currency: totals.currency,
			},
		} );
	}

	if ( Number( shippingAmount ) ) {
		displayItems.push( {
			label: 'Shipping',
			amount: {
				value: formatMoney( shippingAmount, totals.minorUnit ),
				currency: totals.currency,
			},
		} );
	}

	if ( Number( totals.taxTotal ) ) {
		displayItems.push( {
			label: 'Tax',
			amount: {
				value: formatMoney( totals.taxTotal, totals.minorUnit ),
				currency: totals.currency,
			},
		} );
	}

	return {
		total: {
			label: 'Total',
			amount: {
				value: formatMoney( totals.totalPrice, totals.minorUnit ),
				currency: totals.currency,
			},
		},
		displayItems,
		shippingOptions: cartShipping.options.map( ( option ) => ( {
			id: option.id,
			label: option.label,
			amount: {
				value: formatMoney( option.amount, totals.minorUnit ),
				currency: totals.currency,
			},
			selected: option.id === selected,
		} ) ),
	};
};

const submitStoreApiCheckout = async ( paymentPayload ) => {
	const billingAddress = mapApplePayContactToWooAddress( paymentPayload.billingContact, paymentPayload.shippingContact );
	const shippingAddress = mapApplePayContactToWooAddress( paymentPayload.shippingContact, paymentPayload.billingContact );

	return storeApiFetch( '/checkout', {
		method: 'POST',
		body: JSON.stringify( {
			payment_method: applepaysetting.name,
			billing_address: billingAddress,
			shipping_address: shippingAddress,
			payment_data: [
				{ key: 'paymentType', value: 'apple_pay_order_payment' },
				{ key: 'paymentdata', value: JSON.stringify( paymentPayload ) },
			],
		} ),
	} );
};

const updateCustomerShippingAddress = async ( address ) => {
	const postcode = String( address.postalCode || address.postcode || '' ).trim();
	const shippingAddress = {
		country: address.countryCode || '',
		state: address.administrativeArea || '',
		postcode,
		city: address.locality || '',
	};

	return storeApiFetch( '/cart/update-customer', {
		method: 'POST',
		applePayShippingQuote: true,
		body: JSON.stringify( { shipping_address: shippingAddress } ),
	} );
};

const selectShippingRate = async ( optionId ) => {
	if ( ! optionId || optionId.indexOf( '::' ) === -1 ) {
		return storeApiFetch( '/cart', { applePayShippingQuote: true } );
	}

	const [ packageId, rateId ] = optionId.split( '::' );

	return storeApiFetch( '/cart/select-shipping-rate', {
		method: 'POST',
		applePayShippingQuote: true,
		body: JSON.stringify( {
			package_id: packageId,
			rate_id: rateId,
		} ),
	} );
};

const ApplePayBlockContent = ( props ) => {
	const { useEffect, useRef } = wp.element;
	const notifyPaymentMethodsUpdated = () => {
		if ( window.wp && window.wp.hooks && 'function' === typeof window.wp.hooks.doAction ) {
			window.wp.hooks.doAction( 'experimental__woocommerce_blocks-payment-methods-updated' );
		}
	};

	const clearStaleActiveApplePayMethod = () => {
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

		if ( activeMethod !== applepaysetting.name ) {
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
		props.onClose();
		clearStaleActiveApplePayMethod();
	};

	// Pre-fetch cart so it's available synchronously when the button is clicked.
	// iOS Safari requires ApplePaySession to be constructed synchronously within
	// a user gesture handler — any await before new ApplePaySession() breaks that.
	const cachedCartRef = useRef( null );
	useEffect( () => {
		clearStaleActiveApplePayMethod();

		storeApiFetch( '/cart' ).then( ( cart ) => {
			cachedCartRef.current = cart;
		} ).catch( () => {} );
	}, [] );

	const onApplePayButtonClicked = () => {
		// Use pre-fetched (or freshly refreshed) cart data synchronously.
		const cart = cachedCartRef.current || {};
		const totals = getCartTotals( cart );
		const paymentDetails = buildPaymentDetailsFromCart( cart );
		const initialApplePayDetails = toApplePayDetails( paymentDetails );
		let quotedCart = cart;

		let session;
		try {
			session = new window.ApplePaySession(
				3,
				{
					countryCode: applepaysetting.countryCode || 'GB',
					currencyCode: totals.currency,
					merchantCapabilities: applepaysetting.merchantCapabilities || [ 'supports3DS' ],
					supportedNetworks: applepaysetting.supportedNetworks || [ 'visa', 'masterCard' ],
					total: initialApplePayDetails.total,
					lineItems: initialApplePayDetails.lineItems,
					shippingMethods: initialApplePayDetails.shippingMethods,
					requiredShippingContactFields: cart && cart.needs_shipping ? [ 'postalAddress', 'email', 'phone' ] : [ 'email', 'phone' ],
					requiredBillingContactFields: [ 'postalAddress', 'name', 'phone' ],
				}
			);
		} catch ( e ) {
			console.error( 'Error creating Apple Pay session', e );
			props.onError( e );
			closeExpressFlow();
			return;
		}

			session.onvalidatemerchant = async ( event ) => {
			try {
				const formData = new FormData();
				formData.append( 'action', 'apple_pay_merchant_validation' );
				formData.append( 'nonce', applepaysetting.nonce );
				formData.append( 'validationURL', event.validationURL );

				const response = await fetch( applepaysetting.ajaxurl, {
					method: 'POST',
					body: formData,
				} );
				const result = await response.json();

				if ( ! result || ! result.success || ! result.data || ! result.data.merchantSession ) {
					throw new Error( 'Merchant validation failed' );
				}

				session.completeMerchantValidation( result.data.merchantSession );
			} catch ( error ) {
				console.error( 'Merchant validation failed', error );
				session.abort();
				props.onError( error );
				closeExpressFlow();
			}
		};

		const shippingContactError = () => ( {
			newShippingMethods: [],
			newTotal: initialApplePayDetails.total,
			newLineItems: initialApplePayDetails.lineItems,
			errors: [
				new ApplePayError( 'shippingContactInvalid', 'postalAddress', 'No shipping methods available for your address' ),
			],
		} );

		session.onshippingcontactselected = async ( event ) => {
			try {
				const updatedCart = await updateCustomerShippingAddress( event.shippingContact );
				const updatedDetails = buildPaymentDetailsFromCart( updatedCart );
				const updatedApplePayDetails = toApplePayDetails( updatedDetails );

				if ( ! updatedApplePayDetails.shippingMethods.length ) {
					session.completeShippingContactSelection( shippingContactError() );
					return;
				}

				quotedCart = updatedCart;
				session.completeShippingContactSelection( {
					newShippingMethods: updatedApplePayDetails.shippingMethods,
					newTotal: updatedApplePayDetails.total,
					newLineItems: updatedApplePayDetails.lineItems,
					errors: [],
				} );
			} catch ( error ) {
				console.error( 'Shipping contact update failed', error );
				session.completeShippingContactSelection( shippingContactError() );
			}
		};

		session.onshippingmethodselected = async ( event ) => {
			try {
				const optionId = event && event.shippingMethod ? event.shippingMethod.identifier : null;
				const updatedCart = await selectShippingRate( optionId );
				const updatedDetails = buildPaymentDetailsFromCart( updatedCart, optionId );
				const updatedApplePayDetails = toApplePayDetails( updatedDetails );

				quotedCart = updatedCart;
				session.completeShippingMethodSelection( {
					newTotal: updatedApplePayDetails.total,
					newLineItems: updatedApplePayDetails.lineItems,
				} );
			} catch ( error ) {
				console.error( 'Shipping method update failed', error );
				session.completeShippingMethodSelection( {
					newTotal: initialApplePayDetails.total,
					newLineItems: initialApplePayDetails.lineItems,
				} );
			}
		};

		session.onpaymentauthorized = async ( event ) => {
			try {
				const finalCart = await storeApiFetch( '/cart/update-customer', {
					method: 'POST',
					body: JSON.stringify( {
						billing_address: mapApplePayContactToWooAddress( event.payment.billingContact, event.payment.shippingContact ),
						shipping_address: mapApplePayContactToWooAddress( event.payment.shippingContact, event.payment.billingContact ),
					} ),
				} );
				cachedCartRef.current = finalCart;
				const quotedTotals = getCartTotals( quotedCart );
				const finalTotals = getCartTotals( finalCart );
				const quotedRates = getShippingOptionsFromCart( quotedCart ).selectedIds.slice().sort();
				const finalRates = getShippingOptionsFromCart( finalCart ).selectedIds.slice().sort();
				if ( quotedTotals.currency !== finalTotals.currency ||
					String( quotedTotals.totalPrice ) !== String( finalTotals.totalPrice ) ||
					JSON.stringify( quotedRates ) !== JSON.stringify( finalRates ) ) {
					throw new Error( 'Your total or shipping method changed after receiving your full address. Please review checkout and try again.' );
				}

				const checkoutResponse = await submitStoreApiCheckout( event.payment );
				let normalizedCheckoutResponse = checkoutResponse;
				if (
					checkoutResponse &&
					checkoutResponse.payment_result &&
					Array.isArray( checkoutResponse.payment_result.payment_details )
				) {
					normalizedCheckoutResponse = keyValueArrayToObject( checkoutResponse.payment_result.payment_details );
				}

				if ( normalizedCheckoutResponse && normalizedCheckoutResponse.redirect ) {
					session.completePayment( window.ApplePaySession.STATUS_SUCCESS );
					closeExpressFlow();
					window.location.replace( normalizedCheckoutResponse.redirect );
					return;
				}

				if ( normalizedCheckoutResponse && ( normalizedCheckoutResponse.paymentResult === 'captured' || normalizedCheckoutResponse.paymentResult === 'verified' ) ) {
					session.completePayment( window.ApplePaySession.STATUS_SUCCESS );

					if ( checkoutResponse && checkoutResponse.redirect ) {
						closeExpressFlow();
						window.location.replace( checkoutResponse.redirect );
					}
					return;
				}

				throw new Error(
					( normalizedCheckoutResponse && ( normalizedCheckoutResponse.message || normalizedCheckoutResponse.messages ) ) ||
					'Payment failed.'
				);

			} catch ( error ) {
				console.error( 'Payment authorization failed', error );
				session.completePayment( window.ApplePaySession.STATUS_FAILURE );
				props.onError( error );
				closeExpressFlow();
			}
		};

		session.oncancel = () => closeExpressFlow();

		session.begin();
	};

	const applePayButtonRef = useRef();

	useEffect( () => {
		const button = applePayButtonRef.current;
		if ( ! button ) {
			return;
		}
		const handler = () => {
			props.onClick();
			onApplePayButtonClicked();
		};
		button.addEventListener( 'click', handler );
		return () => button.removeEventListener( 'click', handler );
	}, [] );

	return React.createElement( 'apple-pay-button', {
		style: {
			'--apple-pay-button-width': '100%',
			'--apple-pay-button-height': '60px',
			'--apple-pay-button-border-radius': '8px',
			'--apple-pay-button-padding': '0px 0px',
			'--apple-pay-button-box-sizing': 'border-box',
		},
		buttonstyle: 'black',
		type: 'plain',
		id: 'apple-pay-button',
		name: 'apple-pay-button',
		locale: 'en-GB',
		ref: applePayButtonRef,
	} );
};

const DirectApplePayBlock = {
	name: applepaysetting.name,
	label: applepaysetting.title,
	title: applepaysetting.title,
	description: applepaysetting.description,
	content: window.wp.element.createElement( ApplePayBlockContent, null ),
	edit: window.wp.element.createElement( ApplePayBlockContent, null ),
	canMakePayment: () => window.ApplePaySession && window.ApplePaySession.canMakePayments(),
	gatewayId: applepaysetting.name,
	paymentMethodID: applepaysetting.name,
	ariaLabel: applepaysetting.title,
	supports: {
		features: applepaysetting.supports,
	},
};

window.wc.wcBlocksRegistry.registerExpressPaymentMethod( DirectApplePayBlock );

// Re-check payment method availability on viewport resize
window.addEventListener( 'resize', () => {
	const canMakePayment = DirectApplePayBlock.canMakePayment();
	// Trigger a re-evaluation by dispatching a custom event that WooCommerce Blocks may listen to
	if ( window.wp && window.wp.hooks ) {
		window.wp.hooks.doAction( 'experimental__woocommerce_blocks-payment-methods-updated' );
	}
} );
