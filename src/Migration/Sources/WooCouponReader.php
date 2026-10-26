<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;

defined( 'ABSPATH' ) || exit;

/**
 * A WooCommerce coupon as a canonical record (doc 05 §5.8). Products and
 * variations become product and variant references; the coupon depends on
 * the products and categories it names so they are imported first.
 */
final class WooCouponReader {
	public function coupon( int $id ): ?Record {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== 'shop_coupon' ) {
			return null;
		}
		$coupon  = new \WC_Coupon( $id );
		$depends = [];

		$products = $this->product_refs( $coupon->get_product_ids( 'edit' ), $depends );
		$excluded = $this->product_refs( $coupon->get_excluded_product_ids( 'edit' ), $depends );
		$cats     = $this->term_refs( $coupon->get_product_categories( 'edit' ), $depends );
		$no_cats  = $this->term_refs( $coupon->get_excluded_product_categories( 'edit' ), $depends );

		$money = static function ( mixed $value ): ?string {
			$clean = Decimal::clean( $value );
			return $clean !== null && $clean !== '0' ? $clean : null;
		};
		return new Record(
			Record::COUPON,
			Ref::make( 'wc', Record::COUPON, $id ),
			[
				'code'               => (string) $coupon->get_code( 'edit' ),
				'status'             => $post->post_status === 'publish' ? 'active' : 'disabled',
				'description'        => (string) $coupon->get_description( 'edit' ),
				'discount_type'      => (string) $coupon->get_discount_type( 'edit' ),
				'amount'             => Decimal::clean( $coupon->get_amount( 'edit' ) ) ?? '0',
				'currency'           => (string) get_option( 'woocommerce_currency', 'USD' ),
				'starts_at'          => null,
				'expires_at'         => Dates::from_wc( $coupon->get_date_expires( 'edit' ) ),
				'min_subtotal'       => $money( $coupon->get_minimum_amount( 'edit' ) ),
				'max_subtotal'       => $money( $coupon->get_maximum_amount( 'edit' ) ),
				'usage_limit'        => (int) $coupon->get_usage_limit( 'edit' ),
				'usage_count'        => (int) $coupon->get_usage_count( 'edit' ),
				'per_customer_limit' => (int) $coupon->get_usage_limit_per_user( 'edit' ),
				'item_limit'         => (int) $coupon->get_limit_usage_to_x_items( 'edit' ),
				'individual_use'     => (bool) $coupon->get_individual_use( 'edit' ),
				'exclude_sale_items' => (bool) $coupon->get_exclude_sale_items( 'edit' ),
				'free_shipping'      => (bool) $coupon->get_free_shipping( 'edit' ),
				'products'           => $products,
				'exclude_products'   => $excluded,
				'categories'         => $cats,
				'exclude_categories' => $no_cats,
				'emails'             => array_values( array_map( 'strval', $coupon->get_email_restrictions( 'edit' ) ) ),
				'used_by'            => array_values( array_map( 'strval', $coupon->get_used_by() ) ),
				'modified_at'        => Dates::from_db( $post->post_modified_gmt ),
			],
			array_values( array_unique( $depends ) )
		);
	}

	/**
	 * @param array<int|string> $ids
	 * @param list<string>      $depends
	 * @return list<string>
	 */
	private function product_refs( array $ids, array &$depends ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( $post->post_type === 'product_variation' ) {
				$out[]     = Ref::make( 'wc', 'variant', $post->ID );
				$depends[] = Ref::make( 'wc', Record::PRODUCT, $post->post_parent );
			} elseif ( $post->post_type === 'product' ) {
				$out[]     = Ref::make( 'wc', Record::PRODUCT, $post->ID );
				$depends[] = $out[ count( $out ) - 1 ];
			}
		}
		return $out;
	}

	/**
	 * @param array<int|string> $ids
	 * @param list<string>      $depends
	 * @return list<string>
	 */
	private function term_refs( array $ids, array &$depends ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$out[] = Ref::make( 'wc', Record::TERM, (int) $id, 'product_cat' );
		}
		array_push( $depends, ...$out );
		return $out;
	}
}
