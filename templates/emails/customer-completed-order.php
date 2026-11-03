<?php
/**
 * Customer email: the order is complete.
 *
 * Override by copying to yourtheme/flexacommerce/emails/customer-completed-order.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,templates:\Flexa\Commerce\Notifications\EmailTemplates} $args
 */

defined( 'ABSPATH' ) || exit;

$fxcm_order = $args['order'];
$fxcm_url   = $args['to_customer'] ? $args['data']->customer_url( $fxcm_order ) : $args['data']->admin_url( $fxcm_order );
?>
<p style="margin:0;">
	<?php
	/* translators: %s: order number. */
	echo esc_html( sprintf( __( 'Your order #%s is complete. Thank you for shopping with us.', 'flexacommerce' ), $fxcm_order->number ) );
	?>
</p>
<?php
echo $args['templates']->render( 'parts/downloads', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
echo $args['templates']->render( 'parts/shipments', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
echo $args['templates']->render( 'parts/order-details', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
echo $args['templates']->render( 'parts/addresses', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
if ( $fxcm_url !== '' ) :
	?>
	<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $fxcm_url ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php echo $args['to_customer'] ? esc_html__( 'View your order', 'flexacommerce' ) : esc_html__( 'Open the order', 'flexacommerce' ); ?></a></p>
	<?php
endif;
