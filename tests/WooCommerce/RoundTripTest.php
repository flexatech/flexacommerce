<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tests\WooCommerce;

use Flexa\Commerce\Database\Schema;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Direction;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\JobStatus;
use Flexa\Commerce\Migration\MigrationService;
use Flexa\Commerce\Plugin;

/**
 * WC→FXC→WC (doc 05 §11): the golden store goes to FlexaCommerce, the
 * WooCommerce data is wiped, and the FXC→WC job writes it back. The two
 * canonical snapshots, read with WooSource, must match once ids are
 * replaced by the FlexaCommerce id both sides map to.
 */
final class RoundTripTest extends \WP_UnitTestCase {
	/** Entities whose ids go through the id map. */
	private const MAPPED = [ 'product', 'variant', 'term', 'attribute', 'tax_rate', 'order', 'coupon' ];

	/** Keys that may differ by design: new ids and dates, FXC defaults, provenance. */
	private const IGNORED = [ 'number', 'modified_at', 'source', 'dropped', 'virtual', 'downloadable', 'edit_link' ];

	private GoldenStore $store;
	private MigrationService $service;

	public function set_up(): void {
		parent::set_up();
		$this->store   = GoldenStore::build();
		$this->service = Plugin::instance()->migration()->service();
	}

	public function test_round_trip_keeps_the_store(): void {
		$before = $this->snapshot();
		$there  = $this->run_job( Direction::WC_TO_FXC );
		$this->assertContains( $there->status, JobStatus::FINISHED );
		$map_a = $this->id_map();

		$this->wipe_woocommerce();
		$this->clear_id_map();
		$back = $this->run_job( Direction::FXC_TO_WC );
		$this->assertContains( $back->status, JobStatus::FINISHED, (string) wp_json_encode( $this->service->log( $back->id ) ) );
		$this->assertSame( [], $back->report['mismatches'] );
		$this->assertSame( 9, $back->report['checked'] );
		$after = $this->snapshot();
		$map_b = $this->id_map();

		$a = $this->normalize( $before, $map_a );
		$b = $this->normalize( $after, $map_b );
		// The grouped product is rejected and the checkout draft is skipped on the way there.
		unset( $a['product:'], $a['order:'] );
		$this->assertSame( array_keys( $a ), array_keys( $b ) );

		// The later of two equal SKUs was left empty in FlexaCommerce.
		$dupe = 'product:' . $map_a['product'][ (string) $this->store->products['dupe'] ];
		$this->assertSame( 'tee-1', $a[ $dupe ]['base']['sku'] );
		$this->assertNull( $b[ $dupe ]['base']['sku'] );
		$a[ $dupe ]['base']['sku'] = null;

		foreach ( $a as $key => $record ) {
			// FlexaCommerce gives every attribute value a position when it is created.
			if ( ( $record['kind'] ?? '' ) === 'attribute_value' && $record['order'] === null ) {
				$this->assertIsInt( $b[ $key ]['order'] ?? null, $key );
				$record['order'] = $b[ $key ]['order'];
			}
			$this->assertSame( $record, $b[ $key ], $key . ': ' . (string) wp_json_encode( self::diff( $record, $b[ $key ] ) ) );
		}
	}

	public function test_second_run_updates_in_place(): void {
		$this->run_job( Direction::WC_TO_FXC );
		$this->wipe_woocommerce();
		$this->clear_id_map();
		$this->run_job( Direction::FXC_TO_WC );
		$products = $this->id_map()['product'];
		$orders   = $this->id_map()['order'];

		$again = $this->run_job( Direction::FXC_TO_WC, [ 'force' => true ] );
		$this->assertContains( $again->status, JobStatus::FINISHED );
		$this->assertSame( 0, $again->counter( 'products', 'created' ) );
		$this->assertSame( 7, $again->counter( 'products', 'updated' ) );
		$this->assertSame( 9, $again->counter( 'orders', 'updated' ) );
		$this->assertSame( $products, $this->id_map()['product'], 'Products keep their WooCommerce ids.' );
		$this->assertSame( $orders, $this->id_map()['order'], 'Orders keep their WooCommerce ids.' );
		$this->assertCount(
			7,
			wc_get_products(
				[
					'limit'  => -1,
					'status' => [ 'publish', 'draft' ],
					'return' => 'ids',
				]
			)
		);
		$hoodie = wc_get_product( (int) array_search( $this->fxc_id( 'product', $this->store->products['hoodie'] ), $products, true ) );
		$this->assertInstanceOf( \WC_Product_Variable::class, $hoodie );
		$this->assertCount( 2, $hoodie->get_children(), (string) wp_json_encode( $this->service->log( $again->id ) ) );
		$partial = wc_get_order( (int) array_search( $this->fxc_id( 'order', $this->store->orders['partial_refund'] ), $orders, true ) );
		$this->assertCount( 1, $partial->get_refunds(), 'Refunds are rebuilt, not added again.' );
	}

