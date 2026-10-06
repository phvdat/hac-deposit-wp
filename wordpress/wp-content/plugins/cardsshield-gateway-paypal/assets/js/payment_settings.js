jQuery(document).ready(function ($) {
    csPaypalProductTitleSwitch($('#woocommerce_mecom_paypal_product_title_setting').val());
    csPaypalTwoStepsSmartButtonSwitch();

    $('#woocommerce_mecom_paypal_product_title_setting').on('change', function () {
        csPaypalProductTitleSwitch($(this).val());
    });

    $('#woocommerce_mecom_paypal_paypal_button, #woocommerce_mecom_paypal_enable_2_steps_smart_button').on('change', function () {
        csPaypalTwoStepsSmartButtonSwitch();
    });

    csPaypalButtonTypeSwitch($('#woocommerce_mecom_paypal_paypal_button').val());
    $('#woocommerce_mecom_paypal_paypal_button').on('change', function () {
        csPaypalButtonTypeSwitch($(this).val());
    })

    function csPaypalButtonTypeSwitch(type) {
        if (type === 'PAYPAL_STANDARD' || type === 'PAYPAL_HOSTED_CHECKOUT') {
            $('#woocommerce_mecom_paypal_checkout_button_content').closest('tr').show();
            $('#woocommerce_mecom_paypal_payment_option_desc').closest('tr').show();
            $('#woocommerce_mecom_paypal_paypal_button_position').closest('tr').hide();
        } else {
            $('#woocommerce_mecom_paypal_checkout_button_content').closest('tr').hide();
            $('#woocommerce_mecom_paypal_payment_option_desc').closest('tr').hide();
            $('#woocommerce_mecom_paypal_paypal_button_position').closest('tr').show();
        }
    }

    function csPaypalProductTitleSwitch(type) {
        if (type === 'user_define') {
            $('#woocommerce_mecom_paypal_user_define_product_title').closest('tr').show();
            $('#woocommerce_mecom_paypal_random_product_title_list').closest('tr').show();
        } else {
            $('#woocommerce_mecom_paypal_user_define_product_title').closest('tr').hide();
            $('#woocommerce_mecom_paypal_random_product_title_list').closest('tr').hide();
        }
    }

    function csPaypalTwoStepsSmartButtonSwitch() {
        var isSmartButton = $('#woocommerce_mecom_paypal_paypal_button').val() === 'PAYPAL_CHECKOUT';
        var isTwoStepsEnabled = $('#woocommerce_mecom_paypal_enable_2_steps_smart_button').is(':checked');

        $('#woocommerce_mecom_paypal_enable_2_steps_smart_button').closest('tr').toggle(isSmartButton);
        $('#woocommerce_mecom_paypal_two_steps_smart_button_text').closest('tr').toggle(isSmartButton && isTwoStepsEnabled);
    }

    $('#woocommerce_mecom_paypal_sslverify').closest('tr').hide();
    $('#woocommerce_mecom_paypal_custom_card_icon_css').closest('tr').hide();
    $('#pp_advance_setting_toggle').click(function () {
        $('#woocommerce_mecom_paypal_sslverify').closest('tr').toggle();
        $('#woocommerce_mecom_paypal_custom_card_icon_css').closest('tr').toggle();
    });
});
