<?php

/**
 * Plugin Name: Deposit Site
 * Description: Standalone deposit amount form feeding the standard WooCommerce cart, checkout and CardShield PayPal gateway.
 * Version: 2.0.0
 * Text Domain: deposit-site
 */

if (! defined('ABSPATH')) {
	exit;
}

const DEPOSIT_SITE_DIR          = __DIR__ . '/';
const DEPOSIT_SITE_MIN_AMOUNT   = 10;
const DEPOSIT_SITE_MAX_AMOUNT   = 1000;
const DEPOSIT_SITE_PRODUCT_SKU  = 'deposit-site';
const DEPOSIT_SITE_VERSION      = '1.0.0';

/* TEMPORARY DEBUG SWITCH - set to false to restore the normal deposit checkout fields. */
const DEPOSIT_SITE_DEBUG_FULL_BILLING = false;

require_once DEPOSIT_SITE_DIR . 'templates/ui.php';

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

function deposit_site_decimals()
{
	return function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
}

function deposit_site_symbol()
{
	return function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
}

function deposit_site_min_amount()
{
	return (float) get_option('deposit_site_min_amount', DEPOSIT_SITE_MIN_AMOUNT);
}

function deposit_site_max_amount()
{
	return (float) get_option('deposit_site_max_amount', DEPOSIT_SITE_MAX_AMOUNT);
}

function deposit_site_redirect_url()
{
	return (string) get_option('deposit_site_redirect_url', '');
}

/**
 * Lazily create (and remember) the hidden product used as the cart line item.
 */
function deposit_site_get_product_id()
{
	$product_id = (int) get_option('deposit_site_product_id', 0);

	if ($product_id && 'publish' === get_post_status($product_id)) {
		return $product_id;
	}

	$existing = function_exists('wc_get_products')
		? wc_get_products(array('limit' => 1, 'sku' => DEPOSIT_SITE_PRODUCT_SKU, 'return' => 'ids'))
		: array();

	if (! empty($existing)) {
		$product_id = (int) $existing[0];
	} else {
		$product = new WC_Product_Simple();
		$product->set_name('Deposit');
		$product->set_slug('deposit-product');
		$product->set_status('publish');
		$product->set_catalog_visibility('hidden');
		$product->set_sku(DEPOSIT_SITE_PRODUCT_SKU);
		$product->set_regular_price(0);
		$product->set_price(0);
		$product->set_tax_status('none');
		$product->set_virtual(true);
		$product->set_sold_individually(true);
		$product->set_stock_status('instock');
		$product_id = $product->save();
	}

	if ($product_id) {
		update_option('deposit_site_product_id', $product_id);
	}

	return (int) $product_id;
}

function deposit_site_redirect_with_error($code)
{
	$back = home_url(add_query_arg(array()));
	wp_safe_redirect(add_query_arg('deposit_error', $code, remove_query_arg('deposit_error', $back)));
	exit;
}

/* -------------------------------------------------------------------------
 * Deposit form -> cart -> checkout
 * ---------------------------------------------------------------------- */

function deposit_site_handle_form()
{
	if (empty($_POST['deposit_site_submit'])) {
		return;
	}

	if (! function_exists('WC') || ! WC()->cart) {
		return;
	}

	$nonce = isset($_POST['deposit_site_nonce'])
		? sanitize_key(wp_unslash($_POST['deposit_site_nonce']))
		: '';

	if (! wp_verify_nonce($nonce, 'deposit_site_form')) {
		deposit_site_redirect_with_error('nonce');
	}

	$raw = isset($_POST['deposit_amount']) ? trim(wp_unslash($_POST['deposit_amount'])) : '';

	if (! is_numeric($raw)) {
		deposit_site_redirect_with_error('invalid');
	}

	$amount = round((float) $raw, deposit_site_decimals());

	if ($amount <= 0) {
		deposit_site_redirect_with_error('invalid');
	}
	if ($amount < deposit_site_min_amount()) {
		deposit_site_redirect_with_error('min');
	}
	if ($amount > deposit_site_max_amount()) {
		deposit_site_redirect_with_error('max');
	}

	$product_id = deposit_site_get_product_id();

	if (! $product_id) {
		deposit_site_redirect_with_error('cart');
	}

	WC()->cart->empty_cart();

	if (! WC()->cart->add_to_cart($product_id, 1, 0, array(), array('deposit_amount' => $amount))) {
		deposit_site_redirect_with_error('cart');
	}

	wp_safe_redirect(wc_get_checkout_url());
	exit;
}
add_action('template_redirect', 'deposit_site_handle_form');

