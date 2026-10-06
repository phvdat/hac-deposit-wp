<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'plugins_loaded', 'cs_pp_register_cardfields_subgateway', 20 );

function cs_pp_register_cardfields_subgateway() {
    if ( ! class_exists( 'WC_MEcom_Gateway' ) || class_exists( 'WC_MEcom_Gateway_CardFields' ) ) {
        return;
    }

    class WC_MEcom_Gateway_CardFields extends WC_MEcom_Gateway {

        public function __construct() {
            $this->id                 = 'mecom_paypal_cardfields';
            $this->icon               = '';
            $this->method_title       = 'CardsShield PayPal — Credit / Debit Cards';
            $this->method_description = 'Debit/Credit card payment via PayPal Advanced Card Fields. Shares configuration with CardsShield Gateway PayPal.';
            $this->has_fields         = true;
            $this->supports           = [
                'products',
                'refunds',
            ];

            $this->init_form_fields();
            $this->init_settings();

            $this->productTitleSetting    = $this->get_option( 'product_title_setting' );
            $this->userDefineProductTitle = $this->get_option( 'user_define_product_title' );
            $this->randomProductTitleList = $this->get_option( 'random_product_title_list' );
            $this->debug                  = 'yes' === $this->get_option( 'debug', 'no' );
            $this->email                  = $this->get_option( 'email' );
            $this->receiver_email         = $this->get_option( 'receiver_email', $this->email );
            $this->identity_token         = $this->get_option( 'identity_token' );
            $this->intent                 = $this->get_option( 'intent' );
            $this->not_send_bill_address_to_paypal = $this->get_option( 'not_send_bill_address_to_paypal' );
            $this->paypal_button          = $this->get_option( 'paypal_button' );
            $this->paypal_button_position = $this->get_option( 'paypal_button_position', OPT_CS_PAYPAL_BUTTON_POSITION_DEFAULT );
            $this->sslverify              = $this->get_option( 'sslverify' );
            $this->title             = $this->get_option( 'cc_title', 'Credit / Debit Cards' );
            $this->description       = $this->get_option( 'cc_description', 'Pay with Credit / Debit Cards' );
            $this->order_button_text = 'Pay now';
        }

        public function get_option_key() {
            return 'woocommerce_mecom_paypal_settings';
        }

        public function init_form_fields() {
            $this->form_fields = [];
        }

        public function admin_options() {
            $settingsUrl = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mecom_paypal&cs_pp_tab=cc' );

            if ( function_exists( 'wc_back_header' ) ) {
                wc_back_header(
                    $this->get_method_title(),
                    __( 'Return to payments', 'woocommerce' ),
                    admin_url( 'admin.php?page=wc-settings&tab=checkout' )
                );
            } else {
                echo '<h2>' . esc_html( $this->get_method_title() ) . '</h2>';
            }

            echo wp_kses_post( wpautop( $this->get_method_description() ) );
            echo '<p><a href="' . esc_url( $settingsUrl ) . '" class="button button-primary">'
                . esc_html__( 'Open Credit Card Settings', 'custom_paypal' ) . '</a></p>';
        }

        public function is_available() {
            try {
                if ( $this->get_option( 'cc_enabled' ) !== 'yes' ) {
                    return false;
                }
                $parent = WC_MEcom_Gateway::load();
                if ( ! $parent->is_valid_for_use() ) {
                    return false;
                }
                return true;
            } catch ( \Throwable $e ) {
                csPaypalErrorLog( [
                    'msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
                ], 'CardFields is_available fatal' );
                return false;
            }
        }

        public function get_icon() {
            $icons     = $this->get_option( 'cc_icons' );
            $icons_str = '';
            if ( is_array( $icons ) ) {
                foreach ( $icons as $index => $icon ) {
                    if ( $index > 3 ) {
                        break;
                    }
                    $icons_str = '<img class="mecom-paypal-payment-icon" src="' . cs_paypal_icon_src('/assets/images/icons/' . $icon . '.svg', __FILE__, OPT_MECOM_PAYPAL_VERSION) . '" style="float: right; border-radius: 2px; max-height: 25px;padding-top: 2px; margin-right: 4px"/>' . $icons_str;
                }
                if ( count( $icons ) > 4 ) {
                    $icons_str = '<img class="mecom-paypal-payment-icon" src="' . cs_paypal_icon_src('/assets/images/icons/' . ( count( $icons ) - 4 ) . '.svg', __FILE__, OPT_MECOM_PAYPAL_VERSION) . '" style="float: right; border-radius: 2px; max-height: 25px;padding-top: 2px; margin-right: 4px"/>' . $icons_str;
                }
            }
            return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
        }

        public function payment_fields() {
            try {
                $this->do_payment_fields();
            } catch ( \Throwable $e ) {
                csPaypalErrorLog( [
                    'msg'   => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ], 'CardFields payment_fields fatal' );
                echo '<div class="mecom-paypal-cardfields-unavailable">' . esc_html__( 'Card payments are temporarily unavailable. Please try another method.', 'mecom' ) . '</div>';
            }
        }

        private function do_payment_fields() {
            if ( $this->description ) {
                echo '<div class="mecom-paypal-cardfields-description">' . wpautop( wp_kses_post( $this->description ) ) . '</div>';
            }

            $nextProxyUrl = WC()->session ? WC()->session->get( 'mecom-paypal-proxy-active-url' ) : null;
            if ( ! $nextProxyUrl ) {
                echo '<div class="mecom-paypal-cardfields-unavailable">' . esc_html__( 'Card payments are temporarily unavailable. Please try another method.', 'mecom' ) . '</div>';
                return;
            }

            $intent   = strtolower( $this->get_option( 'intent' ) ?: OPT_CS_PAYPAL_CAPTURE );
            $currency = get_woocommerce_currency();

            static $cachedPurchaseUnits = null;
            $purchaseUnits = $cachedPurchaseUnits;
            if ( null === $purchaseUnits ) {
                try {
                    if ( isset( $_GET['pay_for_order'] ) && get_query_var( 'order-pay' ) ) {
                        $order = wc_get_order( get_query_var( 'order-pay' ) );
                        if ( $order ) {
                            $purchaseUnits = get_purchase_unit_from_order( $order );
                        }
                    } elseif ( WC()->cart instanceof \WC_Cart ) {
                        $purchaseUnits = get_purchase_unit_from_cart( WC()->cart );
                    }
                } catch ( \Throwable $e ) {
                    csPaypalErrorLog( $e->getMessage(), 'CardFields payment_fields purchase unit error' );
                }
                $cachedPurchaseUnits = $purchaseUnits;
            }
            if ( empty( $purchaseUnits ) ) {
                echo '<div class="mecom-paypal-cardfields-unavailable">' . esc_html__( 'Card payments are temporarily unavailable. Please try another method.', 'mecom' ) . '</div>';
                return;
            }

            $proxyFullUrl = $nextProxyUrl . '/?rest_route=/cs/woo-paypal-cf-get-form&_cf=' . csPpGenerateRandomString( 16 ) . '&intent=' . $intent . '&currency=' . $currency;
            if ( $this->get_option( 'cc_force_button' ) === 'yes' ) {
                $proxyFullUrl .= '&force_button=1';
            }
            ?>
            <input type="hidden" name="mecom-paypal-cf-order-id" value="" />
            <script>
                window.mecom_paypal_cf_purchase_units = <?= wp_json_encode( [ $purchaseUnits ] ) ?>;
                window.mecom_paypal_cf_currency = <?= wp_json_encode( $currency ) ?>;
                window.mecom_paypal_cf_intent = <?= wp_json_encode( strtoupper( $intent ) ) ?>;
            </script>
            <div id="mecom-paypal-cf-container" class="mecom-paypal-cardfields-container">
                <iframe id="mecom-paypal-cf-iframe"
                        referrerpolicy="no-referrer"
                        src="<?= esc_url( $proxyFullUrl ) ?>"
                        height="280"
                        frameBorder="0"
                        style="width: 100%"></iframe>
            </div>
            <?php
            ?>
            <div id="mecom-paypal-cf-loader" role="status" aria-live="polite" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(255,255,255,0.92);align-items:center;justify-content:center;text-align:center;">
                <div style="display:flex;flex-direction:column;align-items:center;gap:18px;max-width:420px;padding:24px;">
                    <div style="width:72px;height:72px;border-radius:50%;border:5px solid rgba(0,0,0,0.15);border-top-color:#2180c0;animation:mecom-cf-loader-spin 0.8s linear infinite;"></div>
                    <p style="margin:0;font-size:15px;line-height:1.45;color:#2c3e50;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
                        <?= esc_html__( "We're processing your payment…", 'mecom' ) ?><br/>
                        <b><?= esc_html__( 'DO NOT close or reload this page!', 'mecom' ) ?></b>
                    </p>
                </div>
            </div>
            <style>@keyframes mecom-cf-loader-spin{to{transform:rotate(360deg);}}</style>
            <?php
        }

        public function process_payment( $order_id ) {
            try {
                return $this->do_process_payment( $order_id );
            } catch ( \Throwable $e ) {
                csPaypalErrorLog( [
                    'msg'   => $e->getMessage(),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ], 'CardFields process_payment fatal' );
                wc_add_notice(
                    __( 'We could not complete your card payment. If you were charged, please contact us before trying again.', 'mecom' ),
                    'error'
                );
                return [ 'result' => 'failure', 'redirect' => '' ];
            }
        }

        private function do_process_payment( $order_id ) {
            global $woocommerce;
            $order = wc_get_order( $order_id );
            csPaypalStoreCustomerIp( $order );

            $ppOrderId = isset( $_POST['mecom-paypal-cf-order-id'] ) ? wc_clean( wp_unslash( $_POST['mecom-paypal-cf-order-id'] ) ) : '';
            if ( empty( $ppOrderId ) ) {
                wc_add_notice( __( 'We could not process your card payment. Please try again.', 'mecom' ), 'error' );
                return [ 'result' => 'failure', 'redirect' => '' ];
            }

            $isEnableEndpointMode = isCsPaypalEnableEndpointMode();
            if ( $isEnableEndpointMode ) {
                $shieldUrl      = WC()->session->get( 'mecom-paypal-proxy-active-url' );
                $activatedProxy = $shieldUrl ? [ 'id' => null, 'url' => $shieldUrl ] : null;
            } else {
                $activeProxyId  = WC()->session->get( 'mecom-paypal-proxy-active-id' );
                $activatedProxy = findActivatedProxyDataById( get_option( OPT_MECOM_PAYPAL_PROXIES, [] ), $activeProxyId );
            }
            if ( null === $activatedProxy ) {
                wc_add_notice( __( 'We cannot process your card payment right now. Please try another payment method.', 'mecom' ), 'error' );
                return [ 'result' => 'failure', 'redirect' => '' ];
            }
            $getActivateProxyUrl = $activatedProxy['url'];

            $order->update_meta_data( METAKEY_PAYPAL_PROXY_URL, $getActivateProxyUrl );
            $order->update_meta_data( METAKEY_PAYPAL_PROXY_ID, $activatedProxy['id'] );
            $order->update_meta_data( METAKEY_CS_PAYPAL_INTENT, $this->get_option( 'intent' ) );
            $order->update_meta_data( '_shield_payment_method', 'paypal_cardfields' );
            $order->update_meta_data( '_shield_payment_url', $getActivateProxyUrl );
            $order->save_meta_data();

            $purchaseUnits = get_purchase_unit_from_order( $order );
            if ( $this->get_option( 'not_send_bill_address_to_paypal' ) === 'yes' ) {
                unset( $purchaseUnits['shipping']['address'] );
            }

            $billingInfo = sprintf(
                'billing[name]=%s&billing[address_line1]=%s&billing[address_line2]=%s&billing[address_city]=%s&billing[address_country]=%s&billing[address_zip]=%s&billing[email]=%s&billing[address_state]=%s',
                rawurlencode( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                rawurlencode( $order->get_billing_address_1() ),
                rawurlencode( $order->get_billing_address_2() ),
                rawurlencode( $order->get_billing_city() ),
                rawurlencode( $order->get_billing_country() ),
                rawurlencode( $order->get_billing_postcode() ),
                rawurlencode( $order->get_billing_email() ),
                rawurlencode( $order->get_billing_state() )
            );

            $productNames = [];
            foreach ( $order->get_items() as $item ) {
                $product = wc_get_product( $item->get_product_id() );
                if ( $product ) {
                    $productNames[] = $this->getProductTitle( $product->get_title(), $order->get_id() ) . ' x ' . $item->get_quantity();
                }
            }

            $orderData = [
                'order_id'                 => $order_id,
                'pp_order_id'              => $ppOrderId,
                'merchant_site'            => get_home_url(),
                'customer_zipcode'         => $order->get_billing_postcode(),
                'customer_email'           => $order->get_billing_email(),
                'shipping_address_country' => $order->get_shipping_country() ?: $order->get_billing_country(),
                'currency'                 => $order->get_currency(),
                'total'                    => $order->get_total(),
                'invoice_id'               => csPaypalResolveInvoicePrefix( $this->get_option( 'invoice_prefix' ), $order ) . $order->get_order_number(),
                'purchase_units'           => $purchaseUnits,
                'billing_info'             => $billingInfo,
                'items'                    => [ [ 'name' => implode( ', ', $productNames ), 'quantity' => 1, 'total' => $order->get_subtotal() ] ],
                'bfp'                      => WC()->session->get( 'mecom-paypal-browser-fingerprint' ),
            ];

            $action      = $this->get_option( 'intent' ) === OPT_CS_PAYPAL_AUTHORIZE ? 'paypal-cf-authorize-order' : 'paypal-cf-capture-order';
            $urlCheckout = $getActivateProxyUrl . '/?rest_route=/cs/' . $action . '&' . http_build_query( $orderData );

            $order->add_order_note( sprintf( __( 'PayPal Card Fields: starting capture at proxy %s', 'mecom' ), $getActivateProxyUrl ) );

            $request = wp_remote_post( $urlCheckout, [
                'sslverify' => csPaypalGetSSLVerifyStatus(),
                'timeout'   => 45,
                'headers'   => [ 'Content-Type' => 'application/json' ],
                'body'      => json_encode( [
                    'cs_order_detail' => getCsPaypalOrderDetailFromWcOrder( $order ),
                ] ),
            ] );
            if ( is_wp_error( $request ) ) {
                csPaypalErrorLog( $request, 'Card Fields capture request error' );
                $order->update_status( 'failed' );
                wc_add_notice( __( 'We cannot process your card payment right now. Please try another payment method.', 'mecom' ), 'error' );
                forceRotateShield();
                return [ 'result' => 'failure', 'redirect' => '' ];
            }

            $data = json_decode( wp_remote_retrieve_body( $request ) );
            if ( empty( $data ) || $data->status !== 'success' ) {
                $errMsg = isset( $data->error_detail ) ? $data->error_detail : ( isset( $data->code ) ? $data->code : 'Unknown error' );
                $order->add_order_note( sprintf( __( 'PayPal Card Fields charge ERROR by proxy %s, message: %s', 'mecom' ), $getActivateProxyUrl, $errMsg ) );
                $order->update_status( 'failed' );
                csPaypalErrorLog( $request, 'Card Fields capture failed' );
                wc_add_notice( __( 'We cannot process your card payment right now. Please try another payment method.', 'mecom' ), 'error' );
                return [ 'result' => 'failure', 'redirect' => '' ];
            }

            $isAuthorize = $this->get_option( 'intent' ) === OPT_CS_PAYPAL_AUTHORIZE;
            $payment     = $isAuthorize
                ? $data->order->purchase_units[0]->payments->authorizations[0]
                : $data->order->purchase_units[0]->payments->captures[0];

            // Orders v2 trả order.status = COMPLETED ngay cả khi capture bị issuer từ chối, nên
            // status của chính capture/authorization mới là kết quả tiền. Proxy đã chặn, đây là
            // lớp chặn thứ 2 cho trường hợp store chạy với proxy chưa cập nhật.
            // Chỉ chặn khi PayPal nói THẲNG là hỏng. Không đọc được status thì cho qua như code
            // cũ, để một giao dịch thành công không bao giờ bị đánh trượt oan.
            $paymentStatus  = isset( $payment->status ) ? $payment->status : null;
            $badStatuses    = $isAuthorize ? [ 'DENIED', 'VOIDED', 'EXPIRED', 'FAILED' ] : [ 'DECLINED', 'FAILED' ];
            $isPending      = $paymentStatus === 'PENDING' || ( isset( $data->capture_state ) && $data->capture_state === 'pending' );
            $isDeclined     = in_array( $paymentStatus, $badStatuses, true );
            if ( $isDeclined ) {
                $declineCode = isset( $payment->processor_response->response_code ) ? $payment->processor_response->response_code : '-';
                $order->add_order_note( sprintf(
                    __( 'PayPal Card Fields DECLINED by issuer (status: %s, code: %s, ID: %s)', 'mecom' ),
                    $paymentStatus ? $paymentStatus : 'unknown',
                    $declineCode,
                    isset( $payment->id ) ? $payment->id : '-'
                ) );
                $order->update_status( 'failed' );
                csPaypalErrorLog( [
                    'capture_status' => $paymentStatus,
                    'decline_code'   => $declineCode,
                    'payment_id'     => isset( $payment->id ) ? $payment->id : null,
                ], 'Card Fields capture declined' );
                wc_add_notice( __( 'Your card was declined. Please try another card or payment method.', 'mecom' ), 'error' );
                return [ 'result' => 'failure', 'redirect' => '' ];
            }

            $order->update_meta_data( '_shield_paypal_funding_source', $data->order->purchase_units[0]->custom_id ?? 'card' );
            $order->update_meta_data( '_cs_paypal_checkout_page', 'checkout' );
            $order->update_meta_data( METAKEY_PAYPAL_PROCESSING_ORDER_KEY, WC()->session->get( 'mecom-paypal-processing-order-key' ) );

            if ( $isAuthorize ) {
                $order->add_order_note( sprintf( __( 'PayPal Card Fields authorized by proxy %s, ID: %s', 'mecom' ), $getActivateProxyUrl, $payment->id ), 0, false );
                $order->update_status( 'on-hold', 'Payment can be captured.' );
                $order->update_meta_data( METAKEY_CS_PAYPAL_CAPTURED, 'false' );
            } else {
                $order->add_order_note( sprintf( __( 'PayPal Card Fields charged by proxy %s', 'mecom' ), $getActivateProxyUrl ), 0, false );

                if ( isset( $data->seller_receivable_breakdown ) ) {
                    $order->update_meta_data( METAKEY_CS_PAYPAL_FEE, $data->seller_receivable_breakdown->paypal_fee->value ?? '' );
                    $order->update_meta_data( METAKEY_CS_PAYPAL_PAYOUT, $data->seller_receivable_breakdown->net_amount->value ?? '' );
                    $order->update_meta_data( METAKEY_CS_PAYPAL_CURRENCY, $data->seller_receivable_breakdown->paypal_fee->currency_code ?? '' );
                }

                if ( $isPending ) {
                    // Tiền chưa về (PayPal review / eCheck): giữ đơn ở on-hold, chờ PayPal chốt
                    // thay vì đánh dấu đã thanh toán rồi ship hàng.
                    $pendingReason = isset( $payment->status_details->reason ) ? $payment->status_details->reason
                        : ( isset( $data->capture_reason ) ? $data->capture_reason : 'unknown' );
                    $order->add_order_note( sprintf(
                        __( 'PayPal Card Fields capture PENDING (reason: %s, Payment ID: %s). Waiting for PayPal to settle.', 'mecom' ),
                        $pendingReason,
                        $payment->id
                    ) );
                    $order->update_status( 'on-hold', 'Capture pending at PayPal.' );
                } else {
                    $order->add_order_note( sprintf( __( 'PayPal Card Fields charge complete (Payment ID: %s)', 'mecom' ), $payment->id ) );
                    $order->payment_complete();
                }
            }

            try {
                if ( method_exists( $order, 'reduce_order_stock' ) ) {
                    $order->reduce_order_stock();
                } else {
                    wc_reduce_stock_levels( $order_id );
                }
            } catch ( \Throwable $e ) {
                csPaypalErrorLog( $e->getMessage(), 'CardFields reduce_order_stock error' );
            }
            try {
                if ( $isEnableEndpointMode ) {
                    csEndpointPerformShieldRotateByAmount( $order );
                } elseif ( isEnabledAmountRotation() ) {
                    performProxyAmountRotation( $activatedProxy, $order->get_total() );
                    updateRotationAmount( $activatedProxy['id'], $order->get_total() );
                }
            } catch ( \Throwable $e ) {
                csPaypalErrorLog( $e->getMessage(), 'CardFields rotation error' );
            }
            try {
                csPaypalSaveTransactionId( $order, wc_clean( $payment->id ) );
                $order->update_meta_data( METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED );
                $order->save_meta_data();
            } catch ( \Throwable $e ) {
                csPaypalErrorLog( $e->getMessage(), 'CardFields save meta error' );
            }

            try { $woocommerce->cart->empty_cart(); } catch ( \Throwable $e ) {}

            return [
                'result'   => 'success',
                'redirect' => $order->get_checkout_order_received_url(),
            ];
        }
    }
}

add_filter( 'woocommerce_payment_gateways', 'cs_pp_cardfields_register_gateway_class' );

function cs_pp_cardfields_register_gateway_class( $gateways ) {
    if ( class_exists( 'WC_MEcom_Gateway_CardFields' ) && ! in_array( 'WC_MEcom_Gateway_CardFields', $gateways, true ) ) {
        $gateways[] = 'WC_MEcom_Gateway_CardFields';
    }
    return $gateways;
}

add_action( 'woocommerce_checkout_create_order', 'cs_pp_cardfields_set_payment_method_title', 10, 2 );

function cs_pp_cardfields_set_payment_method_title( $order, $data ) {
    if ( ! isset( $data['payment_method'] ) || $data['payment_method'] !== 'mecom_paypal_cardfields' ) {
        return;
    }
    $parentSettings = get_option( 'woocommerce_mecom_paypal_settings', [] );
    $title = isset( $parentSettings['cc_title'] ) && $parentSettings['cc_title'] !== ''
        ? $parentSettings['cc_title']
        : 'Credit / Debit Cards';
    $order->set_payment_method_title( $title );
}

add_filter( 'woocommerce_available_payment_gateways', 'cs_pp_inject_cardfields_subgateway', 20, 1 );

function cs_pp_inject_cardfields_subgateway( $gateways ) {
    if ( is_admin() && ! wp_doing_ajax() ) {
        return $gateways;
    }
    if ( ! class_exists( 'WC_MEcom_Gateway_CardFields' ) ) {
        return $gateways;
    }

    static $running = false;
    if ( $running ) {
        return $gateways;
    }
    $running = true;

    try {
        static $card = null;
        if ( null === $card ) {
            $card = new WC_MEcom_Gateway_CardFields();
        }
        if ( ! $card->is_available() ) {
            $running = false;
            return $gateways;
        }

        $cardFirst = $card->get_option( 'cc_card_first' ) === 'yes';
        $new = [];
        foreach ( $gateways as $key => $gw ) {
            if ( $key === 'mecom_paypal' && $cardFirst ) {
                $new['mecom_paypal_cardfields'] = $card;
                $new[ $key ] = $gw;
            } elseif ( $key === 'mecom_paypal' ) {
                $new[ $key ] = $gw;
                $new['mecom_paypal_cardfields'] = $card;
            } else {
                $new[ $key ] = $gw;
            }
        }
        if ( ! isset( $new['mecom_paypal_cardfields'] ) ) {
            if ( $cardFirst ) {
                $new = [ 'mecom_paypal_cardfields' => $card ] + $new;
            } else {
                $new['mecom_paypal_cardfields'] = $card;
            }
        }
        $running = false;
        return $new;
    } catch ( \Throwable $e ) {
        $running = false;
        if ( function_exists( 'csPaypalErrorLog' ) ) {
            csPaypalErrorLog( [
                'msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            ], 'CardFields inject filter fatal' );
        }
        return $gateways;
    }
}
