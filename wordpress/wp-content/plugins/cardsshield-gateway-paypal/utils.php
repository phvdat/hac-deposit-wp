<?php

const OPT_MECOM_PAYPAL_VERSION                     = '2.10.12';
const OPT_MECOM_PAYPAL_PROXIES                     = 'OPT_MECOM_PAYPAL_PROXIES';
const OPT_MECOM_PAYPAL_UNUSED_PROXIES              = 'OPT_MECOM_PAYPAL_UNUSED_PROXIES';
const OPT_MECOM_PAYPAL_ACTIVATED_PROXY             = 'OPT_MECOM_PAYPAL_ACTIVATED_PROXY';
const OPT_MECOM_PAYPAL_CURRENT_ROTATION_VALUE      = 'OPT_MECOM_PAYPAL_CURRENT_ROTATION_VALUE';
const OPT_MECOM_PAYPAL_ROTATION_METHOD             = 'OPT_MECOM_PAYPAL_ROTATION_METHOD';
const OPT_MECOM_PAYPAL_CONNECTION_MODE            = 'OPT_MECOM_PAYPAL_CONNECTION_MODE';
const OPT_MECOM_PAYPAL_LAST_TIME_RESET_PAID_AMOUNT = 'OPT_MECOM_PAYPAL_LAST_TIME_RESET_PAID_AMOUNT';

const METAKEY_PAYPAL_PROXY_URL          = '_mecom_paypal_proxy_url';
const METAKEY_PAYPAL_PROXY_ID          = '_mecom_paypal_proxy_id';
const METAKEY_PAYPAL_SYNC_TRACKING_INFO = '_mecom_paypal_sync_tracking_info';
const METAKEY_PAYPAL_PROCESSING_ORDER_KEY = '_METAKEY_PAYPAL_PROCESSING_ORDER_KEY';

const METAKEY_CS_PAYPAL_FEE    = '_cs_paypal_fee';
const METAKEY_CS_PAYPAL_PAYOUT = '_cs_paypal_payout';
const METAKEY_CS_PAYPAL_CURRENCY  = '_cs_paypal_currency';
const METAKEY_CS_PAYPAL_INTENT  = '_cs_paypal_intent';
const METAKEY_CS_PAYPAL_CAPTURED  = '_cs_paypal_captured';

const OPT_CS_PAYPAL_NOT_SYNCED = 1;
const OPT_CS_PAYPAL_SYNCED     = 2;
const OPT_CS_PAYPAL_SYNC_ERROR = 99;

const CS_PAYPAL_SYNC_BATCH_SIZE      = 120;
const CS_PAYPAL_SYNC_COUNT_TRANSIENT = 'cs_paypal_sync_order_count';
const CS_PAYPAL_EXCLUDED_STATUSES    = ['wc-cancelled', 'wc-failed', 'wc-pending', 'wc-refunded', 'wc-on-hold'];
const CS_PAYPAL_EXCLUDED_STATUSES_HPOS = ['cancelled', 'failed', 'pending', 'refunded', 'on-hold'];

const OPT_CS_PAYPAL_BY_TIME   = "by_time";
const OPT_CS_PAYPAL_BY_AMOUNT = "by_amount";
const OPT_CS_PAYPAL_ENDPOINT_TOKEN = "OPT_CS_PAYPAL_ENDPOINT_TOKEN";
const OPT_CS_PAYPAL_ENDPOINT_SECRET= "OPT_CS_PAYPAL_ENDPOINT_SECRET";

const OPT_CS_PAYPAL_CONNECTION_MODE_SHIELD_DOMAINS   = "shield_domains";
const OPT_CS_PAYPAL_CONNECTION_MODE_ENDPOINT_TOKEN = "endpoint_token";

const OPT_CS_PAYPAL_AUTHORIZE = "AUTHORIZE";
const OPT_CS_PAYPAL_CAPTURE   = "CAPTURE";

const OPT_CS_PAYPAL_SETTING_STANDARD = "PAYPAL_STANDARD";
const OPT_CS_PAYPAL_SETTING_CHECKOUT   = "PAYPAL_CHECKOUT";
const OPT_CS_PAYPAL_SETTING_HOSTED   = "PAYPAL_HOSTED_CHECKOUT";
const OPT_CS_PAYPAL_BUTTON_POSITION_DEFAULT = "default";

if (!function_exists('csPaypalIsHostedCheckout')) {
    function csPaypalIsHostedCheckout() {
        $obj = WC_MEcom_Gateway::load();
        return $obj && $obj->get_option('paypal_button') === OPT_CS_PAYPAL_SETTING_HOSTED;
    }
}

const OPT_CS_PAYPAL_BUTTON_POSITION_INLINE = "inline";

const OPT_CS_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING='opt_cs_tracking_sync_plugin_advanced_shipment_tracking';
const OPT_CS_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING='opt_cs_tracking_sync_plugin_orders_tracking';
const OPT_CS_TRACKING_SYNC_PLUGIN_DIANXIAOMI='opt_cs_tracking_sync_plugin_dianxiaomi';
const OPT_CS_PAYMENT_GATEWAY_TYPE_PAYPAL = 1;
const OPT_CS_PAYMENT_GATEWAY_TYPE_PAYPAL_HOSTED = 6;
const OPT_CS_SEND_REQUEST_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36';

function logRotation($rotationMethod, $proxy, $type)
{
    $mecom_log_dir = wp_get_upload_dir()["basedir"] . '/mecom';
    if (!is_dir($mecom_log_dir)) {
        mkdir($mecom_log_dir, 0777, true);
    }
    $logFilePath = $mecom_log_dir . '/' . getLogFileName($rotationMethod);
    $currentDateTime             = date('Y-m-d H:i:s');
    $rotationValue               = $rotationMethod === OPT_CS_PAYPAL_BY_TIME ? $proxy['timestamp'] : ( $proxy['paid_amount'] . '/' . $proxy['amount']);
    $content                     = "{$currentDateTime} - {$proxy['url']} , {$rotationValue} - {$type}\n";

    $fp = fopen($logFilePath, 'a');
    fwrite($fp, $content);
    fclose($fp);
}

function loadLogs($rotationMethod)
{
    $mecomLogDir = wp_get_upload_dir()["basedir"] . '/mecom';
    $logFilePath = $mecomLogDir . '/' . getLogFileName($rotationMethod);
    if (!file_exists($logFilePath)) {
        return [];
    }
    $logArr = explode("\n", file_get_contents($logFilePath));
    // reverse order
    $logArr = array_reverse($logArr);
    $result = [];
    foreach($logArr as $log) {
        if (empty($log)) continue;
        list($time, $proxy, $trigger) = explode(" - ", $log);
        list($proxyUrl, $rotationValue) = explode(" , ", $proxy);
        $result[] = [
            'time' => $time,
            'proxyUrl' => $proxyUrl,
            'rotationValue' => $rotationValue,
            'trigger' => $trigger
        ];
    }
    return $result;

}

function clearLogs($rotationMethod)
{
    $mecomLogDir = wp_get_upload_dir()["basedir"] . '/mecom';
    if (!is_dir($mecomLogDir)) {
        mkdir($mecomLogDir, 0777, true);
    }

    $logFilePath = $mecomLogDir . '/' . getLogFileName($rotationMethod);
    $fp = fopen($logFilePath, 'w');
    fwrite($fp, "");
    fclose($fp);
    return empty(file_get_contents($logFilePath));
}

function getLogFileName($rotationMethod) {
    if ( $rotationMethod === OPT_CS_PAYPAL_BY_TIME) {
        return 'mecom_paypal_rotation_time_log.txt';
    } else if ( $rotationMethod === OPT_CS_PAYPAL_BY_AMOUNT) {
        return 'mecom_paypal_rotation_amount_log.txt';
    }
}

function isEnabledAmountRotation() {
    return OPT_CS_PAYPAL_BY_AMOUNT === get_option(OPT_MECOM_PAYPAL_ROTATION_METHOD, OPT_CS_PAYPAL_BY_TIME);
}

function resetPaidAmountIfNeed() {
    $lastTimeReset = get_option(OPT_MECOM_PAYPAL_LAST_TIME_RESET_PAID_AMOUNT, null);
    $proxies = get_option(OPT_MECOM_PAYPAL_PROXIES, []);
    if (empty($proxies)) {
        return [];
    }
    // Reset
    if (empty($lastTimeReset) || date('Y-m-d') > $lastTimeReset) {
        return resetPaidAmount($proxies);
    }
    return $proxies;
}

function resetPaidAmount($proxies = null)
{
    if (empty($proxies)) {
        $proxies = get_option( OPT_MECOM_PAYPAL_PROXIES, [] );
    }
    if (empty($proxies)) return [];
    // Reset
    $newProxies = array_map(function ($proxy) {
        $proxy['paid_amount'] = 0;
        return $proxy;
    }, $proxies);
    $unusedProxies = get_option(OPT_MECOM_PAYPAL_UNUSED_PROXIES, []);
    if (empty($unusedProxies)) {
        $newUnusedProxies = [];
    } else {
        $newUnusedProxies = array_map(function ($unusedProxy) {
            $unusedProxy['paid_amount'] = 0;
            return $unusedProxy;
        }, $unusedProxies);
    }
    update_option(OPT_MECOM_PAYPAL_PROXIES, $newProxies, true);
    update_option(OPT_MECOM_PAYPAL_UNUSED_PROXIES, $newUnusedProxies, true);
    update_option(OPT_MECOM_PAYPAL_LAST_TIME_RESET_PAID_AMOUNT, date('Y-m-d'), true);
    return $newProxies;
}

function findActivatedProxyDataById($proxies, $activatedProxyId) {
    foreach ($proxies as $proxy) {
        if ($proxy['id'] == $activatedProxyId) {
            return $proxy;
        }
    }
    return null;
}

