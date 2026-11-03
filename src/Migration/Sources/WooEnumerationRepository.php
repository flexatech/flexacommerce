<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Database\QueryFailed;

defined( 'ABSPATH' ) || exit;

/**
 * The only SQL that reads WooCommerce tables (doc 05 §1.3, §4.4): keyset id
 * enumeration per stage, counts, and the tax rate rows WC_Tax has no
 * single-rate getter for. Entities themselves are read through CRUD.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- read-only keyset enumeration of WooCommerce tables, values prepared.
final class WooEnumerationRepository {
	/** Customer meta that makes a user a customer for the customers stage. */
	private const CUSTOMER_KEYS = [ 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_address_1', 'billing_country', 'shipping_first_name', 'shipping_address_1', 'shipping_country' ];

	/** Stages that are one record with id 1. */
	private const SINGLE = [ 'settings', 'shipping' ];

	public function __construct( private readonly \wpdb $wpdb ) {}

	public static function hpos(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	public static function sync_enabled(): bool {
		return get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ) === 'yes';
	}

	/** Table the order cursor reads, stored with the job so a storage switch is noticed. */
	public function orders_table(): string {
		$wpdb = $this->wpdb;
		return self::hpos() ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
	}

	/**
	 * @param array{from:?string,to:?string} $range order dates, `Y-m-d H:i:s` UTC
	 * @return list<int>
	 */
	public function ids( string $stage, int $after, int $upper, int $limit, array $range = [
		'from' => null,
		'to'   => null,
	] ): array {
		$wpdb = $this->wpdb;
		if ( in_array( $stage, self::SINGLE, true ) || $stage === 'checkout' ) {
			return $after < 1 && $upper >= 1 ? [ 1 ] : [];
		}
		$sql = match ( $stage ) {
			'taxes'      => $wpdb->prepare( 'SELECT tax_rate_id FROM %i WHERE tax_rate_id > %d AND tax_rate_id <= %d ORDER BY tax_rate_id LIMIT %d', $wpdb->prefix . 'woocommerce_tax_rates', $after, $upper, $limit ),
			'attributes' => $wpdb->prepare( 'SELECT attribute_id FROM %i WHERE attribute_id > %d AND attribute_id <= %d ORDER BY attribute_id LIMIT %d', $wpdb->prefix . 'woocommerce_attribute_taxonomies', $after, $upper, $limit ),
			'terms'      => $this->terms_sql( $after, $upper, $limit ),
			'products'   => $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'product' AND post_status NOT IN ('auto-draft', 'trash') AND ID > %d AND ID <= %d ORDER BY ID LIMIT %d", $wpdb->posts, $after, $upper, $limit ),
			'customers'  => $this->customers_sql( $after, $upper, $limit ),
			'orders'     => $this->orders_sql( $after, $upper, $limit, $range ),
			'coupons'    => $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'shop_coupon' AND post_status NOT IN ('auto-draft', 'trash') AND ID > %d AND ID <= %d ORDER BY ID LIMIT %d", $wpdb->posts, $after, $upper, $limit ),
			'options'    => $this->options_sql( $after, $upper, $limit ),
			'reviews'    => $wpdb->prepare( "SELECT c.comment_ID FROM %i AS c INNER JOIN %i AS p ON p.ID = c.comment_post_ID WHERE p.post_type = 'product' AND c.comment_approved IN ('0', '1') AND c.comment_type IN ('review', 'comment') AND c.comment_ID > %d AND c.comment_ID <= %d ORDER BY c.comment_ID LIMIT %d", $wpdb->comments, $wpdb->posts, $after, $upper, $limit ),
			'subscriptions' => self::hpos()
				? $wpdb->prepare( "SELECT id FROM %i WHERE type = 'shop_subscription' AND status NOT IN ('trash', 'auto-draft') AND id > %d AND id <= %d ORDER BY id LIMIT %d", $this->orders_table(), $after, $upper, $limit )
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and fixed SQL only; every value is a prepare() replacement.
				: $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'shop_subscription' AND post_status NOT IN ('trash', 'auto-draft') AND ID > %d AND ID <= %d ORDER BY ID LIMIT %d", $wpdb->posts, $after, $upper, $limit ),
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; WPCS cannot follow the variable.
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
		if ( $stage === 'checkout' ) {
			// One record, there only when the merchant saved fields in the Checkout Field Editor.
			return WooCheckoutReader::exists() ? 1 : 0;
		}
		$sql = match ( $stage ) {
			'taxes'      => $wpdb->prepare( 'SELECT MAX(tax_rate_id) FROM %i', $wpdb->prefix . 'woocommerce_tax_rates' ),
			'attributes' => $wpdb->prepare( 'SELECT MAX(attribute_id) FROM %i', $wpdb->prefix . 'woocommerce_attribute_taxonomies' ),
			'terms'      => $wpdb->prepare( 'SELECT MAX(term_id) FROM %i', $wpdb->term_taxonomy ),
			'products'   => $wpdb->prepare( "SELECT MAX(ID) FROM %i WHERE post_type = 'product'", $wpdb->posts ),
			'customers'  => $wpdb->prepare( 'SELECT MAX(ID) FROM %i', $wpdb->users ),
			'orders'     => self::hpos()
				? $wpdb->prepare( "SELECT MAX(id) FROM %i WHERE type = 'shop_order'", $this->orders_table() )
				: $wpdb->prepare( "SELECT MAX(ID) FROM %i WHERE post_type = 'shop_order'", $wpdb->posts ),
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and fixed SQL only; every value is a prepare() replacement.
			'coupons'    => $wpdb->prepare( "SELECT MAX(ID) FROM %i WHERE post_type = 'shop_coupon'", $wpdb->posts ),
			'options'    => $wpdb->prepare( "SELECT MAX(ID) FROM %i WHERE post_type IN ('product', 'global_product_addon')", $wpdb->posts ),
			'reviews'    => $wpdb->prepare( 'SELECT MAX(comment_ID) FROM %i', $wpdb->comments ),
			'subscriptions' => self::hpos()
				? $wpdb->prepare( "SELECT MAX(id) FROM %i WHERE type = 'shop_subscription'", $this->orders_table() )
				: $wpdb->prepare( "SELECT MAX(ID) FROM %i WHERE post_type = 'shop_subscription'", $wpdb->posts ),
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; WPCS cannot follow the variable.
			default      => null,
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
		if ( $stage === 'checkout' ) {
			return $this->upper_id( $stage );
		}
		$upper = $this->upper_id( $stage );
		$total = 0;
		$after = 0;
		// Counting with the enumeration queries keeps one definition of "in scope".
		do {
			$ids    = $this->ids( $stage, $after, $upper, 5000, $range );
			$page   = count( $ids );
			$total += $page;
			$after  = $ids === [] ? $after : (int) end( $ids );
		} while ( $page === 5000 );
		return $total;
	}

	/** @return list<array{type:string,code:string}> location rows of one WooCommerce tax rate */
	public function tax_rate_locations( int $rate_id ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT location_type AS type, location_code AS code FROM %i WHERE tax_rate_id = %d ORDER BY location_id', $wpdb->prefix . 'woocommerce_tax_rate_locations', $rate_id ),
			ARRAY_A
		);
		return array_map(
			static fn ( array $row ): array => [
				'type' => (string) $row['type'],
				'code' => (string) $row['code'],
			],
			$rows ?: []
		);
	}

	/** @return array<string,int> product type => products */
	public function product_types(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.slug, COUNT(*) AS n FROM %i p JOIN %i tr ON tr.object_id = p.ID JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type' JOIN %i t ON t.term_id = tt.term_id WHERE p.post_type = 'product' AND p.post_status NOT IN ('auto-draft', 'trash') GROUP BY t.slug",
				$wpdb->posts,
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$wpdb->terms
			),
			ARRAY_A
		);
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['slug'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** @return list<array{sku:string,ids:list<int>}> SKUs used by more than one product or variation, case-insensitive */
	public function duplicate_skus( int $limit = 100 ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER(m.meta_value) AS sku, GROUP_CONCAT(p.ID ORDER BY p.ID) AS ids FROM %i m JOIN %i p ON p.ID = m.post_id WHERE m.meta_key = '_sku' AND m.meta_value <> '' AND p.post_type IN ('product', 'product_variation') AND p.post_status NOT IN ('auto-draft', 'trash') GROUP BY LOWER(m.meta_value) HAVING COUNT(*) > 1 ORDER BY MIN(p.ID) LIMIT %d",
				$wpdb->postmeta,
				$wpdb->posts,
				$limit
			),
			ARRAY_A
		);
		return array_map(
			static fn ( array $row ): array => [
				'sku' => (string) $row['sku'],
				'ids' => array_map( 'intval', explode( ',', (string) $row['ids'] ) ),
			],
			$rows ?: []
		);
	}

	/** @return array<string,int> order status (with `wc-`) => orders */
	public function order_statuses(): array {
		$wpdb = $this->wpdb;
		$sql  = self::hpos()
			? $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM %i WHERE type = 'shop_order' GROUP BY status", $this->orders_table() )
			: $wpdb->prepare( "SELECT post_status AS status, COUNT(*) AS n FROM %i WHERE post_type = 'shop_order' GROUP BY post_status", $wpdb->posts );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; WPCS cannot follow the variable.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Meta of a WooCommerce Subscriptions subscription, read from the table
	 * (ADR-079): the plugin keeps its schedule in meta it hides from
	 * get_meta(), and it may not be active.
	 *
	 * @param list<string> $keys
	 * @return array<string,string> key => value, the first row of each key
	 */
	public function subscription_meta( int $id, array $keys ): array {
		$wpdb = $this->wpdb;
		if ( $keys === [] ) {
			return [];
		}
		$in  = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql = self::hpos()
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is placeholders only.
			? $wpdb->prepare( "SELECT meta_key, meta_value FROM %i WHERE order_id = %d AND meta_key IN ({$in}) ORDER BY id", $wpdb->prefix . 'wc_orders_meta', $id, ...$keys )
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is placeholders only.
			: $wpdb->prepare( "SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND meta_key IN ({$in}) ORDER BY meta_id", $wpdb->postmeta, $id, ...$keys );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; WPCS cannot follow the variable.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['meta_key'] ] ??= (string) $row['meta_value'];
		}
		return $out;
	}

	/**
	 * The renewal and switch orders WooCommerce Subscriptions made for a
	 * subscription: the orders whose meta names it.
	 *
	 * @return list<array{order_id:int,relation:string}> oldest first
	 */
	public function subscription_orders( int $id ): array {
		$wpdb = $this->wpdb;
		$sql  = self::hpos()
			? $wpdb->prepare( "SELECT m.order_id AS order_id, m.meta_key AS meta_key FROM %i m INNER JOIN %i o ON o.id = m.order_id AND o.type = 'shop_order' WHERE m.meta_key IN ('_subscription_renewal', '_subscription_switch') AND m.meta_value = %s ORDER BY m.order_id", $wpdb->prefix . 'wc_orders_meta', $this->orders_table(), (string) $id )
			: $wpdb->prepare( "SELECT m.post_id AS order_id, m.meta_key AS meta_key FROM %i m INNER JOIN %i p ON p.ID = m.post_id AND p.post_type = 'shop_order' WHERE m.meta_key IN ('_subscription_renewal', '_subscription_switch') AND m.meta_value = %s ORDER BY m.post_id", $wpdb->postmeta, $wpdb->posts, (string) $id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; WPCS cannot follow the variable.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[] = [
				'order_id' => (int) $row['order_id'],
				'relation' => $row['meta_key'] === '_subscription_switch' ? 'switch' : 'renewal',
			];
		}
		return $out;
	}

	/**
	 * Live subscriptions (not trashed, not ended) for the preflight: per
	 * payment method, renewed by hand, and billed by PayPal Subscriptions.
	 *
	 * @return array{methods:array<string,int>,manual:int,paypal_plans:int}
	 */
	public function subscription_payments(): array {
		$wpdb  = $this->wpdb;
		$live  = [ 'wc-active', 'wc-on-hold', 'wc-pending', 'wc-pending-cancel' ];
		$in    = implode( ', ', array_fill( 0, count( $live ), '%s' ) );
		$hpos  = self::hpos();
		$meta  = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$owner = $hpos ? 'order_id' : 'post_id';
		$base  = $hpos
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is placeholders only.
			? $wpdb->prepare( "SELECT id AS id, payment_method AS method FROM %i WHERE type = 'shop_subscription' AND status IN ({$in})", $this->orders_table(), ...$live )
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is placeholders only.
			: $wpdb->prepare( "SELECT p.ID AS id, COALESCE(m.meta_value, '') AS method FROM %i p LEFT JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_payment_method' WHERE p.post_type = 'shop_subscription' AND p.post_status IN ({$in})", $wpdb->posts, $wpdb->postmeta, ...$live );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; WPCS cannot follow the variable.
		$rows    = $wpdb->get_results( $base, ARRAY_A );
		$methods = [];
		$ids     = [];
		foreach ( $rows ?: [] as $row ) {
			$method             = (string) $row['method'];
			$methods[ $method ] = ( $methods[ $method ] ?? 0 ) + 1;
			$ids[]              = (int) $row['id'];
		}
		$flag = function ( string $key, string $value ) use ( $wpdb, $ids, $meta, $owner ): int {
			$total = 0;
			foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
				$in     = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
				$total += (int) $wpdb->get_var(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is placeholders only.
					$wpdb->prepare( "SELECT COUNT(DISTINCT %i) FROM %i WHERE meta_key = %s AND meta_value LIKE %s AND %i IN ({$in})", $owner, $meta, $key, $value, $owner, ...$chunk )
				);
			}
			return $total;
		};
		return [
			'methods'      => $methods,
			'manual'       => $flag( '_requires_manual_renewal', 'true' ),
			'paypal_plans' => $flag( 'ppcp_subscription', '_%' ),
		];
	}

	/** @return array<string,int> currency => orders */
	public function order_currencies(): array {
		$wpdb = $this->wpdb;
		$sql  = self::hpos()
			? $wpdb->prepare( "SELECT currency, COUNT(*) AS n FROM %i WHERE type = 'shop_order' GROUP BY currency", $this->orders_table() )
			: $wpdb->prepare( "SELECT m.meta_value AS currency, COUNT(*) AS n FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_order_currency' WHERE p.post_type = 'shop_order' GROUP BY m.meta_value", $wpdb->posts, $wpdb->postmeta );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; WPCS cannot follow the variable.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( $rows ?: [] as $row ) {
			$out[ (string) $row['currency'] ] = (int) $row['n'];
		}
		return $out;
	}

	/** Orders WooCommerce still has to copy between its two stores, null when unknown. */
	public function orders_pending_sync(): ?int {
		if ( ! function_exists( 'wc_get_container' ) || ! class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class ) ) {
			return null;
		}
		try {
			return (int) wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->get_current_orders_pending_sync_count();
		} catch ( \Throwable ) {
			return null;
		}
	}

	public function active_webhooks(): int {
		$wpdb  = $this->wpdb;
		$table = $wpdb->prefix . 'wc_webhooks';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'active'", $table ) );
	}

	/** @return list<int> a few order ids, for the order number sample */
	public function sample_orders( int $limit ): array {
		return $this->ids( 'orders', 0, $this->upper_id( 'orders' ), $limit );
	}

	public function shipping_zones(): int {
		$wpdb = $this->wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'woocommerce_shipping_zones' ) );
	}

	/**
	 * The products whose Product Add-ons list is the same as this one's,
	 * this one included, ascending (ADR-029).
	 *
	 * @return list<int>
	 */
	public function addon_products( int $product_id ): array {
		$wpdb = $this->wpdb;
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_product_addons'
				WHERE p.post_type = 'product' AND p.post_status NOT IN ('auto-draft', 'trash')
				AND m.meta_value = ( SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = '_product_addons' LIMIT 1 )
				ORDER BY p.ID",
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->postmeta,
				$product_id
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Products set to leave out the global add-on groups.
	 *
	 * @return list<int>
	 */
	public function addon_excluded_products(): array {
		$wpdb = $this->wpdb;
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_product_addons_exclude_global' AND m.meta_value = '1'
				WHERE p.post_type = 'product' AND p.post_status NOT IN ('auto-draft', 'trash') ORDER BY p.ID",
				$wpdb->posts,
				$wpdb->postmeta
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Product Add-ons (ADR-029): the global groups, and for each distinct
	 * product add-on list the first product that has it. Products with the
	 * same list share one option set.
	 */
	private function options_sql( int $after, int $upper, int $limit ): string {
		$wpdb = $this->wpdb;
		return (string) $wpdb->prepare(
			"SELECT id FROM (
				SELECT ID AS id FROM %i WHERE post_type = 'global_product_addon' AND post_status NOT IN ('auto-draft', 'trash')
				UNION ALL
				SELECT MIN(p.ID) AS id FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_product_addons'
				WHERE p.post_type = 'product' AND p.post_status NOT IN ('auto-draft', 'trash') AND m.meta_value NOT IN ('', 'a:0:{}')
				GROUP BY MD5(m.meta_value)
			) addons WHERE id > %d AND id <= %d ORDER BY id LIMIT %d",
			$wpdb->posts,
			$wpdb->posts,
			$wpdb->postmeta,
			$after,
			$upper,
			$limit
		);
	}

	private function terms_sql( int $after, int $upper, int $limit ): string {
		$wpdb = $this->wpdb;
		// Attribute value taxonomies are pa_*; underscores are LIKE wildcards, so escape them.
		return (string) $wpdb->prepare(
			"SELECT DISTINCT term_id FROM %i WHERE ( taxonomy IN ('product_cat', 'product_tag') OR taxonomy LIKE %s ) AND term_id > %d AND term_id <= %d ORDER BY term_id LIMIT %d",
			$wpdb->term_taxonomy,
			$wpdb->esc_like( 'pa_' ) . '%',
			$after,
			$upper,
			$limit
		);
	}

	private function customers_sql( int $after, int $upper, int $limit ): string {
		$wpdb = $this->wpdb;
		$in   = implode( ', ', array_fill( 0, count( self::CUSTOMER_KEYS ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders and fixed SQL only; every value is a prepare() replacement.
		return (string) $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above; WPCS cannot follow the variable.
			"SELECT DISTINCT user_id FROM %i WHERE meta_key IN ({$in}) AND meta_value <> '' AND user_id > %d AND user_id <= %d ORDER BY user_id LIMIT %d",
			$wpdb->usermeta,
			...[ ...self::CUSTOMER_KEYS, $after, $upper, $limit ]
		);
	}

	/** @param array{from:?string,to:?string} $range */
	private function orders_sql( int $after, int $upper, int $limit, array $range ): string {
		$wpdb = $this->wpdb;
		$from = $range['from'] ?? null;
		$to   = $range['to'] ?? null;
		if ( self::hpos() ) {
			return (string) $wpdb->prepare(
				"SELECT id FROM %i WHERE type = 'shop_order' AND status NOT IN ('wc-checkout-draft', 'auto-draft') AND id > %d AND id <= %d AND date_created_gmt >= %s AND date_created_gmt <= %s ORDER BY id LIMIT %d",
				$this->orders_table(),
				$after,
				$upper,
				$from ?? '0000-00-00 00:00:00',
				$to ?? '9999-12-31 23:59:59',
				$limit
			);
		}
		return (string) $wpdb->prepare(
			"SELECT ID FROM %i WHERE post_type = 'shop_order' AND post_status NOT IN ('wc-checkout-draft', 'auto-draft') AND ID > %d AND ID <= %d AND post_date_gmt >= %s AND post_date_gmt <= %s ORDER BY ID LIMIT %d",
			$wpdb->posts,
			$after,
			$upper,
			$from ?? '0000-00-00 00:00:00',
			$to ?? '9999-12-31 23:59:59',
			$limit
		);
	}
}
