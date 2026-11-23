const directSettings = window.wc.wcSettings.getSetting( localizeDirectBlockVars.pluginID + '_data', {} );

const useCheckoutShared = ( {
    emitResponse,
    select,
    cartStore,
    paymentStore,
    paymentToken,
    openModal,
    setThreeDSData,
} ) => {
    const { useCallback } = wp.element;

    const normalizeErrorMessage = useCallback( ( rawMessage ) => {
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
    }, [] );

    const failCheckoutAndResetReady = useCallback( ( message ) => {
        const errorMessage = normalizeErrorMessage( message ) || 'Payment details error';

        return {
            type: emitResponse.responseTypes.ERROR,
            message: errorMessage,
            errorMessage,
            messageContext: 'wc/checkout/payments',
        };
    }, [ emitResponse.responseTypes.ERROR, normalizeErrorMessage ] );

    const extractCheckoutPaymentDetails = useCallback( ( event ) => {
        const rawPaymentDetails =
            event?.processingResponse?.paymentDetails ||
            event?.processingResponse?.payment_details ||
            event?.paymentResult?.paymentDetails ||
            event?.paymentResult?.payment_details ||
            event?.response?.payment_result?.payment_details ||
            event?.checkoutResponse?.payment_result?.payment_details ||
            {};

        if ( Array.isArray( rawPaymentDetails ) ) {
            return rawPaymentDetails.reduce( ( accumulator, item ) => {
                if ( ! item || 'string' !== typeof item.key ) {
                    return accumulator;
                }

                accumulator[ item.key ] = item.value;
                return accumulator;
            }, {} );
        }

        if ( rawPaymentDetails && 'object' === typeof rawPaymentDetails ) {
            return rawPaymentDetails;
        }

        return {};
    }, [] );

    const process3DS = useCallback( ( threeDSData ) => {
        if ( 'string' === typeof threeDSData ) {
            threeDSData = JSON.parse( threeDSData );
        }

        return new Promise( function ( resolve, reject ) {
            if ( ! threeDSData || ! threeDSData.acsURL ) {
                reject( {
                    message: '3DS data is invalid.',
                } );
                return;
            }

            const allowedOrigins = [ window.location.origin ];

            try {
                const acsOrigin = new URL( threeDSData.acsURL, window.location.origin ).origin;

                if ( ! allowedOrigins.includes( acsOrigin ) ) {
                    allowedOrigins.push( acsOrigin );
                }
            } catch ( error ) {
                reject( {
                    message: '3DS ACS URL is invalid.',
                } );
                return;
            }

            const handleMessage = ( event ) => {
                if ( ! allowedOrigins.includes( event.origin ) ) {
                    return;
                }

                if ( ! event.data || 'object' !== typeof event.data ) {
                    return;
                }

                window.removeEventListener( 'message', handleMessage );

                if ( 'success' === event.data.result ) {
                    openModal( false );
                    resolve( event.data );
                } else {
                    openModal( false );
                    reject( event.data );
                }
            };

            setThreeDSData( {
                threeDSRequest: threeDSData.threeDSRequest,
                acsURL: threeDSData.acsURL,
            } );

            openModal( true );
            window.addEventListener( 'message', handleMessage );
        } );
    }, [ openModal, setThreeDSData ] );

    return {
        failCheckoutAndResetReady,
        extractCheckoutPaymentDetails,
        process3DS,
    };
};

const getSelectedTokenId = ( select, paymentStore ) => {
    const paymentState = select( paymentStore ).getState() || {};
    return paymentState?.paymentMethodData?.token ?? paymentState?.activeSavedToken ?? null;
};

