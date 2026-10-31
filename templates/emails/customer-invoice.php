<?php
/**
 * Customer email: the order's details, sent by the store from the order screen. Links to the pay page while a payment is due.
 *
 * Override by copying to yourtheme/flexacommerce/emails/customer-invoice.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,templates:\Flexa\Commerce\Notifications\EmailTemplates,pay_url:string} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_order = $args['order'];
$fxc_url   = $args['pay_url'] !== '' ? $args['pay_url'] : $args['data']->customer_url( $fxc_order );
?>
<?php if ( $args['pay_url'] !== '' ) : ?>
	<p style="margin:0;"><?php esc_html_e( 'We have created an order for you. You can pay for it with the link below.', 'flexacommerce' ); ?></p>
<?php else : ?>
	<p style="margin:0;"><?php esc_html_e( 'Here are the details of your order.', 'flexacommerce' ); ?></p>
<?php endif; ?>
<?php
echo $args['templates']->render( 'parts/order-details', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
echo $args['templates']->render( 'parts/addresses', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
if ( $fxc_url !== '' ) :
	?>
	<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $fxc_url ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php echo $args['pay_url'] !== '' ? esc_html__( 'Pay for this order', 'flexacommerce' ) : esc_html__( 'View your order', 'flexacommerce' ); ?></a></p>
	<?php
endif;
