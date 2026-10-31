<?php
/**
 * The download links of a paid order, in the customer's emails (ADR-058).
 *
 * Override by copying to yourtheme/flexacommerce/emails/parts/downloads.php.
 *
 * @version 1.0.0
 *
 * @var array{order:\Flexa\Commerce\Contracts\Orders\OrderView,data:\Flexa\Commerce\Notifications\OrderData,to_customer:bool} $args
 */

defined( 'ABSPATH' ) || exit;

$fxc_links = $args['to_customer'] ? $args['data']->downloads( $args['order'] ) : [];
if ( $fxc_links === [] ) {
	return;
}
$fxc_cell = 'padding:10px 0;border-bottom:1px solid #e4e4e7;vertical-align:top;';
?>
<h2 style="margin:24px 0 8px;font-size:16px;font-weight:600;color:#18181b;"><?php esc_html_e( 'Downloads', 'flexacommerce' ); ?></h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;">
	<?php foreach ( $fxc_links as $fxc_link ) : ?>
		<tr>
			<td style="<?php echo esc_attr( $fxc_cell ); ?>color:#3f3f46;">
				<?php echo esc_html( $fxc_link['product'] ); ?>
				<?php if ( $fxc_link['expires'] !== null ) : ?>
					<br><span style="color:#71717a;font-size:12px;">
					<?php
					/* translators: %s: date. */
					echo esc_html( sprintf( __( 'Available until %s', 'flexacommerce' ), mysql2date( (string) get_option( 'date_format' ), get_date_from_gmt( $fxc_link['expires'] ) ) ) );
					?>
					</span>
				<?php endif; ?>
			</td>
			<td style="<?php echo esc_attr( $fxc_cell ); ?>text-align:right;"><a href="<?php echo esc_url( $fxc_link['url'] ); ?>" style="color:#18181b;font-weight:600;"><?php echo esc_html( $fxc_link['name'] ); ?></a></td>
		</tr>
	<?php endforeach; ?>
</table>
