<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\VariantRepository;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponRepository;
use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/** FlexaCommerce coupons in the shape WooCouponReader emits. */
final class FxcCouponReader {
	public function __construct(
		private readonly CouponRepository $coupons,
		private readonly VariantRepository $variants,
	) {}

	public function coupon( int $id ): ?Record {
		$coupon = $this->coupons->find( $id );
		if ( $coupon === null ) {
			return null;
		}
		$currency = Settings::string( 'currency' );
		$exponent = Currency::exponent( $currency );
		$money    = static fn ( ?int $minor ): ?string => $minor === null ? null : Decimal::trim( Decimal::from_minor( $minor, $currency, $exponent ) );
		$depends  = [];

		$products = $this->product_refs( $coupon->product_ids, $depends );
		$excluded = $this->product_refs( $coupon->exclude_product_ids, $depends );
		$cats     = $this->term_refs( $coupon->category_ids, $depends );
		$no_cats  = $this->term_refs( $coupon->exclude_category_ids, $depends );

		return new Record(
			Record::COUPON,
			Ref::make( 'fxc', Record::COUPON, $id ),
			[
				'code'               => $coupon->code,
				'status'             => $coupon->status,
				'description'        => $coupon->description,
				'discount_type'      => $coupon->type,
				'amount'             => $coupon->type === Coupon::PERCENT ? Decimal::trim( Decimal::divide( (string) $coupon->amount, '100', 2 ) ) : (string) $money( $coupon->amount ),
				'currency'           => $currency,
				'starts_at'          => Dates::from_db( $coupon->starts_at ),
				'expires_at'         => Dates::from_db( $coupon->expires_at ),
				'min_subtotal'       => $money( $coupon->min_subtotal ),
				'max_subtotal'       => $money( $coupon->max_subtotal ),
				'usage_limit'        => $coupon->usage_limit,
				'usage_count'        => $coupon->usage_count,
				'per_customer_limit' => $coupon->per_customer_limit,
				'item_limit'         => 0,
				'individual_use'     => $coupon->individual_use,
				'exclude_sale_items' => $coupon->exclude_sale_items,
				'free_shipping'      => false,
				'products'           => $products,
				'exclude_products'   => $excluded,
				'categories'         => $cats,
				'exclude_categories' => $no_cats,
				'emails'             => $coupon->emails,
				'used_by'            => $this->coupons->used_by( $id ),
				'modified_at'        => Dates::from_db( $coupon->updated_at !== '' ? $coupon->updated_at : null ),
			],
			array_values( array_unique( $depends ) )
		);
	}

	/**
	 * A coupon holds product ids and variant ids in one list; a product post wins.
	 *
	 * @param list<int>    $ids
	 * @param list<string> $depends
	 * @return list<string>
	 */
	private function product_refs( array $ids, array &$depends ): array {
		$out = [];
		foreach ( $ids as $id ) {
			if ( get_post_type( $id ) === PostTypes::PRODUCT ) {
				$out[]     = Ref::make( 'fxc', Record::PRODUCT, $id );
				$depends[] = $out[ count( $out ) - 1 ];
				continue;
			}
			$variant = $this->variants->find( $id );
			if ( $variant !== null && ! $variant->is_base ) {
				$out[]     = Ref::make( 'fxc', 'variant', $id );
				$depends[] = Ref::make( 'fxc', Record::PRODUCT, $variant->product_id );
			}
		}
		return $out;
	}

	/**
	 * @param list<int>    $ids
	 * @param list<string> $depends
	 * @return list<string>
	 */
	private function term_refs( array $ids, array &$depends ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$out[] = Ref::make( 'fxc', Record::TERM, $id, PostTypes::CATEGORY );
		}
		array_push( $depends, ...$out );
		return $out;
	}
}
