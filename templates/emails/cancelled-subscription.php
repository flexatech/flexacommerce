<?php
/**
 * Admin email: a subscription was cancelled (ADR-073).
 *
 * Override by copying to yourtheme/flexacommerce/emails/cancelled-subscription.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,subscription:\Flexa\Commerce\Subscriptions\Subscription,title:string,price:string,ends:string,url:string} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_sub = $args['subscription'];
?>
<p style="margin:0;">
	<?php
	echo esc_html(
		sprintf(
			/* translators: 1: subscription number, 2: customer email. */
			__( 'Subscription #%1$d of %2$s was cancelled.', 'flexacommerce' ),
			$fxc_sub->id,
			$fxc_sub->customer_email
		)
	);
	?>
</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;margin-top:16px;">
	<tr>
		<td style="padding:6px 0;color:#71717a;width:40%;"><?php esc_html_e( 'Products', 'flexacommerce' ); ?></td>
		<td style="padding:6px 0;"><?php echo esc_html( $args['title'] ); ?></td>
	</tr>
	<tr>
		<td style="padding:6px 0;color:#71717a;"><?php esc_html_e( 'Price', 'flexacommerce' ); ?></td>
		<td style="padding:6px 0;"><?php echo esc_html( $args['price'] ); ?></td>
	</tr>
	<?php if ( $args['ends'] !== '' ) : ?>
		<tr>
			<td style="padding:6px 0;color:#71717a;"><?php esc_html_e( 'Ends', 'flexacommerce' ); ?></td>
			<td style="padding:6px 0;"><?php echo esc_html( $args['ends'] ); ?></td>
		</tr>
	<?php endif; ?>
</table>
<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $args['url'] ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php esc_html_e( 'View the subscription', 'flexacommerce' ); ?></a></p>
