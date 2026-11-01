<?php
/**
 * Customer email: a subscription's renewal order waits for its payment
 * (ADR-073), or its automatic payment failed (ADR-074).
 *
 * Override by copying to yourtheme/flexacommerce/emails/customer-renewal-invoice.php.
 *
 * @version 1.1.0
 *
 * @var array{email_id:string,order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,templates:\Flexa\Commerce\Notifications\EmailTemplates,pay_url:string,failed:string,retry_on:string,final:bool,subscription_url:string} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_order  = $args['order'];
$fxc_url    = $args['pay_url'] !== '' ? $args['pay_url'] : $args['data']->customer_url( $fxc_order );
$fxc_button = 'display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;';
?>
<?php if ( $args['failed'] === '' ) : ?>
	<p style="margin:0;"><?php esc_html_e( 'Your subscription renews today. Pay the renewal order with the link below to keep it running.', 'flexacommerce' ); ?></p>
<?php else : ?>
	<p style="margin:0;">
		<?php
		/* translators: %s: why the payment failed, from the payment provider. */
		echo esc_html( sprintf( __( 'We could not take the renewal payment for your subscription: %s', 'flexacommerce' ), $args['failed'] ) );
		?>
	</p>
	<p style="margin:12px 0 0;">
		<?php
		if ( $args['retry_on'] !== '' ) {
			/* translators: %s: date of the next try. */
			echo esc_html( sprintf( __( 'We will try again on %s. To avoid a pause, pay now or change the payment method.', 'flexacommerce' ), $args['retry_on'] ) );
		} else {
			esc_html_e( 'We will not try again. Your subscription is paused until you pay the renewal order.', 'flexacommerce' );
		}
		?>
	</p>
<?php endif; ?>
<?php
echo $args['templates']->render( 'parts/order-details', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
echo $args['templates']->render( 'parts/addresses', $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped template.
if ( $fxc_url !== '' ) :
	?>
	<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $fxc_url ); ?>" style="<?php echo esc_attr( $fxc_button ); ?>"><?php echo $args['pay_url'] !== '' ? esc_html__( 'Pay for this renewal', 'flexacommerce' ) : esc_html__( 'View your order', 'flexacommerce' ); ?></a></p>
	<?php
endif;
if ( $args['failed'] !== '' && $args['subscription_url'] !== '' ) :
	?>
	<p style="margin:12px 0 0;"><a href="<?php echo esc_url( $args['subscription_url'] ); ?>"><?php esc_html_e( 'Change the payment method', 'flexacommerce' ); ?></a></p>
	<?php
endif;
