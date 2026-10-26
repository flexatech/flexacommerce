<?php
/**
 * Synthetic customers and orders for the 100,000-order migration job
 * (doc 05 §11). Writes into the active WooCommerce store, whatever its order
 * storage (HPOS or legacy). Benchmark stack only.
 *
 *   wp eval-file tools/bench/synthetic-orders.php customers <count> [seed]
 *   wp eval-file tools/bench/synthetic-orders.php orders <count> <part> <parts> [seed]
 *
 * `orders` writes part <part> (0-based) of <parts>, so several processes can
 * share the work; the same seed and part give the same orders. Customers go
 * in with SQL (WP-CLI would spend most of the time hashing passwords); orders
 * through WC_Order, with emails and stock reduction off.
 */

// No strict_types: wp eval-file runs this through eval(), where a declare is not allowed.

// phpcs:disable -- dev tooling, not part of the plugin.

if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}

$mode = (string) ( $args[0] ?? '' );
$seed = 42;

const FXC_SYN_FIRST = [ 'Ana', 'Bruno', 'Chen', 'Dana', 'Emil', 'Farah', 'Goran', 'Hana', 'Ines', 'Jonas', 'Kaito', 'Lena', 'Mateo', 'Nora', 'Omar', 'Priya' ];
const FXC_SYN_LAST  = [ 'Lima', 'Okafor', 'Novak', 'Silva', 'Berg', 'Haddad', 'Ito', 'Kowalski', 'Moreau', 'Nguyen', 'Rossi', 'Schmidt', 'Tanaka', 'Weiss' ];
const FXC_SYN_PLACE = [
	[ 'San Francisco', 'CA', '94105' ],
	[ 'Austin', 'TX', '73301' ],
	[ 'Portland', 'OR', '97201' ],
	[ 'Denver', 'CO', '80202' ],
	[ 'Boston', 'MA', '02108' ],
	[ 'Chicago', 'IL', '60601' ],
];

/** @return array<string,string> */
function fxc_syn_address( int $n ): array {
	$place = FXC_SYN_PLACE[ $n % count( FXC_SYN_PLACE ) ];
	return [
		'first_name' => FXC_SYN_FIRST[ $n % count( FXC_SYN_FIRST ) ],
		'last_name'  => FXC_SYN_LAST[ intdiv( $n, count( FXC_SYN_FIRST ) ) % count( FXC_SYN_LAST ) ],
		'address_1'  => ( 1 + $n % 900 ) . ' Market St',
		'city'       => $place[0],
		'state'      => $place[1],
		'postcode'   => $place[2],
		'country'    => 'US',
		'email'      => 'shopper' . $n . '@example.test',
		'phone'      => '555-' . str_pad( (string) ( $n % 10000 ), 4, '0', STR_PAD_LEFT ),
	];
}

if ( $mode === 'customers' ) {
	global $wpdb;
	$count = (int) ( $args[1] ?? 0 );
	$hash  = wp_hash_password( 'bench' );
	$caps  = serialize( [ 'customer' => true ] );
	for ( $n = 1; $n <= $count; $n++ ) {
		$address = fxc_syn_address( $n );
		$wpdb->insert(
			$wpdb->users,
			[
				'user_login'      => 'shopper' . $n,
				'user_pass'       => $hash,
				'user_nicename'   => 'shopper' . $n,
				'user_email'      => $address['email'],
				'user_registered' => gmdate( 'Y-m-d H:i:s', 1700000000 + $n * 3600 ),
				'display_name'    => $address['first_name'] . ' ' . $address['last_name'],
			]
		);
		$id   = (int) $wpdb->insert_id;
		$meta = [
			$wpdb->prefix . 'capabilities' => $caps,
			$wpdb->prefix . 'user_level'   => '0',
			'first_name'                   => $address['first_name'],
			'last_name'                    => $address['last_name'],
		];
		foreach ( $address as $key => $value ) {
			$meta[ 'billing_' . $key ] = $value;
			if ( $key !== 'email' && $key !== 'phone' ) {
				$meta[ 'shipping_' . $key ] = $value;
			}
		}
		$values = [];
		foreach ( $meta as $key => $value ) {
			$values[] = $wpdb->prepare( '(%d, %s, %s)', $id, $key, $value );
		}
		$wpdb->query( "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES " . implode( ',', $values ) );
	}
	WP_CLI::success( sprintf( '%d customers.', $count ) );
	return;
}

if ( $mode !== 'orders' ) {
	WP_CLI::error( 'Usage: customers <count> [seed] | orders <count> <part> <parts> [seed]' );
}

$count = (int) ( $args[1] ?? 0 );
$part  = (int) ( $args[2] ?? 0 );
$parts = max( 1, (int) ( $args[3] ?? 1 ) );
$seed  = (int) ( $args[4] ?? $seed );
mt_srand( $seed * 1000 + $part );

