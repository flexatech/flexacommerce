<?php
/**
 * Prices the tax golden cases with WooCommerce (doc 02 §7.12.9). Run by
 * `./bench.sh tax-golden` on the tax.bench store:
 *
 *   wp eval-file tax-golden.php <cases.json> <out.json>
 *
 * Each case goes through a real cart and WC_Checkout::create_order(), and
 * the stored order is read back. Amounts are written in minor units.
 */

[ $bench_in, $bench_out ] = array_slice( $args, 0, 2 );
$bench_doc                = json_decode( (string) file_get_contents( $bench_in ), true, 512, JSON_THROW_ON_ERROR );

$bench_minor = static fn ( $value ): int => (int) round( (float) $value * 100 );
$bench_money = static fn ( int $minor ): string => number_format( $minor / 100, 2, '.', '' );

$bench_store = $bench_doc['store'];
update_option( 'woocommerce_default_country', $bench_store['country'] . ':' . $bench_store['state'] );
update_option( 'woocommerce_store_postcode', $bench_store['postcode'] );
update_option( 'woocommerce_store_city', $bench_store['city'] );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_cod_settings', [ 'enabled' => 'yes' ] );

$bench_zone = new WC_Shipping_Zone();
$bench_zone->set_zone_name( 'US' );
$bench_zone->add_location( 'US', 'country' );
$bench_zone->save();
$bench_instance = $bench_zone->add_shipping_method( 'flat_rate' );
update_option(
	'woocommerce_flat_rate_' . $bench_instance . '_settings',
	[
		'title'      => 'Flat rate',
		'tax_status' => 'taxable',
		'cost'       => '5',
	]
);
$bench_rate_id = 'flat_rate:' . $bench_instance;

wc_load_cart();

foreach ( $bench_doc['cases'] as &$bench_case ) {
	$settings = $bench_case['settings'];
	update_option( 'woocommerce_prices_include_tax', ! empty( $settings['prices_include_tax'] ) ? 'yes' : 'no' );
	update_option( 'woocommerce_tax_round_at_subtotal', ! empty( $settings['tax_round_at_subtotal'] ) ? 'yes' : 'no' );
	update_option( 'woocommerce_tax_based_on', $settings['tax_based_on'] ?? 'shipping' );
	update_option( 'woocommerce_shipping_tax_class', $settings['shipping_tax_class'] ?? 'inherit' );

	foreach ( WC_Tax::get_rates_for_tax_class( '' ) + WC_Tax::get_rates_for_tax_class( 'reduced-rate' ) + WC_Tax::get_rates_for_tax_class( 'zero-rate' ) as $old ) {
		WC_Tax::_delete_tax_rate( (int) $old->tax_rate_id );
	}
	$ids = [];
	foreach ( $bench_case['rates'] as $order => $rate ) {
		$id = WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => $rate['country'],
				'tax_rate_state'    => $rate['state'],
				'tax_rate'          => $rate['rate'],
				'tax_rate_name'     => $rate['name'],
				'tax_rate_priority' => $rate['priority'],
				'tax_rate_compound' => $rate['compound'] ? 1 : 0,
				'tax_rate_shipping' => $rate['shipping'] ? 1 : 0,
				'tax_rate_order'    => $order,
				'tax_rate_class'    => $rate['tax_class'],
			]
		);
		WC_Tax::_update_tax_rate_postcodes( $id, implode( ';', $rate['postcodes'] ) );
		WC_Tax::_update_tax_rate_cities( $id, implode( ';', $rate['cities'] ) );
		$ids[ $id ] = $order;
	}
	WC_Cache_Helper::invalidate_cache_group( 'taxes' );

	$shipping = $bench_case['address'];
	$billing  = $bench_case['billing'] ?? $shipping;
	$customer = WC()->customer;
	$customer->set_billing_location( $billing['country'], $billing['state'], $billing['postcode'], $billing['city'] );
	$customer->set_shipping_location( $shipping['country'], $shipping['state'], $shipping['postcode'], $shipping['city'] );
	$customer->set_is_vat_exempt( (bool) $bench_case['exempt'] );

	WC()->cart->empty_cart();
	foreach ( $bench_case['lines'] as $line ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Golden ' . $bench_case['id'] );
		$product->set_regular_price( $bench_money( $line['price'] ) );
		$product->set_tax_class( $line['tax_class'] );
		$product->set_tax_status( $line['tax_status'] );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id(), $line['qty'] );
	}
	WC()->session->set( 'chosen_shipping_methods', [ $bench_rate_id ] );
	WC()->cart->calculate_totals();

	$data = [ 'payment_method' => 'cod' ];
	foreach ( [ 'billing' => $billing, 'shipping' => $shipping ] as $type => $address ) {
		foreach ( $address as $key => $value ) {
			$data[ $type . '_' . $key ] = $value;
		}
	}
	$order_id = WC()->checkout()->create_order( $data );
	if ( is_wp_error( $order_id ) ) {
		WP_CLI::error( $bench_case['id'] . ': ' . $order_id->get_error_message() );
	}
	$order = wc_get_order( $order_id );

	$rates = array_fill( 0, count( $bench_case['rates'] ), null );
	foreach ( $order->get_taxes() as $tax ) {
		$rates[ $ids[ $tax->get_rate_id() ] ] = [
			'items'    => $bench_minor( $tax->get_tax_total() ),
			'shipping' => $bench_minor( $tax->get_shipping_tax_total() ),
		];
	}
	$lines = [];
	foreach ( $order->get_items() as $item ) {
		$lines[] = [
			'net' => $bench_minor( $item->get_total() ),
			'tax' => $bench_minor( $item->get_total_tax() ),
		];
	}
	$bench_case['expected'] = [
		'total'        => $bench_minor( $order->get_total() ),
		'tax_total'    => $bench_minor( $order->get_total_tax() ),
		'shipping'     => $bench_minor( $order->get_shipping_total() ),
		'shipping_tax' => $bench_minor( $order->get_shipping_tax() ),
		'rates'        => $rates,
		'lines'        => $lines,
	];
	WP_CLI::log( sprintf( '%-32s total %6d tax %4d', $bench_case['id'], $bench_case['expected']['total'], $bench_case['expected']['tax_total'] ) );
}
unset( $bench_case );

$bench_doc['woocommerce'] = WC()->version;
file_put_contents( $bench_out, json_encode( $bench_doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
WP_CLI::success( count( $bench_doc['cases'] ) . ' cases written to ' . $bench_out );
