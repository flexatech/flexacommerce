<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\Integration\Cart;

use Flexa\Commerce\Cart\CartServices;
use Flexa\Commerce\Cart\MemoryTokenStore;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Plugin;
use Flexa\Commerce\Tests\Integration\Catalog\CatalogTestCase;

/**
 * One CartServices per simulated browser, each with its own in-memory
 * cookie. Session rows survive the test transaction (see CatalogTestCase),
 * so the table is emptied in tear_down.
 */
abstract class CartTestCase extends CatalogTestCase {
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		global $wpdb;
		parent::tear_down();
		// After the rollback, so the delete is not undone with it.
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'sessions' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'COMMIT' );
	}

	protected function browser( ?MemoryTokenStore $tokens = null ): CartServices {
		$plugin = Plugin::instance();
		return new CartServices( $plugin->services(), $plugin->catalog(), $tokens ?? new MemoryTokenStore() );
	}

	/** A published simple product; `$stock` null means stock is not managed. */
	protected function simple( int $price = 1000, ?int $stock = null ): int {
		$id   = $this->product();
		$base = [ 'regular_price' => $price ];
		if ( $stock !== null ) {
			$base += [
				'manage_stock'   => true,
				'stock_quantity' => $stock,
			];
		}
		$this->save( $id, [ 'base' => $base ] );
		return $id;
	}

	/**
	 * A published variable product with priced S/M/L variations.
	 *
	 * @return array{int,array<string,int>} product id, value key => variant id
	 */
	protected function sized( int $stock = 5 ): array {
		$id      = $this->variable_product( [ 'S', 'M', 'L' ] );
		$product = $this->service()->generate_variants( $id )->product;
		$rows    = [];
		$ids     = [];
		foreach ( $product->variations as $variation ) {
			$rows[]                                           = [
				'id'             => $variation->id,
				'regular_price'  => 2000,
				'manage_stock'   => true,
				'stock_quantity' => $stock,
			];
			$ids[ (string) $variation->combination->get( 'l:size' ) ] = $variation->id;
		}
		$this->save( $id, [ 'variations' => $rows ] );
		return [ $id, $ids ];
	}
}