const Content = ( props ) => {
    const { useEffect, useRef, useCallback, useState } = wp.element;
    const { cartStore, paymentStore } = window.wc.wcBlocksData;
    const { select } = window.wp.data;
    const { eventRegistration, emitResponse } = props;
    const { onPaymentSetup, onCheckoutValidation, onCheckoutSuccess, onCheckoutFail } = eventRegistration;
    const hostedFieldsInstance = useRef( null );
    const paymentToken = useRef( {} );
    const handledThreeDSRef = useRef( null );
    const [ modalOpen, openModal ] = useState( false );
    const [ threeDSData, setThreeDSData ] = useState( false );

    const {
        failCheckoutAndResetReady,
        extractCheckoutPaymentDetails,
        process3DS,
    } = useCheckoutShared( {
        emitResponse,
        select,
        cartStore,
        paymentStore,
        paymentToken,
        openModal,
        setThreeDSData,
    } );

    const hostedFieldsInstanceInit = useCallback( async () => {
        hostedFieldsInstance.current = new window.hostedFields.classes.Form( document.getElementById( 'hostedpaymentfields' ), {
            autoSetup: true,
            autoSubmit: false,
            merchantID: directSettings.merchantID,
            fields: {
                any: {
                    style: 'font-family: Helvetica, Arial, sans-serif; font-size: 18px; line-height: 1.4;',
                },
            },
        } );
    }, [ hostedFieldsInstance ] );

    useEffect( () => {
        if ( ! hostedFieldsInstance.current ) {
            hostedFieldsInstanceInit();
        }

        const onCheckoutValidationUnsubscribe = onCheckoutValidation( async () => {
            try {
                const selectedTokenId = getSelectedTokenId( select, paymentStore );

                // Saved-card component handles this flow.
                if ( selectedTokenId && 'new' !== selectedTokenId ) {
                    paymentToken.current = {};
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                    };
                }

                const result = await hostedFieldsInstance.current.getPaymentDetails( {} );

                if ( ! result || ! result.success ) {
                    return failCheckoutAndResetReady( 'Payment details are invalid. Please check your card details.' );
                }

                const deviceInformation = {
                    deviceChannel: 'browser',
                    deviceIdentity: navigator.userAgent ?? null,
                    deviceTimeZone: new Date().getTimezoneOffset(),
                    deviceScreenResolution: `${ window.screen.width }x${ window.screen.height }x${ window.screen.colorDepth }`,
                    deviceAcceptLanguage: navigator.languages ?? null,
                };

                const resultData = {
                    paymentType: 'card_order_payment',
                    cardData: result,
                    deviceInformation,
                    shouldSavePayment: String( !! select( paymentStore ).getState().shouldSavePaymentMethod ),
                };

                paymentToken.current = resultData;

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                };
            } catch ( error ) {
                return failCheckoutAndResetReady( 'Unable to validate payment details. Please try again.' );
            }
        } );

        const onPaymentSetupUnsubscribe = onPaymentSetup( async () => {
            const selectedTokenId = getSelectedTokenId( select, paymentStore );

            // Saved-card component will provide metadata for this case.
            if ( selectedTokenId && 'new' !== selectedTokenId ) {
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                };
            }

            if ( ! paymentToken.current || ! paymentToken.current.cardData ) {
                return failCheckoutAndResetReady( 'Payment details are not ready yet. Please check your card details and try again.' );
            }

            try {
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            paymentData: JSON.stringify( paymentToken.current ),
                            shouldsavepayment: String( !! select( paymentStore ).getState().shouldSavePaymentMethod ),
                        },
                    },
                };
            } catch ( error ) {
                return failCheckoutAndResetReady( 'Unable to prepare checkout payment data. Please try again.' );
            }
        } );

        const handleCheckoutOutcome = async ( event ) => {
            const paymentDetailsObject = extractCheckoutPaymentDetails( event );

            if ( paymentDetailsObject && paymentDetailsObject.threeDSData ) {
                const threeDSPayload = String( paymentDetailsObject.threeDSData );

                if ( handledThreeDSRef.current === threeDSPayload ) {
                    const checkoutFailMessage = event?.processingResponse?.message || event?.message || 'Payment failed. Please try again.';
                    return failCheckoutAndResetReady( checkoutFailMessage );
                }

                handledThreeDSRef.current = threeDSPayload;

                try {
                    const threeDSOutcome = await process3DS( paymentDetailsObject.threeDSData );

                    if ( threeDSOutcome && threeDSOutcome.data && ('captured' === threeDSOutcome.data.paymentResult || 'verified' === threeDSOutcome.data.paymentResult) && threeDSOutcome.data.redirect ) {
                        window.location.replace( threeDSOutcome.data.redirect );
                        return;
                    }

                    const outcomeMessage = threeDSOutcome?.data?.message || 'Something went wrong during 3DS authentication. Please try again.';
                    return failCheckoutAndResetReady( outcomeMessage );
                } catch ( error ) {
                    const message = error?.data?.message || error?.message || 'Payment failed. Please try again.';
                    return failCheckoutAndResetReady( message );
                }
            }

            handledThreeDSRef.current = null;

            if ( ('captured' === paymentDetailsObject.paymentResult || 'verified' === paymentDetailsObject.paymentResult) && paymentDetailsObject.redirect ) {
                window.location.replace( paymentDetailsObject.redirect );
                return;
            }

            const checkoutFailMessage = event?.processingResponse?.message || event?.message || 'Payment failed. Please try again.';
            return failCheckoutAndResetReady( checkoutFailMessage );
        };

        const onCheckoutSuccessUnsubscribe = onCheckoutSuccess( async ( event ) => handleCheckoutOutcome( event ) );
        const onCheckoutFailUnsubscribe = onCheckoutFail( async ( event ) => handleCheckoutOutcome( event ) );

        return () => {
            onCheckoutValidationUnsubscribe();

            if ( 'function' === typeof onPaymentSetupUnsubscribe ) {
                onPaymentSetupUnsubscribe();
            }

            if ( 'function' === typeof onCheckoutSuccessUnsubscribe ) {
                onCheckoutSuccessUnsubscribe();
            }

            if ( 'function' === typeof onCheckoutFailUnsubscribe ) {
                onCheckoutFailUnsubscribe();
            }
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
        extractCheckoutPaymentDetails,
        process3DS,
        hostedFieldsInstanceInit,
        select,
        cartStore,
        paymentStore,
    ] );

    return React.createElement(
        'form',
        { id: 'hostedpaymentfields', name: 'hostedpaymentfields', className: 'wc-credit-card-form wc-payment-form' },
        React.createElement( 'p', null, window.wp.htmlEntities.decodeEntities( directSettings.description || 'Description' ) ),
        React.createElement( cardInputField, { id: 'card_number', placeholder: 'Card number', type: 'hostedfield:cardNumber' } ),
        React.createElement( cardInputField, { id: 'card_expiry', placeholder: 'Expiry', type: 'hostedfield:cardExpiryDate' } ),
        React.createElement( cardInputField, { id: 'card_cvv', placeholder: 'CVV', type: 'hostedfield:cardCVV' } ),
        React.createElement( Modal, { open: modalOpen, component: ThreeDSForm( { data: threeDSData } ) } )
    );
};

