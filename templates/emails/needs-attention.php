<?php
/**
 * Admin email: an order was flagged for review.
 *
 * Override by copying to yourtheme/flexacommerce/emails/needs-attention.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,templates:\Flexa\Commerce\Notifications\EmailTemplates,reason:string} $args
 */

defined( 'ABSPATH' ) || exit;

$fxcm_order = $args['order'];
$fxcm_url   = $args['to_customer'] ? $args['data']->customer_url( $fxcm_order ) : $args['data']->admin_url( $fxcm_order );
?>
<p style="margin:0;">
	<?php
	/* translators: %s: order number. */
	echo esc_html( sprintf( __( 'Order #%s needs your attention.', 'flexacommerce' ), $fxcm_order->number ) );
	?>
</p>
<?php if ( $args['reason'] !== '' ) : ?>
	<p style="margin:16px 0 0;padding:16px;background:#fef3c7;border-radius:6px;"><?php echo esc_html( $args['reason'] ); ?></p>
<?php endif; ?>
<?php
echo $args['templates']->render( 'parts/order-details', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
if ( $fxcm_url !== '' ) :
	?>
	<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $fxcm_url ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php echo $args['to_customer'] ? esc_html__( 'View your order', 'flexacommerce' ) : esc_html__( 'Open the order', 'flexacommerce' ); ?></a></p>
	<?php
endif;