function deposit_site_restore_cart_item($cart_item, $values, $key)
{
	if (isset($values['deposit_amount'])) {
		$cart_item['deposit_amount'] = (float) $values['deposit_amount'];
	}
	return $cart_item;
}
add_filter('woocommerce_get_cart_item_from_session', 'deposit_site_restore_cart_item', 10, 3);

/**
 * The entered amount is applied to the line item just before totals are
 * calculated, so the cart, checkout and order all show the real deposit value.
 */
function deposit_site_apply_price($cart)
{
	if (is_admin() && ! defined('DOING_AJAX')) {
		return;
	}
	if (! $cart instanceof WC_Cart || $cart->is_empty()) {
		return;
	}

	foreach ($cart->get_cart() as $item) {
		if (isset($item['deposit_amount']) && $item['data'] instanceof WC_Product) {
			$item['data']->set_price((float) $item['deposit_amount']);
		}
	}
}
add_action('woocommerce_before_calculate_totals', 'deposit_site_apply_price', 20);

/**
 * The deposit product must only ever reach the cart through the deposit form,
 * otherwise it would be added as a free line item.
 */
function deposit_site_block_direct_add($passed, $product_id)
{
	if ($product_id === (int) get_option('deposit_site_product_id', 0)) {
		wc_add_notice(__('Please enter a deposit amount first.', 'deposit-site'), 'error');
		return false;
	}
	return $passed;
}
add_filter('woocommerce_add_to_cart_validation', 'deposit_site_block_direct_add', 10, 2);

/* -------------------------------------------------------------------------
 * Checkout fields
 * ---------------------------------------------------------------------- */

function deposit_site_checkout_fields($fields)
{
	if (isset($fields['order']['order_comments'])) {
		$fields['order']['order_comments']['placeholder'] = '';
	}

	// TEMPORARY DEBUG: keep WooCommerce's own billing fields so we can tell whether
	// removing them made CardShield/PayPal draw its own billing address/name/ZIP block.
	// Shipping stays off, as before. Set DEPOSIT_SITE_DEBUG_FULL_BILLING to false to revert.
	if (DEPOSIT_SITE_DEBUG_FULL_BILLING) {
		$fields['shipping'] = array();

		// The company field is dropped by WC when its "Company field" setting is hidden,
		// so put it back for the test, using WooCommerce's own definition.
		if (! isset($fields['billing']['billing_company'])) {
			$visibility = class_exists('CartCheckoutUtils')
				? \CartCheckoutUtils::get_company_field_visibility()
				: (string) get_option('woocommerce_checkout_company_field', 'optional');

			$fields['billing']['billing_company'] = array(
				'label'        => __('Company name', 'woocommerce'),
				'placeholder'  => _x('Company name (optional)', 'placeholder', 'woocommerce'),
				'required'     => 'required' === $visibility,
				'class'        => array('form-row-wide'),
				'autocomplete' => 'organization',
				'priority'     => 30,
			);
		}

		if (isset($fields['billing']['billing_phone'])) {
			$fields['billing']['billing_phone']['required'] = true;
		}

		return $fields;
	}

	$keep = array('billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone');

	foreach (array('billing', 'shipping') as $section) {
		if (empty($fields[$section]) || ! is_array($fields[$section])) {
			continue;
		}
		foreach (array_keys($fields[$section]) as $key) {
			if (! in_array($key, $keep, true)) {
				unset($fields[$section][$key]);
			}
		}
	}

	if (isset($fields['billing']['billing_phone'])) {
		$fields['billing']['billing_phone']['required'] = true;
	}

	return $fields;
}
add_filter('woocommerce_checkout_fields', 'deposit_site_checkout_fields');

/**
 * The address section is gone, so the order still needs a billing country.
 */
function deposit_site_set_billing_country($order, $data)
{
	if (! $order->get_billing_country()) {
		$location = wc_get_base_location();
		if (! empty($location['country'])) {
			$order->set_billing_country($location['country']);
		}
	}
	return $order;
}
add_filter('woocommerce_checkout_create_order', 'deposit_site_set_billing_country', 10, 2);

/* -------------------------------------------------------------------------
 * PayPal checkout button timing
 * ---------------------------------------------------------------------- */

