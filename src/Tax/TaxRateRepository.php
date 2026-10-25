<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxRate;
use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Database\Transaction;
use Flexa\Commerce\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Storage and matching for fxc_tax_rates and fxc_tax_rate_locations (doc 03
 * §4.13, doc 02 §7.12.3). Matched rates are cached per location and class in
 * the `fxc_tax` group under a version key that every write bumps, so one
 * location costs at most one query per request, and none with a persistent
 * object cache.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin tables, all values prepared, cache handled here.
final class TaxRateRepository {
	public const CACHE_GROUP = 'fxc_tax';

	/** Rows per multi-row INSERT of location rows. */
	private const INSERT_CHUNK = 500;

	public function __construct(
		private readonly \wpdb $wpdb,
		private readonly Clock $clock,
		private readonly Transaction $transaction,
	) {}

	/**
	 * The rates for one location and each class, in the order the engine
	 * applies them: one rate per priority, simple rates first, then compound.
	 *
	 * @param list<string> $classes
	 * @return array<string,list<TaxRate>> keyed by class
	 * @throws QueryFailed
	 */
	public function matching( TaxLocation $location, array $classes ): array {
		$classes = array_values( array_unique( $classes ) );
		if ( $classes === [] ) {
			return [];
		}
		$version = $this->version();
		$keys    = [];
		foreach ( $classes as $class ) {
			$keys[ $class ] = $version . ':' . md5( $location->key() . '|' . $class );
		}
		$cached  = wp_cache_get_multiple( array_values( $keys ), self::CACHE_GROUP );
		$out     = [];
		$missing = [];
		foreach ( $keys as $class => $key ) {
			if ( isset( $cached[ $key ] ) && is_array( $cached[ $key ] ) ) {
				$out[ $class ] = array_map( [ TaxRate::class, 'from_array' ], $cached[ $key ] );
			} else {
				$missing[] = (string) $class;
			}
		}
		if ( $missing !== [] ) {
			foreach ( $this->query_matching( $location, $missing ) as $class => $rates ) {
				wp_cache_set( $keys[ $class ], array_map( static fn ( TaxRate $rate ): array => $rate->to_array(), $rates ), self::CACHE_GROUP );
				$out[ $class ] = $rates;
			}
		}

		$ordered = [];
		foreach ( $classes as $class ) {
			$ordered[ $class ] = $out[ $class ] ?? [];
		}
		return $ordered;
	}

	/**
	 * One statement: rates without locations, plus rates reached through a
	 * matching postcode code, postcode range or city. Each branch starts from
	 * an index (class_country_state, type_code, type_range), so rows read
	 * follow the rates of one country and state, not the size of the table.
	 *
	 * @param list<string> $classes
	 * @return array<string,list<TaxRate>>
	 */
	private function query_matching( TaxLocation $location, array $classes ): array {
		$rates_table = Schema::table( 'tax_rates' );
		$loc_table   = Schema::table( 'tax_rate_locations' );
		$class_in    = implode( ',', array_fill( 0, count( $classes ), '%s' ) );
		$where       = "r.tax_class IN ({$class_in}) AND r.country IN (%s, '') AND r.state IN (%s, '')";
		$where_args  = array_merge( $classes, [ $location->country, $location->state ] );
		$columns     = 'r.id, r.tax_class, r.country, r.state, r.rate, r.name, r.priority, r.compound, r.shipping, r.location_count, '
			. "(EXISTS (SELECT 1 FROM {$loc_table} t WHERE t.rate_id = r.id AND t.type = 'postcode')"
			. " + EXISTS (SELECT 1 FROM {$loc_table} t WHERE t.rate_id = r.id AND t.type = 'city')) AS l_types";

		$branches = [];
		$args     = [];

		$branches[] = "SELECT {$columns}, '' AS l_type FROM {$rates_table} r WHERE {$where} AND r.location_count = 0";
		array_push( $args, ...$where_args );

		$codes      = self::postcode_search( $location->postcode );
		$codes_in   = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		$branches[] = "SELECT {$columns}, 'postcode' AS l_type FROM {$loc_table} l JOIN {$rates_table} r ON r.id = l.rate_id"
			. " WHERE l.type = 'postcode' AND l.code IN ({$codes_in}) AND {$where}";
		array_push( $args, ...$codes, ...$where_args );

		if ( $location->postcode !== '' && ctype_digit( $location->postcode ) && strlen( $location->postcode ) <= 18 ) {
			$branches[] = "SELECT {$columns}, 'postcode' AS l_type FROM {$loc_table} l JOIN {$rates_table} r ON r.id = l.rate_id"
				. " WHERE l.type = 'postcode' AND l.range_from <= %d AND l.range_to >= %d AND {$where}";
			array_push( $args, (int) $location->postcode, (int) $location->postcode, ...$where_args );
		}

		if ( $location->city !== '' ) {
			$branches[] = "SELECT {$columns}, 'city' AS l_type FROM {$loc_table} l JOIN {$rates_table} r ON r.id = l.rate_id"
				. " WHERE l.type = 'city' AND l.code = %s AND {$where}";
			array_push( $args, $location->city, ...$where_args );
		}

		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names from Schema, values are placeholders.
			$this->wpdb->prepare( implode( ' UNION ALL ', $branches ), ...$args ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}

		/** @var array<int,array{row:array<string,mixed>,types:array<string,true>}> $found */
		$found = [];
		foreach ( $rows as $row ) {
			$id             = (int) $row['id'];
			$found[ $id ] ??= [
				'row'   => $row,
				'types' => [],
			];
			if ( $row['l_type'] !== '' ) {
				$found[ $id ]['types'][ (string) $row['l_type'] ] = true;
			}
		}

		$by_class = array_fill_keys( $classes, [] );
		foreach ( $found as $hit ) {
			$row = $hit['row'];
			if ( (int) $row['location_count'] > 0 && count( $hit['types'] ) !== (int) $row['l_types'] ) {
				continue; // A rate with postcode and city rows must match one of each.
			}
			$row['has_postcode']                      = isset( $hit['types']['postcode'] );
			$row['has_city']                          = isset( $hit['types']['city'] );
			$by_class[ (string) $row['tax_class'] ][] = $row;
		}

		$out = [];
		foreach ( $by_class as $class => $candidates ) {
			$out[ (string) $class ] = self::pick( $candidates );
		}
		return $out;
	}