function getNextProxy($currentProxyId) {
    $proxies = get_option( OPT_MECOM_PAYPAL_PROXIES, [] );
    if (empty($proxies)) return null;
    if ($currentProxyId == end($proxies)['id']) {
        return $proxies[0];
    }

    foreach ($proxies as $key => $proxy) {
        if ($proxy['id'] == $currentProxyId) {
            return $proxies[$key + 1];
        }
    }
    return null;
}

function getNextProxyAmountRotation($activatedProxy, $orderTotal) {
    $proxies = resetPaidAmountIfNeed();
    if ( empty( $proxies ) ) {
        return null;
    }
    $isCurrentProxyMatched = false;
    foreach ( $proxies as $proxy ) {
        if ( $activatedProxy['id'] == $proxy['id'] && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched && isPayableProxy( $proxy, $orderTotal ) ) {
            return $proxy;
        }
    }
    foreach ( $proxies as $proxy ) {
        if ( isPayableProxy( $proxy, $orderTotal ) ) {
            return $proxy;
        }
    }

    return null;
}

function performProxyAmountRotation($activatedProxy, $orderTotal) {
    $proxies = resetPaidAmountIfNeed();
    if ( empty( $proxies ) ) {
        return null;
    }
    $isCurrentProxyMatched = false;
    foreach ( $proxies as $proxy ) {
        if ( $activatedProxy['id'] == $proxy['id']  && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched && isPayableProxy( $proxy, $orderTotal ) ) {
            $activatedProxy = $proxy;
            update_option( OPT_MECOM_PAYPAL_ACTIVATED_PROXY, $activatedProxy, true );
            logRotation( OPT_CS_PAYPAL_BY_AMOUNT, $activatedProxy, "Auto" );

            return $activatedProxy;
        }
    }
    foreach ( $proxies as $proxy ) {
        if ( isPayableProxy( $proxy, $orderTotal ) ) {
            $activatedProxy = $proxy;
            update_option( OPT_MECOM_PAYPAL_ACTIVATED_PROXY, $activatedProxy, true );
            logRotation( OPT_CS_PAYPAL_BY_AMOUNT, $activatedProxy, "Auto" );

            return $activatedProxy;
        }
    }

    return null;
}

function performProxyByTimeRotation($activatedProxy) {
    $proxies = get_option( OPT_MECOM_PAYPAL_PROXIES, [] );
    if ( empty( $proxies ) ) {
        return null;
    }
    $isCurrentProxyMatched = false;
    foreach ( $proxies as $proxy ) {
        if ( $activatedProxy['id'] == $proxy['id']  && !$isCurrentProxyMatched) {
            $isCurrentProxyMatched = true;
            continue;
        }
        if ($isCurrentProxyMatched) {
            $activatedProxy = $proxy;
            update_option( OPT_MECOM_PAYPAL_ACTIVATED_PROXY, $activatedProxy, true );
            logRotation( OPT_CS_PAYPAL_BY_TIME, $activatedProxy, "Auto" );

            return $activatedProxy;
        }
    }
    $activatedProxy = $proxies[0];
    update_option( OPT_MECOM_PAYPAL_ACTIVATED_PROXY, $activatedProxy, true );
    logRotation( OPT_CS_PAYPAL_BY_TIME, $activatedProxy, "Auto" );
    return $activatedProxy;
}

function csDomainForceShieldRotate() {
    $activatedProxy = get_option( OPT_MECOM_PAYPAL_ACTIVATED_PROXY, null );
    $proxies = get_option( OPT_MECOM_PAYPAL_PROXIES, [] );
    if ( empty( $proxies ) ) {
        return null;
    }
    $isCurrentProxyMatched = false;
    if (isset($activatedProxy['id'])) {
        foreach ($proxies as $proxy) {
            if ($activatedProxy['id'] == $proxy['id'] && !$isCurrentProxyMatched) {
                $isCurrentProxyMatched = true;
                continue;
            }
            if ($isCurrentProxyMatched) {
                $activatedProxy = $proxy;
                update_option(OPT_MECOM_PAYPAL_ACTIVATED_PROXY, $activatedProxy, true);

                return $activatedProxy;
            }
        }
    }
    
    $activatedProxy = $proxies[0];
    update_option( OPT_MECOM_PAYPAL_ACTIVATED_PROXY, $activatedProxy, true );
    return $activatedProxy;
}

function isPayableProxy($proxy, $orderTotal) {
    return doubleval( $proxy['paid_amount'] ) + doubleval( $orderTotal ) < doubleval($proxy['amount']);
}

function hasPayableProxy($orderTotal) {
    $proxies = resetPaidAmountIfNeed();
    if (empty($proxies)) {
        return false;
    }

    foreach ($proxies as $proxy) {
        if(isPayableProxy($proxy, $orderTotal)) {
            return true;
        }
    }
    return false;
}

function updateRotationAmount($proxyId, $orderTotal) {
    $activatedProxy = get_option( OPT_MECOM_PAYPAL_ACTIVATED_PROXY, null );
    if ($activatedProxy['id'] == $proxyId) {
        $activatedProxy['paid_amount'] = doubleval($activatedProxy['paid_amount']) + doubleval( $orderTotal );
        update_option(OPT_MECOM_PAYPAL_ACTIVATED_PROXY, $activatedProxy, true);
    }
    $proxies = get_option(OPT_MECOM_PAYPAL_PROXIES, []);
    foreach ($proxies as $key => $proxy) {
        if ($proxy['id'] === $proxyId) {
            $proxies[$key]['paid_amount'] = doubleval($proxy['paid_amount']) + doubleval( $orderTotal );
            break;
        }
    }
    return update_option(OPT_MECOM_PAYPAL_PROXIES, $proxies, true);
}

function getEnabledPaymentGateways() {
    $gateways        = WC()->payment_gateways->get_available_payment_gateways();
    $enabledGateways = [];
    if ( $gateways ) {
        foreach ( $gateways as $gateway ) {

            if ( $gateway->enabled == 'yes' ) {

                $enabledGateways[] = $gateway;

            }
        }
    }
    return $enabledGateways;
}

function getBrowserFingerprint() {
    $browserData = (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '' ) . '|' . 
                   (isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '') . '|' .
                   (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '');
    return sha1($browserData);
}

function csLog($error, $message = '') {
    $logger = wc_get_logger();
    if (is_array($error) || is_object($error)) {
        $error = json_encode($error);
    }
    $error = '\n--------------------- ' . $message . ' ---------------------\n'
             . $error .
             '\n------------------------------------------\n';
    if (empty($logger)) {
        error_log($error);
    } else {
        $logger->debug( $error, [ 'source' => 'cardsshield-gateway-paypal' ] );
    }

}

function moveToUnusedProxyIds($proxyIds) {
    $proxies        = get_option( OPT_MECOM_PAYPAL_PROXIES, [] );
    if (empty($proxies)) {
        $proxies = [];
    }
    $unusedProxies  = get_option( OPT_MECOM_PAYPAL_UNUSED_PROXIES, [] );
    if (empty($unusedProxies)) {
        $unusedProxies = [];
    }
    foreach ( $proxies as $key => $proxy ) {
        if ( in_array( $proxy['id'], $proxyIds ) ) {
            $unusedProxies[] = $proxy;
            unset( $proxies[ $key ] );
        }
    }
    $isSuccess1 = update_option( OPT_MECOM_PAYPAL_PROXIES, array_values($proxies), true );
    $isSuccess2 = update_option( OPT_MECOM_PAYPAL_UNUSED_PROXIES, $unusedProxies, true );
    $proxies        = get_option( OPT_MECOM_PAYPAL_PROXIES, [] );
    if(isset($proxies[0])) {
        update_option( OPT_MECOM_PAYPAL_ACTIVATED_PROXY, $proxies[0], true );
    }
    return $isSuccess1 && $isSuccess2;
}

function moveToUnusedProxyIdsRestrictAccount($proxyIds) {
    $proxies        = get_option( OPT_MECOM_PAYPAL_PROXIES, [] );
    if (empty($proxies)) {
        $proxies = [];
    }
    $unusedProxies  = get_option( OPT_MECOM_PAYPAL_UNUSED_PROXIES, [] );
    if (empty($unusedProxies)) {
        $unusedProxies = [];
    }
    foreach ( $proxies as $key => $proxy ) {
        if ( in_array( $proxy['id'], $proxyIds ) ) {
            $unusedProxies[] = $proxy;
            unset( $proxies[ $key ] );
        }
    }
    $isSuccess1 = update_option( OPT_MECOM_PAYPAL_PROXIES, array_values($proxies), true );
    $isSuccess2 = update_option( OPT_MECOM_PAYPAL_UNUSED_PROXIES, $unusedProxies, true );
    return $isSuccess1 && $isSuccess2;
}

function countOrderNeedSync() {
    $cached = get_transient(CS_PAYPAL_SYNC_COUNT_TRANSIENT);
    if ($cached !== false) return (int) $cached;

    if (get_option('woocommerce_custom_orders_table_enabled') === 'yes') {
        $results = queryOrderNeedSyncPaypalHPOS('count');
    } else {
        $results = queryOrderNeedSyncPaypal('count');
    }
    $count = (int) ($results[0]['count'] ?? 0);
    set_transient(CS_PAYPAL_SYNC_COUNT_TRANSIENT, $count, 60);
    return $count;
}

require_once(plugin_dir_path(__FILE__) . 'cs-woocommerce-orders-tracking/includes/admin/paypal.php');
require_once(plugin_dir_path(__FILE__) . 'cs-woocommerce-orders-tracking/includes/data.php');
require_once(plugin_dir_path(__FILE__) . 'cs-advance-shipment-tracking/cs-advance-shipping-tracking-provider.php');

