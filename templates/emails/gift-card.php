<?php
/**
 * Customer email: a gift card code for its recipient (ADR-069).
 *
 * Override by copying to yourtheme/flexacommerce/emails/gift-card.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,amount:string,code:string,recipient_name:string,sender_name:string,message:string,expires:string,shop_url:string} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<?php if ( $args['recipient_name'] !== '' ) : ?>
	<p style="margin:0 0 16px;">
		<?php
		/* translators: %s: recipient name. */
		echo esc_html( sprintf( __( 'Hi %s,', 'flexacommerce' ), $args['recipient_name'] ) );
		?>
	</p>
<?php endif; ?>
<p style="margin:0 0 16px;">
	<?php
	/* translators: 1: who sends the card, 2: gift card amount. */
	echo esc_html( sprintf( __( '%1$s sent you a gift card worth %2$s.', 'flexacommerce' ), $args['sender_name'], $args['amount'] ) );
	?>
</p>
<?php if ( $args['message'] !== '' ) : ?>
	<blockquote style="margin:0 0 16px;padding:12px 16px;border-left:3px solid #d4d4d8;color:#3f3f46;"><?php echo wp_kses_post( nl2br( esc_html( $args['message'] ) ) ); ?></blockquote>
<?php endif; ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:8px 0 16px;">
	<tr>
		<td style="padding:18px;border:1px dashed #a1a1aa;border-radius:6px;text-align:center;">
			<div style="font-size:12px;color:#71717a;text-transform:uppercase;letter-spacing:0.05em;"><?php esc_html_e( 'Gift card code', 'flexacommerce' ); ?></div>
			<div style="margin-top:6px;font-family:Menlo,Consolas,monospace;font-size:22px;font-weight:700;letter-spacing:0.08em;"><?php echo esc_html( $args['code'] ); ?></div>
			<div style="margin-top:6px;font-size:16px;font-weight:600;"><?php echo esc_html( $args['amount'] ); ?></div>
		</td>
	</tr>
</table>
<p style="margin:0 0 16px;"><?php esc_html_e( 'Enter the code in the cart or at checkout. What you do not spend stays on the card for next time.', 'flexacommerce' ); ?></p>
<?php if ( $args['expires'] !== '' ) : ?>
	<p style="margin:0 0 16px;font-size:13px;color:#71717a;">
		<?php
		/* translators: %s: expiry date. */
		echo esc_html( sprintf( __( 'Use it before %s.', 'flexacommerce' ), $args['expires'] ) );
		?>
	</p>
<?php endif; ?>
<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $args['shop_url'] ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php esc_html_e( 'Go to the shop', 'flexacommerce' ); ?></a></p>
