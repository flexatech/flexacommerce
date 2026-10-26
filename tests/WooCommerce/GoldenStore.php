<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

/**
 * The golden WooCommerce store (doc 05 §11), built through WooCommerce CRUD
 * so every storage mode writes it its own way. Ids are kept by name for the
 * assertions.
 */
final class GoldenStore {
	public const CUSTOM_STATUS = 'wc-awaiting-stock';

	/** @var array<string,int> */
	public array $products = [];

	/** @var array<string,int> */
	public array $variations = [];

	/** @var array<string,int> */
	public array $orders = [];

	/** @var array<string,int> */
	public array $terms = [];

	public int $customer = 0;
	public int $rate     = 0;

	public static function register_custom_status(): void {
		register_post_status(
			self::CUSTOM_STATUS,
			[
				'label'    => 'Awaiting stock',
				'public'   => false,
				'internal' => false,
			]
		);
		add_filter(
			'wc_order_statuses',
			static fn ( array $statuses ): array => $statuses + [ self::CUSTOM_STATUS => 'Awaiting stock' ]
		);
	}

	/**
	 * Empty every content table. The import runs its own START TRANSACTION,
	 * which commits the test transaction, so nothing rolls back between tests.
	 * The suite has its own table prefix in fxc_tests; options and the admin
	 * user stay.
	 */
	public static function reset(): void {
		global $wpdb;
		$keep = [ $wpdb->options, $wpdb->users, $wpdb->usermeta ];
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $table ) {
			if ( ! in_array( $table, $keep, true ) ) {
				$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB
			}
		}
		$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE user_id > 1" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->users} WHERE ID > 1" ); // phpcs:ignore WordPress.DB
		delete_transient( 'wc_attribute_taxonomies' );
		wp_cache_flush();
	}

	public static function build(): self {
		self::reset();
		$store = new self();
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_based_on', 'shipping' );
		update_option( 'woocommerce_currency', 'USD' );
		self::register_custom_status();

		$store->rate = \WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => 'US',
				'tax_rate_state'    => 'CA',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'CA Tax',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			]
		);

		$shirts = wp_insert_term( 'Shirts', 'product_cat', [ 'slug' => 'shirts' ] );
		$summer = wp_insert_term( 'Summer', 'product_tag', [ 'slug' => 'summer' ] );
		$store->terms['shirts'] = (int) $shirts['term_id'];
		$store->terms['summer'] = (int) $summer['term_id'];

		$store->products['tee'] = self::simple(
			'Plain Tee',
			'TEE-1',
			'20',
			static function ( \WC_Product_Simple $p ) use ( $store ): void {
				$p->set_manage_stock( true );
				$p->set_stock_quantity( 50 );
				$p->set_weight( '0.5' );
				$p->set_length( '10' );
				$p->set_width( '20' );
				$p->set_height( '5' );
				$p->set_category_ids( [ $store->terms['shirts'] ] );
				$p->set_tag_ids( [ $store->terms['summer'] ] );
			}
		);
		$store->products['sale'] = self::simple(
			'Sale Mug',
			'MUG-1',
			'50',
			static function ( \WC_Product_Simple $p ): void {
				$p->set_sale_price( '40' );
				$p->set_date_on_sale_from( '2026-01-01 00:00:00' );
				$p->set_date_on_sale_to( '2030-01-01 00:00:00' );
			}
		);
		$store->products['unicode'] = self::simple( 'Áo dài Việt Nam', 'AO-DAI', '75', null );
		$store->products['dupe']    = self::simple( 'Second Tee', 'tee-1', '21', null );
		$store->products['draft']   = self::simple(
			'Draft Lamp',
			'LAMP-1',
			'15',
			static function ( \WC_Product_Simple $p ): void {
				$p->set_status( 'draft' );
			}
		);
		$store->products['doomed'] = self::simple( 'Doomed Item', 'DOOM-1', '9', null );

		$grouped = new \WC_Product_Grouped();
		$grouped->set_name( 'Gift Set' );
		$grouped->set_status( 'publish' );
		$grouped->set_children( [ $store->products['tee'] ] );
		$store->products['grouped'] = $grouped->save();

		$color = self::attribute( 'Color', 'color', [ 'red' => 'Red', 'blue' => 'Blue' ] );
		$size  = self::attribute( 'Size', 'size', [ 'large' => 'Large', 'medium' => 'Medium' ] );

		$hoodie = new \WC_Product_Variable();
		$hoodie->set_name( 'Hoodie' );
		$hoodie->set_status( 'publish' );
		$hoodie->set_sku( 'HOOD' );
		$hoodie->set_manage_stock( true );
		$hoodie->set_stock_quantity( 7 );
		$hoodie->set_attributes( [ self::product_attribute( $color, 0 ), self::product_attribute( $size, 1 ) ] );
		$store->products['hoodie'] = $hoodie->save();

		$store->variations['red_large'] = self::variation(
			$store->products['hoodie'],
			[
				'pa_color' => 'red',
				'pa_size'  => 'large',
			],
			'HOOD-RL',
			'30',
			5
		);
		// Any size: a wildcard axis, and stock from the parent.
		$store->variations['blue_any'] = self::variation(
			$store->products['hoodie'],
			[
				'pa_color' => 'blue',
				'pa_size'  => '',
			],
			'HOOD-B',
			'32',
			null
		);
		\WC_Product_Variable::sync( $store->products['hoodie'] );

		$material = new \WC_Product_Attribute();
		$material->set_name( 'Material' );
		$material->set_options( [ 'Cotton', 'Wool' ] );
		$material->set_visible( true );
		$material->set_variation( true );
		$material->set_position( 0 );
		$scarf = new \WC_Product_Variable();
		$scarf->set_name( 'Scarf' );
		$scarf->set_status( 'publish' );
		$scarf->set_attributes( [ $material ] );
		$store->products['scarf'] = $scarf->save();
		$store->variations['cotton'] = self::variation( $store->products['scarf'], [ 'material' => 'Cotton' ], 'SCARF-C', '12', 3 );
		$store->variations['wool']   = self::variation( $store->products['scarf'], [ 'material' => 'Wool' ], 'SCARF-W', '18', 4 );
		\WC_Product_Variable::sync( $store->products['scarf'] );

		$store->customer = self::factory_user();
		$store->orders   = self::orders( $store );
		return $store;
	}

	/** @return array<string,int> */
	private static function orders( self $store ): array {
		$out = [];

		$out['completed'] = self::order(
			$store,
			[ [ $store->products['tee'], 2 ] ],
			'completed',
			static function ( \WC_Order $o ): void {
				$o->set_transaction_id( 'ch_completed' );
			}
		);

		$coupon = new \WC_Coupon();
		$coupon->set_code( 'save10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();
		$out['coupon_fee'] = self::order(
			$store,
			[ [ $store->products['sale'], 1 ], [ $store->variations['red_large'], 1 ] ],
			'processing',
			static function ( \WC_Order $o ): void {
				$o->apply_coupon( 'save10' );
				$fee = new \WC_Order_Item_Fee();
				$fee->set_name( 'Gift wrap' );
				$fee->set_amount( '3' );
				$fee->set_total( '3' );
				$fee->set_tax_status( 'taxable' );
				$o->add_item( $fee );
				$o->calculate_totals( true );
			}
		);

		$out['partial_refund'] = self::order( $store, [ [ $store->products['tee'], 3 ], [ $store->variations['cotton'], 1 ] ], 'processing', null );
		$order                 = wc_get_order( $out['partial_refund'] );
		$tee_item              = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product_id() === $store->products['tee'] ) {
				$tee_item = (int) $item_id;
			}
		}
		wc_create_refund(
			[
				'order_id'       => $out['partial_refund'],
				'amount'         => '22.00',
				'reason'         => 'One shirt returned',
				'line_items'     => [
					$tee_item => [
						'qty'          => 1,
						'refund_total' => '20.00',
						'refund_tax'   => [ $store->rate => '2.00' ],
					],
				],
				'refund_payment' => false,
				'restock_items'  => false,
			]
		);

		$out['refunded'] = self::order( $store, [ [ $store->products['unicode'], 1 ] ], 'completed', null );
		wc_create_refund(
			[
				'order_id'       => $out['refunded'],
				'amount'         => wc_get_order( $out['refunded'] )->get_total(),
				'reason'         => 'Changed mind',
				'refund_payment' => false,
				'restock_items'  => false,
			]
		);

		$out['guest_pending'] = self::order(
			$store,
			[ [ $store->variations['wool'], 2 ] ],
			'pending',
			static function ( \WC_Order $o ): void {
				$o->set_customer_id( 0 );
				$o->set_payment_method( '' );
			}
		);
		$out['custom'] = self::order( $store, [ [ $store->variations['blue_any'], 1 ] ], substr( self::CUSTOM_STATUS, 3 ), null );
		$out['cancelled'] = self::order( $store, [ [ $store->products['tee'], 1 ] ], 'cancelled', null );
		$out['deleted_product'] = self::order( $store, [ [ $store->products['doomed'], 1 ] ], 'completed', null );
		wc_get_product( $store->products['doomed'] )->delete( true );

		$out['trashed'] = self::order( $store, [ [ $store->products['tee'], 1 ] ], 'on-hold', null );
		wc_get_order( $out['trashed'] )->delete( false );

		$draft = new \WC_Order();
		$draft->set_status( 'checkout-draft' );
		$draft->save();
		$out['checkout_draft'] = $draft->get_id();

		$note = wc_get_order( $out['completed'] );
		$note->add_order_note( 'Packed by the warehouse.' );
		$note->add_order_note( 'Thanks for your order!', 1 );
		return $out;
	}

	/**
	 * @param list<array{0:int,1:int}>          $lines product or variation id, quantity
	 * @param (callable(\WC_Order):void)|null $tweak
	 */
	private static function order( self $store, array $lines, string $status, ?callable $tweak ): int {
		$order = new \WC_Order();
		$order->set_customer_id( $store->customer );
		$order->set_currency( 'USD' );
		$address = [
			'first_name' => 'Ana',
			'last_name'  => 'Lima',
			'address_1'  => '1 Market St',
			'city'       => 'San Francisco',
			'state'      => 'CA',
			'postcode'   => '94105',
			'country'    => 'US',
			'email'      => 'ana@example.com',
			'phone'      => '555-0100',
		];
		$order->set_address( $address, 'billing' );
		$order->set_address( $address, 'shipping' );
		foreach ( $lines as [ $id, $qty ] ) {
			$order->add_product( wc_get_product( $id ), $qty );
		}
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Flat rate' );
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_total( '5.00' );
		$order->add_item( $shipping );
		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Bank transfer' );
		$order->calculate_totals( true );
		if ( $tweak !== null ) {
			$tweak( $order );
		}
		if ( in_array( $status, [ 'processing', 'completed' ], true ) ) {
			$order->set_date_paid( time() );
		}
		$order->set_status( $status );
		$order->save();
		return $order->get_id();
	}

	/** @param (callable(\WC_Product_Simple):void)|null $tweak */
	private static function simple( string $name, string $sku, string $price, ?callable $tweak ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( $price );
		if ( $tweak !== null ) {
			$tweak( $product );
		}
		// WooCommerce refuses a duplicate SKU; old stores got them through meta writes, as here.
		$duplicate = (int) wc_get_product_id_by_sku( $sku ) > 0;
		if ( ! $duplicate ) {
			$product->set_sku( $sku );
		}
		$id = $product->save();
		if ( $duplicate ) {
			update_post_meta( $id, '_sku', $sku );
		}
		return $id;
	}

	/**
	 * @param array<string,string> $terms slug => name
	 * @return array{id:int,taxonomy:string,terms:list<int>}
	 */
	private static function attribute( string $name, string $slug, array $terms ): array {
		$taxonomy = wc_attribute_taxonomy_name( $slug );
		// The taxonomy stays registered from an earlier test; the attribute row was rolled back.
		unregister_taxonomy( $taxonomy );
		$id = wc_create_attribute(
			[
				'name' => $name,
				'slug' => $slug,
			]
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( $id->get_error_message() );
		}
		register_taxonomy( $taxonomy, [ 'product' ] );
		$ids = [];
		foreach ( $terms as $term_slug => $term_name ) {
			$ids[] = (int) wp_insert_term( $term_name, $taxonomy, [ 'slug' => $term_slug ] )['term_id'];
		}
		return [
			'id'       => (int) $id,
			'taxonomy' => $taxonomy,
			'terms'    => $ids,
		];
	}

	/** @param array{id:int,taxonomy:string,terms:list<int>} $attribute */
	private static function product_attribute( array $attribute, int $position ): \WC_Product_Attribute {
		$out = new \WC_Product_Attribute();
		$out->set_id( $attribute['id'] );
		$out->set_name( $attribute['taxonomy'] );
		$out->set_options( $attribute['terms'] );
		$out->set_position( $position );
		$out->set_visible( true );
		$out->set_variation( true );
		return $out;
	}

	/** @param array<string,string> $attributes */
	private static function variation( int $parent, array $attributes, string $sku, string $price, ?int $stock ): int {
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent );
		$variation->set_attributes( $attributes );
		$variation->set_sku( $sku );
		$variation->set_regular_price( $price );
		if ( $stock !== null ) {
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $stock );
		}
		return $variation->save();
	}

	private static function factory_user(): int {
		$id = wp_insert_user(
			[
				'user_login' => 'ana',
				'user_email' => 'ana@example.com',
				'user_pass'  => 'secret',
				'role'       => 'customer',
			]
		);
		update_user_meta( $id, 'billing_first_name', 'Ana' );
		update_user_meta( $id, 'billing_last_name', 'Lima' );
		update_user_meta( $id, 'billing_address_1', '1 Market St' );
		update_user_meta( $id, 'billing_city', 'San Francisco' );
		update_user_meta( $id, 'billing_state', 'CA' );
		update_user_meta( $id, 'billing_postcode', '94105' );
		update_user_meta( $id, 'billing_country', 'US' );
		update_user_meta( $id, 'billing_email', 'ana@example.com' );
		return (int) $id;
	}
}