function syncTrackingInfo() {
    $csPayPalGw = WC()->payment_gateways->payment_gateways()['mecom_paypal'];
    $trackingSyncPlugin = $csPayPalGw->get_option('sync_tracking_plugin');
    if (get_option('woocommerce_custom_orders_table_enabled') === 'yes') {
        $orders = queryOrderNeedSyncPaypalHPOS('get');
    } else {
        $orders = queryOrderNeedSyncPaypal('get');
    }
    $hasError = false;
    $errorOrderIdList = [];
    $shippingData = [];

    foreach ($orders as $order) {
        $orderId = $order['order_id'];
        $order = wc_get_order($orderId);
        $trackingNumberArr = [];

        $processedProxyUrl = $order->get_meta( METAKEY_PAYPAL_PROXY_URL );
        if ( empty( $processedProxyUrl ) ) {
            csPaypalErrorLog('Sync error: Empty proxy url, order_id: ' . $orderId);
            $hasError       = true;
            $errorOrderIdList[] = $orderId;
            $order->update_meta_data( METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_SYNC_ERROR );
            $order->save_meta_data();
            continue;
        }
        if ($order->get_status() === 'on-hold') {
            continue;
        }

        if ($trackingSyncPlugin == OPT_CS_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING) {
            $trackingItems = $order->get_meta('_wc_shipment_tracking_items');
            if (empty($trackingItems)) {
                continue;
            }
            foreach ($trackingItems as $trackingItem) {
                if (empty($trackingItem['tracking_number'])) {
                    continue;
                }
                if (in_array($trackingItem['tracking_number'], $trackingNumberArr)) {
                    continue;
                }
                $trackingNumberArr[] = $trackingItem['tracking_number'];

                if (!empty($trackingItem['custom_tracking_provider'])) {
                    if (stripos($trackingItem['custom_tracking_provider'], 'canada post') !== false || stripos($trackingItem['custom_tracking_provider'], 'xpresspost') !== false) {
                        $trackingItem['tracking_provider'] = 'canada-post';
                    }
                    if (stripos($trackingItem['custom_tracking_provider'], 'usps') !== false) {
                        $trackingItem['tracking_provider'] = 'usps';
                    }
                }

                $ppProvider = CS_ADVANCE_SHIPPING_TRACKING_PROVIDER::get_instance()->getPaypalProvider($trackingItem['tracking_provider']);
                $displayName = $ppProvider->provider_name ?? ($trackingItem['custom_tracking_provider'] ?? '');
                $paypalCarrierOverride = [
                    'ups global'        => 'UPS',
                    'dhl express uk'    => 'DHL',
                    'omniva lithuania'  => 'OMNIVA',
                    'omniva'            => 'OMNIVA',
                    'fedex one balance' => 'FEDEX',
                ];
                $overrideKey = strtolower(trim($displayName));
                if ('' !== $overrideKey && isset($paypalCarrierOverride[$overrideKey])) {
                    if (!is_object($ppProvider)) {
                        $ppProvider = (object) ['paypal_slug' => '', 'provider_url' => '', 'provider_name' => $displayName];
                    }
                    $ppProvider->paypal_slug = $paypalCarrierOverride[$overrideKey];
                }
                $trackingData = [
                    'order_id' => $orderId,
                    'transaction_id' => csPaypalGetTransactionId($order),
                    'tracking_number' => $trackingItem['tracking_number'],
                    'status' => 'SHIPPED',
                ];
                if (isset($ppProvider->paypal_slug) && '' != $ppProvider->paypal_slug) {
                    $trackingData['carrier'] = $ppProvider->paypal_slug;
                    if (isset($ppProvider->provider_url) && '' != $ppProvider->provider_url) {
                        $trackingData['tracking_url'] = str_replace('%number%', $trackingItem['tracking_number'], $ppProvider->provider_url);
                    }
                } else {
                    $trackingData['carrier'] = 'OTHER';
                    $formatted = [];
                    if (function_exists('wc_advanced_shipment_tracking')
                        && isset(wc_advanced_shipment_tracking()->actions)
                        && method_exists(wc_advanced_shipment_tracking()->actions, 'get_formatted_tracking_item')) {
                        $formatted = wc_advanced_shipment_tracking()->actions->get_formatted_tracking_item($orderId, $trackingItem);
                    }
                    $carrierName = '';
                    foreach ([
                        $formatted['formatted_tracking_provider'] ?? null,
                        $trackingItem['custom_tracking_provider'] ?? null,
                        $ppProvider->provider_name ?? null,
                        $trackingItem['tracking_provider'] ?? null,
                    ] as $candidate) {
                        if (!empty($candidate)) {
                            $carrierName = $candidate;
                            break;
                        }
                    }
                    if ('' !== $carrierName) {
                        $trackingData['carrier_name_other'] = $carrierName;
                    }
                    $trackingUrl = '';
                    foreach ([
                        $formatted['formatted_tracking_link'] ?? null,
                        $trackingItem['custom_tracking_link'] ?? null,
                        !empty($ppProvider->provider_url)
                            ? str_replace('%number%', $trackingItem['tracking_number'] ?? '', $ppProvider->provider_url)
                            : null,
                    ] as $candidate) {
                        if (!empty($candidate)) {
                            $trackingUrl = $candidate;
                            break;
                        }
                    }
                    if ('' !== $trackingUrl) {
                        $trackingData['tracking_url'] = $trackingUrl;
                    }
                }
                $shippingData[$processedProxyUrl][] = $trackingData;
            }
        } else if ($trackingSyncPlugin == OPT_CS_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING) {
            if($trackingDataByOrder = $order->get_meta('_wot_tracking_number')) {
                $carrierSlug = $order->get_meta('_wot_tracking_carrier');
                $carrierPaypalName = CS_VI_WOOCOMMERCE_ORDERS_TRACKING_ADMIN_PAYPAL::get_paypal_carrier_by_slug($carrierSlug);
                $trackingData = [
                    'order_id' => $orderId,
                    'transaction_id' => csPaypalGetTransactionId($order),
                    'tracking_number' => $trackingDataByOrder,
                    'status' => 'SHIPPED',
                    'carrier' => $carrierPaypalName,
                ];
                $trackingUrl = null;
                try {
                    $itemTrackingData = CS_VI_WOOCOMMERCE_ORDERS_TRACKING_DATA::get_instance()->get_item_tracking_number($orderId);
                    $trackingUrl = $itemTrackingData['tracking_url_show'] ?? null;
                    csPaypalDebugLog($itemTrackingData, 'Sync get tracking URL  $itemTrackingData');
                } catch (\Exception $e) {
                    csPaypalDebugLog($e->getMessage(), 'Sync get tracking URL exception!');
                }
                if (!empty($trackingUrl)) {
                    $trackingData['tracking_url'] = $trackingUrl;
                }
                if ('OTHER' === $carrierPaypalName) {
                    $trackingData['carrier_name_other'] = $carrierSlug;
                }
                $shippingData[$processedProxyUrl][] = $trackingData;
            } else {
                foreach ( $order->get_items() as $item_id => $item_value ) {
                    $item_tracking_data    = wc_get_order_item_meta( $item_id, '_vi_wot_order_item_tracking_data', true );
                    if ( empty($item_tracking_data )) {
                        continue;
                    }
                    $item_tracking_data    = json_decode( $item_tracking_data, true );
                    $current_tracking_data = array_pop( $item_tracking_data );
                    if (in_array($current_tracking_data['tracking_number'], $trackingNumberArr)) {
                        continue;
                    }
                    $trackingNumberArr[] = $current_tracking_data['tracking_number'];
                    $carrierName = $current_tracking_data['carrier_name'];
                    $carrierPaypalName = CS_VI_WOOCOMMERCE_ORDERS_TRACKING_ADMIN_PAYPAL::get_carrier($carrierName);
                    $trackingData = [
                        'order_id'           => $orderId,
                        'transaction_id'     => csPaypalGetTransactionId($order),
                        'tracking_number'    => $current_tracking_data['tracking_number'],
                        'status'             => 'SHIPPED',
                        'carrier'            => $carrierPaypalName,
                    ];
                    if ('OTHER' === $carrierPaypalName) {
                        $trackingData['carrier_name_other'] = $carrierName;
                    }
                    $trackingUrl = null;
                    try {
                        $itemTrackingData = CS_VI_WOOCOMMERCE_ORDERS_TRACKING_DATA::get_instance()->get_item_tracking_number($orderId, $item_id);
                        $trackingUrl = $itemTrackingData['tracking_url_show'] ?? null;
                        csPaypalDebugLog($itemTrackingData, 'Sync get tracking URL  $itemTrackingData');
                    } catch (\Exception $e) {
                        csPaypalDebugLog($e->getMessage(), 'Sync get tracking URL exception!');
                    }
                    if (!empty($trackingUrl)) {
                        $trackingData['tracking_url'] = $trackingUrl;
                    }
                    $shippingData[$processedProxyUrl][] = $trackingData;
                }   
            }
        } else if ($trackingSyncPlugin == OPT_CS_TRACKING_SYNC_PLUGIN_DIANXIAOMI) {
            $trackingProvider = $order->get_meta('_dianxiaomi_tracking_provider_name');
            $trackingNumber = $order->get_meta('_dianxiaomi_tracking_number');
            if ( empty( $trackingProvider ) || empty($trackingNumber) ) {
                continue;
            }
            $shippingData[$processedProxyUrl][] = [
                'order_id'           => $orderId,
                'transaction_id'     => csPaypalGetTransactionId($order),
                'tracking_number'    => $trackingNumber,
                'status'             => 'SHIPPED',
                'carrier'            => 'OTHER',
                'carrier_name_other' => $trackingProvider,
            ];
        }
    }
    foreach ($shippingData as $proxyUrl => $shippingDataBatch) {
        $shippingData[$proxyUrl] = array_values(array_filter($shippingDataBatch, function ($item) use (&$errorOrderIdList, &$hasError) {
            $isInvalid = 'OTHER' === ($item['carrier'] ?? '') && empty($item['carrier_name_other']);
            if (!$isInvalid) {
                return true;
            }
            $invalidOrderId = $item['order_id'];
            csPaypalErrorLog($item, 'Sync skip: OTHER without carrier_name_other');
            if (!in_array($invalidOrderId, $errorOrderIdList)) {
                $errorOrderIdList[] = $invalidOrderId;
            }
            $hasError = true;
            $invalidOrder = wc_get_order($invalidOrderId);
            if ($invalidOrder) {
                $invalidOrder->update_meta_data(METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_SYNC_ERROR);
                $invalidOrder->save_meta_data();
            }
            return false;
        }));
    }
    csPaypalDebugLog($shippingData, 'Sync data $shippingData INFO');
    foreach ($shippingData as $proxyUrl => $shippingDataBatch) {
        if (isset($shippingDataBatch) && count($shippingDataBatch)) {
            $shippingDataParts = array_chunk($shippingDataBatch, 20);
        } else {
            $shippingDataParts = [];
        }
        
        foreach($shippingDataParts as $shippingDataPart) {
            $orderIds = array_unique(array_map(function ($data) {
                return $data['order_id'];
            }, $shippingDataPart));
            
            // Remove order_id and drop empty optional fields PayPal would reject
            $shippingDataPush = array_map(function ($data) {
                unset($data['order_id']);
                foreach (['tracking_url', 'carrier_name_other'] as $optionalKey) {
                    if (isset($data[$optionalKey]) && '' === $data[$optionalKey]) {
                        unset($data[$optionalKey]);
                    }
                }
                return $data;
            }, $shippingDataPart);
            $requestUrl = $proxyUrl . "?mecom-paypal-sync-tracking=1&" . csPaypalBuildQuery( [
                'data-track' => $shippingDataPush
            ]);
            $response = wp_remote_get($requestUrl, [
                'timeout' => 5 * 60,
            ]);
            
            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                $errorOrderIdList = array_unique(array_merge($errorOrderIdList, $orderIds));
                csPaypalErrorLog([$response, $requestUrl, $orderIds], "Sync data error![1]");
                foreach ($orderIds as $orderId) {
                    $subOrder = wc_get_order($orderId);
                    $subOrder->update_meta_data( METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_SYNC_ERROR );
                    $subOrder->save_meta_data();
                }
                $hasError = true;
                continue;
            }
            $data = json_decode( wp_remote_retrieve_body( $response ) );
            if ( ! $data->success ) {
                $errorOrderIdList = array_unique(array_merge($errorOrderIdList, $orderIds));
                csPaypalErrorLog([$response, $requestUrl, $orderIds], "Sync data error![2]");
                foreach ($orderIds as $orderId) {
                    $subOrder = wc_get_order($orderId);
                    $subOrder->update_meta_data( METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_SYNC_ERROR );
                    $subOrder->save_meta_data();
                }
                $hasError = true;
            } else {
                foreach ($orderIds as $orderId) {
                    if (in_array($orderId, $errorOrderIdList)) {
                        continue;
                    }
                    $subOrder = wc_get_order($orderId);
                    $subOrder->update_meta_data( METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_SYNCED );
                    $subOrder->save_meta_data();
                }
            }
        }
    }
   
    delete_transient(CS_PAYPAL_SYNC_COUNT_TRANSIENT);
    if ($hasError) {
        echo json_encode( [
            'success' => false,
            'error'   => 'Fail synced order_id: ' . implode(', ', array_unique($errorOrderIdList)),
        ] );
    } else {
        echo json_encode( [
            'success' => true,
            'count'   => countOrderNeedSync()
        ] );
    }
}
require_once(plugin_dir_path(__FILE__) . 'vendor/autoload.php');
$bootstrapMecomPaypalModules = require_once(plugin_dir_path(__FILE__) . 'modules/ppcp-api-client/bootstrap.php');
$appContainerMecomPaypalModules = $bootstrapMecomPaypalModules( plugin_dir_path(__FILE__) . 'modules/ppcp-api-client');
    
