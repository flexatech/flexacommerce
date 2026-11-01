<?php
/**
 * Customer email: a subscription's renewal order waits for its payment (ADR-073).
 *
 * Override by copying to yourtheme/flexacommerce/emails/customer-renewal-invoice.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,templates:\Flexa\Commerce\Notifications\EmailTemplates,pay_url:string} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_order = $args['order'];
$fxc_url   = $args['pay_url'] !== '' ? $args['pay_url'] : $args['data']->customer_url( $fxc_order );
?>
<p style="margin:0;"><?php esc_html_e( 'Your subscription renews today. Pay the renewal order with the link below to keep it running.', 'flexacommerce' ); ?></p>
<?php
echo $args['templates']->render( 'parts/order-details', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
echo $args['templates']->render( 'parts/addresses', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
if ( $fxc_url !== '' ) :
	?>
	<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $fxc_url ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php echo $args['pay_url'] !== '' ? esc_html__( 'Pay for this renewal', 'flexacommerce' ) : esc_html__( 'View your order', 'flexacommerce' ); ?></a></p>
	<?php
endif;
