<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Customers\CustomerProfile;
use Flexa\Commerce\Database\QueryFailed;
use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Reviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * SQL of a FlexaCommerce to WooCommerce job (doc 05 §4.4): keyset id
 * enumeration per stage and the raw order rows. Catalog entities are read
 * through the catalog services.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only keyset enumeration of FlexaCommerce tables, values prepared.
final class FxcEnumerationRepository {
	/** Stages that are one record with id 1. */
	private const SINGLE = [ 'settings', 'shipping' ];

	public function __construct( private readonly \wpdb $wpdb ) {}

	/**
	 * @param array{from:?string,to:?string} $range order dates, `Y-m-d H:i:s` UTC
	 * @return list<int>
	 */
	public function ids( string $stage, int $after, int $upper, int $limit, array $range = [
		'from' => null,
		'to'   => null,
	] ): array {
		$wpdb = $this->wpdb;
		if ( in_array( $stage, self::SINGLE, true ) ) {
			return $after < 1 && $upper >= 1 ? [ 1 ] : [];
		}
		$sql = match ( $stage ) {
			'taxes'      => $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d AND id <= %d ORDER BY id LIMIT %d', Schema::table( 'tax_rates' ), $after, $upper, $limit ),
			'attributes' => $wpdb->prepare( 'SELECT term_id FROM %i WHERE taxonomy = %s AND parent = 0 AND term_id > %d AND term_id <= %d ORDER BY term_id LIMIT %d', $wpdb->term_taxonomy, PostTypes::ATTRIBUTE, $after, $upper, $limit ),
			'terms'      => $wpdb->prepare(
				'SELECT term_id FROM %i WHERE ( taxonomy IN (%s, %s) OR ( taxonomy = %s AND parent > 0 ) ) AND term_id > %d AND term_id <= %d ORDER BY term_id LIMIT %d',
				$wpdb->term_taxonomy,
				PostTypes::CATEGORY,
				PostTypes::TAG,
				PostTypes::ATTRIBUTE,
				$after,
				$upper,
				$limit
			),
			'products'   => $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'trash') AND ID > %d AND ID <= %d ORDER BY ID LIMIT %d", $wpdb->posts, PostTypes::PRODUCT, $after, $upper, $limit ),
			'customers'  => $wpdb->prepare(
				"SELECT DISTINCT user_id FROM %i WHERE meta_key IN (%s, %s) AND meta_value <> '' AND user_id > %d AND user_id <= %d ORDER BY user_id LIMIT %d",
				$wpdb->usermeta,
				$wpdb->get_blog_prefix() . CustomerProfile::META['billing'],
				$wpdb->get_blog_prefix() . CustomerProfile::META['shipping'],
				$after,
				$upper,
				$limit
			),
			'orders'     => $wpdb->prepare(
				'SELECT id FROM %i WHERE id > %d AND id <= %d AND created_at >= %s AND created_at <= %s ORDER BY id LIMIT %d',
				Schema::table( 'orders' ),
				$after,
				$upper,
				$range['from'] ?? '0000-00-00 00:00:00',
				$range['to'] ?? '9999-12-31 23:59:59',
				$limit
			),
			'coupons'    => $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d AND id <= %d ORDER BY id LIMIT %d', Schema::table( 'coupons' ), $after, $upper, $limit ),
			'reviews'    => $wpdb->prepare( "SELECT c.comment_ID FROM %i AS c INNER JOIN %i AS p ON p.ID = c.comment_post_ID WHERE p.post_type = %s AND c.comment_approved IN ('0', '1') AND c.comment_type = %s AND c.comment_ID > %d AND c.comment_ID <= %d ORDER BY c.comment_ID LIMIT %d", $wpdb->comments, $wpdb->posts, PostTypes::PRODUCT, Reviews::TYPE, $after, $upper, $limit ),
			default      => null,
		};
		if ( $sql === null ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; WPCS cannot follow the variable.
		$ids = $wpdb->get_col( $sql );
		if ( $wpdb->last_error !== '' ) {
			throw QueryFailed::from_wpdb( $wpdb );
		}
		return array_map( 'intval', $ids );
	}

	public function upper_id( string $stage ): int {
		$wpdb = $this->wpdb;
		if ( in_array( $stage, self::SINGLE, true ) ) {
			return 1;
		}
		$sql = match ( $stage ) {
			'taxes'                => $wpdb->prepare( 'SELECT MAX(id) FROM %i', Schema::table( 'tax_rates' ) ),
			'attributes', 'terms'  => $wpdb->prepare( 'SELECT MAX(term_id) FROM %i', $wpdb->term_taxonomy ),
			'products'             => $wpdb->prepare( 'SELECT MAX(ID) FROM %i WHERE post_type = %s', $wpdb->posts, PostTypes::PRODUCT ),
			'customers'            => $wpdb->prepare( 'SELECT MAX(ID) FROM %i', $wpdb->users ),
			'orders'               => $wpdb->prepare( 'SELECT MAX(id) FROM %i', Schema::table( 'orders' ) ),
			'coupons'              => $wpdb->prepare( 'SELECT MAX(id) FROM %i', Schema::table( 'coupons' ) ),
			'reviews'              => $wpdb->prepare( 'SELECT MAX(comment_ID) FROM %i', $wpdb->comments ),
			default                => null,
		};
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; WPCS cannot follow the variable.
		return $sql === null ? 0 : (int) $wpdb->get_var( $sql );
	}

	/** @param array{from:?string,to:?string} $range */
	public function count( string $stage, array $range = [
		'from' => null,
		'to'   => null,
	] ): int {
		if ( in_array( $stage, self::SINGLE, true ) ) {
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
	 * @return array{order:array<string,mixed>,items:list<array<string,mixed>>,addresses:list<array<string,mixed>>,history:list<array<string,mixed>>,meta:list<array<string,mixed>>,transactions:list<array<string,mixed>>,downloads:list<array<string,mixed>>,shipments:list<array<string,mixed>>}|null
	 */
	public function order( int $order_id ): ?array {
		$wpdb  = $this->wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'orders' ), $order_id ), ARRAY_A );
		if ( ! is_array( $order ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; WPCS cannot follow the variable.
		$rows = fn ( string $sql ): array => $wpdb->get_results( $sql, ARRAY_A ) ?: [];
		return [
			'order'        => $order,
			'items'        => $rows( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY position, id', Schema::table( 'order_items' ), $order_id ) ),
			'addresses'    => $rows( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', Schema::table( 'order_addresses' ), $order_id ) ),
			'history'      => $rows( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY created_at, id', Schema::table( 'order_history' ), $order_id ) ),
			'meta'         => $rows( $wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE order_id = %d ORDER BY id', Schema::table( 'order_meta' ), $order_id ) ),
			'transactions' => $rows( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id', Schema::table( 'transactions' ), $order_id ) ),
			'downloads'    => $rows( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY order_item_id, id', Schema::table( 'downloads' ), $order_id ) ),
			'shipments'    => $rows( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY shipped_at, id', Schema::table( 'shipments' ), $order_id ) ),
		];
	}

	/** @return array<string,int> currency => orders */
	public function order_currencies(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT currency, COUNT(*) AS n FROM %i GROUP BY currency', Schema::table( 'orders' ) ), ARRAY_A );
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['currency'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** Subscriptions stay here on the way back (ADR-079); the preflight says so. */
	public function subscription_count(): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'subscriptions' ) ) );
	}

	/** @return array<string,int> status => orders */
	public function order_statuses(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', Schema::table( 'orders' ) ), ARRAY_A );
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}
}