/**
 * Prime the CardShield PayPal session before the checkout form is rendered.
 *
 * CardShield resolves its PayPal proxy/shield URL on `wp_head` and stores it in
 * the WooCommerce session; the gateway reads it back when it builds the PayPal
 * button container. Block themes (Twenty Twenty-Five) render the block template
 * — and therefore the checkout shortcode — *before* `wp_head` runs, so on a cold
 * session the container is built while that session value is still empty and the
 * PayPal button only shows up after a manual reload. Running the same setup one
 * step earlier fixes the first paint; CardShield's own `wp_head` pass still
 * prints its head tags.
 */
function deposit_site_prime_paypal_checkout_session()
{
	if (! function_exists('is_checkout') || ! is_checkout()) {
		return;
	}
	if (! function_exists('cs_pp_action_wp_head') || ! function_exists('WC') || ! WC()->session) {
		return;
	}

	ob_start();
	cs_pp_action_wp_head();
	ob_end_clean();
}
add_action('template_redirect', 'deposit_site_prime_paypal_checkout_session', 5);

/* -------------------------------------------------------------------------
 * Deposit checkout order summary
 * ---------------------------------------------------------------------- */

/**
 * Whether the current cart is the single-line Deposit checkout.
 */
function deposit_site_is_deposit_checkout()
{
	if (! function_exists('WC') || ! WC()->cart || WC()->cart->is_empty()) {
		return false;
	}

	$product_id = (int) get_option('deposit_site_product_id', 0);

	foreach (WC()->cart->get_cart() as $cart_item) {
		if (isset($cart_item['deposit_amount']) || ($product_id && (int) $cart_item['product_id'] === $product_id)) {
			return true;
		}
	}

	return false;
}

/**
 * The deposit is a single line item, so the "x 1" quantity is noise.
 */
function deposit_site_hide_checkout_quantity($quantity)
{
	if (deposit_site_is_deposit_checkout()) {
		return '';
	}
	return $quantity;
}
add_filter('woocommerce_checkout_cart_item_quantity', 'deposit_site_hide_checkout_quantity');

/**
 * Tag the Deposit checkout so its redundant Subtotal row can be hidden.
 */
function deposit_site_checkout_body_class($classes)
{
	if (deposit_site_is_deposit_checkout()) {
		$classes[] = 'deposit-site-checkout';
	}
	return $classes;
}
add_filter('body_class', 'deposit_site_checkout_body_class');

/* -------------------------------------------------------------------------
 * Presentation
 * ---------------------------------------------------------------------- */

function deposit_site_form_shortcode()
{
	$error = isset($_GET['deposit_error']) ? sanitize_key(wp_unslash($_GET['deposit_error'])) : '';

	ob_start();
	deposit_site_deposit_form($error);
	return ob_get_clean();
}
add_shortcode('deposit_form', 'deposit_site_form_shortcode');

function deposit_site_enqueue_assets()
{
	wp_enqueue_style(
		'deposit-site',
		plugins_url('assets/style.css', __FILE__),
		array(),
		DEPOSIT_SITE_VERSION
	);
}
add_action('wp_enqueue_scripts', 'deposit_site_enqueue_assets');

/**
 * Back button target, based on the current page. Never uses browser history.
 *
 *  - cart / checkout -> Deposit home ("/")
 *  - Deposit home    -> configured Redirect URL from WP-Admin
 *  - anything else   -> Deposit home ("/")
 */
function deposit_site_back_url()
{
	if (function_exists('is_checkout') && (is_checkout() || is_cart())) {
		return home_url('/');
	}

	$page_id         = (int) get_option('deposit_site_page_id', 0);
	$is_deposit_home = is_front_page() || ($page_id && is_page($page_id));

	if ($is_deposit_home) {
		$redirect = deposit_site_redirect_url();
		if ('' !== $redirect) {
			return $redirect;
		}
	}

	return home_url('/');
}

function deposit_site_render_back_header()
{
	deposit_site_header(deposit_site_back_url());
}
add_action('wp_body_open', 'deposit_site_render_back_header');

/**
 * Send any front-end WordPress 404 to the Deposit home.
 */
function deposit_site_redirect_404()
{
	if (is_admin() || wp_doing_ajax() || ! is_404()) {
		return;
	}

	wp_safe_redirect(home_url('/'));
	exit;
}
add_action('template_redirect', 'deposit_site_redirect_404');

/**
 * Drop every core/template-part block (site header, footer, navigation, cart
 * link, mini cart, customer account) so the plugin owns the page chrome.
 */
function deposit_site_suppress_chrome($pre_render, $parsed_block)
{
	if (is_admin()) {
		return $pre_render;
	}

	if (isset($parsed_block['blockName']) && 'core/template-part' === $parsed_block['blockName']) {
		return '';
	}

	return $pre_render;
}
add_filter('pre_render_block', 'deposit_site_suppress_chrome', 10, 2);

