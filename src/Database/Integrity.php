<?php

declare(strict_types=1);

namespace Flexa\Commerce\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Orphan counts for the Doctor (doc 03 §2.8). There are no foreign keys,
 * so these queries are how broken references are found.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only diagnostics on plugin tables.
final class Integrity {
	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * @return array<string,array{label:string,count:int}>
	 */
	public function orphans(): array {
		$posts  = $this->wpdb->posts;
		$q      = fn ( string $child, string $column, string $parent ): int => (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i c LEFT JOIN %i p ON p.ID = c.%i WHERE p.ID IS NULL',
				Schema::table( $child ),
				$parent,
				$column
			)
		);
		$orders = fn ( string $child ): int => (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i c LEFT JOIN %i o ON o.id = c.order_id WHERE o.id IS NULL',
				Schema::table( $child ),
				Schema::table( 'orders' )
			)
		);

		return [
			'variants_without_product'   => [
				'label' => __( 'Variants without a product post', 'flexacommerce' ),
				'count' => $q( 'variants', 'product_id', $posts ),
			],
			'items_without_order'        => [
				'label' => __( 'Order items without an order', 'flexacommerce' ),
				'count' => $orders( 'order_items' ),
			],
			'addresses_without_order'    => [
				'label' => __( 'Order addresses without an order', 'flexacommerce' ),
				'count' => $orders( 'order_addresses' ),
			],
			'history_without_order'      => [
				'label' => __( 'Order history rows without an order', 'flexacommerce' ),
				'count' => $orders( 'order_history' ),
			],
			'meta_without_order'         => [
				'label' => __( 'Order meta without an order', 'flexacommerce' ),
				'count' => $orders( 'order_meta' ),
			],
			'transactions_without_order' => [
				'label' => __( 'Transactions without an order', 'flexacommerce' ),
				'count' => $orders( 'transactions' ),
			],
		];
	}
}