// No emails, no stock change, no analytics jobs: this only fills the store.
foreach ( WC()->mailer()->get_emails() as $email ) {
	add_filter( 'woocommerce_email_enabled_' . $email->id, '__return_false' );
}
add_filter( 'pre_wp_mail', '__return_false' );
// Every order save bumps the orders transient version, one option row that
// parallel parts would queue on. Transients stay in this process instead.
wp_using_ext_object_cache( true );
foreach ( [ 'woocommerce_payment_complete', 'woocommerce_order_status_completed', 'woocommerce_order_status_processing', 'woocommerce_order_status_on-hold' ] as $hook ) {
	remove_action( $hook, 'wc_maybe_reduce_stock_levels' );
	// total_sales is product meta that every part would write and lock.
	remove_action( $hook, 'wc_update_total_sales_counts' );
}
foreach ( [ 'woocommerce_order_status_cancelled', 'woocommerce_order_status_pending' ] as $hook ) {
	remove_action( $hook, 'wc_maybe_increase_stock_levels' );
}

global $wpdb;
$customers = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'shopper%' ORDER BY ID" ) );
// Simple products and variations with a price.
$sellable = array_map(
	'intval',
	$wpdb->get_col(
		"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_price' AND m.meta_value <> ''
		 WHERE p.post_status = 'publish' AND ( p.post_type = 'product_variation' OR ( p.post_type = 'product' AND NOT EXISTS (
		   SELECT 1 FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id JOIN {$wpdb->terms} x ON x.term_id = t.term_id
		   WHERE r.object_id = p.ID AND t.taxonomy = 'product_type' AND x.slug <> 'simple' ) ) ) ORDER BY p.ID"
	)
);
if ( $sellable === [] ) {
	WP_CLI::error( 'The store has no product with a price.' );
}

$statuses = [
	'completed'  => 60,
	'processing' => 20,
	'on-hold'    => 5,
	'cancelled'  => 5,
	'refunded'   => 3,
	'pending'    => 4,
	'failed'     => 3,
];
$pick_status = static function () use ( $statuses ): string {
	$roll = mt_rand( 1, 100 );
	foreach ( $statuses as $status => $weight ) {
		$roll -= $weight;
		if ( $roll <= 0 ) {
			return $status;
		}
	}
	return 'completed';
};

$products = [];
$product  = static function ( int $id ) use ( &$products ): WC_Product {
	return $products[ $id ] ??= wc_get_product( $id );
};

$first   = intdiv( $count * $part, $parts );
$last    = intdiv( $count * ( $part + 1 ), $parts );
$started = microtime( true );
$created = 0;
$from    = 1700000000; // 2023-11-14, orders spread over two years
for ( $n = $first; $n < $last; $n++ ) {
	$order    = new WC_Order();
	$customer = mt_rand( 1, 100 ) <= 70 && $customers !== [] ? $customers[ mt_rand( 0, count( $customers ) - 1 ) ] : 0;
	$address  = fxc_syn_address( $customer > 0 ? $customer : 100000 + $n );
	$order->set_customer_id( $customer );
	$order->set_currency( 'USD' );
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->set_date_created( $from + intdiv( 63072000 * $n, max( 1, $count ) ) );
	$lines = mt_rand( 1, 4 );
	for ( $i = 0; $i < $lines; $i++ ) {
		$order->add_product( $product( $sellable[ mt_rand( 0, count( $sellable ) - 1 ) ] ), mt_rand( 1, 3 ) );
	}
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Flat rate' );
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_total( '5.00' );
	$order->add_item( $shipping );
	$order->set_payment_method( 'cod' );
	$order->set_payment_method_title( 'Cash on delivery' );
	$order->calculate_totals( false );
	$status = $pick_status();
	if ( in_array( $status, [ 'processing', 'completed', 'refunded' ], true ) ) {
		$order->set_date_paid( $order->get_date_created()->getTimestamp() + 600 );
	}
	$order->set_status( $status === 'refunded' ? 'completed' : $status );
	$order->save();
	if ( $status === 'refunded' || ( $status === 'completed' && mt_rand( 1, 100 ) <= 5 ) ) {
		$total  = (float) $order->get_total();
		$amount = $status === 'refunded' ? $total : round( $total / 2, 2 );
		wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => wc_format_decimal( $amount, 2 ),
				'reason'         => $status === 'refunded' ? 'Returned' : 'Partial refund',
				'refund_payment' => false,
				'restock_items'  => false,
			]
		);
	}
	if ( mt_rand( 1, 100 ) <= 20 ) {
		$order->add_order_note( 'Synthetic note ' . $n );
	}
	++$created;
	if ( $created % 1000 === 0 ) {
		WP_CLI::log( sprintf( 'part %d: %d orders, %.0f/s', $part, $created, $created / ( microtime( true ) - $started ) ) );
		wp_cache_flush();
		$products = [];
	}
}
WP_CLI::success( sprintf( 'part %d: %d orders in %.0f s.', $part, $created, microtime( true ) - $started ) );
