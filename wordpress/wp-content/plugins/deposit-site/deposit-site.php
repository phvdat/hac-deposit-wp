<?php
/**
 * Plugin Name: Deposit Site
 * Description: Adds a deposit amount form that feeds the standard WooCommerce cart and checkout.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DEPOSIT_SITE_MIN_AMOUNT = 1;
const DEPOSIT_SITE_MAX_AMOUNT = 1000;
const DEPOSIT_SITE_PRODUCT_SKU = 'deposit-site';

function deposit_site_decimals() {
	return function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
}

function deposit_site_symbol() {
	return function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
}

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

function deposit_site_block_direct_add( $passed, $product_id ) {
	if ( $product_id === (int) get_option( 'deposit_site_product_id', 0 ) ) {
		wc_add_notice( __( 'Please enter a deposit amount first.', 'deposit-site' ), 'error' );
		return false;
	}
	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'deposit_site_block_direct_add', 10, 2 );

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

function deposit_site_form() {
	$decimals = deposit_site_decimals();
	$symbol   = deposit_site_symbol();
	$step     = number_format( pow( 10, -$decimals ), $decimals, '.', '' );
	$min      = number_format( DEPOSIT_SITE_MIN_AMOUNT, $decimals, '.', '' );
	$max      = number_format( DEPOSIT_SITE_MAX_AMOUNT, $decimals, '.', '' );
	$limits   = sprintf(
		/* translators: 1: minimum amount, 2: maximum amount */
		__( 'Minimum %1$s%2$s &middot; Maximum %1$s%3$s', 'deposit-site' ),
		$symbol,
		number_format( DEPOSIT_SITE_MIN_AMOUNT, $decimals ),
		number_format( DEPOSIT_SITE_MAX_AMOUNT, $decimals )
	);

	$messages = array(
		'nonce'   => __( 'Your session has expired. Please try again.', 'deposit-site' ),
		'invalid' => __( 'Please enter a valid deposit amount.', 'deposit-site' ),
		'min'     => sprintf(
			/* translators: %s: minimum amount */
			__( 'The minimum deposit is %s.', 'deposit-site' ),
			$symbol . number_format( DEPOSIT_SITE_MIN_AMOUNT, $decimals )
		),
		'max'     => sprintf(
			/* translators: %s: maximum amount */
			__( 'The maximum deposit is %s.', 'deposit-site' ),
			$symbol . number_format( DEPOSIT_SITE_MAX_AMOUNT, $decimals )
		),
		'cart'    => __( 'We could not add the deposit to your cart. Please try again.', 'deposit-site' ),
	);

	$error = isset( $_GET['deposit_error'] ) ? sanitize_key( wp_unslash( $_GET['deposit_error'] ) ) : '';
	$error = isset( $messages[ $error ] ) ? $messages[ $error ] : '';

	ob_start();
	?>
	<style>
		.deposit-site-form{max-width:420px;margin:0 auto;padding:24px;border:1px solid #e3e3e3;border-radius:8px;background:#fff;font-size:16px;box-sizing:border-box}
		.deposit-site-form *{box-sizing:border-box}
		.deposit-site-form label{display:block;font-weight:600;margin:0 0 8px}
		.deposit-site-error{margin:0 0 16px;padding:10px 12px;color:#b32d2e;background:#fcf0f1;border-left:4px solid #b32d2e}
		.deposit-site-amount{display:flex;align-items:stretch;border:1px solid #ccc;border-radius:6px;overflow:hidden}
		.deposit-site-amount span{display:flex;align-items:center;padding:0 14px;background:#f6f6f6;border-right:1px solid #ccc;color:#555}
		.deposit-site-amount input{flex:1;min-width:0;padding:12px;border:0;font-size:16px;outline:none}
		.deposit-site-form button{width:100%;margin-top:16px;padding:14px 16px;border:0;border-radius:6px;background:#2271b1;color:#fff;font-size:16px;cursor:pointer}
		.deposit-site-form button:hover{background:#135e96}
		.deposit-site-hint{margin:12px 0 0;color:#666;font-size:14px}
		@media (max-width:480px){.deposit-site-form{padding:16px}}
	</style>
	<form class="deposit-site-form" method="post">
		<?php wp_nonce_field( 'deposit_site_form', 'deposit_site_nonce' ); ?>
		<?php if ( $error ) : ?>
			<p class="deposit-site-error"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<label for="deposit_amount"><?php esc_html_e( 'Deposit amount', 'deposit-site' ); ?></label>
		<div class="deposit-site-amount">
			<span><?php echo esc_html( $symbol ); ?></span>
			<input
				type="number"
				id="deposit_amount"
				name="deposit_amount"
				value=""
				min="<?php echo esc_attr( $min ); ?>"
				max="<?php echo esc_attr( $max ); ?>"
				step="<?php echo esc_attr( $step ); ?>"
				inputmode="decimal"
				required
			/>
		</div>
		<button type="submit" name="deposit_site_submit" value="1"><?php esc_html_e( 'Proceed to checkout', 'deposit-site' ); ?></button>
		<p class="deposit-site-hint"><?php echo wp_kses_post( $limits ); ?></p>
	</form>
	<?php
	return ob_get_clean();
}
add_shortcode( 'deposit_form', 'deposit_site_form' );

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
