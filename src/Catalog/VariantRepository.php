<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Contracts\Exceptions\DuplicateSku;
use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;
use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for fxc_variants. Rows of one product are cached together in the
 * `flexacommerce_variants` object cache group, keyed by product id, so a
 * page of products costs one query (doc 02 §7.1).
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared, cache handled here.
final class VariantRepository {
	public const CACHE_GROUP = 'flexacommerce_variants';

	private const FORMATS = [
		'product_id'          => '%d',
		'is_base'             => '%d',
		'combination_hash'    => '%s',
		'attributes'          => '%s',
		'enabled'             => '%d',
		'menu_order'          => '%d',
		'sku'                 => '%s',
		'regular_price'       => '%d',
		'sale_price'          => '%d',
		'sale_from'           => '%s',
		'sale_to'             => '%s',
		'manage_stock'        => '%d',
		'stock_from_base'     => '%d',
		'stock_quantity'      => '%d',
		'backorders'          => '%s',
		'low_stock_threshold' => '%d',
		'stock_status'        => '%s',
		'weight'              => '%s',
		'length'              => '%s',
		'width'               => '%s',
		'height'              => '%s',
		'image_id'            => '%d',
		'tax_class'           => '%s',
		'tax_status'          => '%s',
		'description'         => '%s',
		'is_virtual'          => '%d',
		'is_downloadable'     => '%d',
		'downloads'           => '%s',
		'meta'                => '%s',
		'created_at'          => '%s',
		'updated_at'          => '%s',
	];

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Clock $clock,
	) {}

	public function find( int $variant_id ): ?Variant {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'variants' ), $variant_id ),
			ARRAY_A
		);
		return is_array( $row ) ? Variant::from_row( $row ) : null;
	}

	/** @return list<Variant> base row first, then by menu_order and id */
	public function for_product( int $product_id ): array {
		return $this->for_products( [ $product_id ] )[ $product_id ] ?? [];
	}

	/**
	 * Loads every uncached product in one query.
	 *
	 * @param list<int> $product_ids
	 * @return array<int,list<Variant>>
	 */
	public function for_products( array $product_ids ): array {
		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( $product_ids === [] ) {
			return [];
		}

		$rows    = [];
		$missing = [];
		$cached  = wp_cache_get_multiple( $product_ids, self::CACHE_GROUP );
		foreach ( $product_ids as $id ) {
			if ( isset( $cached[ $id ] ) && is_array( $cached[ $id ] ) ) {
				$rows[ $id ] = $cached[ $id ];
			} else {
				$missing[] = $id;
			}
		}

		if ( $missing !== [] ) {
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			$found        = $this->wpdb->get_results(
				$this->wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
					"SELECT * FROM %i WHERE product_id IN ({$placeholders}) ORDER BY product_id, is_base DESC, menu_order, id",
					Schema::table( 'variants' ),
					...$missing
				),
				ARRAY_A
			);
			$grouped = array_fill_keys( $missing, [] );
			foreach ( is_array( $found ) ? $found : [] as $row ) {
				$grouped[ (int) $row['product_id'] ][] = $row;
			}
			foreach ( $grouped as $id => $group ) {
				wp_cache_set( $id, $group, self::CACHE_GROUP );
				$rows[ $id ] = $group;
			}
		}

		$out = [];
		foreach ( $product_ids as $id ) {
			$out[ $id ] = array_map( [ Variant::class, 'from_row' ], array_values( $rows[ $id ] ?? [] ) );
		}
		return $out;
	}

	/** Case-insensitive through the table collation, like the unique index. */
	public function find_by_sku( string $sku ): ?Variant {
		$sku = trim( $sku );
		if ( $sku === '' ) {
			return null;
		}
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE sku = %s LIMIT 1', Schema::table( 'variants' ), $sku ),
			ARRAY_A
		);
		return is_array( $row ) ? Variant::from_row( $row ) : null;
	}

	/**
	 * Rows whose SKU contains the text, for the admin pickers.
	 *
	 * @return list<array{id:int,product_id:int,is_base:bool}>
	 */
	public function search_sku( string $text, int $limit ): array {
		$text = trim( $text );
		if ( $text === '' ) {
			return [];
		}
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, product_id, is_base FROM %i WHERE sku LIKE %s ORDER BY product_id, is_base DESC, menu_order, id LIMIT %d',
				Schema::table( 'variants' ),
				'%' . $this->wpdb->esc_like( $text ) . '%',
				$limit
			),
			ARRAY_A
		);
		return array_map(
			static fn ( array $row ): array => [
				'id'         => (int) $row['id'],
				'product_id' => (int) $row['product_id'],
				'is_base'    => (bool) $row['is_base'],
			],
			is_array( $rows ) ? $rows : []
		);
	}

	/**
	 * The product of each row that exists.
	 *
	 * @param list<int> $variant_ids
	 * @return array<int,int> variant id => product id
	 */
	public function owners( array $variant_ids ): array {
		$variant_ids = array_values( array_unique( array_filter( array_map( 'intval', $variant_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( $variant_ids === [] ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $variant_ids ), '%d' ) );
		$rows         = $this->wpdb->get_results(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				"SELECT id, product_id FROM %i WHERE id IN ({$placeholders})",
				Schema::table( 'variants' ),
				...$variant_ids
			),
			ARRAY_A
		);
		$out = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (int) $row['id'] ] = (int) $row['product_id'];
		}
		return $out;
	}

	/** @throws DuplicateSku|InvalidProduct|QueryFailed */
	public function insert( Variant $variant ): int {
		$now  = $this->clock->db_now();
		$data = $variant->to_row() + [
			'created_at' => $now,
			'updated_at' => $now,
		];
		$ok   = $this->write( fn (): int|false => $this->wpdb->insert( Schema::table( 'variants' ), $data, $this->formats( $data ) ) );
		if ( ! $ok ) {
			throw $this->failure( $variant );
		}
		$this->flush( $variant->product_id );
		return (int) $this->wpdb->insert_id;
	}

	/** @throws DuplicateSku|InvalidProduct|QueryFailed */
	public function update( Variant $variant ): void {
		$data = $variant->to_row() + [ 'updated_at' => $this->clock->db_now() ];
		$ok   = $this->write(
			fn (): int|false => $this->wpdb->update( Schema::table( 'variants' ), $data, [ 'id' => $variant->id ], $this->formats( $data ), [ '%d' ] )
		);
		if ( ! $ok ) {
			throw $this->failure( $variant );
		}
		$this->flush( $variant->product_id );
	}

	/**
	 * Clears the SKU and combination hash of rows about to change, so a save
	 * that swaps SKUs or combinations between rows does not trip the unique
	 * indexes halfway through.
	 *
	 * @param list<int> $variant_ids
	 */
	public function release_unique_keys( int $product_id, array $variant_ids ): void {
		foreach ( $variant_ids as $id ) {
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					'UPDATE %i SET sku = NULL, combination_hash = %s WHERE id = %d AND product_id = %d',
					Schema::table( 'variants' ),
					sha1( 'release:' . $id ),
					$id,
					$product_id
				)
			);
			if ( $result === false ) {
				throw QueryFailed::from_wpdb( $this->wpdb );
			}
		}
		$this->flush( $product_id );
	}

	/** @param list<int> $variant_ids */
	public function delete_many( int $product_id, array $variant_ids ): int {
		$variant_ids = array_values( array_filter( array_map( 'intval', $variant_ids ) ) );
		if ( $variant_ids === [] ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $variant_ids ), '%d' ) );
		$deleted      = $this->wpdb->query(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
				"DELETE FROM %i WHERE product_id = %d AND is_base = 0 AND id IN ({$placeholders})",
				Schema::table( 'variants' ),
				$product_id,
				...$variant_ids
			)
		);
		if ( $deleted === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		$this->flush( $product_id );
		return (int) $deleted;
	}

	/** Variation rows only; the base row stays. */
	public function delete_variations( int $product_id ): int {
		$deleted = $this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE product_id = %d AND is_base = 0', Schema::table( 'variants' ), $product_id )
		);
		if ( $deleted === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		$this->flush( $product_id );
		return (int) $deleted;
	}

	public function delete_for_product( int $product_id ): int {
		$deleted = $this->wpdb->query(
			$this->wpdb->prepare( 'DELETE FROM %i WHERE product_id = %d', Schema::table( 'variants' ), $product_id )
		);
		$this->flush( $product_id );
		return (int) $deleted;
	}

	/**
	 * Conditional decrement (doc 02 §7.4): fails instead of overselling when
	 * backorders are off. The stock status is recalculated in the same
	 * statement.
	 */
	public function decrement_stock( int $variant_id, int $qty, int $product_id = 0 ): bool {
		$product_id = $product_id > 0 ? $product_id : $this->product_of( $variant_id );
		$affected   = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET stock_quantity = stock_quantity - %d,
					stock_status = CASE
						WHEN stock_quantity > 0 THEN %s
						WHEN backorders = %s THEN %s
						ELSE %s END,
					updated_at = %s
				WHERE id = %d AND manage_stock = 1 AND ( backorders <> %s OR stock_quantity >= %d )',
				Schema::table( 'variants' ),
				$qty,
				Variant::INSTOCK,
				'no',
				Variant::OUTOFSTOCK,
				Variant::ONBACKORDER,
				$this->clock->db_now(),
				$variant_id,
				'no',
				$qty
			)
		);
		if ( $affected === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		$this->flush( $product_id );
		return (int) $affected === 1;
	}

	/** Unconditional change; a negative `$qty` lowers the stock (admin adjustments). */
	public function increment_stock( int $variant_id, int $qty, int $product_id = 0 ): void {
		$product_id = $product_id > 0 ? $product_id : $this->product_of( $variant_id );
		$affected   = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE %i SET stock_quantity = stock_quantity + %d,
					stock_status = CASE
						WHEN stock_quantity > 0 THEN %s
						WHEN backorders = %s THEN %s
						ELSE %s END,
					updated_at = %s
				WHERE id = %d AND manage_stock = 1',
				Schema::table( 'variants' ),
				$qty,
				Variant::INSTOCK,
				'no',
				Variant::OUTOFSTOCK,
				Variant::ONBACKORDER,
				$this->clock->db_now(),
				$variant_id
			)
		);
		if ( $affected === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		$this->flush( $product_id );
	}

	/**
	 * Fresh stock columns, bypassing the cache. With `$lock` the rows are
	 * locked FOR UPDATE in id order, so two writers that lock overlapping sets
	 * cannot deadlock (doc 02 §7.4). Only meaningful inside a transaction.
	 *
	 * @param list<int> $variant_ids
	 * @return array<int,array{product_id:int,manage_stock:bool,stock_quantity:?int,backorders:string,stock_status:string,low_stock_threshold:?int}>
	 */
	public function stock_rows( array $variant_ids, bool $lock = false ): array {
		$variant_ids = array_values( array_unique( array_filter( array_map( 'intval', $variant_ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( $variant_ids === [] ) {
			return [];
		}
		sort( $variant_ids );
		$placeholders = implode( ',', array_fill( 0, count( $variant_ids ), '%d' ) );
		$suffix       = $lock ? ' FOR UPDATE' : '';
		$found        = $this->wpdb->get_results(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders and a fixed suffix only.
				"SELECT id, product_id, manage_stock, stock_quantity, backorders, stock_status, low_stock_threshold FROM %i WHERE id IN ({$placeholders}) ORDER BY id{$suffix}",
				Schema::table( 'variants' ),
				...$variant_ids
			),
			ARRAY_A
		);
		if ( ! is_array( $found ) || $this->wpdb->last_error !== '' ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		$out = [];
		foreach ( $found as $row ) {
			$out[ (int) $row['id'] ] = [
				'product_id'          => (int) $row['product_id'],
				'manage_stock'        => (bool) (int) $row['manage_stock'],
				'stock_quantity'      => $row['stock_quantity'] === null ? null : (int) $row['stock_quantity'],
				'backorders'          => (string) $row['backorders'],
				'stock_status'        => (string) $row['stock_status'],
				'low_stock_threshold' => $row['low_stock_threshold'] === null ? null : (int) $row['low_stock_threshold'],
			];
		}
		return $out;
	}

	public function flush( int $product_id ): void {
		wp_cache_delete( $product_id, self::CACHE_GROUP );
	}

	private function product_of( int $variant_id ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT product_id FROM %i WHERE id = %d', Schema::table( 'variants' ), $variant_id )
		);
	}

	/**
	 * Runs a write with error output suppressed; failures are reported
	 * through exceptions instead.
	 *
	 * @param callable():(int|false) $write
	 */
	private function write( callable $write ): bool {
		$suppressed = $this->wpdb->suppress_errors( true );
		try {
			return $write() !== false;
		} finally {
			$this->wpdb->suppress_errors( $suppressed );
		}
	}

	private function failure( Variant $variant ): \RuntimeException {
		$error = QueryFailed::from_wpdb( $this->wpdb );
		if ( ! $error->is_duplicate_key() ) {
			return $error;
		}
		if ( preg_match( "/for key '(?:[^']*\\.)?sku'/", $error->getMessage() ) === 1 && $variant->sku !== null ) {
			$holder = $this->find_by_sku( $variant->sku );
			return new DuplicateSku( $variant->sku, $holder !== null ? $holder->product_id : 0 );
		}
		return new InvalidProduct( __( 'Another variation already has the same attributes.', 'flexacommerce' ), 'variations' );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return list<string>
	 */
	private function formats( array $data ): array {
		$out = [];
		foreach ( array_keys( $data ) as $column ) {
			$out[] = self::FORMATS[ $column ] ?? '%s';
		}
		return $out;
	}
}
