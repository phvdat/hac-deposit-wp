jQuery(function ($) {
    var GATEWAY_ID = 'mecom_paypal_cardfields';
    var iframeReady = false;
    var awaitingApproval = false;
    var iframeMode = 'fields';

    var $form = $('#cs_pay_for_order_page').length ? $('#order_review') : $('form.checkout');

    function loaderTarget() {
        return $('.woocommerce-checkout-payment, .woocommerce-checkout-review-order-table');
    }
    function showLoader() {
        var $t = loaderTarget();
        if (!$t.length || !$.fn.block) return;
        $t.block({
            message: null,
            overlayCSS: { background: '#fff', opacity: 0.6 }
        });
    }
    function hideLoader() {
        var $t = loaderTarget();
        if (!$t.length || !$.fn.unblock) return;
        $t.unblock();
    }

    function currentMethod() {
        return $('input[name="payment_method"]:checked').val();
    }

    function buildFormInfo() {
        var info = {
            email: mecomCfField('billing_email'),
            first_name: mecomCfField('billing_first_name'),
            last_name: mecomCfField('billing_last_name'),
            phone: mecomCfField('billing_phone'),
            address: {
                line1: mecomCfField('billing_address_1'),
                line2: mecomCfField('billing_address_2'),
                city: mecomCfField('billing_city'),
                state: mecomCfField('billing_state'),
                country: mecomCfField('billing_country'),
                postal_code: mecomCfField('billing_postcode')
            },
            shipping_address: null,
            purchase_units: window.mecom_paypal_cf_purchase_units || null,
            currency: window.mecom_paypal_cf_currency || null,
            orderIntent: (window.mecom_paypal_cf_intent || 'CAPTURE').toString().toUpperCase(),
            whitelist_obj: {
                merchant_site: window.location.origin,
                postal_code: mecomCfField('billing_postcode'),
                email: mecomCfField('billing_email'),
                state: mecomCfField('billing_state'),
                city: mecomCfField('billing_city')
            }
        };
        if (mecomCfField('ship_to_different_address') === '1' || $('#ship-to-different-address-checkbox').is(':checked')) {
            info.shipping_address = {
                name: mecomCfField('shipping_first_name') + ' ' + mecomCfField('shipping_last_name'),
                line1: mecomCfField('shipping_address_1'),
                line2: mecomCfField('shipping_address_2'),
                city: mecomCfField('shipping_city'),
                state: mecomCfField('shipping_state'),
                country: mecomCfField('shipping_country'),
                postal_code: mecomCfField('shipping_postcode')
            };
        }
        return info;
    }

    function mecomCfField(name) {
        var el = document.getElementById(name) || document.querySelector('[name="' + name + '"]');
        return el ? el.value : '';
    }

    function postToIframe(msg) {
        var iframe = document.getElementById('mecom-paypal-cf-iframe');
        if (!iframe || !iframe.contentWindow) return;
        iframe.contentWindow.postMessage(msg, '*');
    }

    $form.on('checkout_place_order_' + GATEWAY_ID, function () {
        if (currentMethod() !== GATEWAY_ID) return true;
        if ($form.find('[name="mecom-paypal-cf-order-id"]').val()) return true;
        if (awaitingApproval) return false;
        if (iframeMode === 'fallback-button') return false;

        awaitingApproval = true;
        showLoader();
        postToIframe({ name: 'mecom-paypal-cf-submit', value: buildFormInfo() });
        return false;
    });

    if (!document.getElementById('mecom-cf-place-order-style')) {
        var s = document.createElement('style');
        s.id = 'mecom-cf-place-order-style';
        s.textContent = '.mecom-cf-place-order-hide{display:none !important;}';
        document.head.appendChild(s);
    }
    function applyPlaceOrderVisibility() {
        var $placeOrder = $('#place_order');
        if (!$placeOrder.length) return;
        var shouldHide = iframeMode === 'fallback-button' && currentMethod() === GATEWAY_ID;
        $placeOrder.toggleClass('mecom-cf-place-order-hide', shouldHide);
    }
    $(document.body).on('updated_checkout payment_method_selected', applyPlaceOrderVisibility);
    $form.on('change', 'input[name="payment_method"]', applyPlaceOrderVisibility);

    $(document).on('checkout_error', function () {
        if (currentMethod() === GATEWAY_ID) {
            awaitingApproval = false;
            hideLoader();
            $form.find('[name="mecom-paypal-cf-order-id"]').val('');
            postToIframe({ name: 'mecom-paypal-cf-reset' });
        }
    });

    window.addEventListener('message', function (event) {
        var data = event.data;
        if (!data) return;

        if (data === 'mecom-paypal-cf-iframe-ready' || data.name === 'mecom-paypal-cf-iframe-ready') {
            iframeReady = true;
            return;
        }
        if (data === 'mecom-paypal-cf-button-clicked' || data.name === 'mecom-paypal-cf-button-clicked') {
            if (currentMethod() === GATEWAY_ID) {
                awaitingApproval = true;
                showLoader();
            }
            return;
        }
        if (data === 'mecom-paypal-cf-form-shown' || data.name === 'mecom-paypal-cf-form-shown') {
            hideLoader();
            return;
        }
        if (data === 'mecom-paypal-cf-reset' || data.name === 'mecom-paypal-cf-reset') {
            awaitingApproval = false;
            hideLoader();
            return;
        }
        if (data.name === 'mecom-paypal-cf-mode' && data.value) {
            iframeMode = data.value;
            applyPlaceOrderVisibility();
            return;
        }
        if (data.name === 'mecom-paypal-cf-request-info') {
            postToIframe({ name: 'mecom-paypal-cf-info', value: buildFormInfo() });
            return;
        }
        if (data.name === 'mecom-paypal-cf-approved' && data.value && data.value.order_id) {
            $form.find('[name="mecom-paypal-cf-order-id"]').val(data.value.order_id);
            awaitingApproval = false;
            $form.trigger('submit');
            return;
        }
        if (data.name === 'mecom-paypal-cf-error') {
            awaitingApproval = false;
            hideLoader();
            $form.find('[name="mecom-paypal-cf-order-id"]').val('');
            var msg = data.value || 'Unable to process your card payment. Please try again.';
            $('.woocommerce-notices-wrapper').first().prepend(
                '<div class="woocommerce-error" role="alert">' + msg + '</div>'
            );
            $('html, body').animate({ scrollTop: 0 }, 200);
        }
        if (data.name === 'mecom-paypal-cf-resize' && typeof data.value === 'number') {
            var iframe = document.getElementById('mecom-paypal-cf-iframe');
            if (iframe) iframe.style.height = data.value + 'px';
        }
    });
});
