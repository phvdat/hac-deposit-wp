<?php
/**
 * Plugin Name: Deposit Site
 * Description: Standalone deposit amount form feeding the standard WooCommerce cart, checkout and CardShield PayPal gateway.
 * Version: 2.0.0
 * Text Domain: deposit-site
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DEPOSIT_SITE_DIR          = __DIR__ . '/';
const DEPOSIT_SITE_MIN_AMOUNT   = 1;
const DEPOSIT_SITE_MAX_AMOUNT   = 1000;
const DEPOSIT_SITE_PRODUCT_SKU  = 'deposit-site';
const DEPOSIT_SITE_VERSION      = '1.0.0';

require_once DEPOSIT_SITE_DIR . 'templates/ui.php';

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

function deposit_site_decimals() {
	return function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
}

function deposit_site_symbol() {
	return function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
}

/**
 * Lazily create (and remember) the hidden product used as the cart line item.
 */
function deposit_site_get_product_id() {
	$product_id = (int) get_option( 'deposit_site_product_id', 0 );

	if ( $product_id && 'publish' === get_post_status( $product_id ) ) {
		return $product_id;
	}

	$existing = function_exists( 'wc_get_products' )
		? wc_get_products( array( 'limit' => 1, 'sku' => DEPOSIT_SITE_PRODUCT_SKU, 'return' => 'ids' ) )
		: array();

	if ( ! empty( $existing ) ) {
		$product_id = (int) $existing[0];
	} else {
		$product = new WC_Product_Simple();
		$product->set_name( 'Deposit' );
		$product->set_slug( 'deposit-product' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_sku( DEPOSIT_SITE_PRODUCT_SKU );
		$product->set_regular_price( 0 );
		$product->set_price( 0 );
		$product->set_tax_status( 'none' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_stock_status( 'instock' );
		$product_id = $product->save();
	}

	if ( $product_id ) {
		update_option( 'deposit_site_product_id', $product_id );
	}

	return (int) $product_id;
}

function deposit_site_redirect_with_error( $code ) {
	$back = home_url( add_query_arg( array() ) );
	wp_safe_redirect( add_query_arg( 'deposit_error', $code, remove_query_arg( 'deposit_error', $back ) ) );
	exit;
}

/* -------------------------------------------------------------------------
 * Deposit form -> cart -> checkout
 * ---------------------------------------------------------------------- */

function deposit_site_handle_form() {
	if ( empty( $_POST['deposit_site_submit'] ) ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	$nonce = isset( $_POST['deposit_site_nonce'] )
		? sanitize_key( wp_unslash( $_POST['deposit_site_nonce'] ) )
		: '';

	if ( ! wp_verify_nonce( $nonce, 'deposit_site_form' ) ) {
		deposit_site_redirect_with_error( 'nonce' );
	}

	$raw = isset( $_POST['deposit_amount'] ) ? trim( wp_unslash( $_POST['deposit_amount'] ) ) : '';

	if ( ! is_numeric( $raw ) ) {
		deposit_site_redirect_with_error( 'invalid' );
	}

	$amount = round( (float) $raw, deposit_site_decimals() );

	if ( $amount <= 0 ) {
		deposit_site_redirect_with_error( 'invalid' );
	}
	if ( $amount < DEPOSIT_SITE_MIN_AMOUNT ) {
		deposit_site_redirect_with_error( 'min' );
	}
	if ( $amount > DEPOSIT_SITE_MAX_AMOUNT ) {
		deposit_site_redirect_with_error( 'max' );
	}

	$product_id = deposit_site_get_product_id();

	if ( ! $product_id ) {
		deposit_site_redirect_with_error( 'cart' );
	}

	WC()->cart->empty_cart();

	if ( ! WC()->cart->add_to_cart( $product_id, 1, 0, array(), array( 'deposit_amount' => $amount ) ) ) {
		deposit_site_redirect_with_error( 'cart' );
	}

	wp_safe_redirect( wc_get_checkout_url() );
	exit;
}
add_action( 'template_redirect', 'deposit_site_handle_form' );

function deposit_site_restore_cart_item( $cart_item, $values, $key ) {
	if ( isset( $values['deposit_amount'] ) ) {
		$cart_item['deposit_amount'] = (float) $values['deposit_amount'];
	}
	return $cart_item;
}
add_filter( 'woocommerce_get_cart_item_from_session', 'deposit_site_restore_cart_item', 10, 3 );

/**
 * The entered amount is applied to the line item just before totals are
 * calculated, so the cart, checkout and order all show the real deposit value.
 */
function deposit_site_apply_price( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! $cart instanceof WC_Cart || $cart->is_empty() ) {
		return;
	}

	foreach ( $cart->get_cart() as $item ) {
		if ( isset( $item['deposit_amount'] ) && $item['data'] instanceof WC_Product ) {
			$item['data']->set_price( (float) $item['deposit_amount'] );
		}
	}
}
add_action( 'woocommerce_before_calculate_totals', 'deposit_site_apply_price', 20 );

/**
 * The deposit product must only ever reach the cart through the deposit form,
 * otherwise it would be added as a free line item.
 */
function deposit_site_block_direct_add( $passed, $product_id ) {
	if ( $product_id === (int) get_option( 'deposit_site_product_id', 0 ) ) {
		wc_add_notice( __( 'Please enter a deposit amount first.', 'deposit-site' ), 'error' );
		return false;
	}
	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'deposit_site_block_direct_add', 10, 2 );

/* -------------------------------------------------------------------------
 * Checkout fields
 * ---------------------------------------------------------------------- */

function deposit_site_checkout_fields( $fields ) {
	$keep = array( 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone' );

	foreach ( array( 'billing', 'shipping' ) as $section ) {
		if ( empty( $fields[ $section ] ) || ! is_array( $fields[ $section ] ) ) {
			continue;
		}
		foreach ( array_keys( $fields[ $section ] ) as $key ) {
			if ( ! in_array( $key, $keep, true ) ) {
				unset( $fields[ $section ][ $key ] );
			}
		}
	}

	if ( isset( $fields['billing']['billing_phone'] ) ) {
		$fields['billing']['billing_phone']['required'] = true;
	}

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'deposit_site_checkout_fields' );

/**
 * The address section is gone, so the order still needs a billing country.
 */
function deposit_site_set_billing_country( $order, $data ) {
	if ( ! $order->get_billing_country() ) {
		$location = wc_get_base_location();
		if ( ! empty( $location['country'] ) ) {
			$order->set_billing_country( $location['country'] );
		}
	}
	return $order;
}
add_filter( 'woocommerce_checkout_create_order', 'deposit_site_set_billing_country', 10, 2 );

/* -------------------------------------------------------------------------
 * Presentation
 * ---------------------------------------------------------------------- */

function deposit_site_form_shortcode() {
	$error = isset( $_GET['deposit_error'] ) ? sanitize_key( wp_unslash( $_GET['deposit_error'] ) ) : '';

	ob_start();
	deposit_site_deposit_form( $error );
	return ob_get_clean();
}
add_shortcode( 'deposit_form', 'deposit_site_form_shortcode' );

function deposit_site_enqueue_assets() {
	wp_enqueue_style(
		'deposit-site',
		plugins_url( 'assets/style.css', __FILE__ ),
		array(),
		DEPOSIT_SITE_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'deposit_site_enqueue_assets' );

/**
 * Deposit page falls back to the site home, everything in the checkout flow
 * falls back to the deposit page.
 */
function deposit_site_back_url() {
	if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ) {
		$page_id = (int) get_option( 'deposit_site_page_id', 0 );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		if ( $url ) {
			return $url;
		}
	}
	return home_url( '/' );
}

function deposit_site_render_back_header() {
	deposit_site_header( deposit_site_back_url() );
}
add_action( 'wp_body_open', 'deposit_site_render_back_header' );

/**
 * Drop every core/template-part block (site header, footer, navigation, cart
 * link, mini cart, customer account) so the plugin owns the page chrome.
 */
function deposit_site_suppress_chrome( $pre_render, $parsed_block ) {
	if ( is_admin() ) {
		return $pre_render;
	}

	if ( isset( $parsed_block['blockName'] ) && 'core/template-part' === $parsed_block['blockName'] ) {
		return '';
	}

	return $pre_render;
}
add_filter( 'pre_render_block', 'deposit_site_suppress_chrome', 10, 2 );

/**
 * Coupon form removal. Must run after WooCommerce loads its template hooks on
 * `init` priority 0.
 */
function deposit_site_remove_wc_ui_hooks() {
	remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
}
add_action( 'init', 'deposit_site_remove_wc_ui_hooks', 20 );

/**
 * WooCommerce serves the order-received endpoint from its own block template,
 * which never renders the checkout page content. Drop that template from the
 * hierarchy so the classic checkout shortcode runs and `checkout/thankyou.php`
 * (routed to templates/ui.php above) is used instead.
 */
function deposit_site_force_classic_order_received( $templates ) {
	if ( is_admin() ) {
		return $templates;
	}
	return array_values( array_diff( $templates, array( 'order-confirmation' ) ) );
}
add_filter( 'page_template_hierarchy', 'deposit_site_force_classic_order_received', 2 );

/* -------------------------------------------------------------------------
 * Templates
 * ---------------------------------------------------------------------- */

/**
 * Route WooCommerce templates to this plugin's presentation files.
 */
function deposit_site_route_template( $template, $template_name ) {
	if ( 'checkout/thankyou.php' === $template_name ) {
		return DEPOSIT_SITE_DIR . 'templates/ui.php';
	}

	$customer_emails = array(
		'emails/customer-processing-order.php',
		'emails/customer-completed-order.php',
		'emails/customer-on-hold-order.php',
		'emails/customer-invoice.php',
	);

	if ( in_array( $template_name, $customer_emails, true ) ) {
		return DEPOSIT_SITE_DIR . 'email.php';
	}

	return $template;
}
add_filter( 'wc_get_template', 'deposit_site_route_template', 10, 2 );

/* -------------------------------------------------------------------------
 * Email
 * ---------------------------------------------------------------------- */

function deposit_site_render_email( $order ) {
	ob_start();
	include DEPOSIT_SITE_DIR . 'email.php';
	return ob_get_clean();
}

/**
 * Send the deposit confirmation as soon as the order exists.
 *
 * Signature must match `do_action( 'woocommerce_checkout_order_processed',
 * $order_id, $posted_data, $order )`.
 */
function deposit_site_send_confirmation_email( $order_id, $posted_data, $order ) {
	unset( $posted_data );

	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	if ( ! $order->get_billing_email() ) {
		return;
	}

	if ( 'yes' === $order->get_meta( '_deposit_confirmation_sent' ) ) {
		return;
	}

	$order->update_meta_data( '_deposit_confirmation_sent', 'yes' );
	$order->save();

	$subject = sprintf(
		/* translators: %s: order number */
		__( 'Deposit order received - #%s', 'deposit-site' ),
		$order->get_order_number()
	);

	wp_mail(
		$order->get_billing_email(),
		$subject,
		deposit_site_render_email( $order ),
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
}
add_action( 'woocommerce_checkout_order_processed', 'deposit_site_send_confirmation_email', 10, 3 );

/* -------------------------------------------------------------------------
 * Activation
 * ---------------------------------------------------------------------- */

function deposit_site_activate() {
	update_option( 'woocommerce_coming_soon', 'no' );

	$pages = array(
		'woocommerce_cart_page_id'     => '[woocommerce_cart]',
		'woocommerce_checkout_page_id' => '[woocommerce_checkout]',
	);

	foreach ( $pages as $option => $content ) {
		$page_id = (int) get_option( $option, 0 );
		if ( $page_id && get_post_status( $page_id ) ) {
			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => $content,
				)
			);
		}
	}

	$page_id = (int) get_option( 'deposit_site_page_id', 0 );

	if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
		$existing = get_posts(
			array(
				'name'           => 'deposit',
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$args = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Deposit',
			'post_name'    => 'deposit',
			'post_content' => '[deposit_form]',
		);

		if ( $existing ) {
			$args['ID'] = (int) $existing[0];
		}

		$page_id = wp_insert_post( $args, true );

		if ( ! is_wp_error( $page_id ) ) {
			update_option( 'deposit_site_page_id', (int) $page_id );
		}
	}
}
register_activation_hook( __FILE__, 'deposit_site_activate' );
