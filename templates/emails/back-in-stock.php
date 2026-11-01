<?php
/**
 * Customer email: a product the customer asked about is back in stock (ADR-065).
 *
 * Override by copying to yourtheme/flexacommerce/emails/back-in-stock.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,message:string,product:string,image:string,product_url:string,unsubscribe_url:string} $args
 */

defined( 'ABSPATH' ) || exit;
?>
<?php echo wp_kses_post( wpautop( esc_html( $args['message'] ) ) ); ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;margin-top:16px;">
	<tr>
		<?php if ( $args['image'] !== '' ) : ?>
			<td style="padding:10px 0;width:112px;vertical-align:middle;"><img src="<?php echo esc_url( $args['image'] ); ?>" alt="" width="96" height="96" style="display:block;width:96px;height:96px;border-radius:4px;object-fit:cover;"></td>
		<?php endif; ?>
		<td style="padding:10px 0;vertical-align:middle;font-weight:600;font-size:16px;"><?php echo esc_html( $args['product'] ); ?></td>
	</tr>
</table>
<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $args['product_url'] ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php esc_html_e( 'View the product', 'flexacommerce' ); ?></a></p>
<p style="margin:32px 0 0;font-size:12px;color:#71717a;">
	<?php esc_html_e( 'You get this email because you asked to hear when this product is back in stock. We send it once.', 'flexacommerce' ); ?>
	<a href="<?php echo esc_url( $args['unsubscribe_url'] ); ?>" style="color:#71717a;"><?php esc_html_e( 'Stop back-in-stock emails', 'flexacommerce' ); ?></a>
</p>
