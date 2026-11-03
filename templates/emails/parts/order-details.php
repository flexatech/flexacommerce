<?php
/**
 * The order lines and totals, shared by every order email.
 *
 * Override by copying to yourtheme/flexacommerce/emails/parts/order-details.php.
 *
 * @version 1.1.0
 *
 * @var array{order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData} $args
 */

defined( 'ABSPATH' ) || exit;

$fxcm_order = $args['order'];
$fxcm_data  = $args['data'];
$fxcm_cell  = 'padding:10px 0;border-bottom:1px solid #e4e4e7;vertical-align:top;';
$fxcm_rows  = [
	[ __( 'Subtotal', 'flexacommerce' ), $fxcm_order->subtotal ],
];
if ( ! $fxcm_order->discount_total->is_zero() ) {
	$fxcm_rows[] = [ __( 'Discount', 'flexacommerce' ), $fxcm_order->discount_total->negate() ];
}
foreach ( $fxcm_order->items_of_type( 'shipping' ) as $fxcm_line ) {
	$fxcm_rows[] = [ $fxcm_line->name !== '' ? $fxcm_line->name : __( 'Shipping', 'flexacommerce' ), $fxcm_line->total ];
}
foreach ( $fxcm_order->items_of_type( 'fee' ) as $fxcm_line ) {
	$fxcm_rows[] = [ $fxcm_line->name, $fxcm_line->total ];
}
// One row per tax rate; orders without tax lines (imported ones) show the sum.
$fxcm_taxes = $fxcm_order->items_of_type( 'tax' );
foreach ( $fxcm_taxes as $fxcm_line ) {
	$fxcm_rows[] = [ $fxcm_line->name !== '' ? $fxcm_line->name : __( 'Tax', 'flexacommerce' ), $fxcm_line->total ];
}
if ( $fxcm_taxes === [] && ! $fxcm_order->tax_total->is_zero() ) {
	$fxcm_rows[] = [ __( 'Tax', 'flexacommerce' ), $fxcm_order->tax_total ];
}
?>
<h2 style="margin:24px 0 8px;font-size:16px;font-weight:600;color:#18181b;">
	<?php
	/* translators: %s: order number. */
	echo esc_html( sprintf( __( 'Order #%s', 'flexacommerce' ), $fxcm_order->number ) );
	?>
</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;">
	<?php foreach ( $fxcm_order->items_of_type( 'product' ) as $fxcm_line ) : ?>
		<?php if ( $fxcm_line->bundle_part ) : ?>
			<tr>
				<td style="padding:0 0 8px 16px;color:#52525b;font-size:13px;vertical-align:top;">
					<?php echo esc_html( $fxcm_line->name ); ?>
					<?php foreach ( $fxcm_line->attributes as $fxcm_attribute ) : ?>
						<br><span style="color:#71717a;"><?php echo esc_html( $fxcm_attribute['label'] . ': ' . $fxcm_attribute['value'] ); ?></span>
					<?php endforeach; ?>
				</td>
				<td style="padding:0 0 8px;color:#52525b;font-size:13px;text-align:center;width:48px;white-space:nowrap;vertical-align:top;">&times; <?php echo esc_html( (string) $fxcm_line->quantity ); ?></td>
				<td></td>
			</tr>
			<?php continue; ?>
		<?php endif; ?>
		<tr>
			<td style="<?php echo esc_attr( $fxcm_cell ); ?>">
				<?php echo esc_html( $fxcm_line->name ); ?>
				<?php foreach ( $fxcm_line->attributes as $fxcm_attribute ) : ?>
					<br><span style="color:#71717a;font-size:13px;"><?php echo esc_html( $fxcm_attribute['label'] . ': ' . $fxcm_attribute['value'] ); ?></span>
				<?php endforeach; ?>
			</td>
			<td style="<?php echo esc_attr( $fxcm_cell ); ?>text-align:center;width:48px;white-space:nowrap;">&times; <?php echo esc_html( (string) $fxcm_line->quantity ); ?></td>
			<td style="<?php echo esc_attr( $fxcm_cell ); ?>text-align:right;white-space:nowrap;"><?php echo esc_html( $fxcm_data->money( $fxcm_line->subtotal ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php foreach ( $fxcm_rows as [ $fxcm_label, $fxcm_amount ] ) : ?>
		<tr>
			<td colspan="2" style="padding:6px 0;color:#52525b;"><?php echo esc_html( $fxcm_label ); ?></td>
			<td style="padding:6px 0;text-align:right;white-space:nowrap;"><?php echo esc_html( $fxcm_data->money( $fxcm_amount ) ); ?></td>
		</tr>
	<?php endforeach; ?>
	<tr>
		<td colspan="2" style="padding:10px 0;border-top:1px solid #e4e4e7;font-weight:600;"><?php esc_html_e( 'Total', 'flexacommerce' ); ?></td>
		<td style="padding:10px 0;border-top:1px solid #e4e4e7;text-align:right;font-weight:600;white-space:nowrap;"><?php echo esc_html( $fxcm_data->money( $fxcm_order->total ) ); ?></td>
	</tr>
	<?php if ( ! $fxcm_order->amount_refunded->is_zero() ) : ?>
		<tr>
			<td colspan="2" style="padding:6px 0;color:#52525b;"><?php esc_html_e( 'Refunded', 'flexacommerce' ); ?></td>
			<td style="padding:6px 0;text-align:right;white-space:nowrap;"><?php echo esc_html( $fxcm_data->money( $fxcm_order->amount_refunded->negate() ) ); ?></td>
		</tr>
	<?php endif; ?>
	<?php if ( $fxcm_order->payment_method_title !== '' ) : ?>
		<tr>
			<td colspan="2" style="padding:6px 0;color:#52525b;"><?php esc_html_e( 'Payment method', 'flexacommerce' ); ?></td>
			<td style="padding:6px 0;text-align:right;"><?php echo esc_html( $fxcm_order->payment_method_title ); ?></td>
		</tr>
	<?php endif; ?>
</table>
<?php if ( $fxcm_order->customer_note !== '' ) : ?>
	<p style="margin:16px 0 0;font-size:14px;"><strong><?php esc_html_e( 'Note:', 'flexacommerce' ); ?></strong> <?php echo nl2br( esc_html( $fxcm_order->customer_note ) ); ?></p>
<?php endif; ?>