const cardInputField = ( { id, label, type, placeholder, required, value, onChange, inputStyle } ) => {
    return React.createElement(
        'div',
        {
            style: {
                width: '100%',
                maxWidth: '260px',
                height: '52px',
                margin: '10px 0',
            },
        },
        React.createElement( 'input', {
            className: 'hostedfield',
            required,
            type,
            id,
            name: id,
            placeholder,
            value,
            onChange,
            style: inputStyle,
        } )
    );
};

const ThreeDSForm = ( { data } ) => {
    const { useEffect, useRef, useState } = wp.element;
    const [ isLoading, setIsLoading ] = useState( true );
    const formFields = [];

    if ( data ) {
        for ( const [ key, item ] of Object.entries( data?.threeDSRequest ) ) {
            formFields.push(
                React.createElement( 'input', {
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
            id: '3ds-window-container',
            style: { position: 'relative', width: '400px', height: '400px', margin: 'auto' },
        },
        React.createElement( 'form', { id: 'threedsform', target: 'threedsiframe', ref: formRef, action: data?.acsURL, method: 'POST' }, formFields ),
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
            name: 'threedsiframe',
            onLoad: handleIframeLoad,
            style: { margin: 'auto', width: '400px', height: '400px', display: 'block', border: 'none', borderRadius: '8px' },
        } ),
        React.createElement(
            'style',
            null,
            `
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `
        )
    );
};

const Modal = ( { component, open, id, style } ) => {
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
                overflow: 'auto',
                backgroundColor: 'rgba(0,0,0,0.4)',
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
                    margin: '10% auto',
                    padding: '5px',
                    borderRadius: '8px',
                    width: 'fit-content',
                    height: 'fit-content',
                },
            },
            component
        )
    );

    return ( window.ReactDOM || wp.element ).createPortal( modalContent, document.body );
};

