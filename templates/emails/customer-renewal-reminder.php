<?php
/**
 * Customer email: a subscription renews in a few days, or its free trial
 * ends (ADR-075).
 *
 * Override by copying to yourtheme/flexacommerce/emails/customer-renewal-reminder.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,templates:\Flexa\Commerce\Notifications\EmailTemplates,title:string,price:string,renews_on:string,trial:bool,automatic:bool,payment_label:string,subscription_url:string} $args
 */

defined( 'ABSPATH' ) || exit;

$fxcm_url = $args['subscription_url'] !== '' ? $args['subscription_url'] : $args['data']->customer_url( $args['order'] );
?>
<p style="margin:0;">
	<?php
	if ( $args['trial'] ) {
		/* translators: %s: date the trial ends. */
		echo esc_html( sprintf( __( 'Your free trial ends on %s. Your subscription then starts its first paid period.', 'flexacommerce' ), $args['renews_on'] ) );
	} else {
		/* translators: %s: renewal date. */
		echo esc_html( sprintf( __( 'Your subscription renews on %s.', 'flexacommerce' ), $args['renews_on'] ) );
	}
	?>
</p>
<p style="margin:12px 0 0;">
	<?php
	if ( $args['automatic'] && $args['payment_label'] !== '' ) {
		/* translators: %s: saved payment method, like "Visa ending in 4242". */
		echo esc_html( sprintf( __( 'We will charge %s on that day. You do not need to do anything.', 'flexacommerce' ), $args['payment_label'] ) );
	} elseif ( $args['automatic'] ) {
		esc_html_e( 'We will charge your saved payment method on that day. You do not need to do anything.', 'flexacommerce' );
	} else {
		esc_html_e( 'On that day we will email you a link to pay the renewal.', 'flexacommerce' );
	}
	?>
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;margin-top:16px;">
	<tr>
		<td style="padding:6px 0;color:#71717a;width:40%;"><?php esc_html_e( 'Subscription', 'flexacommerce' ); ?></td>
		<td style="padding:6px 0;"><?php echo esc_html( $args['title'] ); ?></td>
	</tr>
	<tr>
		<td style="padding:6px 0;color:#71717a;"><?php esc_html_e( 'Price', 'flexacommerce' ); ?></td>
		<td style="padding:6px 0;"><?php echo esc_html( $args['price'] ); ?></td>
	</tr>
	<tr>
		<td style="padding:6px 0;color:#71717a;"><?php echo $args['trial'] ? esc_html__( 'First payment', 'flexacommerce' ) : esc_html__( 'Next payment', 'flexacommerce' ); ?></td>
		<td style="padding:6px 0;"><?php echo esc_html( $args['renews_on'] ); ?></td>
	</tr>
	<?php if ( $args['payment_label'] !== '' ) : ?>
		<tr>
			<td style="padding:6px 0;color:#71717a;"><?php esc_html_e( 'Payment method', 'flexacommerce' ); ?></td>
			<td style="padding:6px 0;"><?php echo esc_html( $args['payment_label'] ); ?></td>
		</tr>
	<?php endif; ?>
</table>
<?php if ( $fxcm_url !== '' ) : ?>
	<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $fxcm_url ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php esc_html_e( 'Manage your subscription', 'flexacommerce' ); ?></a></p>
	<?php if ( $args['subscription_url'] !== '' ) : ?>
		<p style="margin:8px 0 0;font-size:13px;color:#71717a;"><?php esc_html_e( 'You can change the payment method or cancel from that page before the renewal.', 'flexacommerce' ); ?></p>
	<?php endif; ?>
<?php endif; ?>