function get_purchase_unit_from_cart(WC_Cart $cart) {
    global $appContainerMecomPaypalModules;
    $purchaseUnits = $appContainerMecomPaypalModules->get('api.factory.purchase-unit')->from_wc_cart($cart)->to_array();
    $result = [];
    foreach (['amount', 'items'] as $attrToUse) {
        if (isset($purchaseUnits[$attrToUse])) {
            $result[$attrToUse] = $purchaseUnits[$attrToUse];
        }
    }
    if (!empty(WC_MEcom_Gateway::load()->get_option('soft_descriptor'))) {
        $result['soft_descriptor'] = WC_MEcom_Gateway::load()->get_option('soft_descriptor');
    }
    return $result;
}

function get_purchase_unit_from_order(WC_Order $order) {
    global $appContainerMecomPaypalModules;
    $purchaseUnits = $appContainerMecomPaypalModules->get('api.factory.purchase-unit')->from_wc_order($order)->to_array();
        $result = [];
    foreach (['amount', 'items', 'invoice_id'] as $attrToUse) {
        $result[$attrToUse] = $purchaseUnits[$attrToUse];
    }
    // The factory builds invoice_id from this store's own prefix; the endpoint prefix wins when set.
    $csEndpointInvoicePrefix = csPaypalGetEndpointInvoicePrefix($order);
    if ($csEndpointInvoicePrefix !== '') {
        $result['invoice_id'] = $csEndpointInvoicePrefix . $order->get_order_number();
    }
    if (!empty(WC_MEcom_Gateway::load()->get_option('soft_descriptor'))) {
        $result['soft_descriptor'] = WC_MEcom_Gateway::load()->get_option('soft_descriptor');
    }
    return $result;
}

function csPaypalErrorLog($data, $message = '')
{
    $trace = debug_backtrace();
    $dataLogString = csPaypalHandleDataLog($data, $message) 
        . json_encode([$trace[1]['class'] ?? null, $trace[1]['function'] ?? null, $trace[1]['args'] ?? null], true);
    if (!$logger = wc_get_logger()) {
        error_log($dataLogString);
    } else {
        $logger->debug($dataLogString, ['source' => 'cardshield-gateway-paypal-ERROR']);
    }
}

function csPaypalDebugLog($data, $message = '')
{
    $trace = debug_backtrace();
    $dataLogString = csPaypalHandleDataLog($data, $message) 
        . json_encode([$trace[1]['class'] ?? null, $trace[1]['function'] ?? null, $trace[1]['args'] ?? null], true);
    if (!$logger = wc_get_logger()) {
        error_log($dataLogString);
    } else {
        $logger->debug($dataLogString, ['source' => 'cardshield-gateway-paypal-INFO']);
    }
}

function csPaypalHandleDataLog($data, $message = '') {
    try {
        if (is_array($data) || is_object($data)) {
            $dataLog = json_encode($data);
        } else {
            $dataLog = (string)$data;
        }
    } catch (\Exception $e) {
        $dataLog = 'csPaypalLog ERROR: ' . $e->getMessage();
    }
    return '\n--------------------- ' . $message . ' ---------------------\n'
        . $dataLog;
}

function getCsPaypalOrderDetailFromWcOrder(WC_Order $order) {
    // Shipping
    $shippingName     = $order->get_shipping_first_name() . " " . $order->get_shipping_last_name();
    $shippingAddress1 = $order->get_shipping_address_1();
    $shippingAddress2 = $order->get_shipping_address_2();
    $shippingCity     = $order->get_shipping_city();
    $shippingCountry  = $order->get_shipping_country();
    $shippingPostCode = $order->get_shipping_postcode();
    $shippingState    = $order->get_shipping_state();

    // Billing
    $billingName     = $order->get_billing_first_name() . " " . $order->get_billing_last_name();
    $billingAddress1 = $order->get_billing_address_1();
    $billingAddress2 = $order->get_billing_address_2();
    $billingCity     = $order->get_billing_city();
    $billingCountry  = $order->get_billing_country();
    $billingPostCode = $order->get_billing_postcode();
    $billingState    = $order->get_billing_state();

    $shippingName     = ( empty( $order->get_shipping_first_name() ) && empty( $order->get_shipping_last_name() ) ) ? $billingName : $shippingName;
    $shippingAddress1 = empty( $shippingAddress1 ) ? $billingAddress1 : $shippingAddress1;
    $shippingAddress2 = empty( $shippingAddress2 ) ? $billingAddress2 : $shippingAddress2;
    $shippingCity     = empty( $shippingCity ) ? $billingCity : $shippingCity;
    $shippingCountry  = empty( $shippingCountry ) ? $billingCountry : $shippingCountry;
    $shippingPostCode = empty( $shippingPostCode ) ? $billingPostCode : $shippingPostCode;
    $shippingState    = empty( $shippingState ) ? $billingState : $shippingState;
    $csPaypalGw = WC()->payment_gateways->payment_gateways()['mecom_paypal'];
    $trackingSyncPlugin = $csPaypalGw->get_option('transaction_logs_enable');
    if ($trackingSyncPlugin && $trackingSyncPlugin === 'yes') {
        $products = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $variationParts = [];
            foreach ($item->get_formatted_meta_data('_', true) as $meta) {
                $variationParts[] = html_entity_decode(wp_strip_all_tags($meta->display_key . ': ' . $meta->display_value), ENT_QUOTES, 'UTF-8');
            }
            $products[] = [
                'name' => $item->get_name(),
                'sku' => $product ? $product->get_sku() : '',
                'variation' => implode(' | ', $variationParts),
                'quantity' => $item->get_quantity(),
                'total' => $item->get_total(),
            ];
        }
        $orderFees = [];
        foreach ($order->get_fees() as $fee) {
            $orderFees[] = [
                'name' => $fee->get_name(),
                'amount' => $fee->get_total(),
            ];
        }
        $gatewayFee = $order->get_meta(METAKEY_CS_PAYPAL_FEE);
        $gatewayNet = $order->get_meta(METAKEY_CS_PAYPAL_PAYOUT);
        $hasGatewayFee = ($gatewayFee !== '' && $gatewayFee !== null);
        $hasGatewayNet = ($gatewayNet !== '' && $gatewayNet !== null);
        return [
            'order_id' => $order->get_id(),
            'platform' => 'woocommerce',
            'endpoint_token' => get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null),
            'created_at' => date('Y/m/d H:i:s'),
            'amount_total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'store_domain' => get_home_url(),
            'customer_name' => $shippingName,
            'customer_email' => $order->get_billing_email(),
            'customer_phone' => $order->get_billing_phone(),
            'customer_ip' => $order->get_meta('_cs_customer_ip') ?: $order->get_customer_ip_address(),
            'shipping_address_company' => $order->get_billing_company(),
            'shipping_address_line1' => $shippingAddress1,
            'shipping_address_line2' => $shippingAddress2,
            'shipping_address_city' => $shippingCity,
            'shipping_address_state' => $shippingState,
            'shipping_address_country' => $shippingCountry,
            'shipping_address_postal_code' => $shippingPostCode,
            'products' => $products,
            'amount_subtotal' => $order->get_subtotal(),
            'amount_discount' => $order->get_total_discount(),
            'amount_shipping' => $order->get_shipping_total(),
            'amount_tax' => $order->get_total_tax(),
            'order_fees' => $orderFees,
            'gateway_fee' => $hasGatewayFee ? $gatewayFee : null,
            'gateway_net' => $hasGatewayNet ? $gatewayNet : null,
        ];
    } else {
        return [
            'SETTING_DISABLE_TRANSACTION_LOG' => true,
        ];
    }
}

