jQuery(document).ready(function ($) {
    if ($('#cs_pay_for_order_page').length) {
        var mecom_checkout_form = $('#order_review');
    } else {
        var mecom_checkout_form = $('form.checkout');
    }
    var OPT_CS_PAYPAL_SETTING_CHECKOUT = 'PAYPAL_CHECKOUT';
    var isTwoStepsSmartButtonEnabled = $('#mecom-paypal-2-step-enabled').data('value') === 'yes';
    var isTwoStepsSmartButtonActivated = false;

    syncPaypalPlaceOrderButtonUi();
    syncInlineTwoStepButtonUiIfNeeded();

    mecom_checkout_form.on('checkout_place_order', function () {
        if (
            $('input[name="payment_method"]:checked').val() === 'mecom_paypal' &&
            $('#mecom-paypal-button-setting').data('value') === OPT_CS_PAYPAL_SETTING_CHECKOUT &&
            shouldUsePlaceOrderTwoStepButton() &&
            !isTwoStepsSmartButtonActivated
        ) {
            isTwoStepsSmartButtonActivated = true;
            handleShowHidePaypalButton();
            return false;
        }

        if ($('input[name="payment_method"]:checked').val() === 'mecom_paypal') {
            var paypalPaymentOrderIdEl = mecom_checkout_form.find('[name="mecom-paypal-payment-order-id"]');
            if (validateFormCheckoutPaypal() && paypalPaymentOrderIdEl.length && paypalPaymentOrderIdEl.val().length == 0) {
                csPaypalClientLog({
                    'note': 'can not submit case [name="mecom-paypal-payment-order-id"] not have data',
                    'email': mecomGetUserField('email')
                });
                if(confirm('An error occurred. Please try again!')) {
                    location.reload();
                }
                return false;
            }
            setTimeout(function () {
                if (!window.mecom_paypal_checkout_error) {
                    $('.blockUI').hide();
                    $('#cs-pp-loader').show();
                    setTimeout((function () {
                        $('#cs-pp-loader').hide();
                    }), 30000);
                }
            }, 1000)
        }
    });

    $(document).on('checkout_error', function () {
        if ($('input[name="payment_method"]:checked').val() == 'mecom_paypal') {
            $('#cs-pp-loader').hide();
            $('#cs-pp-loader-credit').hide();
            window.mecom_paypal_checkout_error = true;
        }
    })

    // When the checkout is restored from the browser back/forward (bfcache) cache after
    // returning from the shield/PayPal, the page keeps the frozen "redirecting" overlay and
    // the form's `processing` lock, which makes it look stuck and blocks a new submit.
    window.addEventListener('pageshow', function (event) {
        var navType = (window.performance && performance.getEntriesByType && (performance.getEntriesByType('navigation')[0] || {}).type) || '';
        if (event.persisted || navType === 'back_forward') {
            $('#cs-pp-loader').hide();
            $('#cs-pp-loader-credit').hide();
            mecom_checkout_form.removeClass('processing').unblock();
            window.mecom_paypal_checkout_error = false;
            isTwoStepsSmartButtonActivated = false;
        }
    });

    // Hosted checkout: as soon as the checkout AJAX returns our redirect, force the navigation to the
    // shield ourselves. The custom (FunnelKit) checkout sometimes fails to navigate after a bfcache
    // restore, leaving the page spinning even though the server already returned success in ~1-2s.
    $(document).ajaxComplete(function (event, xhr, settings) {
        if (((settings && settings.url) || '').indexOf('wc-ajax=checkout') === -1) {
            return;
        }
        var res;
        try { res = xhr.responseJSON || JSON.parse(xhr.responseText); } catch (e) { return; }
        if (res && res.result === 'success' && res.type === 'cs-paypal-hosted' && res.redirect) {
            window.location.href = res.redirect;
        }
    });

    if ($('#mecom-paypal-button-setting').data('value') === OPT_CS_PAYPAL_SETTING_CHECKOUT &&
        $('#mecom_express_paypal_current_proxy_url').data('value')) {
        fetch($('#mecom_express_paypal_current_proxy_url').data('value') + "/?rest_route=/cs/paypal-check-account-status").then(response => response.json())
            .then(res => {
                if (res.status !== 'active') {
                    $.ajax({url: '/?mecom-paypal-force-rotate-shield=1&_wpnonce=' + encodeURIComponent((window.csPaypalSecurity && window.csPaypalSecurity.rotateNonce) || ''), method: 'POST'})
                }
            })
            .catch(err => {
                $.ajax({url: '/?mecom-paypal-force-rotate-shield=1&_wpnonce=' + encodeURIComponent((window.csPaypalSecurity && window.csPaypalSecurity.rotateNonce) || ''), method: 'POST'})
            });
    }
    

    setInterval(function () {
        handleShowHidePaypalButton();
    }, 200) // fix monlesacx.com

    $('body').on('updated_checkout', function () {
        syncPaypalPlaceOrderButtonUi();
        syncInlineTwoStepButtonUiIfNeeded();
        handleShowHidePaypalButton();
    });

    $(document).on('payment_method_selected', function () {
        syncPaypalPlaceOrderButtonUi();
        syncInlineTwoStepButtonUiIfNeeded();
        handleShowHidePaypalButton();
    })

    if (window.addEventListener) {
        window.addEventListener("message", listenerPaypal);
    } else {
        window.attachEvent("onmessage", listenerPaypal);
    }

    function handleShowHidePaypalButton() {
        var placeOrderButton = $('#place_order');
        var isPaypalSelected = $('input[name="payment_method"]:checked').val() == 'mecom_paypal';
        var isPaypalCheckoutButton = $('#mecom-paypal-button-setting').data('value') === OPT_CS_PAYPAL_SETTING_CHECKOUT;

        if (isPaypalSelected && isPaypalCheckoutButton) {
            if (shouldUseInlineTwoStepButton()) {
                $('#mecom-paypal-2-step-trigger-container').toggle(!isTwoStepsSmartButtonActivated);
                $('#mecom-paypal-credit-form-container').toggle(isTwoStepsSmartButtonActivated);
                placeOrderButton.addClass('important-hide');
            } else if (shouldUsePlaceOrderTwoStepButton()) {
                $('#mecom-paypal-2-step-trigger-container').hide();
                $('#mecom-paypal-credit-form-container').toggle(isTwoStepsSmartButtonActivated);
                placeOrderButton.toggleClass('important-hide', isTwoStepsSmartButtonActivated);
            } else {
                $('#mecom-paypal-2-step-trigger-container').hide();
                $('#mecom-paypal-credit-form-container').show();
                placeOrderButton.addClass('important-hide');
            }
        } else {
            isTwoStepsSmartButtonActivated = false;
            $('#mecom-paypal-2-step-trigger-container').hide();
            $('#mecom-paypal-credit-form-container').hide();
            placeOrderButton.removeClass('important-hide');
        }
    }

    function syncPaypalPlaceOrderButtonUi() {
        var placeOrderButton = $('#place_order');
        var nextText;

        if (!placeOrderButton.length) {
            return;
        }

        if (
            $('input[name="payment_method"]:checked').val() === 'mecom_paypal' &&
            $('#mecom-paypal-button-setting').data('value') === OPT_CS_PAYPAL_SETTING_CHECKOUT &&
            shouldUsePlaceOrderTwoStepButton() &&
            !isTwoStepsSmartButtonActivated
        ) {
            nextText = getTwoStepButtonText();
        } else {
            nextText = getPlaceOrderDefaultText();
        }

        if (getButtonText(placeOrderButton) !== nextText) {
            setButtonText(placeOrderButton, nextText);
        }
    }

    function syncInlineTwoStepButtonUiIfNeeded() {
        var placeOrderButton = $('#place_order');
        var triggerContainer = $('#mecom-paypal-2-step-trigger-container');
        var triggerButton;
        var sourceSignature;

        if (!triggerContainer.length) {
            return;
        }

        if (!shouldUseInlineTwoStepButton()) {
            triggerContainer.empty();
            return;
        }

        if (!placeOrderButton.length) {
            triggerContainer.empty();
            return;
        }

        sourceSignature = [
            placeOrderButton.prop('tagName'),
            placeOrderButton.attr('class') || '',
            placeOrderButton.attr('style') || ''
        ].join('|');

        triggerButton = triggerContainer.children('#mecom-paypal-2-step-trigger');

        if (!triggerButton.length || triggerButton.data('mecomSourceSignature') !== sourceSignature) {
            triggerContainer.empty();
            triggerButton = placeOrderButton.clone(false, false);

            triggerButton
                .attr('id', 'mecom-paypal-2-step-trigger')
                .attr('type', 'button')
                .removeAttr('name')
                .removeClass('important-hide');

            triggerContainer.append(triggerButton);
        }

        triggerButton.data('mecomSourceSignature', sourceSignature);
        triggerButton.prop('disabled', placeOrderButton.prop('disabled'));
        setButtonText(triggerButton, getTwoStepButtonText());
    }

    function getTwoStepButtonText() {
        return $('#mecom-paypal-2-step-text').data('value') || getPlaceOrderDefaultText();
    }

    function getPlaceOrderDefaultText() {
        var placeOrderButton = $('#place_order');
        var defaultText;

        if (!placeOrderButton.length) {
            return '';
        }

        defaultText = placeOrderButton.data('mecomOriginalText');

        if (!defaultText) {
            defaultText = placeOrderButton.data('value') || placeOrderButton.attr('value') || getButtonText(placeOrderButton);
            placeOrderButton.data('mecomOriginalText', defaultText);
        }

        return defaultText;
    }

    function getButtonText(button) {
        if (!button.length) {
            return '';
        }

        if (button.is('input')) {
            return button.val() || '';
        }

        return $.trim(button.text());
    }

    function setButtonText(button, text) {
        if (!button.length) {
            return;
        }

        if (button.is('input')) {
            button.val(text);
            return;
        }

        button.text(text);
    }

    function isInlineSmartButtonMode() {
        return $('#mecom-paypal-inline-mode').data('value') === 'yes';
    }

    function shouldUseInlineTwoStepButton() {
        return isTwoStepsSmartButtonEnabled && isInlineSmartButtonMode();
    }

    function shouldUsePlaceOrderTwoStepButton() {
        return isTwoStepsSmartButtonEnabled && !isInlineSmartButtonMode();
    }

    $(document).on('click', '#mecom-paypal-2-step-trigger', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();

        if (isTwoStepsSmartButtonActivated) {
            return false;
        }

        isTwoStepsSmartButtonActivated = true;
        handleShowHidePaypalButton();
        return false;
    });

    function listenerPaypal(event) {
        if (event.data === "mecom-paypalRequestFromBlacklist") {
            setInterval(function () {
                $('#payment-paypal-area').remove();
                $('.cs_pp_element').remove();
                $('.wc_payment_method.payment_method_mecom_paypal, .wc_payment_method.payment_method_mecom_paypal_cardfields').hide();
            }, 100)
        }
        if (event.data === "mecom-paypalOpenCreditForm") {
            validateFormCheckoutPaypal();
            csPaypalClientLog({
                'note': 'mecom-paypalOpenCreditForm',
                'email': mecomGetUserField('email'),
                'validateFormCheckoutPaypal': window.cs_validateFormCheckoutPaypal_debug_string
            });
            $('#payment-paypal-area').attr('height', 400);
        }
        if (event.data === "mecom-paypalOpenCreditFormReject") {
            if (!validateFormCheckoutPaypal()) {
                var msg = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div role="alert"><ul class="woocommerce-error" tabindex="-1">';
                window.cs_validateFormCheckoutPaypal_msg.forEach(function (value, index) {
                    var infoMsg = 'is a required field.'
                    if (value.custom_msg && value.custom_msg !== '') {
                        infoMsg = value.custom_msg;
                    }
                    msg += '<li data-id="' + value.id + '"><a href="#' + value.id + '"><strong>' + value.field_label + '</strong> ' + infoMsg + '</a></li>';
                })
                msg += '</ul></div></div>';
                var existsNotice = $('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout');
                if (existsNotice.length) {
                    existsNotice.remove();
                }
                mecom_checkout_form.prepend(msg);
                mecom_checkout_form.find('.input-text, select, input:checkbox').trigger('validate').trigger('blur');
                var scrollElement = $('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout');
                if (!scrollElement.length) {
                    scrollElement = mecom_checkout_form;
                }
                $.scroll_to_notices(scrollElement);
                csPaypalClientLog({
                    'note': 'mecom-paypalOpenCreditFormReject',
                    'email': mecomGetUserField('email'),
                    'validateFormCheckoutPaypal': window.cs_validateFormCheckoutPaypal_debug_string
                });
            }

            // mecom_checkout_form.submit();
        }
        if (event.data === "mecom-paypalCloseCreditForm") {
            $('#payment-paypal-area').attr('height', 120);
        }
        if (event.data === "mecom-paypalCreateOrderFail") {
            $.ajax({url: '/?mecom-paypal-force-rotate-shield=1&_wpnonce=' + encodeURIComponent((window.csPaypalSecurity && window.csPaypalSecurity.rotateNonce) || ''), method: 'POST'})
        }
        if (event.data === "mecom-paypalMakeFullIframeCreditForm") {
            $('#payment-paypal-area').addClass('full_screen_iframe_paypal_checkout')
        }
        if (event.data === "mecom-paypalMakeIframeCreditFormNormal") {
            $('#payment-paypal-area').removeClass('full_screen_iframe_paypal_checkout')
        }
        if ((typeof event.data === 'object') && event.data.name === 'mecom-paypalBodyResizeCreditForm') {
            if (event.data.value >= 130) {
                $('#payment-paypal-area').attr('height', event.data.value + 10);
            }
        }
        if ((typeof event.data === 'object') && event.data.name === 'mecom-paypalOpenCreditFormFail') {
            csPaypalClientLog({
                'note': 'mecom-paypalOpenCreditFormFail',
                'email': mecomGetUserField('email')
            });
            checkout_error_paypal(event.data.value)
        }
        if ((typeof event.data === 'object') && event.data.name === 'mecom-paypalOpenCreditFormError') {
            $.ajax({
                url: '/?mecom-paypal-button-create-order=1',
                method: 'POST',
                data: {
                    'cs_order': event.data.value,
                    'current_proxy_id': $('#mecom_express_paypal_current_proxy_id').data('value'),
                    'current_proxy_url': $('#mecom_express_paypal_current_proxy_url').data('value')
                }
            })
        }
        if ((typeof event.data === 'object') && event.data.name === 'mecom-paypalApprovedOrder') {
            var orderId = event.data.value.order_id;
            // Shield was rendered by PHP at iframe-render time = shield of the iframe
            // that just created this pp_order_id. Pins shield to pp_order_id even if
            // session shield rotates between approve and submit.
            var shieldUrl = window.mecom_paypal_shield_url || '';
            var shieldId = window.mecom_paypal_shield_id || '';
            csPaypalClientLog({
                'note': 'mecom-paypalApprovedOrder',
                'pp_order_id': orderId,
                'shield_url': shieldUrl,
                'shield_id': shieldId,
                'email': mecomGetUserField('email')
            });
            mecom_checkout_form.find('[name="mecom-paypal-payment-order-id"]').val(orderId);
            mecom_checkout_form.find('[name="mecom-paypal-payment-shield-url"]').val(shieldUrl);
            mecom_checkout_form.find('[name="mecom-paypal-payment-shield-id"]').val(shieldId);
            mecom_checkout_form.removeClass('processing').unblock();
            mecom_checkout_form.submit();
            if (validateFormCheckoutPaypal()) {
                setTimeout(function () {
                    if (!window.mecom_paypal_checkout_error) {
                        $('.blockUI').hide();
                        $('#cs-pp-loader-credit').show();
                        setTimeout((function () {
                            $('#cs-pp-loader-credit').hide();
                        }), 30000);
                    }
                }, 1000)
            }
        }
    }

    if ($('#mecom_enable_paypal_card_payment').length) {
        setInterval(function () {
            if ($('input[name="payment_method"]:checked').val() == 'mecom_paypal'
                && $('#mecom-paypal-button-setting').data('value') === OPT_CS_PAYPAL_SETTING_CHECKOUT
                && $('#payment-paypal-area')[0]) {
                if (validateFormCheckoutPaypal()) {
                    var whitelistPostalCode = null;
                    var whitelistEmail = null;
                    var whitelistState = null;
                    var whitelistCity = null;
                    if (typeof $('#billing_postcode').val() === 'string' && $('#billing_postcode').val().trim().length > 0) {
                        whitelistPostalCode = Sha1.hash($('#billing_postcode').val())
                    }
                    if (typeof $('#billing_email').val() === 'string' && $('#billing_email').val().trim().length > 0) {
                        whitelistEmail = Sha1.hash($('#billing_email').val())
                    }
                    if (typeof $('#billing_state').val() === 'string' && $('#billing_state').val().trim().length > 0) {
                        whitelistState = Sha1.hash($('#billing_state').val().toLowerCase())
                    }
                    if (typeof $('#billing_city').val() === 'string' && $('#billing_city').val().trim().length > 0) {
                        whitelistCity = Sha1.hash($('#billing_city').val().toLowerCase())
                    }
                    var merchantSite = $('#mecom_merchant_site_url').data('value');
                    if (merchantSite.endsWith("/")) {
                      merchantSite = merchantSite.slice(0, -1);
                    }
                    var shippingAddObj = null;
                    if ($('input[name="ship_to_different_address"]').is(':checked')) {
                        shippingAddObj = {
                            name: mecomGetUserFieldShipping('first_name') + ' ' + mecomGetUserFieldShipping('last_name'),
                            city: mecomGetUserFieldShipping('city'),
                            country: mecomGetUserFieldShipping('country'),
                            line1: mecomGetUserFieldShipping('address_1'),
                            line2: mecomGetUserFieldShipping('address_2'),
                            postal_code: mecomGetUserFieldShipping('postcode'),
                            state: mecomGetUserFieldShipping('state'),
                        }
                    }
                    $('#payment-paypal-area')[0].contentWindow.postMessage({
                        name: 'mecom-paypalSendOrderInfo',
                        value: {
                            whitelist_obj: {
                                merchant_site: Sha1.hash(merchantSite),
                                postal_code: whitelistPostalCode,
                                email: whitelistEmail,
                                state: whitelistState,
                                city: whitelistCity,
                            },
                            merchant_token: $('#mecom_merchant_site_encode').data('value'),
                            store_invoice_prefix: $('#mecom-paypal-store-invoice-prefix').data('value') || '',
                            isNotSendAddress: $('#cs_not_send_bill_address_to_paypal').length,
                            purchase_units: window.mecom_paypal_checkout_purchase_units,
                            orderIntent: $('#mecom-paypal-order-intent').data('value'),
                            last_name: mecomGetUserField('last_name'),
                            first_name: mecomGetUserField('first_name'),
                            email: mecomGetUserField('email'),
                            address: {
                                city: mecomGetUserField('city'),
                                country: mecomGetUserField('country'),
                                line1: mecomGetUserField('address_1'),
                                line2: mecomGetUserField('address_2'),
                                postal_code: mecomGetUserField('postcode'),
                                state: mecomGetUserField('state'),
                            },
                            shipping_address: shippingAddObj,
                            phone: mecomGetUserField('phone'),
                        }
                    }, '*')
                } else {
                    $('#payment-paypal-area')[0].contentWindow.postMessage({
                        name: 'mecom-paypalSendOrderInfo',
                        value: null
                    }, '*')
                }
            }
        }, 100);
    }

    function checkFieldValidatedPaypal(target) {
        if (target.length === 0) {
            return true;
        }
        var targetId = target.attr('id');
        window.cs_validateFormCheckoutPaypal_debug_string[targetId] = {
            "1": target.closest('.form-row').length,
            "11": target.closest('.form-row').prop('outerHTML'),
            "2": typeof target.val(),
            "3": target.val(),
            "4": target.val()? target.val().length : 0,
            "5": target.is(':hidden'),
            "6": target.prop('outerHTML'),
        }
        if (target.is(':hidden')) {
            return true;
        }
        var isNotInvalid = !target.closest('.form-row').hasClass('woocommerce-invalid');
        var isNotEmpty = true;
        if (target.closest('.form-row').hasClass('validate-required')) {
            isNotEmpty = (typeof target.val() == 'string') ? target.val().length : false;
        }
        window.cs_validateFormCheckoutPaypal_debug_string[targetId]["isNotInvalid"] = isNotInvalid;
        window.cs_validateFormCheckoutPaypal_debug_string[targetId]["isNotEmpty"] = isNotEmpty;
        if (!isNotInvalid || !isNotEmpty) {
            var label_field = targetId;
            var custom_msg = null;
            if ($('#' + targetId + '_field label')) {
                label_field = $('#' + targetId + '_field label').text().replace(/\s*\*$/, '').trim();
            }
            if (targetId.includes('email') && target.val() && target.val() !== '') {
                custom_msg = 'is invalid.'
            }
            if (targetId.includes('postcode') && target.val() && target.val() !== '') {
                custom_msg = 'is invalid.'
            }
            if (targetId.includes('phone') && target.val() && target.val() !== '') {
                custom_msg = 'is invalid.'
            }
            window.cs_validateFormCheckoutPaypal_msg.push({
                'id': targetId,
                'field_label': label_field,
                'custom_msg': custom_msg
            })
        }
        return isNotInvalid && isNotEmpty;
    }

    function validateFormCheckoutPaypal() {
        var requiredFields = $('form.woocommerce-checkout .validate-required:visible :input');
        requiredFields.each((i, input) => {
            $(input).trigger('validate');
        });
        window.cs_validateFormCheckoutPaypal_debug_string = {};
        window.cs_validateFormCheckoutPaypal_msg = [];
        window.cs_validateFormCheckoutPaypal_debug_string["$('#shipping_city').val()"] = $('#shipping_city').val();
        if ($('#shipping_city').val()) {
            window.cs_validateFormCheckoutPaypal_debug_string["$('#shipping_city').val().toString()"] = $('#shipping_city').val().toString();
        }
        window.cs_validateFormCheckoutPaypal_debug_string["$('#shipping_postcode').val()"] = $('#shipping_postcode').val();
        if ($('#shipping_postcode').val()) {
            window.cs_validateFormCheckoutPaypal_debug_string["$('#shipping_postcode').val().toString()"] = $('#shipping_postcode').val().toString();
        }

        var valid = true;
        valid &&= checkFieldValidatedPaypal($('#billing_first_name'));
        valid &&= checkFieldValidatedPaypal($('#billing_last_name'));
        valid &&= checkFieldValidatedPaypal($('#billing_email'));
        valid &&= ($('#shipping_city').val() && $('#shipping_city').val().toString().length || checkFieldValidatedPaypal($('#billing_city')));
        valid &&= checkFieldValidatedPaypal($('#billing_country'));
        valid &&= ($('#shipping_postcode').val() && $('#shipping_postcode').val().toString().length || checkFieldValidatedPaypal($('#billing_postcode')));
        valid &&= checkFieldValidatedPaypal($('#billing_state'));
        valid &&= checkFieldValidatedPaypal($('#billing_address_1'));
        valid &&= checkFieldValidatedPaypal($('#billing_address_2'));
        valid &&= checkFieldValidatedPaypal($('#billing_phone'));
        valid &&= checkFieldValidatedPaypal($('#shipping_first_name'));
        valid &&= checkFieldValidatedPaypal($('#shipping_last_name'));
        valid &&= checkFieldValidatedPaypal($('#shipping_city'));
        valid &&= checkFieldValidatedPaypal($('#shipping_country'));
        valid &&= checkFieldValidatedPaypal($('#shipping_postcode'));
        valid &&= checkFieldValidatedPaypal($('#shipping_state'));
        valid &&= checkFieldValidatedPaypal($('#shipping_address_1'));
        valid &&= checkFieldValidatedPaypal($('#shipping_address_2'));
        return valid;
    }

    function checkout_error_paypal(error_message) {
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        mecom_checkout_form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
            '<ul class="woocommerce-error">' +
            '<li data-id="billing_last_name">' + error_message + '' +
            '</li>' +
            '</ul>' +
            '</div>'); // eslint-disable-line max-len
        mecom_checkout_form.removeClass('processing').unblock();
        mecom_checkout_form.find('.input-text, select, input:checkbox').trigger('validate').trigger('blur');
        var scrollElement = $('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-NoticeGroup-checkout');
        if (!scrollElement.length) {
            scrollElement = mecom_checkout_form;
        }
        $.scroll_to_notices(scrollElement);
        $(document.body).trigger('checkout_error', [error_message]);
    }

    function mecomGetUserField(fieldName) {
        if ($('#billing_' + fieldName).val() && $('#billing_' + fieldName).val().length > 0) {
            return $('#billing_' + fieldName).val();
        }
        return $('#shipping_' + fieldName).val()
    }
    
    function mecomGetUserFieldShipping(fieldName) {
        if ($('#shipping_' + fieldName).val() && $('#shipping_' + fieldName).val().length > 0) {
            return $('#shipping_' + fieldName).val();
        }
        return $('#billing_' + fieldName).val()
    }
    
    function csPaypalClientLog(data) {
        $.ajax({
            url: '/?mecom-paypal-note-debug=1',
            method: 'POST',
    contentType: 'application/json',
            data: JSON.stringify(data)
        })
    }
});