	public function test_preflight_warns_about_existing_orders(): void {
		$this->run_job( Direction::WC_TO_FXC );
		$job   = $this->service->create( Direction::FXC_TO_WC, [], true, 1 );
		$codes = array_column( $job->report['preflight'], 'code' );
		$this->assertContains( 'target_has_orders', $codes );
	}

	/** @param array<string,mixed> $options */
	private function run_job( string $direction, array $options = [] ): Job {
		$job = $this->service->create( $direction, [ 'backup_confirmed' => true ] + $options, false, 1 );
		$this->assertSame( JobStatus::READY, $job->status, (string) wp_json_encode( $job->report ) );
		$this->service->start( $job->id );
		return $this->service->run( $job->id );
	}

	/** @return array<string,array<string,mixed>> record id => data */
	private function snapshot(): array {
		$source = Plugin::instance()->migration()->woo_source();
		$job    = $this->service->create( Direction::WC_TO_FXC, [], true, 1 );
		$out    = [];
		foreach ( Job::STAGES as $stage ) {
			$upper = $source->upper_id( $stage, $job );
			foreach ( $source->ids( $stage, 0, $upper, 1000, $job ) as $id ) {
				try {
					$record = $source->read( $stage, $id, $job );
				} catch ( Rejected ) {
					continue;
				}
				if ( $record instanceof Record ) {
					$out[ $record->id ] = $record->data;
				}
			}
		}
		$this->service->cancel( $job->id );
		return $out;
	}

	/**
	 * Records keyed and cross-referenced by the FlexaCommerce id they map to.
	 *
	 * @param array<string,array<string,mixed>>       $records
	 * @param array<string,array<string,int>>         $map entity => WooCommerce id => FlexaCommerce id
	 * @return array<string,array<string,mixed>>
	 */
	private function normalize( array $records, array $map ): array {
		$out = [];
		foreach ( $records as $id => $data ) {
			$ref = Ref::parse( $id );
			$key = $ref->entity . ':' . ( in_array( $ref->entity, self::MAPPED, true ) ? ( $map[ $ref->entity ][ $ref->id ] ?? '' ) : $ref->id );
			if ( $ref->entity === Record::ORDER ) {
				$data = self::order_positions( $data );
			}
			if ( $ref->entity === Record::SHIPPING ) {
				$data = self::shipping_ids( $data, $map );
			}
			// FlexaCommerce keeps the variant order as a position, so equal menu_order values become 0, 1, ...
			foreach ( array_keys( $data['variants'] ?? [] ) as $v ) {
				unset( $data['variants'][ $v ]['menu_order'] );
			}
			$out[ $key ] = self::walk( $data, $map );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Order item and transaction ids are new on the way back; lines and
	 * refunds point at items by position instead.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function order_positions( array $data ): array {
		$positions = [];
		foreach ( $data['items'] as $i => $item ) {
			$positions[ $item['id'] ] = 'item#' . $i;
			unset( $data['items'][ $i ]['id'] );
		}
		foreach ( $data['transactions'] as $t => $txn ) {
			unset( $data['transactions'][ $t ]['id'] );
			foreach ( $txn['lines'] ?? [] as $l => $line ) {
				$data['transactions'][ $t ]['lines'][ $l ]['item'] = $positions[ $line['item'] ] ?? 'missing';
			}
		}
		return $data;
	}

	/**
	 * Zone and method ids are new on the way back; compare the FlexaCommerce ids.
	 *
	 * @param array<string,mixed>             $data
	 * @param array<string,array<string,int>> $map
	 * @return array<string,mixed>
	 */
	private static function shipping_ids( array $data, array $map ): array {
		$method = static fn ( array $m ): array => [ 'id' => (string) ( $map['shipping_method'][ $m['id'] ] ?? 'unmapped' ) ] + $m;
		foreach ( $data['zones'] as $z => $zone ) {
			$data['zones'][ $z ]['id']      = (string) ( $map['shipping_zone'][ $zone['id'] ] ?? 'unmapped' );
			$data['zones'][ $z ]['methods'] = array_map( $method, $zone['methods'] );
		}
		$data['rest']['methods'] = array_map( $method, $data['rest']['methods'] );
		return $data;
	}

	/**
	 * @param array<string,array<string,int>> $map
	 */
	private static function walk( mixed $value, array $map ): mixed {
		if ( is_string( $value ) ) {
			return self::ref( $value, $map );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = [];
		foreach ( $value as $key => $item ) {
			if ( in_array( $key, self::IGNORED, true ) ) {
				continue;
			}
			$out[ is_string( $key ) ? self::ref( $key, $map ) : $key ] = self::walk( $item, $map );
		}
		if ( ! array_is_list( $out ) ) {
			ksort( $out );
		}
		return $out;
	}

	/** @param array<string,array<string,int>> $map */
	private static function ref( string $value, array $map ): string {
		$ref = Ref::parse( $value );
		if ( $ref === null || $ref->system !== 'wc' || ! in_array( $ref->entity, self::MAPPED, true ) ) {
			return $value;
		}
		return $ref->entity . ':' . ( $map[ $ref->entity ][ $ref->id ] ?? 'unmapped' );
	}

	/** @return array<string,array<string,int>> entity => WooCommerce id => FlexaCommerce id */
	private function id_map(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT entity, remote_id, local_id FROM %i', Schema::table( 'id_map' ) ), ARRAY_A ) as $row ) {
			$out[ $row['entity'] ][ (string) $row['remote_id'] ] = (int) $row['local_id'];
		}
		ksort( $out );
		return $out;
	}

	private function fxc_id( string $entity, int $wc_id ): int {
		return $this->id_map_before[ $entity ][ (string) $wc_id ] ?? 0;
	}

	private function clear_id_map(): void {
		global $wpdb;
		$this->id_map_before = $this->id_map();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( 'id_map' ) ) );
	}