function getCsPaypalHostedDisplayFromWcOrder(WC_Order $order) {
    $currency = $order->get_currency();
    $items = [];
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $imageUrl = '';
        if ($product && $product->get_image_id()) {
            $imageUrl = wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') ?: '';
        }
        $variationParts = [];
        foreach ($item->get_formatted_meta_data('_', true) as $meta) {
            $variationParts[] = html_entity_decode(wp_strip_all_tags($meta->display_key . ': ' . $meta->display_value), ENT_QUOTES, 'UTF-8');
        }
        $qty = (int) $item->get_quantity();
        $items[] = [
            'name' => $item->get_name(),
            'variant' => implode(' | ', $variationParts),
            'qty' => $qty,
            'price' => $qty > 0 ? round((float) $item->get_subtotal() / $qty, 2) : round((float) $item->get_subtotal(), 2),
            'image' => $imageUrl,
        ];
    }

    $logoUrl = '';
    $logoId = get_theme_mod('custom_logo');
    if ($logoId) {
        $logoUrl = wp_get_attachment_image_url($logoId, 'full') ?: '';
    }

    $shippingName = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
    if ($shippingName === '') {
        $shippingName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    }

    // Whether the billing address is the same as the shipping address (Ship to a different address off).
    $shipAddr1 = $order->get_shipping_address_1();
    $billingSame = ($shipAddr1 === '') || (
        $shipAddr1 === $order->get_billing_address_1()
        && $order->get_shipping_postcode() === $order->get_billing_postcode()
        && $order->get_shipping_city() === $order->get_billing_city()
        && $order->get_shipping_state() === $order->get_billing_state()
        && $order->get_shipping_country() === $order->get_billing_country()
        && trim($order->get_shipping_first_name() . $order->get_shipping_last_name()) === trim($order->get_billing_first_name() . $order->get_billing_last_name())
    );

    // Order fees (e.g. "NGPeptide Protection") so the summary lines sum up to the total.
    $fees = [];
    foreach ($order->get_fees() as $fee) {
        $fees[] = [
            'name' => $fee->get_name(),
            'amount' => round((float) $fee->get_total() + (float) $fee->get_total_tax(), 2),
        ];
    }

    return [
        'store_name' => get_bloginfo('name'),
        'store_url' => get_home_url(),
        'order_number' => $order->get_order_number(),
        'checkout_url' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : get_home_url(),
        'store_logo' => $logoUrl,
        'currency' => $currency,
        'items' => $items,
        'shipping_method' => $order->get_shipping_method(),
        'fees' => $fees,
        'amounts' => [
            'subtotal' => round((float) $order->get_subtotal(), 2),
            'shipping' => round((float) $order->get_shipping_total(), 2),
            'tax' => round((float) $order->get_total_tax(), 2),
            'discount' => round((float) $order->get_total_discount(), 2),
            'total' => round((float) $order->get_total(), 2),
        ],
        'customer' => [
            'name' => $shippingName,
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'shipping' => [
                'line1' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
                'line2' => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
                'city' => $order->get_shipping_city() ?: $order->get_billing_city(),
                'region' => $order->get_shipping_state() ?: $order->get_billing_state(),
                'postal' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
                'country' => $order->get_shipping_country() ?: $order->get_billing_country(),
            ],
            'billing_same' => $billingSame,
            'billing' => [
                'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'phone' => $order->get_billing_phone(),
                'line1' => $order->get_billing_address_1(),
                'line2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'region' => $order->get_billing_state(),
                'postal' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ],
        ],
    ];
}

function csPaypalAcquireOrderLock($order_id, $ttl = 10) {
    $key = '_cs_pp_return_lock_' . $order_id;
    $existing = get_option($key, false);
    if ($existing !== false && (time() - intval($existing)) > $ttl) {
        csPaypalDebugLog([
            'order_id' => $order_id,
            'lock_age' => time() - intval($existing),
            'ttl'      => $ttl,
        ], 'csPaypalAcquireOrderLock: clearing stale lock');
        delete_option($key);
    }
    if (!add_option($key, time(), '', 'no')) {
        csPaypalDebugLog([
            'order_id'    => $order_id,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
        ], 'csPaypalAcquireOrderLock: lock held by another request — duplicate detected');
        return false;
    }
    register_shutdown_function(function () use ($key) {
        delete_option($key);
    });
    return true;
}

function csPaypalSaveTransactionId(WC_Order $order, $transactionId) {
    $order->set_transaction_id($transactionId);
    $order->update_meta_data('METAKEY_CS_TRANSACTION_ID', $transactionId);
    $order->save();
}

function csPaypalGetTransactionId(WC_Order $order) {
    $id = $order->get_transaction_id();
    if (empty($id)) {
        $id = $order->get_meta('METAKEY_CS_TRANSACTION_ID');
    }
    return $id;
}

