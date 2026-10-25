<?php
/**
 * Customer email: the order is on hold until the payment arrives, with the payment instructions.
 *
 * Override by copying to yourtheme/flexacommerce/emails/customer-on-hold-order.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,templates:\Flexa\Commerce\Notifications\EmailTemplates,instructions:string} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_order = $args['order'];
$fxc_url   = $args['to_customer'] ? $args['data']->customer_url( $fxc_order ) : $args['data']->admin_url( $fxc_order );
?>
<?php if ( $args['instructions'] === '' ) : ?>
	<p style="margin:0;"><?php esc_html_e( 'Thank you for your order. We will let you know once your payment is confirmed.', 'flexacommerce' ); ?></p>
<?php else : ?>
	<p style="margin:0;"><?php esc_html_e( 'Thank you for your order. It is on hold until we receive your payment.', 'flexacommerce' ); ?></p>
	<div style="margin:16px 0 0;padding:16px;background:#f4f4f5;border-radius:6px;">
		<h2 style="margin:0 0 8px;font-size:16px;font-weight:600;color:#18181b;"><?php esc_html_e( 'How to pay', 'flexacommerce' ); ?></h2>
		<p style="margin:0;"><?php echo nl2br( esc_html( $args['instructions'] ) ); ?></p>
	</div>
<?php endif; ?>
<?php
echo $args['templates']->render( 'parts/order-details', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
echo $args['templates']->render( 'parts/addresses', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
if ( $fxc_url !== '' ) :
	?>
	<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $fxc_url ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php echo $args['to_customer'] ? esc_html__( 'View your order', 'flexacommerce' ) : esc_html__( 'Open the order', 'flexacommerce' ); ?></a></p>
	<?php
endif;
