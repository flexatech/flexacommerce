<?php
/**
 * Customer email: a refund was made, full or partial.
 *
 * Override by copying to yourtheme/flexacommerce/emails/customer-refunded-order.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,templates:\Flexa\Commerce\Notifications\EmailTemplates,refund:?\Flexa\Commerce\Contracts\Payments\TransactionView,full:bool} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_order = $args['order'];
$fxc_url   = $args['to_customer'] ? $args['data']->customer_url( $fxc_order ) : $args['data']->admin_url( $fxc_order );
?>
<p style="margin:0;">
	<?php
	if ( $args['refund'] !== null ) {
		/* translators: 1: refunded amount, 2: order number. */
		echo esc_html( sprintf( __( 'We refunded %1$s for your order #%2$s.', 'flexacommerce' ), $args['data']->money( $args['refund']->amount ), $fxc_order->number ) );
	}
	if ( $args['full'] ) {
		echo ' ';
		esc_html_e( 'The order is now fully refunded.', 'flexacommerce' );
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