	/**
	 * WooCommerce's order (WC_Tax::sort_rates_callback): priority, a set
	 * country before an empty one, a set state before an empty one, postcode
	 * match, city match, then id. The first rate of each priority wins.
	 *
	 * @param list<array<string,mixed>> $rows
	 * @return list<TaxRate> simple rates first, then compound, each by priority
	 */
	private static function pick( array $rows ): array {
		usort(
			$rows,
			static fn ( array $a, array $b ): int => [ (int) $a['priority'], $a['country'] === '' ? 1 : 0, $a['state'] === '' ? 1 : 0, $a['has_postcode'] ? 0 : 1, $a['has_city'] ? 0 : 1, (int) $a['id'] ]
				<=> [ (int) $b['priority'], $b['country'] === '' ? 1 : 0, $b['state'] === '' ? 1 : 0, $b['has_postcode'] ? 0 : 1, $b['has_city'] ? 0 : 1, (int) $b['id'] ]
		);
		$simple   = [];
		$compound = [];
		$seen     = [];
		foreach ( $rows as $row ) {
			$priority = (int) $row['priority'];
			if ( isset( $seen[ $priority ] ) ) {
				continue;
			}
			$seen[ $priority ] = true;
			$rate              = RateRow::from_row(
				$row + [
					'rate_order' => 0,
					'source'     => 'manual',
				]
			)->to_rate();
			if ( $rate->compound ) {
				$compound[] = $rate;
			} else {
				$simple[] = $rate;
			}
		}
		return array_merge( $simple, $compound );
	}

	/**
	 * The exact code and its `*` prefixes: 90210, 9021*, 902*, 90*, 9*, *.
	 *
	 * @return list<string>
	 */
	public static function postcode_search( string $postcode ): array {
		$codes = [ '*' ];
		if ( $postcode !== '' ) {
			$codes[] = $postcode;
			for ( $i = strlen( $postcode ) - 1; $i > 0; $i-- ) {
				$codes[] = substr( $postcode, 0, $i ) . '*';
			}
			$codes[] = $postcode . '*';
		}
		return array_values( array_unique( $codes ) );
	}