/**
 * Coupon form removal. Must run after WooCommerce loads its template hooks on
 * `init` priority 0.
 */
function deposit_site_remove_wc_ui_hooks()
{
	remove_action('woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10);
}
add_action('init', 'deposit_site_remove_wc_ui_hooks', 20);

/**
 * WooCommerce serves the order-received endpoint from its own block template,
 * which never renders the checkout page content. Drop that template from the
 * hierarchy so the classic checkout shortcode runs and `checkout/thankyou.php`
 * (routed to templates/ui.php above) is used instead.
 */
function deposit_site_force_classic_order_received($templates)
{
	if (is_admin()) {
		return $templates;
	}
	return array_values(array_diff($templates, array('order-confirmation')));
}
add_filter('page_template_hierarchy', 'deposit_site_force_classic_order_received', 2);

/* -------------------------------------------------------------------------
 * Templates
 * ---------------------------------------------------------------------- */

/**
 * Route WooCommerce templates to this plugin's presentation files.
 */
function deposit_site_route_template($template, $template_name)
{
	if ('checkout/thankyou.php' === $template_name) {
		return DEPOSIT_SITE_DIR . 'templates/ui.php';
	}

	$order_emails = array(
		'emails/customer-processing-order.php',
		'emails/customer-completed-order.php',
		'emails/customer-on-hold-order.php',
		'emails/customer-invoice.php',
		'emails/customer-failed-order.php',
		'emails/admin-new-order.php',
		'emails/admin-cancelled-order.php',
		'emails/admin-failed-order.php',
	);

	if (in_array($template_name, $order_emails, true)) {
		return DEPOSIT_SITE_DIR . 'email.php';
	}

	return $template;
}
add_filter('wc_get_template', 'deposit_site_route_template', 10, 2);

/* -------------------------------------------------------------------------
 * Email
 * ---------------------------------------------------------------------- */

/**
 * Format an amount as plain text (no HTML) for email output.
 */
function deposit_site_format_email_money($amount, $currency)
{
	return html_entity_decode(
		wp_strip_all_tags(wc_price((float) $amount, array('currency' => $currency))),
		ENT_QUOTES,
		'UTF-8'
	);
}

/**
 * Total / PayPal fee / net for an order.
 *
 * The real PayPal transaction fee is captured by the CardShield gateway into
 * order meta `_cs_paypal_fee` (with `_cs_paypal_currency`) once PayPal settles
 * the capture. It is not yet available while an order is still awaiting
 * confirmation, so `fee` and `net` are returned as null and callers render a
 * neutral placeholder instead of inventing a value. Net = Total - Fee.
 *
 * @return array{total:string,fee:?string,net:?string}
 */
function deposit_site_order_payment_summary($order)
{
	$currency = $order->get_currency();
	$total    = (float) $order->get_total();
	$fee_raw  = $order->get_meta('_cs_paypal_fee');
	$has_fee  = ('' !== $fee_raw && null !== $fee_raw && is_numeric($fee_raw));
	$fee      = $has_fee ? (float) $fee_raw : null;

	return array(
		'total' => deposit_site_format_email_money($total, $currency),
		'fee'   => null === $fee ? null : deposit_site_format_email_money($fee, $currency),
		'net'   => null === $fee ? null : deposit_site_format_email_money($total - $fee, $currency),
	);
}

function deposit_site_render_email($order)
{
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
function deposit_site_send_confirmation_email($order_id, $posted_data, $order)
{
	unset($posted_data);

	if (! $order instanceof WC_Order) {
		$order = wc_get_order($order_id);
	}

	if (! $order instanceof WC_Order) {
		return;
	}

	if (! $order->get_billing_email()) {
		return;
	}

	if ('yes' === $order->get_meta('_deposit_confirmation_sent')) {
		return;
	}

	$order->update_meta_data('_deposit_confirmation_sent', 'yes');
	$order->save();

	$subject = sprintf(
		/* translators: %s: order number */
		__('Deposit order received - #%s', 'deposit-site'),
		$order->get_order_number()
	);

	wp_mail(
		$order->get_billing_email(),
		$subject,
		deposit_site_render_email($order),
		array('Content-Type: text/html; charset=UTF-8')
	);
}
add_action('woocommerce_checkout_order_processed', 'deposit_site_send_confirmation_email', 10, 3);

/* -------------------------------------------------------------------------
 * Admin settings
 * ---------------------------------------------------------------------- */

function deposit_site_register_settings()
{
	register_setting(
		'deposit_site_settings',
		'deposit_site_min_amount',
		array(
			'type'              => 'number',
			'sanitize_callback' => 'deposit_site_sanitize_amount',
			'default'           => DEPOSIT_SITE_MIN_AMOUNT,
		)
	);

	register_setting(
		'deposit_site_settings',
		'deposit_site_max_amount',
		array(
			'type'              => 'number',
			'sanitize_callback' => 'deposit_site_sanitize_amount',
			'default'           => DEPOSIT_SITE_MAX_AMOUNT,
		)
	);

	register_setting(
		'deposit_site_settings',
		'deposit_site_redirect_url',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		)
	);

	add_settings_section(
		'deposit_site_main',
		__('Deposit configuration', 'deposit-site'),
		'__return_false',
		'deposit_site_settings'
	);

	add_settings_field(
		'deposit_site_min_amount',
		__('Minimum Amount', 'deposit-site'),
		'deposit_site_render_amount_field',
		'deposit_site_settings',
		'deposit_site_main',
		array(
			'option'  => 'deposit_site_min_amount',
			'default' => DEPOSIT_SITE_MIN_AMOUNT,
		)
	);

	add_settings_field(
		'deposit_site_max_amount',
		__('Maximum Amount', 'deposit-site'),
		'deposit_site_render_amount_field',
		'deposit_site_settings',
		'deposit_site_main',
		array(
			'option'  => 'deposit_site_max_amount',
			'default' => DEPOSIT_SITE_MAX_AMOUNT,
		)
	);

	add_settings_field(
		'deposit_site_redirect_url',
		__('Redirect URL', 'deposit-site'),
		'deposit_site_render_url_field',
		'deposit_site_settings',
		'deposit_site_main'
	);
}
add_action('admin_init', 'deposit_site_register_settings');

function deposit_site_sanitize_amount($value)
{
	return is_numeric($value) ? (float) $value : 0;
}

function deposit_site_render_amount_field($args)
{
	$option = $args['option'];
	$value  = get_option($option, $args['default']);
?>
	<input
		type="number"
		step="any"
		min="0"
		name="<?php echo esc_attr($option); ?>"
		id="<?php echo esc_attr($option); ?>"
		value="<?php echo esc_attr($value); ?>"
		class="regular-text" />
<?php
}

function deposit_site_render_url_field()
{
?>
	<input
		type="url"
		name="deposit_site_redirect_url"
		id="deposit_site_redirect_url"
		value="<?php echo esc_attr(deposit_site_redirect_url()); ?>"
		class="regular-text"
		placeholder="<?php echo esc_attr(home_url('/')); ?>" />
	<p class="description"><?php esc_html_e('Target of the Back button on the cart and checkout pages. Leave empty to use the Deposit page.', 'deposit-site'); ?></p>
<?php
}

function deposit_site_settings_page()
{
	if (! current_user_can('manage_options')) {
		return;
	}
?>
	<div class="wrap">
		<h1><?php esc_html_e('Deposit Site', 'deposit-site'); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields('deposit_site_settings');
			do_settings_sections('deposit_site_settings');
			submit_button();
			?>
		</form>
	</div>
<?php
}

function deposit_site_add_settings_page()
{
	add_options_page(
		__('Deposit Site', 'deposit-site'),
		__('Deposit Site', 'deposit-site'),
		'manage_options',
		'deposit-site',
		'deposit_site_settings_page'
	);
}
add_action('admin_menu', 'deposit_site_add_settings_page');

/* -------------------------------------------------------------------------
 * Activation
 * ---------------------------------------------------------------------- */

function deposit_site_activate()
{
	update_option('woocommerce_coming_soon', 'no');

	$pages = array(
		'woocommerce_cart_page_id'     => '[woocommerce_cart]',
		'woocommerce_checkout_page_id' => '[woocommerce_checkout]',
	);

	foreach ($pages as $option => $content) {
		$page_id = (int) get_option($option, 0);
		if ($page_id && get_post_status($page_id)) {
			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => $content,
				)
			);
		}
	}

	$page_id = (int) get_option('deposit_site_page_id', 0);

	if (! $page_id || 'publish' !== get_post_status($page_id)) {
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

		if ($existing) {
			$args['ID'] = (int) $existing[0];
		}

		$page_id = wp_insert_post($args, true);

		if (! is_wp_error($page_id)) {
			update_option('deposit_site_page_id', (int) $page_id);
		}
	}
}
register_activation_hook(__FILE__, 'deposit_site_activate');
