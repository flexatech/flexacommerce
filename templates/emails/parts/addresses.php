<?php
/**
 * Billing and shipping addresses, side by side.
 *
 * Override by copying to yourtheme/flexacommerce/emails/parts/addresses.php.
 *
 * @version 1.0.0
 *
 * @var array{order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_columns = [];
if ( $args['order']->billing !== null ) {
	$fxc_columns[] = [ __( 'Billing address', 'flexacommerce' ), $args['data']->address_lines( $args['order']->billing ) ];
}
if ( $args['order']->shipping !== null ) {
	$fxc_columns[] = [ __( 'Shipping address', 'flexacommerce' ), $args['data']->address_lines( $args['order']->shipping ) ];
}
if ( $fxc_columns === [] ) {
	return;
}
?>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:24px;font-size:14px;">
	<tr>
		<?php foreach ( $fxc_columns as [ $fxc_title, $fxc_lines ] ) : ?>
			<td style="vertical-align:top;padding-right:16px;width:50%;">
				<h2 style="margin:0 0 8px;font-size:16px;font-weight:600;color:#18181b;"><?php echo esc_html( $fxc_title ); ?></h2>
				<p style="margin:0;line-height:1.5;color:#3f3f46;"><?php echo implode( '<br>', array_map( 'esc_html', $fxc_lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each line is escaped. ?></p>
			</td>
		<?php endforeach; ?>
	</tr>
</table>
