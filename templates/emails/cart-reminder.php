<?php
/**
 * Customer email: a reminder about a cart left without an order (ADR-063), with a link that brings the cart back.
 *
 * Override by copying to yourtheme/flexacommerce/emails/cart-reminder.php.
 *
 * @version 1.0.0
 *
 * @var array{email_id:string,message:string,items:list<array{name:string,quantity:int,total:string,image:string}>,total:string,coupon:array{code:string,text:string}|null,recover_url:string,unsubscribe_url:string} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_cell = 'padding:10px 0;border-bottom:1px solid #e4e4e7;vertical-align:middle;';
?>
<?php echo wp_kses_post( wpautop( esc_html( $args['message'] ) ) ); ?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;margin-top:16px;">
	<?php foreach ( $args['items'] as $fxc_item ) : ?>
		<tr>
			<?php if ( $fxc_item['image'] !== '' ) : ?>
				<td style="<?php echo esc_attr( $fxc_cell ); ?>width:64px;"><img src="<?php echo esc_url( $fxc_item['image'] ); ?>" alt="" width="48" height="48" style="display:block;width:48px;height:48px;border-radius:4px;object-fit:cover;"></td>
			<?php else : ?>
				<td style="<?php echo esc_attr( $fxc_cell ); ?>width:0;"></td>
			<?php endif; ?>
			<td style="<?php echo esc_attr( $fxc_cell ); ?>"><?php echo esc_html( $fxc_item['name'] ); ?></td>
			<td style="<?php echo esc_attr( $fxc_cell ); ?>text-align:center;width:48px;white-space:nowrap;">&times; <?php echo esc_html( (string) $fxc_item['quantity'] ); ?></td>
			<td style="<?php echo esc_attr( $fxc_cell ); ?>text-align:right;white-space:nowrap;"><?php echo esc_html( $fxc_item['total'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if ( $args['total'] !== '' ) : ?>
		<tr>
			<td colspan="3" style="padding:10px 0;font-weight:600;"><?php esc_html_e( 'Subtotal', 'flexacommerce' ); ?></td>
			<td style="padding:10px 0;text-align:right;font-weight:600;white-space:nowrap;"><?php echo esc_html( $args['total'] ); ?></td>
		</tr>
	<?php endif; ?>
</table>
<?php if ( $args['coupon'] !== null ) : ?>
	<div style="margin:24px 0 0;padding:16px;border:1px dashed #a1a1aa;border-radius:6px;text-align:center;">
		<p style="margin:0 0 8px;font-size:14px;color:#3f3f46;"><?php echo esc_html( $args['coupon']['text'] ); ?></p>
		<p style="margin:0;font-size:20px;font-weight:700;letter-spacing:1px;color:#18181b;"><?php echo esc_html( $args['coupon']['code'] ); ?></p>
	</div>
<?php endif; ?>
<p style="margin:24px 0 0;"><a href="<?php echo esc_url( $args['recover_url'] ); ?>" style="display:inline-block;padding:10px 18px;background:#18181b;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;"><?php esc_html_e( 'Return to your cart', 'flexacommerce' ); ?></a></p>
<?php if ( $args['coupon'] !== null ) : ?>
	<p style="margin:8px 0 0;font-size:13px;color:#71717a;"><?php esc_html_e( 'The link adds the code to your cart for you.', 'flexacommerce' ); ?></p>
<?php endif; ?>
<p style="margin:32px 0 0;font-size:12px;color:#71717a;">
	<?php esc_html_e( 'You get this email because you left items in your cart.', 'flexacommerce' ); ?>
	<a href="<?php echo esc_url( $args['unsubscribe_url'] ); ?>" style="color:#71717a;"><?php esc_html_e( 'Stop cart reminders', 'flexacommerce' ); ?></a>
</p>