const SavedTokenComponent = ( props ) => {
    const { useEffect, useRef, useState } = wp.element;
    const { cartStore, paymentStore } = window.wc.wcBlocksData;
    const { select } = window.wp.data;
    const { eventRegistration, emitResponse } = props;
    const { onPaymentSetup, onCheckoutValidation, onCheckoutSuccess, onCheckoutFail } = eventRegistration;
    const paymentToken = useRef( {} );
    const handledThreeDSRef = useRef( null );
    const [ modalOpen, openModal ] = useState( false );
    const [ threeDSData, setThreeDSData ] = useState( false );

    const {
        failCheckoutAndResetReady,
        extractCheckoutPaymentDetails,
        process3DS,
    } = useCheckoutShared( {
        emitResponse,
        select,
        cartStore,
        paymentStore,
        paymentToken,
        openModal,
        setThreeDSData,
    } );

    const cvvCode = useRef( null );

    const handleCVVChange = ( event ) => {
        cvvCode.current = event.target.value;
    };

    useEffect( () => {
        const onCheckoutValidationUnsubscribe = onCheckoutValidation( async () => {
            try {
                const selectedTokenId = getSelectedTokenId( select, paymentStore );
                const cardCVV = ( cvvCode.current || '' ).trim();

                if ( ! selectedTokenId || 'new' === selectedTokenId ) {
                    paymentToken.current = {};
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                    };
                }

                if ( ! cardCVV ) {
                    return failCheckoutAndResetReady( 'Please enter CVV for the selected saved card.' );
                }

                const deviceInformation = {
                    deviceChannel: 'browser',
                    deviceIdentity: navigator.userAgent ?? null,
                    deviceTimeZone: new Date().getTimezoneOffset(),
                    deviceScreenResolution: `${ window.screen.width }x${ window.screen.height }x${ window.screen.colorDepth }`,
                    deviceAcceptLanguage: navigator.languages ?? null,
                };

                const resultData = {
                    paymentType: 'saved_card_order_payment',
                    selectedTokenId,
                    cardCVV,
                    deviceInformation,
                };

                paymentToken.current = resultData;

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                };
            } catch ( error ) {
                return failCheckoutAndResetReady( 'Unable to validate payment details. Please try again.' );
            }
        } );

        const onPaymentSetupUnsubscribe = onPaymentSetup( async () => {
            const selectedTokenId = getSelectedTokenId( select, paymentStore );

            // New-card component handles this flow.
            if ( ! selectedTokenId || 'new' === selectedTokenId ) {
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                };
            }

            if ( ! paymentToken.current || ! paymentToken.current.selectedTokenId || ! paymentToken.current.cardCVV ) {
                return failCheckoutAndResetReady( 'Saved card details are incomplete. Please select a card and enter CVV.' );
            }

            try {
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            paymentData: JSON.stringify( paymentToken.current ),
                        },
                    },
                };
            } catch ( error ) {
                return failCheckoutAndResetReady( 'Unable to prepare checkout payment data. Please try again.' );
            }
        } );

        const handleCheckoutOutcome = async ( event ) => {
            const paymentDetailsObject = extractCheckoutPaymentDetails( event );

            if ( paymentDetailsObject && paymentDetailsObject.threeDSData ) {
                const threeDSPayload = String( paymentDetailsObject.threeDSData );

                if ( handledThreeDSRef.current === threeDSPayload ) {
                    const checkoutFailMessage = event?.processingResponse?.message || event?.message || 'Payment failed. Please try again.';
                    return failCheckoutAndResetReady( checkoutFailMessage );
                }

                handledThreeDSRef.current = threeDSPayload;

                try {
                    const threeDSOutcome = await process3DS( paymentDetailsObject.threeDSData );

                    if ( threeDSOutcome && threeDSOutcome.data && 'captured' === threeDSOutcome.data.paymentResult && threeDSOutcome.data.redirect ) {
                        window.location.replace( threeDSOutcome.data.redirect );
                        return;
                    }

                    const outcomeMessage = threeDSOutcome?.data?.message || 'Something went wrong during 3DS authentication. Please try again.';
                    return failCheckoutAndResetReady( outcomeMessage );
                } catch ( error ) {
                    const message = error?.data?.message || error?.message || 'Payment failed. Please try again.';
                    return failCheckoutAndResetReady( message );
                }
            }

            handledThreeDSRef.current = null;

            if ( 'captured' === paymentDetailsObject.paymentResult && paymentDetailsObject.redirect ) {
                window.location.replace( paymentDetailsObject.redirect );
                return;
            }

            const checkoutFailMessage = event?.processingResponse?.message || event?.message || 'Payment failed. Please try again.';
            return failCheckoutAndResetReady( checkoutFailMessage );
        };

        const onCheckoutSuccessUnsubscribe = onCheckoutSuccess( async ( event ) => handleCheckoutOutcome( event ) );
        const onCheckoutFailUnsubscribe = onCheckoutFail( async ( event ) => handleCheckoutOutcome( event ) );

        return () => {
            onCheckoutValidationUnsubscribe();

            if ( 'function' === typeof onPaymentSetupUnsubscribe ) {
                onPaymentSetupUnsubscribe();
            }

            if ( 'function' === typeof onCheckoutSuccessUnsubscribe ) {
                onCheckoutSuccessUnsubscribe();
            }

            if ( 'function' === typeof onCheckoutFailUnsubscribe ) {
                onCheckoutFailUnsubscribe();
            }
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
        extractCheckoutPaymentDetails,
        process3DS,
        select,
        paymentStore,
    ] );

    return React.createElement(
        'form',
        { id: 'saved-card-cvv', name: 'saved-card-cvv', className: 'wc-credit-card-form wc-payment-form' },
        React.createElement( 'p', null, 'Please enter the CVV for your saved card.' ),
        React.createElement( cardInputField, {
            id: 'card_cvv',
            name: 'card_cvv',
            label: 'CVV',
            placeholder: 'CVV',
            type: 'text',
            required: true,
            value: cvvCode.current,
            onChange: handleCVVChange,
            inputStyle: {
                width: '100%',
                maxWidth: '180px',
                padding: '12px 14px',
                border: '1px solid #c3c4c7',
                borderRadius: '6px',
                backgroundColor: '#fff',
                fontSize: '16px',
                lineHeight: '1.4',
                boxSizing: 'border-box',
            },
        } ),
        React.createElement( Modal, { open: modalOpen, component: ThreeDSForm( { data: threeDSData } ) } )
    );
};

const DirectCheckoutBlock = {
    name: directSettings.name,
    label: directSettings.title,
    savedTokenComponent: Object( window.wp.element.createElement )( SavedTokenComponent, null ),
    content: Object( window.wp.element.createElement )( Content, null ),
    edit: Object( window.wp.element.createElement )( Content, null ),
    canMakePayment: () => true,
    gatewayId: directSettings.name,
    paymentMethodID: directSettings.name,
    placeOrderButtonLabel: window.wp.i18n.__( 'Place Order and Pay', 'my_custom_gateway' ),
    ariaLabel: directSettings.title,
    supports: {
        features: directSettings.supports,
        showSaveOption: document.body.classList.contains( 'logged-in' ),
        showSavedCards: document.body.classList.contains( 'logged-in' ),
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod( DirectCheckoutBlock );
