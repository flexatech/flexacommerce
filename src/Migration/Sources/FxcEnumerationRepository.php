<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * SQL of a FlexaCommerce to WooCommerce job (doc 05 §4.4): keyset id
 * enumeration per stage and the raw order rows. Catalog entities are read
 * through the catalog services.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only keyset enumeration of FlexaCommerce tables, values prepared.
final class FxcEnumerationRepository {
	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * @param array{from:?string,to:?string} $range order dates, `Y-m-d H:i:s` UTC
	 * @return list<int>
	 */
	public function ids( string $stage, int $after, int $upper, int $limit, array $range = [
		'from' => null,
		'to'   => null,
	] ): array {
		if ( $stage === 'settings' ) {
			return $after < 1 && $upper >= 1 ? [ 1 ] : [];
		}
		$sql = match ( $stage ) {
			'taxes'      => $this->wpdb->prepare( 'SELECT id FROM %i WHERE id > %d AND id <= %d ORDER BY id LIMIT %d', Schema::table( 'tax_rates' ), $after, $upper, $limit ),
			'attributes' => $this->wpdb->prepare( 'SELECT term_id FROM %i WHERE taxonomy = %s AND parent = 0 AND term_id > %d AND term_id <= %d ORDER BY term_id LIMIT %d', $this->wpdb->term_taxonomy, PostTypes::ATTRIBUTE, $after, $upper, $limit ),
			'terms'      => $this->wpdb->prepare(
				'SELECT term_id FROM %i WHERE ( taxonomy IN (%s, %s) OR ( taxonomy = %s AND parent > 0 ) ) AND term_id > %d AND term_id <= %d ORDER BY term_id LIMIT %d',
				$this->wpdb->term_taxonomy,
				PostTypes::CATEGORY,
				PostTypes::TAG,
				PostTypes::ATTRIBUTE,
				$after,
				$upper,
				$limit
			),
			'products'   => $this->wpdb->prepare( "SELECT ID FROM %i WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'trash') AND ID > %d AND ID <= %d ORDER BY ID LIMIT %d", $this->wpdb->posts, PostTypes::PRODUCT, $after, $upper, $limit ),
			'customers'  => $this->wpdb->prepare(
				"SELECT DISTINCT user_id FROM %i WHERE meta_key IN (%s, %s) AND meta_value <> '' AND user_id > %d AND user_id <= %d ORDER BY user_id LIMIT %d",
				$this->wpdb->usermeta,
				$this->wpdb->get_blog_prefix() . CustomerProfile::META['billing'],
				$this->wpdb->get_blog_prefix() . CustomerProfile::META['shipping'],
				$after,
				$upper,
				$limit
			),
			'orders'     => $this->wpdb->prepare(
				'SELECT id FROM %i WHERE id > %d AND id <= %d AND created_at >= %s AND created_at <= %s ORDER BY id LIMIT %d',
				Schema::table( 'orders' ),
				$after,
				$upper,
				$range['from'] ?? '0000-00-00 00:00:00',
				$range['to'] ?? '9999-12-31 23:59:59',
				$limit
			),
			default      => null,
		};
		if ( $sql === null ) {
			return [];
		}
		$ids = $this->wpdb->get_col( $sql );
		if ( $this->wpdb->last_error !== '' ) {
			throw QueryFailed::from_wpdb( $this->wpdb );
		}
		return array_map( 'intval', $ids );
	}

	public function upper_id( string $stage ): int {
		if ( $stage === 'settings' ) {
			return 1;
		}
		$sql = match ( $stage ) {
			'taxes'                => $this->wpdb->prepare( 'SELECT MAX(id) FROM %i', Schema::table( 'tax_rates' ) ),
			'attributes', 'terms'  => $this->wpdb->prepare( 'SELECT MAX(term_id) FROM %i', $this->wpdb->term_taxonomy ),
			'products'             => $this->wpdb->prepare( 'SELECT MAX(ID) FROM %i WHERE post_type = %s', $this->wpdb->posts, PostTypes::PRODUCT ),
			'customers'            => $this->wpdb->prepare( 'SELECT MAX(ID) FROM %i', $this->wpdb->users ),
			'orders'               => $this->wpdb->prepare( 'SELECT MAX(id) FROM %i', Schema::table( 'orders' ) ),
			default                => null,
		};
		return $sql === null ? 0 : (int) $this->wpdb->get_var( $sql );
	}

	/** @param array{from:?string,to:?string} $range */
	public function count( string $stage, array $range = [
		'from' => null,
		'to'   => null,
	] ): int {
		if ( $stage === 'settings' ) {
			return 1;
		}
		$upper = $this->upper_id( $stage );
		$total = 0;
		$after = 0;
		do {
			$ids    = $this->ids( $stage, $after, $upper, 5000, $range );
			$page   = count( $ids );
			$total += $page;
			$after  = $ids === [] ? $after : (int) end( $ids );
		} while ( $page === 5000 );
		return $total;
	}

	/**
	 * An order with its children, as stored.
	 *
	 * @return array{order:array<string,mixed>,items:list<array<string,mixed>>,addresses:list<array<string,mixed>>,history:list<array<string,mixed>>,meta:list<array<string,mixed>>,transactions:list<array<string,mixed>>}|null
	 */
	public function order( int $order_id ): ?array {
		$order = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'orders' ), $order_id ), ARRAY_A );
		if ( ! is_array( $order ) ) {
			return null;
		}
		$rows = fn ( string $sql ): array => $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
		return [
			'order'        => $order,
			'items'        => $rows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY position, id', Schema::table( 'order_items' ), $order_id ) ),
			'addresses'    => $rows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', Schema::table( 'order_addresses' ), $order_id ) ),
			'history'      => $rows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY created_at, id', Schema::table( 'order_history' ), $order_id ) ),
			'meta'         => $rows( $this->wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE order_id = %d ORDER BY id', Schema::table( 'order_meta' ), $order_id ) ),
			'transactions' => $rows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', Schema::table( 'transactions' ), $order_id ) ),
		];
	}

	/** @return array<string,int> currency => orders */
	public function order_currencies(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT currency, COUNT(*) AS n FROM %i GROUP BY currency', Schema::table( 'orders' ) ), ARRAY_A );
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['currency'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** @return array<string,int> status => orders */
	public function order_statuses(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', Schema::table( 'orders' ) ), ARRAY_A );
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}
}