	public function find( int $id ): ?RateRow {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'tax_rates' ), $id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return RateRow::from_row( $row, $this->locations( [ $id ] )[ $id ] ?? [] );
	}

	/**
	 * One page of a class for the Taxes screen, in rate order. The search
	 * matches country, state, name, or a postcode or city row.
	 *
	 * @return array{rows:list<RateRow>,total:int}
	 */
	public function page( string $tax_class, string $search = '', int $page = 1, int $per_page = 100 ): array {
		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$where    = $this->wpdb->prepare( 'r.tax_class = %s', $tax_class );
		$search   = trim( $search );
		if ( $search !== '' ) {
			$like   = '%' . $this->wpdb->esc_like( strtoupper( $search ) ) . '%';
			$where .= $this->wpdb->prepare(
				' AND (r.country LIKE %s OR r.state LIKE %s OR UPPER(r.name) LIKE %s OR EXISTS (SELECT 1 FROM %i s WHERE s.rate_id = r.id AND s.code LIKE %s))',
				$like,
				$like,
				$like,
				Schema::table( 'tax_rate_locations' ),
				$like
			);
		}
		$table = Schema::table( 'tax_rates' );
		$total = (int) $this->wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i r WHERE {$where}", $table )
		);
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
				"SELECT r.* FROM %i r WHERE {$where} ORDER BY r.rate_order, r.id LIMIT %d OFFSET %d",
				$table,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		return [
			'rows'  => $this->hydrate( is_array( $rows ) ? $rows : [] ),
			'total' => $total,
		];
	}

	/**
	 * Every rate, optionally of one class or source, for export and previews.
	 *
	 * @return list<RateRow>
	 */
	public function all( ?string $tax_class = null, ?string $source = null ): array {
		$where = '1=1';
		if ( $tax_class !== null ) {
			$where .= $this->wpdb->prepare( ' AND tax_class = %s', $tax_class );
		}
		if ( $source !== null ) {
			$where .= $this->wpdb->prepare( ' AND source = %s', $source );
		}
		$rows = $this->wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
			$this->wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY tax_class, rate_order, id", Schema::table( 'tax_rates' ) ),
			ARRAY_A
		);
		return $this->hydrate( is_array( $rows ) ? $rows : [] );
	}

	/** @return array<string,int> number of rates per class slug */
	public function counts(): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT tax_class, COUNT(*) AS n FROM %i GROUP BY tax_class', Schema::table( 'tax_rates' ) ),
			ARRAY_A
		);
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[ (string) $row['tax_class'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Insert (id 0) or update one rate and replace its locations.
	 *
	 * @throws QueryFailed
	 */
	public function save( RateRow $row ): RateRow {
		return $this->transaction->run( fn (): RateRow => $this->write( $row ) );
	}

	/**
	 * Insert many rates in one transaction (CSV batches, presets).
	 *
	 * @param list<RateRow> $rows
	 * @return int rates written
	 * @throws QueryFailed
	 */
	public function save_many( array $rows ): int {
		if ( $rows === [] ) {
			return 0;
		}
		return $this->transaction->run(
			function () use ( $rows ): int {
				foreach ( $rows as $row ) {
					$this->write( $row, false );
				}
				$this->bump();
				return count( $rows );
			}
		);
	}

	/**
	 * Save rows (id 0 inserts) and delete ids in one transaction.
	 *
	 * @param list<RateRow> $rows
	 * @param list<int>     $delete
	 * @throws QueryFailed
	 */
	public function change( array $rows, array $delete ): void {
		$this->transaction->run(
			function () use ( $rows, $delete ): void {
				$this->delete( $delete );
				foreach ( $rows as $row ) {
					$this->write( $row, false );
				}
				$this->bump();
			}
		);
	}

	/**
	 * Replace the rates of one source (`preset:us`) with new rows, so a preset
	 * re-apply leaves manual and imported rows alone.
	 *
	 * @param list<RateRow> $rows
	 * @throws QueryFailed
	 */
	public function replace_source( string $source, array $rows ): int {
		return $this->transaction->run(
			function () use ( $source, $rows ): int {
				$this->delete_where( $this->wpdb->prepare( 'source = %s', $source ) );
				foreach ( $rows as $row ) {
					$this->write( $row, false );
				}
				$this->bump();
				return count( $rows );
			}
		);
	}

	/**
	 * @param list<int> $ids
	 * @throws QueryFailed
	 */
	public function delete( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) );
		if ( $ids === [] ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $this->transaction->run(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
			fn (): int => $this->delete_where( $this->wpdb->prepare( "id IN ({$in})", ...$ids ) )
		);
	}

	/** @throws QueryFailed */
	public function delete_class( string $tax_class ): int {
		return $this->transaction->run( fn (): int => $this->delete_where( $this->wpdb->prepare( 'tax_class = %s', $tax_class ) ) );
	}

	/** @throws QueryFailed */
	public function delete_source( string $source ): int {
		return $this->transaction->run( fn (): int => $this->delete_where( $this->wpdb->prepare( 'source = %s', $source ) ) );
	}

	/** The cache version; every write moves it, which orphans every cached match. */
	public function version(): string {
		$version = wp_cache_get( 'version', self::CACHE_GROUP );
		if ( ! is_string( $version ) || $version === '' ) {
			$version = $this->bump();
		}
		return $version;
	}

	private function bump(): string {
		$version = str_replace( '.', '', uniqid( '', true ) );
		wp_cache_set( 'version', $version, self::CACHE_GROUP );
		return $version;
	}

	/** @throws QueryFailed */
	private function write( RateRow $row, bool $bump = true ): RateRow {
		$table     = Schema::table( 'tax_rates' );
		$locations = $row->location_rows();
		$now       = $this->clock->db_now();
		$data      = [
			'tax_class'      => $row->tax_class,
			'country'        => $row->country,
			'state'          => $row->state,
			'rate'           => $row->rate,
			'name'           => $row->name,
			'priority'       => $row->priority,
			'compound'       => $row->compound ? 1 : 0,
			'shipping'       => $row->shipping ? 1 : 0,
			'rate_order'     => $row->rate_order,
			'location_count' => count( $locations ),
			'source'         => $row->source,
			'updated_at'     => $now,
		];
		$formats   = [ '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ];
		$id        = $row->id;
		if ( $id > 0 ) {
			$ok = $this->wpdb->update( $table, $data, [ 'id' => $id ], $formats, [ '%d' ] );
			if ( $ok === false ) {
				throw QueryFailed::from_wpdb( $this->wpdb );
			}
			$this->check( $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM %i WHERE rate_id = %d', Schema::table( 'tax_rate_locations' ), $id ) ) );
		} else {
			if ( $data['rate_order'] === 0 ) {
				$data['rate_order'] = 1 + (int) $this->wpdb->get_var(
					$this->wpdb->prepare( 'SELECT COALESCE(MAX(rate_order), 0) FROM %i WHERE tax_class = %s', $table, $row->tax_class )
				);
			}
			$data['created_at'] = $now;
			$formats[]          = '%s';
			if ( $this->wpdb->insert( $table, $data, $formats ) === false ) {
				throw QueryFailed::from_wpdb( $this->wpdb );
			}
			$id = (int) $this->wpdb->insert_id;
		}
		$this->insert_locations( $id, $locations );
		if ( $bump ) {
			$this->bump();
		}
		return new RateRow( $id, $row->tax_class, $row->country, $row->state, $row->rate, $row->name, $row->priority, $row->compound, $row->shipping, (int) $data['rate_order'], $row->postcodes, $row->cities, $row->source );
	}

	/**
	 * @param list<array{type:string,code:string,range_from:?int,range_to:?int}> $locations
	 * @throws QueryFailed
	 */
	private function insert_locations( int $rate_id, array $locations ): void {
		foreach ( array_chunk( $locations, self::INSERT_CHUNK ) as $chunk ) {
			$values = [];
			$args   = [ Schema::table( 'tax_rate_locations' ) ];
			foreach ( $chunk as $location ) {
				if ( $location['range_from'] === null ) {
					$values[] = '(%d, %s, %s, NULL, NULL)';
					array_push( $args, $rate_id, $location['type'], $location['code'] );
				} else {
					$values[] = '(%d, %s, %s, %d, %d)';
					array_push( $args, $rate_id, $location['type'], $location['code'], $location['range_from'], $location['range_to'] );
				}
			}
			$this->check(
				$this->wpdb->query(
					$this->wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
						'INSERT INTO %i (rate_id, type, code, range_from, range_to) VALUES ' . implode( ',', $values ),
						...$args
					)
				)
			);
		}
	}

	/**
	 * @param string $where a prepared condition on fxc_tax_rates
	 * @throws QueryFailed
	 */
	private function delete_where( string $where ): int {
		$rates = Schema::table( 'tax_rates' );
		$this->check(
			$this->wpdb->query(
				$this->wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared by the caller.
					"DELETE FROM %i WHERE rate_id IN (SELECT id FROM %i WHERE {$where})",
					Schema::table( 'tax_rate_locations' ),
					$rates
				)
			)
		);
		$deleted = $this->wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared by the caller.
			$this->wpdb->prepare( "DELETE FROM %i WHERE {$where}", $rates )
		);
		$this->check( $deleted );
		$this->bump();
		return (int) $deleted;
	}

	/** @throws QueryFailed */
	private function check( int|bool $result ): void {
		if ( $result === false ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return list<RateRow>
	 */
	private function hydrate( array $rows ): array {
		$ids       = array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
		$locations = $this->locations( $ids );
		return array_map( static fn ( array $row ): RateRow => RateRow::from_row( $row, $locations[ (int) $row['id'] ] ?? [] ), $rows );
	}

	/**
	 * @param list<int> $rate_ids
	 * @return array<int,list<array<string,mixed>>>
	 */
	private function locations( array $rate_ids ): array {
		if ( $rate_ids === [] ) {
			return [];
		}
		$out = [];
		foreach ( array_chunk( $rate_ids, 1000 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
					"SELECT rate_id, type, code, range_from, range_to FROM %i WHERE rate_id IN ({$in}) ORDER BY id",
					Schema::table( 'tax_rate_locations' ),
					...$chunk
				),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$out[ (int) $row['rate_id'] ][] = $row;
			}
		}
		return $out;
	}
}
