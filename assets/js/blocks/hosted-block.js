const hostedSettings = window.wc.wcSettings.getSetting( localizeHostedBlockVars.pluginID + '_data', {} );
const isModalType = hostedSettings.type === 'modal';

const HostedModalContent = ( props ) => {
	const { useEffect, useCallback, useState, useRef } = wp.element;
	const { eventRegistration, emitResponse } = props;
	const { onPaymentSetup, onCheckoutValidation, onCheckoutSuccess, onCheckoutFail } = eventRegistration;
	const handledHostedRef = useRef( null );
	const [ modalOpen, openModal ] = useState( false );
	const [ hostedFormData, setHostedFormData ] = useState( false );

	const normalizeErrorMessage = useCallback( ( rawMessage ) => {
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
	}, [] );

	const failCheckoutAndResetReady = useCallback( ( message ) => {
		const errorMessage = normalizeErrorMessage( message ) || 'Payment details error';
		openModal( false );
		return {
			type: emitResponse.responseTypes.ERROR,
			message: errorMessage,
			errorMessage,
			messageContext: 'wc/checkout/payments',
		};
	}, [ emitResponse.responseTypes.ERROR, normalizeErrorMessage ] );

	const processHostedModal = useCallback( ( hostedFormDataPayload ) => {
		let parsedHostedFormData = hostedFormDataPayload;

		if ( typeof parsedHostedFormData === 'string' ) {
			parsedHostedFormData = JSON.parse( parsedHostedFormData );
		}

		return new Promise( function ( resolve, reject ) {
			if ( ! parsedHostedFormData || ! parsedHostedFormData.paymentFormURL ) {
				reject( {
					message: 'Hosted form data is invalid.',
				} );
				return;
			}

			const allowedOrigins = [ window.location.origin ];

			const handleMessage = ( event ) => {
				// Only handle messages from the hosted form iframe.
				// Accessing event.source.name throws SecurityError for cross-origin frames,
				// so treat that as not our iframe and skip.
				try {
					if ( ! event.source || event.source.name !== 'hostedFormIframe' ) {
						return;
					}
				} catch ( e ) {
					return;
				}

				if ( ! allowedOrigins.includes( event.origin ) ) {
					console.warn( 'Received message from unallowed origin:', event.origin );
					return;
				}

				if ( ! event.data || typeof event.data !== 'object' ) {
					console.warn( 'Received message with invalid data format:', event.data );
					return;
				}

				if ( event.data.result !== 'success' && event.data.result !== 'failure' ) {
					console.warn( 'Received message with unexpected result:', event.data.result );
					return;
				}

				window.removeEventListener( 'message', handleMessage );

				if ( event.data.result === 'success' ) {
					openModal( false );
					resolve( event.data );
				} else {
					openModal( false );
					reject( event.data );
				}
			};

			setHostedFormData( {
				hostedFormFields: parsedHostedFormData.hostedRequestFields,
				paymentFormURL: parsedHostedFormData.paymentFormURL,
			} );

			openModal( true );
			window.addEventListener( 'message', handleMessage );
		} );
	}, [ openModal, setHostedFormData ] );

	useEffect( () => {
		const onCheckoutValidationUnsubscribe = onCheckoutValidation( async () => {
			return {
				type: emitResponse.responseTypes.SUCCESS,
			};
		} );

		const onPaymentSetupUnsubscribe = onPaymentSetup( async () => {
			try {
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							paymentData: JSON.stringify( {
								paymentType: 'hosted_order_payment',
							} ),
						},
					},
				};
			} catch ( error ) {
				return failCheckoutAndResetReady( 'Unable to prepare checkout payment data. Please try again.' );
			}
		} );

		// For non-modal (redirect) type, WC Blocks handles the redirect natively.
		// Only register checkout outcome handlers when modal type is configured.
		if ( ! isModalType ) {
			return () => {
				onCheckoutValidationUnsubscribe();
				onPaymentSetupUnsubscribe();
			};
		}

		const handleCheckoutOutcome = async ( event ) => {
			const paymentDetailsObject = event?.processingResponse?.paymentDetails || {};

			if ( paymentDetailsObject && paymentDetailsObject.hostedFormData ) {
				const hostedPayload = String( paymentDetailsObject.hostedFormData );
				if ( handledHostedRef.current === hostedPayload ) {
					const checkoutFailMessage = event?.processingResponse?.message || event?.message || 'Payment failed. Please try again.';
					return failCheckoutAndResetReady( checkoutFailMessage );
				}

				handledHostedRef.current = hostedPayload;
				try {
					const hostedOutcome = await processHostedModal( paymentDetailsObject.hostedFormData );
					if ( hostedOutcome && hostedOutcome.data && (hostedOutcome.data.paymentResult === 'captured' || hostedOutcome.data.paymentResult === 'verified') && hostedOutcome.data.redirect ) {
						window.location.replace( hostedOutcome.data.redirect );
						return;
					}

					const outcomeMessage = hostedOutcome?.data?.message || 'Something went wrong during payment. Please try again.';
					return failCheckoutAndResetReady( outcomeMessage );
				} catch ( error ) {
					const message = error?.data?.message || error?.message || 'Payment failed. Please try again.';
					return failCheckoutAndResetReady( message );
				}
			} else {
				handledHostedRef.current = null;
			}

			if ( (paymentDetailsObject.paymentResult === 'captured' || paymentDetailsObject.paymentResult === 'verified') && paymentDetailsObject.redirect ) {
				window.location.replace( paymentDetailsObject.redirect );
				return;
			}

			const checkoutFailMessage = event?.processingResponse?.message || event?.message || 'Payment failed. Please try again.';
			return failCheckoutAndResetReady( checkoutFailMessage );
		};

		const onCheckoutSuccessUnsubscribe = onCheckoutSuccess( async ( event ) => {
			return handleCheckoutOutcome( event );
		} );

		const onCheckoutFailUnsubscribe = onCheckoutFail( async ( event ) => {
			return handleCheckoutOutcome( event );
		} );

		return () => {
			onCheckoutValidationUnsubscribe();
			onPaymentSetupUnsubscribe();
			onCheckoutSuccessUnsubscribe();
			onCheckoutFailUnsubscribe();
		};
	}, [
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
		emitResponse.responseTypes.FAIL,
		onCheckoutValidation,
		onPaymentSetup,
		onCheckoutSuccess,
		onCheckoutFail,
		failCheckoutAndResetReady,
		processHostedModal,
	] );

	return React.createElement(
		'div',
		{ id: 'hosted-checkout-container' },
		React.createElement( 'p', null, window.wp.htmlEntities.decodeEntities( hostedSettings.description || 'You will be redirected to complete your payment.' ) ),
		isModalType && React.createElement( HostedModal, { open: modalOpen, component: HostedForm( { data: hostedFormData } ) } )
	);
};