function queryOrderNeedSyncPaypal($mode = 'count') {
    global $wpdb;

    $csPayPalGw = WC()->payment_gateways->payment_gateways()['mecom_paypal'];
    $trackingSyncPlugin = $csPayPalGw->get_option('sync_tracking_plugin');
    $selectStatement = 'COUNT(*) as count';
    $limitClause     = '';
    if ($mode === 'get') {
        $selectStatement = 'posts.id as order_id';
        $limitClause     = 'LIMIT ' . CS_PAYPAL_SYNC_BATCH_SIZE;
    }
    $excludedStatuses    = implode(',', array_fill(0, count(CS_PAYPAL_EXCLUDED_STATUSES), '%s'));
    $excludedStatusArgs  = CS_PAYPAL_EXCLUDED_STATUSES;

    switch ( $trackingSyncPlugin ) {
        case OPT_CS_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING:
            return $wpdb->get_results( $wpdb->prepare( "
                SELECT $selectStatement FROM {$wpdb->prefix}posts AS posts
                INNER JOIN {$wpdb->prefix}postmeta AS pm_sync ON posts.id = pm_sync.post_id AND pm_sync.meta_key = %s AND pm_sync.meta_value = %d
                INNER JOIN {$wpdb->prefix}postmeta AS pm_ast ON posts.id = pm_ast.post_id AND pm_ast.meta_key = '_wc_shipment_tracking_items'
                WHERE posts.post_type = 'shop_order'
                  AND posts.post_status NOT IN ($excludedStatuses)
                  AND NOT EXISTS (
                      SELECT 1 FROM {$wpdb->prefix}postmeta
                      WHERE post_id = posts.id AND meta_key = %s AND meta_value <> 'true'
                  )
                $limitClause
            ", array_merge([METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED], $excludedStatusArgs, [METAKEY_CS_PAYPAL_CAPTURED]) ), ARRAY_A );

        case OPT_CS_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING:
            return $wpdb->get_results( $wpdb->prepare( "
                SELECT $selectStatement FROM {$wpdb->prefix}posts AS posts
                INNER JOIN {$wpdb->prefix}postmeta AS pm_sync ON posts.id = pm_sync.post_id AND pm_sync.meta_key = %s AND pm_sync.meta_value = %d
                WHERE posts.post_type = 'shop_order'
                  AND posts.post_status NOT IN ($excludedStatuses)
                  AND (
                    (
                      EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items AS oi
                        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim
                            ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_vi_wot_order_item_tracking_data'
                        WHERE oi.order_id = posts.id AND oi.order_item_type = 'line_item'
                      )
                      AND NOT EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}postmeta
                        WHERE post_id = posts.id AND meta_key = %s AND meta_value <> 'true'
                      )
                    )
                    OR EXISTS (
                      SELECT 1 FROM {$wpdb->prefix}postmeta
                      WHERE post_id = posts.id AND meta_key = '_wot_tracking_number'
                    )
                  )
                $limitClause
            ", array_merge([METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED], $excludedStatusArgs, [METAKEY_CS_PAYPAL_CAPTURED]) ), ARRAY_A );

        case OPT_CS_TRACKING_SYNC_PLUGIN_DIANXIAOMI:
            return $wpdb->get_results( $wpdb->prepare( "
                SELECT $selectStatement FROM {$wpdb->prefix}posts AS posts
                INNER JOIN {$wpdb->prefix}postmeta AS pm_sync ON posts.id = pm_sync.post_id AND pm_sync.meta_key = %s AND pm_sync.meta_value = %d
                INNER JOIN {$wpdb->prefix}postmeta AS pm_provider ON posts.id = pm_provider.post_id AND pm_provider.meta_key = '_dianxiaomi_tracking_provider_name'
                INNER JOIN {$wpdb->prefix}postmeta AS pm_tracking ON posts.id = pm_tracking.post_id AND pm_tracking.meta_key = '_dianxiaomi_tracking_number'
                WHERE posts.post_type = 'shop_order'
                  AND posts.post_status NOT IN ($excludedStatuses)
                  AND NOT EXISTS (
                      SELECT 1 FROM {$wpdb->prefix}postmeta
                      WHERE post_id = posts.id AND meta_key = %s AND meta_value <> 'true'
                  )
                $limitClause
            ", array_merge([METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED], $excludedStatusArgs, [METAKEY_CS_PAYPAL_CAPTURED]) ), ARRAY_A );
    }
}

function queryOrderNeedSyncPaypalHPOS($mode = 'count')
{
    global $wpdb;

    $csPayPalGw = WC()->payment_gateways->payment_gateways()['mecom_paypal'];
    $trackingSyncPlugin = $csPayPalGw->get_option('sync_tracking_plugin');
    $selectStatement = 'COUNT(*) as count';
    $limitClause     = '';
    if ($mode === 'get') {
        $selectStatement = 'orders.id as order_id';
        $limitClause     = 'LIMIT ' . CS_PAYPAL_SYNC_BATCH_SIZE;
    }
    $excludedStatuses   = implode(',', array_fill(0, count(CS_PAYPAL_EXCLUDED_STATUSES_HPOS), '%s'));
    $excludedStatusArgs = CS_PAYPAL_EXCLUDED_STATUSES_HPOS;

    switch ($trackingSyncPlugin) {
        case OPT_CS_TRACKING_SYNC_PLUGIN_ADVANCED_SHIPMENT_TRACKING:
            return $wpdb->get_results($wpdb->prepare("
                SELECT $selectStatement FROM {$wpdb->prefix}wc_orders AS orders
                INNER JOIN {$wpdb->prefix}wc_orders_meta AS om_sync ON orders.id = om_sync.order_id AND om_sync.meta_key = %s AND om_sync.meta_value = %d
                INNER JOIN {$wpdb->prefix}wc_orders_meta AS om_ast ON orders.id = om_ast.order_id AND om_ast.meta_key = '_wc_shipment_tracking_items'
                WHERE orders.status NOT IN ($excludedStatuses)
                  AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}wc_orders_meta
                    WHERE order_id = orders.id AND meta_key = %s AND meta_value <> 'true'
                  )
                $limitClause
            ", array_merge([METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED], $excludedStatusArgs, [METAKEY_CS_PAYPAL_CAPTURED])), ARRAY_A);

        case OPT_CS_TRACKING_SYNC_PLUGIN_ORDERS_TRACKING:
            return $wpdb->get_results($wpdb->prepare("
                SELECT $selectStatement FROM {$wpdb->prefix}wc_orders AS orders
                INNER JOIN {$wpdb->prefix}wc_orders_meta AS om_sync ON orders.id = om_sync.order_id AND om_sync.meta_key = %s AND om_sync.meta_value = %d
                WHERE orders.status NOT IN ($excludedStatuses)
                  AND (
                    (
                      EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items AS oi
                        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim
                            ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_vi_wot_order_item_tracking_data'
                        WHERE oi.order_id = orders.id AND oi.order_item_type = 'line_item'
                      )
                      AND NOT EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}wc_orders_meta
                        WHERE order_id = orders.id AND meta_key = %s AND meta_value <> 'true'
                      )
                    )
                    OR EXISTS (
                      SELECT 1 FROM {$wpdb->prefix}wc_orders_meta
                      WHERE order_id = orders.id AND meta_key = '_wot_tracking_number'
                    )
                  )
                $limitClause
            ", array_merge([METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED], $excludedStatusArgs, [METAKEY_CS_PAYPAL_CAPTURED])), ARRAY_A);

        case OPT_CS_TRACKING_SYNC_PLUGIN_DIANXIAOMI:
            return $wpdb->get_results($wpdb->prepare("
                SELECT $selectStatement FROM {$wpdb->prefix}wc_orders AS orders
                INNER JOIN {$wpdb->prefix}wc_orders_meta AS om_sync ON orders.id = om_sync.order_id AND om_sync.meta_key = %s AND om_sync.meta_value = %d
                INNER JOIN {$wpdb->prefix}wc_orders_meta AS om_provider ON orders.id = om_provider.order_id AND om_provider.meta_key = '_dianxiaomi_tracking_provider_name'
                INNER JOIN {$wpdb->prefix}wc_orders_meta AS om_tracking ON orders.id = om_tracking.order_id AND om_tracking.meta_key = '_dianxiaomi_tracking_number'
                WHERE orders.status NOT IN ($excludedStatuses)
                  AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}wc_orders_meta
                    WHERE order_id = orders.id AND meta_key = %s AND meta_value <> 'true'
                  )
                $limitClause
            ", array_merge([METAKEY_PAYPAL_SYNC_TRACKING_INFO, OPT_CS_PAYPAL_NOT_SYNCED], $excludedStatusArgs, [METAKEY_CS_PAYPAL_CAPTURED])), ARRAY_A);
    }
}

function isPaypalShieldReachAmount($orderTotal) {
    if (!isEnabledAmountRotation()) {
        return false;
    }
    $proxies = get_option(OPT_MECOM_PAYPAL_PROXIES, []);
    if (empty($proxies)) {
        return false;
    }
    foreach ($proxies as $proxy) {
        if(doubleval( $proxy['paid_amount'] ) + doubleval( $orderTotal ) < doubleval($proxy['amount'])) {
            return false;
        }
    }
    return true;
}

function csPaypalSendMailShieldReachAmount() {
    $csPaypalGw = WC()->payment_gateways->payment_gateways()['mecom_paypal'];
    if($csPaypalGw->get_option('send_email_notice_to_admin') === 'no') {
        return false;
    }
    $lastSent = strtotime(get_option('CS_PAYPAL_LAST_SEND_EMAIL_SHIELD_REACH_AMOUNT'));
    $now = strtotime(date( 'Y-m-d H:i:s'));
    if (($now - $lastSent)/60 > 360) { //Delay send by 360 minute
        update_option('CS_PAYPAL_LAST_SEND_EMAIL_SHIELD_REACH_AMOUNT', date( 'Y-m-d H:i:s'));
    } else {
        return false;
    }
    try {
        $headers[] = 'Content-type: text/html; charset=utf-8';
        add_filter( 'wp_mail_from_name', function () {
            return 'CardsShield';
        });
        $siteDomain = parse_url(get_home_url())['host'];
        ob_start();
		?>
            <p>
                Your website [<?=$siteDomain?>] cannot affort new orders due to shield amount has been reached.
                Please go to settings and increase the shield amount immediately!
            </p>
        <?php
		$body = ob_get_clean();
        $body = csPaypalBuildBodyEmail("Paypal shield amount has been reached", $body);
        wp_mail(csPaypalGetAdminEmails(), "[$siteDomain]: Paypal shield amount has been reached", $body, $headers);
    } catch (\Exception $e) {
        csPaypalErrorLog($e->getMessage(), 'csPaypalSendMailShieldReachAmount failed!');
    }
}

function csPaypalSendMailShieldDie($shieldUrl) {
    $csPaypalGw = WC()->payment_gateways->payment_gateways()['mecom_paypal'];
    if($csPaypalGw->get_option('send_email_notice_to_admin') === 'no') {
        return false;
    }
    try {
        $headers[] = 'Content-type: text/html; charset=utf-8';
        add_filter( 'wp_mail_from_name', function () {
            return 'CardsShield';
        });
        $siteDomain = parse_url(get_home_url())['host'];
        ob_start();
		?>
            <p>
                A Paypal shield <?= $shieldUrl ?> has been moved to unused list due to unchargable problem.
                Please check your payment account immediately!
            </p>
        <?php
		$body = ob_get_clean();
        $body = csPaypalBuildBodyEmail("A Paypal shield has been moved to unused list", $body);
        wp_mail(csPaypalGetAdminEmails(), "[$siteDomain]: A Paypal shield $shieldUrl has been moved to unused list", $body, $headers);
    } catch (\Exception $e) {
        csPaypalErrorLog($e->getMessage(), 'csPaypalSendMailShieldDie failed!');
    }
}

function csPaypalSendMailOrderBlacklisted($orderId) {
    $csPaypalGw = WC()->payment_gateways->payment_gateways()['mecom_paypal'];
    if($csPaypalGw->get_option('send_email_notice_to_admin') === 'no') {
        return false;
    }
    $order = wc_get_order($orderId);
    try {
        $headers[] = 'Content-type: text/html; charset=utf-8';
        add_filter( 'wp_mail_from_name', function () {
            return 'CardsShield';
        });
        $siteDomain = parse_url(get_home_url())['host'];
        ob_start();
		?>
            <?php
                wc_get_template( 'emails/email-order-details.php', array(
                    'order'         => $order,
                    'sent_to_admin' => true,
                    'plain_text'    => false,
                    'email'         => '',
                ));
                wc_get_template( 'emails/email-addresses.php', array(
                    'order'         => $order,
                    'sent_to_admin' => true,
                    'plain_text'    => false,
                    'email'         => '',
                ));
            ?>   
        <?php
		$body = ob_get_clean();
        $body = csPaypalBuildBodyEmail("Order Blacklisted: #{$order->get_order_number()}", $body);
        wp_mail(csPaypalGetAdminEmails(), "[$siteDomain]: Order #{$order->get_order_number()} has been BLACKLISTED", $body, $headers);
    } catch (\Exception $e) {
        csPaypalErrorLog($e->getMessage(), 'csPaypalSendMailShieldDie failed!');
    }
}

function csPaypalBuildBodyEmail($heading, $body) {
    add_filter( 'woocommerce_email_footer_text', function () {
        return "<br><b>Cards Shield Team</b><br/>support@cardsshield.com";
    } );
    $mailer = WC()->mailer();
    $wrapped_message = $mailer->wrap_message($heading, $body);
    $wc_email = new WC_Email;
    return $wc_email->style_inline($wrapped_message);
}

function csPaypalGetAdminEmails() {
    $blogusers = get_users('role=Administrator');
    $emails = [];
    foreach ($blogusers as $user) {
        $emails[] = $user->user_email;
    }
    if (!empty($emails)) {
        return implode(',', $emails);
    }
    return false;
}

function csPaypalGenerateProcessingOrderKey() {
    return md5(get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null)) . '_' . md5(uniqid(rand(), true));
}

function csEndpointGetShieldPaypalToProcess($csOrderKey, $orderTotal) {
    $lastTimeGetShield = strtotime(get_option('CS_ENDPOINT_LAST_TIME_GET_SHIELD_PAYPAL_TO_PROCESS'));
    $shield = get_option('CS_ENDPOINT_SHIELD_PAYPAL_TO_PROCESS', null);
    $now = strtotime(date( 'Y-m-d H:i:s'));
    if ($now - strtotime(get_option('CS_ENDPOINT_LAST_TIME_GET_SHIELD_PAYPAL_TO_PROCESS_FAILED')) < 60) { //cache 60s when call failed
        csPaypalDebugLog('cache get shield failed');
        return null;
    }
    if ($shield && ($now - $lastTimeGetShield) < 10) { //Cached 10s
        WC()->session->set("csEndpointGetShieldPaypalToProcessValue_$csOrderKey", $shield);
        $shield = json_decode($shield);
        return 'https://' . $shield->shield_domain;
    }
    if (!$gwDomain = csGetGatewayDomain()) {
        return null;
    }
    wc_get_logger()->debug('request csEndpointGetShieldPaypalToProcess', ['source' => 'cardshield-gateway-paypal-INFO']);
    $request = wp_remote_post($gwDomain . '/woo/get-shield-process', [
        'sslverify' => csPaypalGetSSLVerifyStatus(),
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'cs_order_key' => $csOrderKey,
            'order_total' => $orderTotal,
            'ep_token' => get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null),
            'merchant_site' => get_home_url(),
            'payment_gateway' => csPaypalIsHostedCheckout() ? OPT_CS_PAYMENT_GATEWAY_TYPE_PAYPAL_HOSTED : OPT_CS_PAYMENT_GATEWAY_TYPE_PAYPAL,
        ])
    ]);
    if (is_wp_error($request)) {
        csPaypalErrorLog($request, "csEndpointGetShieldPaypalToProcess error");
        return null;
    }
    $responseBody = wp_remote_retrieve_body($request);
    $data = json_decode($responseBody);
    csPaypalDebugLog($data, 'csEndpointGetShieldPaypalToProcess response');
    if ($data->status == 'success') {
        $shieldUrl = 'https://' . $data->shield->shield_domain;
        // Global cache shield Url 30s
        WC()->session->set("csEndpointGetShieldPaypalToProcessValue_$csOrderKey", json_encode($data->shield));
        update_option('CS_ENDPOINT_SHIELD_PAYPAL_TO_PROCESS', json_encode($data->shield));
        update_option('CS_ENDPOINT_LAST_TIME_GET_SHIELD_PAYPAL_TO_PROCESS', date( 'Y-m-d H:i:s'));
        return $shieldUrl;
    } else if ($data->code === 'EMPTY_SHIELDS' || $data->code === 'SHIELD_NOT_FOUND') {
        update_option('CS_ENDPOINT_LAST_TIME_GET_SHIELD_PAYPAL_TO_PROCESS_FAILED', date( 'Y-m-d H:i:s'));
        csPaypalErrorLog($request, "csEndpointGetShieldPaypalToProcess error[2]");
        return null;
    } else {
        csPaypalErrorLog($request, "csEndpointGetShieldPaypalToProcess error[3]");
        return null;
    }
}

