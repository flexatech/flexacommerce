<?php
/**
 * The tracking of the order's packages, in the customer's emails (ADR-067).
 * The shipped email shows the package it is about; the completed email
 * shows every package.
 *
 * Override by copying to yourtheme/flexacommerce/emails/parts/shipments.php.
 *
 * @version 1.0.0
 *
 * @var array{order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool,shipment?:?\Flexa\Commerce\Orders\Shipment} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_rows = $args['to_customer'] ? $args['data']->shipments( $args['order'], $args['shipment'] ?? null ) : [];
if ( $fxc_rows === [] ) {
	return;
}
$fxc_cell = 'padding:10px 0;border-bottom:1px solid #e4e4e7;vertical-align:top;';
?>
<h2 style="margin:24px 0 8px;font-size:16px;font-weight:600;color:#18181b;"><?php esc_html_e( 'Tracking', 'flexacommerce' ); ?></h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;">
	<?php foreach ( $fxc_rows as $fxc_row ) : ?>
		<tr>
			<td style="<?php echo esc_attr( $fxc_cell ); ?>color:#3f3f46;">
				<strong><?php echo esc_html( $fxc_row['carrier'] ); ?></strong>
				<br><span style="color:#71717a;font-size:12px;">
				<?php
				/* translators: %s: date. */
				echo esc_html( sprintf( __( 'Shipped %s', 'flexacommerce' ), $fxc_row['date'] ) );
				?>
				</span>
				<?php foreach ( $fxc_row['items'] as $fxc_item ) : ?>
					<br><span style="color:#71717a;font-size:12px;"><?php echo esc_html( $fxc_item['name'] . ' × ' . $fxc_item['quantity'] ); ?></span>
				<?php endforeach; ?>
			</td>
			<td style="<?php echo esc_attr( $fxc_cell ); ?>text-align:right;">
				<?php if ( $fxc_row['number'] !== '' && $fxc_row['url'] !== '' ) : ?>
					<a href="<?php echo esc_url( $fxc_row['url'] ); ?>" style="color:#18181b;font-weight:600;"><?php echo esc_html( $fxc_row['number'] ); ?></a>
				<?php elseif ( $fxc_row['number'] !== '' ) : ?>
					<span style="color:#18181b;font-weight:600;"><?php echo esc_html( $fxc_row['number'] ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
</table>
