<?php
/**
 * Admin and customer email: the order was cancelled.
 *
 * Override by copying to yourtheme/flexacommerce/emails/cancelled-order.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,templates:\Flexa\Commerce\Notifications\EmailTemplates} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_order = $args['order'];
$fxc_url   = $args['to_customer'] ? $args['data']->customer_url( $fxc_order ) : $args['data']->admin_url( $fxc_order );
?>
<p style="margin:0;">
	<?php
	if ( $args['to_customer'] ) {
		/* translators: %s: order number. */
		echo esc_html( sprintf( __( 'Your order #%s was cancelled.', 'flexacommerce' ), $fxc_order->number ) );
		if ( ! $fxc_order->refundable()->is_zero() ) {
			echo ' ';
			/* translators: %s: amount paid. */
			echo esc_html( sprintf( __( 'You paid %s for it. We will contact you about the refund.', 'flexacommerce' ), $args['data']->money( $fxc_order->refundable() ) ) );
		}
	} else {
		/* translators: 1: order number, 2: customer name or email. */
		echo esc_html( sprintf( __( 'Order #%1$s from %2$s was cancelled.', 'flexacommerce' ), $fxc_order->number, $fxc_order->customer_name !== '' ? $fxc_order->customer_name : $fxc_order->customer_email ) );
		if ( ! $fxc_order->refundable()->is_zero() ) {
			echo ' ';
			/* translators: %s: amount paid. */
			echo esc_html( sprintf( __( 'The customer paid %s and nothing was refunded automatically.', 'flexacommerce' ), $args['data']->money( $fxc_order->refundable() ) ) );
		}
	}
	?>
</p>
<?php
echo $args['templates']->render( 'parts/order-details', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
if ( $fxc_url !== '' ) :
	?>
	<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $fxc_url ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php echo $args['to_customer'] ? esc_html__( 'View your order', 'flexacommerce' ) : esc_html__( 'Open the order', 'flexacommerce' ); ?></a></p>
	<?php
endif;