function csEndpointPerformShieldRotateByAmount(WC_Order $order) {
    if (!$gwDomain = csGetGatewayDomain()) {
        return null;
    }
    // Count each order's volume once — hosted checkout can reach completion via several paths
    // (buyer-return, async webhook, PayPal retries) and we must not double-credit the shield.
    if ($order->get_meta('_cs_paypal_amount_rotated') === 'yes') {
        return null;
    }
    $csOrderKey = $order->get_meta(METAKEY_PAYPAL_PROCESSING_ORDER_KEY);
    // Hosted checkout completes server-to-server (no WC session), so prefer the shield persisted
    // on the order at session-creation time; fall back to the session value the Smart Button uses.
    $shieldProcessingRaw = $order->get_meta('_cs_paypal_hosted_shield_processing');
    if (empty($shieldProcessingRaw) && WC()->session) {
        $shieldProcessingRaw = WC()->session->get("csEndpointGetShieldPaypalToProcessValue_$csOrderKey");
    }
    $body = [
        'cs_order_key' => $csOrderKey,
        'order_total' => $order->get_total(),
        'order_currency' => $order->get_currency(),
        'ep_token' => get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null),
        'shield_processing' => json_decode($shieldProcessingRaw, true),
    ];
    $request = wp_remote_post($gwDomain . '/woo/perform-rotate-shield-by-amount', [
        'sslverify' => csPaypalGetSSLVerifyStatus(),
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($body)
    ]);
    csPaypalDebugLog(['request' => $body, 'response' => $request], "csEndpointPerformShieldRotateByAmount response");
    if (is_wp_error($request)) {
        csPaypalErrorLog($request, "csEndpointPerformShieldRotateByAmount error");
        return null;
    }
    $order->update_meta_data('_cs_paypal_amount_rotated', 'yes');
    $order->save_meta_data();
}

function csEndpointForceShieldRotate() {
    if (!$gwDomain = csGetGatewayDomain()) {
        return null;
    }
    $csOrderKey = WC()->session->get('mecom-paypal-processing-order-key');
    $body = [
        'cs_order_key' => $csOrderKey,
        'order_total' => 0,
        'order_currency' => get_woocommerce_currency(),
        'ep_token' => get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null),
        'shield_processing' => json_decode(WC()->session->get("csEndpointGetShieldPaypalToProcessValue_$csOrderKey"), true),
    ];
    $request = wp_remote_post($gwDomain . '/woo/force-rotate-shield', [
        'sslverify' => csPaypalGetSSLVerifyStatus(),
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($body)
    ]);
    csPaypalDebugLog(['request' => $body, 'response' => $request], "csEndpointPerformShieldRotate response");
    if (is_wp_error($request)) {
        csPaypalErrorLog($request, "csEndpointPerformShieldRotate error");
        return null;
    }
}

function csEndpointSetNextShield(WC_Order $order) {
    if (!$gwDomain = csGetGatewayDomain()) {
        return null;
    }
    $csOrderKey = $order->get_meta(METAKEY_PAYPAL_PROCESSING_ORDER_KEY);
    $request = wp_remote_post($gwDomain . '/woo/set-next-shield', [
        'sslverify' => csPaypalGetSSLVerifyStatus(),
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'cs_order_key' => $csOrderKey,
            'order_total' => $order->get_total(),
            'order_currency' => $order->get_currency(),
            'ep_token' => get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null),
            'shield_processing' => json_decode(WC()->session->get("csEndpointGetShieldPaypalToProcessValue_$csOrderKey"), true),
        ])
    ]);
    csPaypalDebugLog($request, "csEndpointSetNextShield response");
    if (is_wp_error($request)) {
        csPaypalErrorLog($request, "csEndpointSetNextShield error");
        return null;
    }
}

function csEndpointGetAmountRemaining() {
    if (!$gwDomain = csGetGatewayDomain()) {
        return null;
    }
    if (!$epToken = get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null)) {
        return -1;
    }
    $request = wp_remote_post($gwDomain . '/woo/get-remaining-amount', [
        'sslverify' => csPaypalGetSSLVerifyStatus(),
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'ep_token' => $epToken,
        ])
    ]);
    if (is_wp_error($request)) {
        csPaypalErrorLog($request, "csEndpointGetAmountRemaining error");
        return null;
    }
    $responseBody = wp_remote_retrieve_body($request);
    $data = json_decode($responseBody);
    csPaypalDebugLog($data, 'csEndpointGetAmountRemaining response');
    if ($data->status == 'success') {
        return $data->value;
    }
    return -1;
}

function csEndpointMoveToUnusedShield($shieldDomain, $issue = null) {
    if (!$gwDomain = csGetGatewayDomain()) {
        return null;
    }
    $request = wp_remote_post($gwDomain . '/woo/move-to-unused-shield', [
        'sslverify' => csPaypalGetSSLVerifyStatus(),
        'timeout' => 300,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'ep_token' => get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null),
            'shield_domain' => $shieldDomain,
            'issue' => $issue,
        ])
    ]);
    csPaypalDebugLog($request, "csEndpointMoveToUnusedShield response");
    if (is_wp_error($request)) {
        csPaypalErrorLog($request, "csEndpointMoveToUnusedShield error");
        return null;
    }
}

function isCsPaypalEnableEndpointMode() {
    return get_option(OPT_MECOM_PAYPAL_CONNECTION_MODE, null) == OPT_CS_PAYPAL_CONNECTION_MODE_ENDPOINT_TOKEN;
}

// The endpoint-level invoice prefix (manager: Edit Endpoint > Invoice prefix) rides along with the
// shield payload cs_gateway returns at checkout, so it can be changed from the manager without
// touching this store. Empty (or non-endpoint mode) means the store setting stays in charge.
function csPaypalGetEndpointInvoicePrefix($order = null) {
    if (!isCsPaypalEnableEndpointMode()) {
        return '';
    }
    $shieldRaw = '';
    if ($order instanceof WC_Order) {
        $shieldRaw = $order->get_meta('_cs_paypal_hosted_shield_processing');
    }
    if (empty($shieldRaw) && function_exists('WC') && WC()->session) {
        $csOrderKey = WC()->session->get('mecom-paypal-processing-order-key');
        if (!empty($csOrderKey)) {
            $shieldRaw = WC()->session->get("csEndpointGetShieldPaypalToProcessValue_$csOrderKey");
        }
    }
    if (empty($shieldRaw)) {
        $shieldRaw = get_option('CS_ENDPOINT_SHIELD_PAYPAL_TO_PROCESS', '');
    }
    if (empty($shieldRaw)) {
        return '';
    }
    $shield = json_decode($shieldRaw, true);
    return isset($shield['endpoint_invoice_prefix']) ? trim((string) $shield['endpoint_invoice_prefix']) : '';
}

