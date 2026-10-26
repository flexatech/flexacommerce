<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Job;
use Flexa\Commerce\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce as the target of an FXC→WC job, the way back (doc 05 §2).
 * Writes go through the WooCommerce CRUD, which manages its own
 * transactions (wc_create_refund starts one), so a record is not wrapped
 * in ours; the id map row is written last, after the entity saved.
 */
final class WooTarget implements TargetAdapter {
	public function __construct(
		private readonly WooStoreWriter $store,
		private readonly WooCatalogWriter $catalog,
		private readonly WooOrderWriter $orders,
		private readonly WooShippingWriter $shipping,
		private readonly WooCouponWriter $coupons,
	) {}

	public function system(): string {
		return 'wc';
	}

	public function write( Record $record, Mapping $map, Job $job ): WriteResult {
		if ( ! function_exists( 'WC' ) ) {
			throw new Rejected( 'woocommerce_inactive', 'WooCommerce is not active.' );
		}
		return match ( $record->type ) {
			Record::SETTINGS  => $this->store->settings( $record, $map ),
			Record::TAX_RATE  => $this->store->tax_rate( $record, $map ),
			Record::SHIPPING  => $this->shipping->shipping( $record, $map ),
			Record::CUSTOMER  => $this->store->customer( $record, $map ),
			Record::ATTRIBUTE => $this->catalog->attribute( $record, $map ),
			Record::TERM      => $this->catalog->term( $record, $map ),
			Record::PRODUCT   => $this->catalog->product( $record, $map, $job ),
			Record::ORDER     => $this->orders->order( $record, $map, $job ),
			Record::COUPON    => $this->coupons->coupon( $record, $map ),
			default           => throw new Rejected( 'unsupported_entity', sprintf( 'WooCommerce cannot import "%s" records.', $record->type ) ),
		};
	}

	public function modified_at( string $entity, int $target_id ): ?string {
		return match ( $entity ) {
			Record::PRODUCT => $this->catalog->modified_at( $target_id ),
			Record::ORDER   => $this->orders->modified_at( $target_id ),
			Record::COUPON  => $this->coupons->modified_at( $target_id ),
			default         => null,
		};
	}

	public function finish_stage( string $stage, Job $job ): void {
		unset( $job );
		if ( $stage === 'attributes' ) {
			delete_transient( 'wc_attribute_taxonomies' );
		} elseif ( $stage === 'products' && function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
			foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
				$terms = get_terms(
					[
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'fields'     => 'ids',
					]
				);
				if ( is_array( $terms ) && $terms !== [] ) {
					wp_update_term_count_now( array_map( 'intval', $terms ), $taxonomy );
				}
			}
		}
	}

	public function delete( string $entity, int $target_id ): bool {
		return match ( $entity ) {
			Record::ORDER => $this->orders->delete( $target_id ),
			Record::COUPON => $this->coupons->delete( $target_id ),
			Record::PRODUCT, Record::TERM, Record::ATTRIBUTE => $this->catalog->delete( $entity, $target_id ),
			default => false,
		};
	}

	public function preflight( Job $job, ?Record $settings ): array {
		if ( ! function_exists( 'WC' ) ) {
			return [ Problem::error( 'woocommerce_inactive', 'WooCommerce must be active to copy data back to it.' ) ];
		}
		$problems = [];
		$data     = $settings->data ?? [];
		$currency = strtoupper( (string) ( $data['currency'] ?? '' ) );
		if ( in_array( 'orders', $job->stages(), true ) ) {
			$existing = wc_get_orders(
				[
					'limit'  => 1,
					'return' => 'ids',
					'type'   => 'shop_order',
				]
			);
			if ( is_array( $existing ) && $existing !== [] ) {
				$problems[] = Problem::warning( 'target_has_orders', 'WooCommerce already has orders. Copied orders are added next to them and get new numbers.' );
			}
			if ( $currency !== '' && ! in_array( 'settings', $job->stages(), true ) && $currency !== get_woocommerce_currency() ) {
				$problems[] = Problem::warning( 'currency_differs', sprintf( 'FlexaCommerce uses %1$s and WooCommerce uses %2$s. Orders keep their own currency.', $currency, get_woocommerce_currency() ) );
			}
		}
		return $problems;
	}
}
