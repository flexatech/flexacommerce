<?php
/**
 * Customer email: a package of the order left, with its tracking (ADR-067).
 *
 * Override by copying to yourtheme/flexacommerce/emails/customer-shipped-order.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,templates:\Flexa\Commerce\Notifications\EmailTemplates,shipment:?\Flexa\Commerce\Orders\Shipment} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_order = $args['order'];
$fxc_url   = $args['data']->customer_url( $fxc_order );
?>
<p style="margin:0;">
	<?php
	/* translators: %s: order number. */
	echo esc_html( sprintf( __( 'Good news: your order #%s is on its way. You can follow the package with the details below.', 'flexacommerce' ), $fxc_order->number ) );
	?>
</p>
<?php
echo $args['templates']->render( 'parts/shipments', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
echo $args['templates']->render( 'parts/order-details', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
echo $args['templates']->render( 'parts/addresses', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
if ( $fxc_url !== '' ) :
	?>
	<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $fxc_url ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php esc_html_e( 'View your order', 'flexacommerce' ); ?></a></p>
	<?php
endif;
