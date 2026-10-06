<?php
/**
 * Deposit Site - deposit confirmation email.
 *
 * Single source of truth for every customer-facing deposit email:
 *  1. the confirmation sent with wp_mail() when the deposit order is created;
 *  2. WooCommerce customer order emails routed here through `wc_get_template`.
 *
 * Renders a complete, self contained, email-client-safe document. It never uses
 * the WooCommerce email header/footer, product images, address blocks or any
 * storefront navigation.
 *
 * Expected variable: $order (WC_Order)
 *
 * @package deposit-site
 */

if ( ! isset( $order ) || ! $order instanceof WC_Order ) {
	return;
}

$status        = $order->get_status();
$is_paid       = in_array( $status, array( 'processing', 'completed' ), true );
$is_failed     = in_array( $status, array( 'failed', 'cancelled' ), true );
$name          = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
$amount        = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
$payment       = $order->get_payment_method_title();
$created       = wc_format_datetime( $order->get_date_created() );
$billing_email = $order->get_billing_email();
$store         = get_bloginfo( 'name' );

$heading = __( 'Deposit Order Received', 'deposit-site' );

if ( $is_paid ) {
	$status_label = __( 'Payment confirmed', 'deposit-site' );
	$status_bg    = '#16a34a';
	$status_fg    = '#ffffff';
	$status_text  = __( 'Your payment has been received. This deposit is now confirmed.', 'deposit-site' );
} elseif ( $is_failed ) {
	$status_label = __( 'Payment not completed', 'deposit-site' );
	$status_bg    = '#dc2626';
	$status_fg    = '#ffffff';
	$status_text  = __( 'We could not confirm your payment for this deposit. Please contact us quoting your order number.', 'deposit-site' );
} else {
	$status_label = __( 'Awaiting payment confirmation', 'deposit-site' );
	$status_bg    = '#f59e0b';
	$status_fg    = '#111111';
	$status_text  = __( 'Your deposit order has been received. We are awaiting payment confirmation and will email you again as soon as it is confirmed.', 'deposit-site' );
}

$deposit_rows = array(
	__( 'Order number', 'deposit-site' )  => '#' . $order->get_order_number(),
	__( 'Date', 'deposit-site' )          => $created,
	__( 'Amount', 'deposit-site' )        => $amount,
	__( 'Payment method', 'deposit-site' ) => $payment ? $payment : '-',
	__( 'Name', 'deposit-site' )          => $name ? $name : '-',
	__( 'Email', 'deposit-site' )         => $billing_email ? $billing_email : '-',
);
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="x-apple-disable-message-reformatting">
	<title><?php echo esc_html( $heading ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#0e1116;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;background-color:#0e1116;">
	<tr>
		<td align="center" style="padding:24px 12px;background-color:#0e1116;">

			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:560px;border-collapse:collapse;background-color:#161b22;border:1px solid #2a313c;border-radius:10px;overflow:hidden;">
				<tr>
					<td style="padding:18px 24px;background-color:#0e1116;border-bottom:1px solid #2a313c;">
						<span style="display:block;color:#e6edf3;font-size:17px;font-weight:700;letter-spacing:0.02em;"><?php echo esc_html( $store ); ?></span>
						<span style="display:block;color:#9aa4b2;font-size:12px;text-transform:uppercase;letter-spacing:0.12em;margin-top:4px;"><?php esc_html_e( 'Secure deposit', 'deposit-site' ); ?></span>
					</td>
				</tr>
				<tr>
					<td style="padding:28px 24px 8px;background-color:#161b22;">
						<h1 style="margin:0;color:#e6edf3;font-size:24px;line-height:1.3;font-weight:700;"><?php echo esc_html( $heading ); ?></h1>
					</td>
				</tr>
				<tr>
					<td style="padding:8px 24px 20px;background-color:#161b22;">
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
							<tr>
								<td style="padding:8px 14px;background-color:<?php echo esc_attr( $status_bg ); ?>;border-radius:999px;color:<?php echo esc_attr( $status_fg ); ?>;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;">
									<?php echo esc_html( $status_label ); ?>
								</td>
							</tr>
						</table>
					</td>
				</tr>
				<tr>
					<td style="padding:0 24px 20px;background-color:#161b22;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;border:1px solid #2a313c;border-radius:8px;overflow:hidden;">
							<?php foreach ( $deposit_rows as $label => $value ) : ?>
								<tr>
									<td width="45%" valign="top" style="padding:12px 14px;background-color:#1c232c;border-bottom:1px solid #2a313c;color:#9aa4b2;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;">
										<?php echo esc_html( $label ); ?>
									</td>
									<td width="55%" valign="top" style="padding:12px 14px;background-color:#1c232c;border-bottom:1px solid #2a313c;color:#e6edf3;font-size:15px;font-weight:600;text-align:right;word-break:break-word;">
										<?php echo esc_html( $value ); ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					</td>
				</tr>
				<tr>
					<td style="padding:0 24px 20px;background-color:#161b22;">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">
							<tr>
								<td style="padding:14px 16px;background-color:#1c232c;border-left:4px solid <?php echo esc_attr( $status_bg ); ?>;border-radius:6px;color:#e6edf3;font-size:14px;line-height:1.5;">
									<?php echo esc_html( $status_text ); ?>
								</td>
							</tr>
						</table>
					</td>
				</tr>
				<tr>
					<td style="padding:0 24px 28px;background-color:#161b22;">
						<p style="margin:0;color:#9aa4b2;font-size:13px;line-height:1.6;">
							<?php esc_html_e( 'Thank you for your deposit. Please keep this email for your records - it contains your order number.', 'deposit-site' ); ?>
						</p>
						<p style="margin:10px 0 0;color:#9aa4b2;font-size:13px;line-height:1.6;">
							<?php
							printf(
								/* translators: %s: store name */
								esc_html__( '%s - deposit orders', 'deposit-site' ),
								esc_html( $store )
							);
							?>
						</p>
					</td>
				</tr>
			</table>

		</td>
	</tr>
</table>

</body>
</html>