const HostedForm = ( { data } ) => {
	const { useEffect, useRef, useState } = wp.element;
	const [ isLoading, setIsLoading ] = useState( true );

	let formFields = [];
	if ( data ) {
		for ( const [ key, item ] of Object.entries( data?.hostedFormFields ) ) {
			formFields.push(
				React.createElement( 'input', {
					key,
					type: 'hidden',
					id: key,
					name: key,
					value: item,
				} )
			);
		}
	}

	const formRef = useRef( null );
	const iframeRef = useRef( null );

	useEffect( () => {
		if ( data ) {
			setIsLoading( true );
			formRef.current.submit();
		}
	}, [ data ] );

	const handleIframeLoad = () => {
		setIsLoading( false );
	};

	return React.createElement(
		'div',
		{
			id: 'hosted-modal-container',
		//	style: { position: 'relative', width: '400px', height: '400px', margin: 'auto' },
		},
		React.createElement( 'form', { id: 'hostedForm', target: 'hostedFormIframe', ref: formRef, action: data?.paymentFormURL, method: 'POST' }, formFields ),
		isLoading && React.createElement(
			'div',
			{
				style: {
					position: 'absolute',
					top: '50%',
					left: '50%',
					transform: 'translate(-50%, -50%)',
					zIndex: '10',
					textAlign: 'center',
				},
			},
			React.createElement( 'div', {
				style: {
					border: '4px solid #f3f3f3',
					borderTop: '4px solid #3498db',
					borderRadius: '50%',
					width: '40px',
					height: '40px',
					animation: 'spin 1s linear infinite',
					margin: '0 auto 10px',
				},
			} ),
			React.createElement( 'p', null, 'Loading...' )
		),
		React.createElement( 'iframe', {
			ref: iframeRef,
			name: 'hostedFormIframe',
			onLoad: handleIframeLoad,
			allow: 'payment *',
			style: { margin: 'auto', width: '430px', height: '820px', display: 'block', border: 'none' },
		} ),
		React.createElement( 'style', null, '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }' )
	);
};

const HostedModal = ( { component, open } ) => {
	if ( ! open ) {
		return null;
	}

	const modalContent = React.createElement(
		'div',
		{
			id: 'modalOverlay',
			style: {
				display: 'block',
				position: 'fixed',
				zIndex: '2147483647',
				left: '0',
				top: '0',
				width: '100%',
				height: '100%',
				overflow: 'hidden',
				backgroundColor: 'rgba(255 255 255 / 19%)',
			},
		},
		React.createElement(
			'div',
			{
				id: 'modalContent',
				style: {
					position: 'relative',
					zIndex: '2147483647',
					backgroundColor: '#fefefe',
					margin: 'auto',
					padding: '5px',
					border: 'none',
					width: 'fit-content',
					boxShadow: '0 5px 15px rgba(0,0,0,0.3)',
					borderRadius: '8px',
				},
			},
			component
		)
	);

	return ( window.ReactDOM || wp.element ).createPortal( modalContent, document.body );
};

const HostedCheckoutBlock = {
	name: hostedSettings.name,
	label: hostedSettings.title,
	content: Object( window.wp.element.createElement )( HostedModalContent, null ),
	edit: Object( window.wp.element.createElement )( HostedModalContent, null ),
	canMakePayment: () => true,
	gatewayId: hostedSettings.name,
	paymentMethodID: hostedSettings.name,
	placeOrderButtonLabel: window.wp.i18n.__( 'Place Order and Pay', 'my_custom_gateway' ),
	ariaLabel: hostedSettings.title,
	supports: {
		features: hostedSettings.supports,
	},
};

window.wc.wcBlocksRegistry.registerPaymentMethod( HostedCheckoutBlock );
