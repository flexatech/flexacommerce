<?php
/**
 * The order lines and totals, shared by every order email.
 *
 * Override by copying to yourtheme/flexacommerce/emails/parts/order-details.php.
 *
 * @version 1.0.0
 *
 * @var array{order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_order = $args['order'];
$fxc_data  = $args['data'];
$fxc_cell  = 'padding:10px 0;border-bottom:1px solid #e4e4e7;vertical-align:top;';
$fxc_rows  = [
	[ __( 'Subtotal', 'flexacommerce' ), $fxc_order->subtotal ],
];
if ( ! $fxc_order->discount_total->is_zero() ) {
	$fxc_rows[] = [ __( 'Discount', 'flexacommerce' ), $fxc_order->discount_total->negate() ];
}
foreach ( $fxc_order->items_of_type( 'shipping' ) as $fxc_line ) {
	$fxc_rows[] = [ $fxc_line->name !== '' ? $fxc_line->name : __( 'Shipping', 'flexacommerce' ), $fxc_line->total ];
}
foreach ( $fxc_order->items_of_type( 'fee' ) as $fxc_line ) {
	$fxc_rows[] = [ $fxc_line->name, $fxc_line->total ];
}
// One row per tax rate; orders without tax lines (imported ones) show the sum.
$fxc_taxes = $fxc_order->items_of_type( 'tax' );
foreach ( $fxc_taxes as $fxc_line ) {
	$fxc_rows[] = [ $fxc_line->name !== '' ? $fxc_line->name : __( 'Tax', 'flexacommerce' ), $fxc_line->total ];
}
if ( $fxc_taxes === [] && ! $fxc_order->tax_total->is_zero() ) {
	$fxc_rows[] = [ __( 'Tax', 'flexacommerce' ), $fxc_order->tax_total ];
}
?>
<h2 style="margin:24px 0 8px;font-size:16px;font-weight:600;color:#18181b;">
	<?php
	/* translators: %s: order number. */
	echo esc_html( sprintf( __( 'Order #%s', 'flexacommerce' ), $fxc_order->number ) );
	?>
</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;">
	<?php foreach ( $fxc_order->items_of_type( 'product' ) as $fxc_line ) : ?>
		<tr>
			<td style="<?php echo esc_attr( $fxc_cell ); ?>">
				<?php echo esc_html( $fxc_line->name ); ?>
				<?php foreach ( $fxc_line->attributes as $fxc_attribute ) : ?>
					<br><span style="color:#71717a;font-size:13px;"><?php echo esc_html( $fxc_attribute['label'] . ': ' . $fxc_attribute['value'] ); ?></span>
				<?php endforeach; ?>
			</td>
			<td style="<?php echo esc_attr( $fxc_cell ); ?>text-align:center;width:48px;white-space:nowrap;">&times; <?php echo esc_html( (string) $fxc_line->quantity ); ?></td>
			<td style="<?php echo esc_attr( $fxc_cell ); ?>text-align:right;white-space:nowrap;"><?php echo esc_html( $fxc_data->money( $fxc_line->subtotal ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php foreach ( $fxc_rows as [ $fxc_label, $fxc_amount ] ) : ?>
		<tr>
			<td colspan="2" style="padding:6px 0;color:#52525b;"><?php echo esc_html( $fxc_label ); ?></td>
			<td style="padding:6px 0;text-align:right;white-space:nowrap;"><?php echo esc_html( $fxc_data->money( $fxc_amount ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	<tr>
		<td colspan="2" style="padding:10px 0;border-top:1px solid #e4e4e7;font-weight:600;"><?php esc_html_e( 'Total', 'flexacommerce' ); ?></td>
		<td style="padding:10px 0;border-top:1px solid #e4e4e7;text-align:right;font-weight:600;white-space:nowrap;"><?php echo esc_html( $fxc_data->money( $fxc_order->total ) ); ?></td>
	</tr>
	<?php if ( ! $fxc_order->amount_refunded->is_zero() ) : ?>
		<tr>
			<td colspan="2" style="padding:6px 0;color:#52525b;"><?php esc_html_e( 'Refunded', 'flexacommerce' ); ?></td>
			<td style="padding:6px 0;text-align:right;white-space:nowrap;"><?php echo esc_html( $fxc_data->money( $fxc_order->amount_refunded->negate() ) ); ?></td>
		</tr>
	<?php endif; ?>
	<?php if ( $fxc_order->payment_method_title !== '' ) : ?>
		<tr>
			<td colspan="2" style="padding:6px 0;color:#52525b;"><?php esc_html_e( 'Payment method', 'flexacommerce' ); ?></td>
			<td style="padding:6px 0;text-align:right;"><?php echo esc_html( $fxc_order->payment_method_title ); ?></td>
		</tr>
	<?php endif; ?>
</table>
<?php if ( $fxc_order->customer_note !== '' ) : ?>
	<p style="margin:16px 0 0;font-size:14px;"><strong><?php esc_html_e( 'Note:', 'flexacommerce' ); ?></strong> <?php echo nl2br( esc_html( $fxc_order->customer_note ) ); ?></p>
<?php endif; ?>