	/** @var array<string,array<string,int>> */
	private array $id_map_before = [];

	/** Remove the WooCommerce catalog, orders, coupons and tax rates; FlexaCommerce data, users and options stay. */
	private function wipe_woocommerce(): void {
		global $wpdb;
		$types = [ 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon' ];
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE m FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ($in)", ...$types ) );
		$wpdb->query( $wpdb->prepare( "DELETE r FROM {$wpdb->term_relationships} r JOIN {$wpdb->posts} p ON p.ID = r.object_id WHERE p.post_type IN ($in)", ...$types ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE post_type IN ($in)", ...$types ) );
		$wpdb->query( "DELETE c, cm FROM {$wpdb->comments} c LEFT JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID WHERE c.comment_type = 'order_note'" );
		$taxonomies = "tt.taxonomy IN ('product_cat','product_tag') OR tt.taxonomy LIKE 'pa\\_%'";
		$wpdb->query( "DELETE tm FROM {$wpdb->termmeta} tm JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE {$taxonomies}" );
		$wpdb->query( "DELETE r FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id WHERE {$taxonomies}" );
		$wpdb->query( "DELETE t, tt FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE {$taxonomies}" );
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_' ) . '%' ) ) as $table ) {
			$wpdb->query( "DELETE FROM `{$table}`" );
		}
		foreach ( [ 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'woocommerce_attribute_taxonomies', 'woocommerce_tax_rates', 'woocommerce_tax_rate_locations', 'woocommerce_shipping_zones', 'woocommerce_shipping_zone_locations', 'woocommerce_shipping_zone_methods' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		// phpcs:enable
		// WooCommerce registers one taxonomy per attribute on init; the rows are gone, so are they.
		foreach ( get_taxonomies() as $taxonomy ) {
			if ( str_starts_with( $taxonomy, 'pa_' ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		delete_transient( 'wc_attribute_taxonomies' );
		wp_cache_flush();
	}

	/**
	 * Paths where two values differ, for the failure message.
	 *
	 * @return array<string,array{0:mixed,1:mixed}>
	 */
	private static function diff( mixed $a, mixed $b, string $path = '' ): array {
		if ( is_array( $a ) && is_array( $b ) ) {
			$out = [];
			foreach ( array_unique( [ ...array_keys( $a ), ...array_keys( $b ) ] ) as $key ) {
				$out += self::diff( $a[ $key ] ?? '(none)', $b[ $key ] ?? '(none)', $path . '/' . $key );
			}
			return $out;
		}
		return $a === $b ? [] : [ $path => [ $a, $b ] ];
	}
}
