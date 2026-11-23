jQuery(document).ready(function ($) {
    const getButtonContainer = () => document.getElementById('apple-pay-button-container');

    const namedFormElement = window.document.forms.order_review || window.document.forms.checkout;
    const formElement = namedFormElement ? $(namedFormElement) : $('form.checkout').first();
    if (!formElement.length) {
        return;
    }

    const storeApiBase = (() => {
        if ( localizeAppleClassicVars.storeApiEndpoint ) {
            return localizeAppleClassicVars.storeApiEndpoint.replace(/\/$/, '');
        }
        const settings = window.wc && window.wc.wcSettings;
        const base = settings && settings.getSetting && settings.getSetting('storeApiEndpoint')
            ? settings.getSetting('storeApiEndpoint')
            : '/wp-json/wc/store/v1';
        return base.replace(/\/$/, '');
    })();

    const storeApiNonce = localizeAppleClassicVars.storeApiNonce || null;

    const storeApiFetch = async (path, options = {}) => {
        const headers = Object.assign(
            { 'Content-Type': 'application/json' },
            options.headers || {}
        );

        if (storeApiNonce) {
            headers.nonce = storeApiNonce;
        }
        if (options.applePayShippingQuote) {
            headers['X-Payment-Module-Apple-Pay-Quote'] = '1';
        }

        const response = await fetch(`${storeApiBase}${path}`, Object.assign({}, options, { headers }));
        if (!response.ok) {
            let message = `Store API error: ${response.status}`;
            try {
                const responseBody = await response.json();
                message = (responseBody && (responseBody.message || responseBody.code))
                    ? String(responseBody.message || responseBody.code)
                    : message;
            } catch (error) {
                try {
                    const responseText = await response.text();
                    if (responseText) {
                        message = responseText;
                    }
                } catch (ignored) {}
            }
            throw new Error(message);
        }

        return response.json();
    };

    const formatMoney = (amountMinor, minorUnit) => {
        const value = Number(amountMinor || 0) / Math.pow(10, minorUnit);
        return value.toFixed(minorUnit);
    };

    const getCartTotals = (cart) => {
        const totals = cart && cart.totals ? cart.totals : {};
        const minorUnit = Number(totals.currency_minor_unit || 2);
        const currency = totals.currency_code;
        const itemsTotal = totals.items_total || totals.items_total_price || 0;
        const shippingTotal = totals.shipping_total || 0;
        const taxTotal = totals.total_tax || 0;
        const totalPrice = totals.total_price || (Number(itemsTotal) + Number(shippingTotal) + Number(taxTotal));

        return { currency, minorUnit, itemsTotal, shippingTotal, taxTotal, totalPrice };
    };

    const getShippingOptionsFromCart = (cart) => {
        const packages = Array.isArray(cart && cart.shipping_rates) ? cart.shipping_rates : [];
        const options = [];
        const selectedIds = [];

        packages.forEach((pkg) => {
            const packageId = pkg.package_id != null ? String(pkg.package_id) : '0';
            const rates = pkg.shipping_rates || pkg.rates || [];
            rates.forEach((rate) => {
                const rateId = rate.rate_id || rate.id;
                if (!rateId) return;
                const optionId = `${packageId}::${rateId}`;
                const label = rate.name || rate.label || 'Shipping';
                const amount = rate.price || rate.cost || rate.amount || 0;
                const isSelected = !!rate.selected;

                options.push({ id: optionId, label, amount, packageId, rateId, selected: isSelected });
                if (isSelected) selectedIds.push(optionId);
            });
        });

        return { options, selectedIds };
    };

    const buildPaymentDetailsFromCart = (cart, selectedOptionId) => {
        const totals = getCartTotals(cart);
        const cartShipping = getShippingOptionsFromCart(cart);
        const selected = selectedOptionId || cartShipping.selectedIds[0];
        const selectedRate = cartShipping.options.find((option) => option.id === selected);
        const shippingAmount = selectedRate ? selectedRate.amount : totals.shippingTotal;

        const displayItems = [];
        if (Number(totals.itemsTotal)) {
            displayItems.push({
                label: 'Subtotal',
                amount: { value: formatMoney(totals.itemsTotal, totals.minorUnit), currency: totals.currency },
            });
        }
        if (Number(shippingAmount)) {
            displayItems.push({
                label: 'Shipping',
                amount: { value: formatMoney(shippingAmount, totals.minorUnit), currency: totals.currency },
            });
        }
        if (Number(totals.taxTotal)) {
            displayItems.push({
                label: 'Tax',
                amount: { value: formatMoney(totals.taxTotal, totals.minorUnit), currency: totals.currency },
            });
        }

        return {
            total: {
                label: 'Total',
                amount: { value: formatMoney(totals.totalPrice, totals.minorUnit), currency: totals.currency },
            },
            displayItems,
            shippingOptions: cartShipping.options.map((option) => ({
                id: option.id,
                label: option.label,
                amount: { value: formatMoney(option.amount, totals.minorUnit), currency: totals.currency },
                selected: option.id === selected,
            })),
        };
    };

    const toApplePayDetails = (details) => ({
        total: {
            label: details.total.label,
            amount: details.total.amount.value,
            type: 'final',
        },
        lineItems: (details.displayItems || []).map((item) => ({
            label: item.label,
            amount: item.amount.value,
            type: 'final',
        })),
        shippingMethods: (details.shippingOptions || []).map((option) => ({
            identifier: option.id,
            label: option.label,
            amount: option.amount.value,
            detail: option.label,
        })),
    });

    const mapApplePayContactToWooAddress = (contact, fallbackContact) => {
        const primary = contact || {};
        const fallback = fallbackContact || {};
        const primaryLines = Array.isArray(primary.addressLines) ? primary.addressLines : [];
        const fallbackLines = Array.isArray(fallback.addressLines) ? fallback.addressLines : [];

        return {
            first_name: primary.givenName || fallback.givenName || '',
            last_name: primary.familyName || fallback.familyName || '',
            company: '',
            address_1: primaryLines[0] || fallbackLines[0] || '',
            address_2: primaryLines[1] || fallbackLines[1] || '',
            city: primary.locality || fallback.locality || '',
            state: primary.administrativeArea || fallback.administrativeArea || '',
            postcode: primary.postalCode || fallback.postalCode || '',
            country: primary.countryCode || fallback.countryCode || '',
            email: primary.emailAddress || fallback.emailAddress || '',
            phone: primary.phoneNumber || fallback.phoneNumber || '',
        };
    };

    const keyValueArrayToObject = (items) => {
        return (Array.isArray(items) ? items : []).reduce((obj, item) => {
            if (!item || typeof item.key !== 'string') return obj;
            let value = item.value;
            if (typeof value === 'string') {
                const trimmed = value.trim();
                if (
                    (trimmed.startsWith('{') && trimmed.endsWith('}')) ||
                    (trimmed.startsWith('[') && trimmed.endsWith(']'))
                ) {
                    try { value = JSON.parse(trimmed); } catch (e) { /* keep original */ }
                }
            }
            obj[item.key] = value;
            return obj;
        }, {});
    };

    const toggleCheckoutOverlay = (checkoutForm, show) => {
        if (!checkoutForm) return;

        const checkoutFormElement = checkoutForm.jquery ? checkoutForm.get(0) : checkoutForm;
        if (!checkoutFormElement) return;

        if (!document.getElementById('checkout-overlay-style')) {
            const style = document.createElement('style');
            style.id = 'checkout-overlay-style';
            style.textContent = '@keyframes checkout-overlay-spin { to { transform: rotate(360deg); } }';
            document.head.appendChild(style);
        }

        let overlay = document.getElementById('checkout-processing-overlay');

        if (show) {
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'checkout-processing-overlay';
                overlay.setAttribute('style', 'position:fixed;inset:0;background:rgba(255,255,255,0.75);z-index:999999;display:flex;align-items:center;justify-content:center;');
                const spinner = document.createElement('div');
                spinner.setAttribute('style', 'width:36px;height:36px;border:3px solid #d0d0d0;border-top-color:#2271b1;border-radius:50%;animation:checkout-overlay-spin .8s linear infinite;');
                overlay.appendChild(spinner);
                document.body.appendChild(overlay);
            }
            checkoutFormElement.setAttribute('aria-busy', 'true');
            const placeOrderButton = document.getElementById('place_order');
            if (placeOrderButton) placeOrderButton.disabled = true;
            return;
        }

        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
        checkoutFormElement.setAttribute('aria-busy', 'false');
        const placeOrderButton = document.getElementById('place_order');
        if (placeOrderButton) placeOrderButton.disabled = false;
    };

    const showCheckoutError = (checkoutForm, messageOrHtml) => {
        const normalizeErrorMessage = (rawMessage) => {
            if (typeof rawMessage !== 'string') return '';
            const withoutTags = rawMessage.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            if (!withoutTags) return '';
            try {
                const textarea = document.createElement('textarea');
                textarea.innerHTML = withoutTags;
                return (textarea.value || '').trim();
            } catch (error) {
                return withoutTags;
            }
        };

        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const normalizedMessage = normalizeErrorMessage(messageOrHtml) || 'Payment failed. Please try again.';
        const messageHtml = `\n<div class="wc-block-components-notice-banner is-error" role="alert">\n\t<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false">\n\t\t<path d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path>\n\t</svg>\n\t<div class="wc-block-components-notice-banner__content">\n\t\t${escapeHtml(normalizedMessage)}\t</div>\n</div>\n`;

        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        const noticeGroup = $('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"></div>');
        noticeGroup.html(messageHtml);

        if (checkoutForm.length) {
            checkoutForm.prepend(noticeGroup);
        } else {
            $('.woocommerce-notices-wrapper').first().html(noticeGroup);
        }

        $('html, body').animate({
            scrollTop: (noticeGroup.offset() ? noticeGroup.offset().top : 0) - 120,
        }, 250);

        $(document.body).trigger('checkout_error', [messageHtml]);
    };

    const submitStoreApiCheckout = async (paymentPayload) => {
        const billingAddress = mapApplePayContactToWooAddress(paymentPayload.billingContact, paymentPayload.shippingContact);
        const shippingAddress = mapApplePayContactToWooAddress(paymentPayload.shippingContact, paymentPayload.billingContact);

        return storeApiFetch('/checkout', {
            method: 'POST',
            body: JSON.stringify({
                payment_method: localizeAppleClassicVars.gatewayId,
                billing_address: billingAddress,
                shipping_address: shippingAddress,
                payment_data: [
                    { key: 'paymentType', value: 'apple_pay_order_payment' },
                    { key: 'paymentdata', value: JSON.stringify(paymentPayload) },
                ],
            }),
        });
    };

    const updateCustomerShippingAddress = async (address) => {
        const shippingAddress = {
            country: address.countryCode || '',
            state: address.administrativeArea || '',
            postcode: String(address.postalCode || address.postcode || '').trim(),
            city: address.locality || '',
        };

        return storeApiFetch('/cart/update-customer', {
            method: 'POST',
            applePayShippingQuote: true,
            body: JSON.stringify({ shipping_address: shippingAddress }),
        });
    };

    const selectShippingRate = async (optionId) => {
        if (!optionId || optionId.indexOf('::') === -1) {
            return storeApiFetch('/cart', { applePayShippingQuote: true });
        }

        const [packageId, rateId] = optionId.split('::');

        return storeApiFetch('/cart/select-shipping-rate', {
            method: 'POST',
            applePayShippingQuote: true,
            body: JSON.stringify({
                package_id: packageId,
                rate_id: rateId,
            }),
        });
    };

    // Pre-fetch cart so it's available synchronously when the button is clicked.
    // iOS Safari requires ApplePaySession to be constructed synchronously within
    // a user gesture handler — any await before new ApplePaySession() breaks that.
    let cachedCart = null;
    storeApiFetch('/cart').then((cart) => { cachedCart = cart; }).catch(() => {});

    const onApplePayButtonClicked = () => {
        // Use pre-fetched cart data synchronously.
        const cart = cachedCart || {};
        const totals = getCartTotals(cart);
        const paymentDetails = buildPaymentDetailsFromCart(cart);
        const initialApplePayDetails = toApplePayDetails(paymentDetails);
        let quotedCart = cart;

        let session;
        try {
            session = new window.ApplePaySession(
                3,
                {
                    countryCode: localizeAppleClassicVars.countryCode || 'GB',
                    currencyCode: totals.currency,
                    merchantCapabilities: localizeAppleClassicVars.merchantCapabilities || ['supports3DS'],
                    supportedNetworks: localizeAppleClassicVars.supportedNetworks || ['visa', 'masterCard'],
                    total: initialApplePayDetails.total,
                    lineItems: initialApplePayDetails.lineItems,
                    shippingMethods: initialApplePayDetails.shippingMethods,
                    requiredShippingContactFields: cart && cart.needs_shipping ? ['postalAddress', 'email', 'phone'] : ['email', 'phone'],
                    requiredBillingContactFields: ['postalAddress', 'name', 'phone'],
                }
            );
        } catch (error) {
            showCheckoutError(formElement, 'Apple Pay is not available. Please try again.');
            return;
        }

        session.onvalidatemerchant = async (event) => {
            try {
                const formData = new FormData();
                formData.append('action', 'apple_pay_merchant_validation');
                formData.append('nonce', localizeAppleClassicVars.nonce);
                formData.append('validationURL', event.validationURL);

                const response = await fetch(localizeAppleClassicVars.ajaxurl, {
                    method: 'POST',
                    body: formData,
                });
                const result = await response.json();

                if (!result || !result.success || !result.data || !result.data.merchantSession) {
                    throw new Error('Merchant validation failed');
                }

                session.completeMerchantValidation(result.data.merchantSession);
            } catch (error) {
                session.abort();
                toggleCheckoutOverlay(formElement, false);
                showCheckoutError(formElement, 'Apple Pay merchant validation failed. Please try again.');
            }
        };

        const shippingContactError = () => ({
            newShippingMethods: [],
            newTotal: initialApplePayDetails.total,
            newLineItems: initialApplePayDetails.lineItems,
            errors: [
                new ApplePayError('shippingContactInvalid', 'postalAddress', 'No shipping methods available for your address'),
            ],
        });

        session.onshippingcontactselected = async (event) => {
            try {
                const updatedCart = await updateCustomerShippingAddress(event.shippingContact);
                const updatedDetails = buildPaymentDetailsFromCart(updatedCart);
                const updatedApplePayDetails = toApplePayDetails(updatedDetails);

                if (!updatedApplePayDetails.shippingMethods.length) {
                    session.completeShippingContactSelection(shippingContactError());
                    return;
                }

                quotedCart = updatedCart;
                session.completeShippingContactSelection({
                    newShippingMethods: updatedApplePayDetails.shippingMethods,
                    newTotal: updatedApplePayDetails.total,
                    newLineItems: updatedApplePayDetails.lineItems,
                    errors: [],
                });
            } catch (error) {
                session.completeShippingContactSelection(shippingContactError());
            }
        };

        session.onshippingmethodselected = async (event) => {
            try {
                const optionId = event && event.shippingMethod ? event.shippingMethod.identifier : null;
                const updatedCart = await selectShippingRate(optionId);
                const updatedDetails = buildPaymentDetailsFromCart(updatedCart, optionId);
                const updatedApplePayDetails = toApplePayDetails(updatedDetails);

                quotedCart = updatedCart;
                session.completeShippingMethodSelection(
                    window.ApplePaySession.STATUS_SUCCESS,
                    updatedApplePayDetails.total,
                    updatedApplePayDetails.lineItems
                );
            } catch (error) {
                session.completeShippingMethodSelection(
                    window.ApplePaySession.STATUS_FAILURE,
                    initialApplePayDetails.total,
                    initialApplePayDetails.lineItems
                );
            }
        };

        session.onpaymentauthorized = async (event) => {
            try {
                toggleCheckoutOverlay(formElement, true);

                const finalCart = await storeApiFetch('/cart/update-customer', {
                    method: 'POST',
                    body: JSON.stringify({
                        billing_address: mapApplePayContactToWooAddress(event.payment.billingContact, event.payment.shippingContact),
                        shipping_address: mapApplePayContactToWooAddress(event.payment.shippingContact, event.payment.billingContact),
                    }),
                });
                cachedCart = finalCart;
                const quotedTotals = getCartTotals(quotedCart);
                const finalTotals = getCartTotals(finalCart);
                const quotedRates = getShippingOptionsFromCart(quotedCart).selectedIds.slice().sort();
                const finalRates = getShippingOptionsFromCart(finalCart).selectedIds.slice().sort();
                if (quotedTotals.currency !== finalTotals.currency ||
                    String(quotedTotals.totalPrice) !== String(finalTotals.totalPrice) ||
                    JSON.stringify(quotedRates) !== JSON.stringify(finalRates)) {
                    throw new Error('Your total or shipping method changed after receiving your full address. Please review checkout and try again.');
                }

                const checkoutResponse = await submitStoreApiCheckout(event.payment);
                let normalizedCheckoutResponse = checkoutResponse;
                if (
                    checkoutResponse &&
                    checkoutResponse.payment_result &&
                    Array.isArray(checkoutResponse.payment_result.payment_details)
                ) {
                    normalizedCheckoutResponse = keyValueArrayToObject(checkoutResponse.payment_result.payment_details);
                }

                if (normalizedCheckoutResponse && normalizedCheckoutResponse.redirect) {
                    session.completePayment(window.ApplePaySession.STATUS_SUCCESS);
                    window.location.replace(normalizedCheckoutResponse.redirect);
                    return;
                }

                if (normalizedCheckoutResponse && (normalizedCheckoutResponse.paymentResult === 'captured' || normalizedCheckoutResponse.paymentResult === 'verified')) {
                    session.completePayment(window.ApplePaySession.STATUS_SUCCESS);
                    if (checkoutResponse && checkoutResponse.redirect) {
                        window.location.replace(checkoutResponse.redirect);
                    }
                    return;
                }

                throw new Error(
                    (normalizedCheckoutResponse && (normalizedCheckoutResponse.message || normalizedCheckoutResponse.messages)) ||
                    'Payment failed.'
                );
            } catch (error) {
                session.completePayment(window.ApplePaySession.STATUS_FAILURE);
                toggleCheckoutOverlay(formElement, false);
                showCheckoutError(formElement, error.message || 'Payment failed. Please try again.');
            }
        };

        session.oncancel = () => {
            toggleCheckoutOverlay(formElement, false);
        };

        session.begin();
    };

    const createAndRenderPayButton = () => {
        const container = getButtonContainer();
        if (!container) return;

        if (container.querySelector('apple-pay-button')) return;

        if (!window.ApplePaySession || !window.ApplePaySession.canMakePayments()) {
            return;
        }

        // Refresh cached cart data so click handler always has up-to-date totals.
        storeApiFetch('/cart').then((cart) => { cachedCart = cart; }).catch(() => {});

        const button = document.createElement('apple-pay-button');
        button.setAttribute('buttonstyle', 'black');
        button.setAttribute('type', 'plain');
        button.setAttribute('locale', 'en-UK');
        button.style.setProperty('--apple-pay-button-width', '100%');
        button.style.setProperty('--apple-pay-button-height', '50px');
        button.addEventListener('click', onApplePayButtonClicked);
        container.appendChild(button);
    };

    createAndRenderPayButton();

    $(document.body).on('checkout_error updated_checkout', function () {
        createAndRenderPayButton();
    });

    $(document.body).on('payment_method_selected', function () {
        createAndRenderPayButton();
    });

    $(document).on('change', 'input[name="payment_method"]', function () {
        createAndRenderPayButton();
    });

    window.addEventListener('resize', createAndRenderPayButton);
});
