<?php
/**
 * Deposit Site - presentation layer.
 *
 * The file is loaded in two ways:
 *  1. required once by deposit-site.php, which defines the render helpers below;
 *  2. included by WooCommerce through the `wc_get_template` filter when it asks
 *     for `checkout/thankyou.php` - in that case `$order` is extracted into this
 *     scope by wc_get_template() and the confirmation view is rendered at the
 *     bottom of the file.
 *
 * @package deposit-site
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'deposit_site_header' ) ) {

	/**
	 * Minimal site header: a single Back button. Theme header, footer and
	 * navigation are suppressed by the plugin.
	 *
	 * @param string $back_url Target of the Back button.
	 */
	function deposit_site_header( $back_url ) {
		?>
		<header class="ds-header">
			<a class="ds-back" href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back', 'deposit-site' ); ?></a>
		</header>
		<?php
	}

	/**
	 * Deposit amount form.
	 *
	 * @param string $error_code Validation error code, empty when there is none.
	 */
	function deposit_site_deposit_form( $error_code = '' ) {
		$decimals = deposit_site_decimals();
		$symbol   = deposit_site_symbol();
		$step     = number_format( pow( 10, -$decimals ), $decimals, '.', '' );
		$min      = number_format( DEPOSIT_SITE_MIN_AMOUNT, $decimals, '.', '' );
		$max      = number_format( DEPOSIT_SITE_MAX_AMOUNT, $decimals, '.', '' );

		$limits = sprintf(
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

		$error = isset( $messages[ $error_code ] ) ? $messages[ $error_code ] : '';
		?>
		<form class="deposit-site-form ds-card" method="post">
			<?php wp_nonce_field( 'deposit_site_form', 'deposit_site_nonce' ); ?>
			<?php if ( $error ) : ?>
				<p class="ds-error"><?php echo esc_html( $error ); ?></p>
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
			<button class="ds-btn" type="submit" name="deposit_site_submit" value="1"><?php esc_html_e( 'Proceed to checkout', 'deposit-site' ); ?></button>
			<p class="deposit-site-hint"><?php echo wp_kses_post( $limits ); ?></p>
		</form>
		<?php
	}

	/**
	 * Standalone order confirmation view (WooCommerce's `checkout/thankyou.php`).
	 *
	 * @param WC_Order|false $order Order being confirmed, false when invalid.
	 */
	function deposit_site_render_order_received( $order ) {
		if ( ! $order instanceof WC_Order ) {
			?>
			<section class="ds-confirm">
				<div class="ds-confirm__mark">&#10003;</div>
				<h1 class="ds-confirm__title"><?php esc_html_e( 'Deposit', 'deposit-site' ); ?></h1>
				<p class="ds-confirm__closing"><?php esc_html_e( 'Order details are unavailable. Please contact us if you need a copy.', 'deposit-site' ); ?></p>
			</section>
			<?php
			return;
		}

		$status      = $order->get_status();
		$is_paid     = in_array( $status, array( 'processing', 'completed' ), true );
		$is_failed   = in_array( $status, array( 'failed', 'cancelled' ), true );
		$name        = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$amount      = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
		$payment     = $order->get_payment_method_title();
		$created     = wc_format_datetime( $order->get_date_created() );
		$deposit_url = get_permalink( (int) get_option( 'deposit_site_page_id', 0 ) );

		if ( $is_paid ) {
			$status_class = 'ds-confirm__status--ok';
			$status_label = __( 'Payment confirmed', 'deposit-site' );
			$note_class   = 'ds-confirm__note--ok';
			$note         = __( 'Your payment has been received. A copy of this confirmation has been sent to your email address.', 'deposit-site' );
		} elseif ( $is_failed ) {
			$status_class = 'ds-confirm__status--error';
			$status_label = __( 'Payment not completed', 'deposit-site' );
			$note_class   = 'ds-confirm__note--error';
			$note         = __( 'We could not confirm your payment for this deposit. Please contact us quoting your order number.', 'deposit-site' );
		} else {
			$status_class = 'ds-confirm__status--pending';
			$status_label = __( 'Awaiting payment confirmation', 'deposit-site' );
			$note_class   = '';
			$note         = __( 'Your deposit order has been received. We are awaiting payment confirmation and will email you as soon as it is confirmed.', 'deposit-site' );
		}

		$rows = array(
			__( 'Order number', 'deposit-site' ) => '#' . $order->get_order_number(),
			__( 'Date', 'deposit-site' )          => $created,
			__( 'Amount', 'deposit-site' )        => $amount,
			__( 'Payment method', 'deposit-site' ) => $payment ? $payment : '&#8212;',
		);

		if ( $name ) {
			$rows[ __( 'Name', 'deposit-site' ) ] = $name;
		}

		if ( $order->get_billing_email() ) {
			$rows[ __( 'Email', 'deposit-site' ) ] = $order->get_billing_email();
		}
		?>
		<section class="ds-confirm">
			<div class="ds-confirm__mark">&#10003;</div>
			<h1 class="ds-confirm__title"><?php esc_html_e( 'Deposit order received', 'deposit-site' ); ?></h1>
			<span class="ds-confirm__status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>

			<div class="ds-confirm__grid">
				<?php foreach ( $rows as $label => $value ) : ?>
					<div class="ds-confirm__row">
						<span class="ds-confirm__label"><?php echo esc_html( $label ); ?></span>
						<span class="ds-confirm__value"><?php echo esc_html( $value ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<p class="ds-confirm__note <?php echo esc_attr( $note_class ); ?>"><?php echo esc_html( $note ); ?></p>

			<?php if ( $deposit_url ) : ?>
				<a class="ds-btn" href="<?php echo esc_url( $deposit_url ); ?>"><?php esc_html_e( 'Back to deposit', 'deposit-site' ); ?></a>
			<?php endif; ?>
		</section>
		<?php
	}
}

// Rendered only when WooCommerce includes this file as `checkout/thankyou.php`.
if ( isset( $order ) ) {
	deposit_site_render_order_received( $order );
}