function csPaypalResolveInvoicePrefix($storePrefix, $order = null) {
    $endpointPrefix = csPaypalGetEndpointInvoicePrefix($order);
    return $endpointPrefix !== '' ? $endpointPrefix : (string) $storePrefix;
}

function csGetGatewayDomainFromCreds($endpointToken, $endpointSecret)
{
    try {
        $decrypt = base64_decode(strtr(base64_decode($endpointSecret),
            './-:?=&%# ZQXJKVWPY abcdefghijklmnopqrstuvwxyz123456789ABCDEFGHILMNORSTU',
            'ZQXJKVWPY ./-:?=&%# 123456789ABCDEFGHILMNORSTUabcdefghijklmnopqrstuvwxyz'));
        return str_replace($endpointToken, '', $decrypt);
    } catch (\Exception $e) {
        csPaypalErrorLog($e->getMessage(), 'csGetGatewayDomain failed!');
        return null;
    }
}

function csGetGatewayDomain()
{
    return csGetGatewayDomainFromCreds(get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null), get_option(OPT_CS_PAYPAL_ENDPOINT_SECRET, null));
}

function csEndpointGetInfo($gwDomain, $epToken)
{
    if (empty($gwDomain) || empty($epToken)) {
        return null;
    }
    $request = wp_remote_post($gwDomain . '/woo/get-endpoint-info', [
        'sslverify' => csPaypalGetSSLVerifyStatus(),
        'timeout' => 30,
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode([
            'ep_token' => $epToken,
        ])
    ]);
    if (is_wp_error($request)) {
        csPaypalErrorLog($request, 'csEndpointGetInfo error');
        return null;
    }
    return json_decode(wp_remote_retrieve_body($request));
}

function csPaypalValidateEndpointToken($endpointToken, $endpointSecret)
{
    $endpointToken = trim((string) $endpointToken);
    if ($endpointToken === '') {
        return ['status' => 'empty', 'message' => ''];
    }
    $gwDomain = csGetGatewayDomainFromCreds($endpointToken, trim((string) $endpointSecret));
    if (empty($gwDomain) || !filter_var($gwDomain, FILTER_VALIDATE_URL)) {
        return ['status' => 'error', 'message' => 'Could not determine the gateway from the Secret Key. Please check the Secret Key.'];
    }
    $info = csEndpointGetInfo($gwDomain, $endpointToken);
    if (!is_object($info) || ($info->status ?? '') !== 'success') {
        return ['status' => 'error', 'message' => 'Endpoint not found. The Token or Secret Key may be wrong, or the endpoint does not exist.'];
    }
    $type = intval($info->payment_gateway_type ?? 0);
    if (!in_array($type, [OPT_CS_PAYMENT_GATEWAY_TYPE_PAYPAL, OPT_CS_PAYMENT_GATEWAY_TYPE_PAYPAL_HOSTED], true)) {
        return ['status' => 'warning', 'message' => 'This endpoint token is NOT a PayPal type (payment gateway type = ' . $type . '). Are you sure you want to save?'];
    }
    $buttonHosted = csPaypalIsHostedCheckout();
    if ($buttonHosted && $type === OPT_CS_PAYMENT_GATEWAY_TYPE_PAYPAL) {
        return ['status' => 'warning', 'message' => 'Button mode is set to "PayPal Hosted Checkout" but the endpoint token is regular PayPal (type 1). They do not match — are you sure you want to save?'];
    }
    if (!$buttonHosted && $type === OPT_CS_PAYMENT_GATEWAY_TYPE_PAYPAL_HOSTED) {
        return ['status' => 'warning', 'message' => 'Button mode is set to Smart Button/Standard but the endpoint token is Hosted Checkout (type 6). They do not match — are you sure you want to save?'];
    }
    return ['status' => 'ok', 'message' => ''];
}

function csPaypalClearEndpointConfigCache()
{
    delete_transient('cs_pp_endpoint_config_invalid');
}

function csPaypalIsEndpointConfigInvalid()
{
    if (!isCsPaypalEnableEndpointMode()) {
        return false;
    }
    $token = get_option(OPT_CS_PAYPAL_ENDPOINT_TOKEN, null);
    $hosted = csPaypalIsHostedCheckout() ? 1 : 0;
    $cached = get_transient('cs_pp_endpoint_config_invalid');
    if (is_array($cached) && ($cached['token'] ?? null) === $token && intval($cached['hosted'] ?? -1) === $hosted) {
        return !empty($cached['invalid']);
    }
    $result = csPaypalValidateEndpointToken($token, get_option(OPT_CS_PAYPAL_ENDPOINT_SECRET, null));
    $invalid = ($result['status'] === 'warning');
    set_transient('cs_pp_endpoint_config_invalid', [
        'token' => $token,
        'hosted' => $hosted,
        'invalid' => $invalid ? 1 : 0,
    ], 5 * MINUTE_IN_SECONDS);
    return $invalid;
}

function csPaypalStoreCustomerIp($order)
{
    if (!($order instanceof WC_Order)) {
        return;
    }
    $ip = csPaypalGetClientIP();
    if (!empty($ip)) {
        $order->update_meta_data('_cs_customer_ip', $ip);
        $order->save_meta_data();
    }
}

function csPaypalGetClientIP()
{
    foreach (array('HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function csMerchantSiteEncode($merchantSite)
{
    return base64_encode(strtr(base64_encode($merchantSite),
        './-:?=&%# ZQXJKVWPY abcdefghijklmnopqrstuvwxyz123456789ABCDEFGHILMNORSTU',
        'ZQXJKVWPY ./-:?=&%# 123456789ABCDEFGHILMNORSTUabcdefghijklmnopqrstuvwxyz'));
}

function csPaypalGetSSLVerifyStatus()
{
    try {
        if (WC_MEcom_Gateway::load()->sslverify === 'yes') {
            return true;
        }
    } catch (\Exception $e) {
        csPaypalErrorLog($e->getMessage(), 'csPaypalGetSSLVerifyStatus failed');
    }
    return false;
}

function csPpGenerateRandomString($length = 16)
{
    $length = $length - 1;
    $characters = '012345678901234567890123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return rand(0, 9) . $randomString;
}

function forceRotateShield() {
    WC()->session->set('order_awaiting_payment', null);
    if (isCsPaypalEnableEndpointMode()) {
        csEndpointForceShieldRotate();
    } else {
        csDomainForceShieldRotate();
    }
}

function csPaypalBuildQuery($params)
{
    return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

// Pull the PayPal capture info from the proxy by pp_order_id, for when the webhook body is missing
// (manual re-trigger of the notice URL as a GET, or an empty pushed body). Returns the decoded object
// in the same shape returnWebhook pushes ({status, seller_receivable_breakdown, order}), or null.
function csPaypalPullOrderCapture($shieldUrl, $ppOrderId)
{
    $shieldUrl = wp_unslash($shieldUrl);
    if (empty($shieldUrl) || empty($ppOrderId)) {
        return null;
    }
    // Hash matches the proxy's gate: sha256('cs_' . <proxy host> . pp_order_id). We take the proxy host
    // from the trusted proxy URL (not $_GET), so it stays consistent even on manual re-triggers.
    $shieldHost = strtolower(parse_url($shieldUrl, PHP_URL_HOST) ?: '');
    $url = rtrim($shieldUrl, '/') . '/?rest_route=/cs/paypal-get-order-capture&' . csPaypalBuildQuery([
        'pp_order_id'   => $ppOrderId,
        'merchant_site' => get_home_url(),
        'hash'          => hash('sha256', 'cs_' . $shieldHost . $ppOrderId),
    ]);
    $resp = wp_remote_get($url, [
        'sslverify' => csPaypalGetSSLVerifyStatus(),
        'timeout'   => 60,
    ]);
    if (is_wp_error($resp)) {
        csPaypalErrorLog($resp, 'csPaypalPullOrderCapture wp_error');
        return null;
    }
    $body = wp_remote_retrieve_body($resp);
    csPaypalDebugLog([$url, wp_remote_retrieve_response_code($resp), substr($body, 0, 500)], 'csPaypalPullOrderCapture response');
    return json_decode($body);
}

const OPT_CS_PAYPAL_SHIELD_TITLES_POOL_CACHE = 'cs_paypal_shield_titles_pool_cache';
const CS_PAYPAL_SHIELD_TITLES_POOL_TTL       = 3600;

function csPaypalGetRandomTitleFromShield() {
    $cache = get_transient(OPT_CS_PAYPAL_SHIELD_TITLES_POOL_CACHE);
    $proxyUrl = WC()->session ? WC()->session->get('mecom-paypal-proxy-active-url') : null;
    if (!is_array($cache) || ($cache['proxy_url'] ?? '') !== $proxyUrl) {
        $pool = [];
        if ($proxyUrl) {
            $response = wp_remote_get(rtrim($proxyUrl, '/') . '/?rest_route=/cs/paypal-random-shield-titles', [
                'sslverify' => csPaypalGetSSLVerifyStatus(),
                'timeout'   => 10,
            ]);
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($body['titles']) && is_array($body['titles'])) {
                    $pool = array_values(array_filter(array_map('trim', $body['titles']), 'strlen'));
                }
            } else {
                csPaypalErrorLog($response, 'csPaypalGetRandomTitleFromShield request error');
            }
        }
        $cache = ['proxy_url' => $proxyUrl, 'pool' => $pool];
        set_transient(OPT_CS_PAYPAL_SHIELD_TITLES_POOL_CACHE, $cache, CS_PAYPAL_SHIELD_TITLES_POOL_TTL);
    }
    $pool = $cache['pool'] ?? [];
    if (empty($pool)) {
        return 'Products';
    }
    return $pool[array_rand($pool)];
}


// Plugin icon URL with a version query so released icon changes bust CDN/browser cache.
function cs_paypal_icon_src( $rel_path, $plugin_file, $ver = '1' ) {
    return plugins_url( $rel_path, $plugin_file ) . '?v=' . $ver;
}
