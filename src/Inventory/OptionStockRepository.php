<?php

declare(strict_types=1);

namespace Flexa\Commerce\Inventory;

use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Stock of product option choices (ADR-040), in fxc_option_stock. A choice
 * is named `<set>-<field>-<choice>`; only choices that track stock have a
 * row. As with variants, every decrement is one conditional UPDATE, so two
 * buyers of the last one cannot both succeed. Orders call take() and
 * put_back() with the keys kept on their items, without loading options.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table, all values prepared; counts change at checkout, so they are not object-cached.
final class OptionStockRepository {
	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Transaction $transaction,
	) {}

	public static function key( int $set_id, int $field_id, int $choice_id ): string {
		return $set_id . '-' . $field_id . '-' . $choice_id;
	}

	/**
	 * What is left of each choice in one query; a key without a row is left out.
	 *
	 * @param list<string> $keys
	 * @return array<string,int>
	 */
	public function levels( array $keys, bool $lock = false ): array {
		$wpdb             = $this->wpdb;
		[ $where, $args ] = self::where( $keys );
		if ( $where === '' ) {
			return [];
		}
		$where .= $lock ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds placeholders only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT set_id, field_id, choice_id, quantity FROM %i WHERE {$where}", Schema::table( 'option_stock' ), ...$args ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		$levels = [];
		foreach ( $rows as $row ) {
			$levels[ self::key( (int) $row['set_id'], (int) $row['field_id'], (int) $row['choice_id'] ) ] = (int) $row['quantity'];
		}
		return $levels;
	}

	/**
	 * Sets what is left of each choice, adding the rows it lacks.
	 *
	 * @param array<string,int> $quantities key => quantity
	 */
	public function set( array $quantities ): void {
		$wpdb = $this->wpdb;
		foreach ( $quantities as $key => $quantity ) {
			[ $set, $field, $choice ] = self::parts( (string) $key );
			$done                     = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (set_id, field_id, choice_id, quantity) VALUES (%d, %d, %d, %d) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)',
					Schema::table( 'option_stock' ),
					$set,
					$field,
					$choice,
					$quantity
				)
			);
			if ( $done === false ) {
				throw QueryFailed::from_wpdb( $wpdb );
			}
		}
	}

	/**
	 * Drops the rows of a set except `$keep`, when a choice stops tracking
	 * stock or the set is deleted.
	 *
	 * @param list<string> $keep
	 */
	public function forget( int $set_id, array $keep = [] ): void {
		$wpdb = $this->wpdb;
		$have = $wpdb->get_results( $wpdb->prepare( 'SELECT field_id, choice_id FROM %i WHERE set_id = %d', Schema::table( 'option_stock' ), $set_id ), ARRAY_A );
		foreach ( is_array( $have ) ? $have : [] as $row ) {
			if ( in_array( self::key( $set_id, (int) $row['field_id'], (int) $row['choice_id'] ), $keep, true ) ) {
				continue;
			}
			$wpdb->delete(
				Schema::table( 'option_stock' ),
				[
					'set_id'    => $set_id,
					'field_id'  => (int) $row['field_id'],
					'choice_id' => (int) $row['choice_id'],
				],
				[ '%d', '%d', '%d' ]
			);
		}
	}

	/**
	 * Takes stock for several choices, all or nothing. A choice that no
	 * longer tracks stock is skipped. Throws InsufficientStock naming the
	 * first choice short of its quantity; the transaction then rolls back.
	 *
	 * @param array<string,array{quantity:int,label:string,variant_id:int}> $wanted key => what to take
	 * @throws InsufficientStock
	 */
	public function take( array $wanted ): void {
		$wanted = array_filter( $wanted, static fn ( array $want ): bool => $want['quantity'] > 0 );
		if ( $wanted === [] ) {
			return;
		}
		ksort( $wanted );
		$this->transaction->run(
			function () use ( $wanted ): void {
				$wpdb   = $this->wpdb;
				$levels = $this->levels( array_keys( $wanted ), true );
				// Check every row before the first write, as StockService does.
				foreach ( $wanted as $key => $want ) {
					if ( isset( $levels[ $key ] ) && $levels[ $key ] < $want['quantity'] ) {
						throw self::short( $want, $levels[ $key ] );
					}
				}
				foreach ( $wanted as $key => $want ) {
					if ( ! isset( $levels[ $key ] ) ) {
						continue;
					}
					[ $set, $field, $choice ] = self::parts( (string) $key );
					$done                     = $wpdb->query(
						$wpdb->prepare(
							'UPDATE %i SET quantity = quantity - %d WHERE set_id = %d AND field_id = %d AND choice_id = %d AND quantity >= %d',
							Schema::table( 'option_stock' ),
							$want['quantity'],
							$set,
							$field,
							$choice,
							$want['quantity']
						)
					);
					if ( $done === false ) {
						throw QueryFailed::from_wpdb( $wpdb );
					}
					if ( $done === 0 ) {
						throw self::short( $want, $levels[ $key ] );
					}
				}
			}
		);
	}

	/**
	 * Puts stock back (cancellation, refund with restock). A choice that no
	 * longer tracks stock is skipped.
	 *
	 * @param array<string,int> $quantities key => quantity
	 */
	public function put_back( array $quantities ): void {
		$wpdb = $this->wpdb;
		foreach ( $quantities as $key => $quantity ) {
			if ( $quantity <= 0 ) {
				continue;
			}
			[ $set, $field, $choice ] = self::parts( (string) $key );
			$done                     = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET quantity = quantity + %d WHERE set_id = %d AND field_id = %d AND choice_id = %d',
					Schema::table( 'option_stock' ),
					$quantity,
					$set,
					$field,
					$choice
				)
			);
			if ( $done === false ) {
				throw QueryFailed::from_wpdb( $wpdb );
			}
		}
	}

	/** @param array{quantity:int,label:string,variant_id:int} $want */
	public static function short( array $want, int $left ): InsufficientStock {
		$left = max( 0, $left );
		return new InsufficientStock(
			$want['variant_id'],
			$left,
			$left === 0
				/* translators: %s: an option choice, such as "Finish: Gold". */
				? sprintf( __( '%s is sold out.', 'flexacommerce' ), $want['label'] )
				: sprintf(
					/* translators: 1: how many are left, 2: an option choice, such as "Finish: Gold". */
					_n( 'Only %1$d of %2$s is left.', 'Only %1$d of %2$s are left.', $left, 'flexacommerce' ),
					$left,
					$want['label']
				)
		);
	}

	/** @return array{0:int,1:int,2:int} */
	private static function parts( string $key ): array {
		$parts = array_map( 'intval', array_pad( explode( '-', $key, 3 ), 3, '0' ) );
		return [ $parts[0], $parts[1], $parts[2] ];
	}

	/**
	 * @param list<string> $keys
	 * @return array{0:string,1:list<int>}
	 */
	private static function where( array $keys ): array {
		$sql  = [];
		$args = [];
		foreach ( array_unique( $keys ) as $key ) {
			[ $set, $field, $choice ] = self::parts( $key );
			if ( $set > 0 ) {
				$sql[] = '(set_id = %d AND field_id = %d AND choice_id = %d)';
				array_push( $args, $set, $field, $choice );
			}
		}
		return [ implode( ' OR ', $sql ), $args ];
	}
}
