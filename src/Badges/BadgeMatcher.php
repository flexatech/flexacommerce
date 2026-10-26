<?php

declare(strict_types=1);

namespace Flexa\Commerce\Badges;

use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Inventory\Availability;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Which badges a product gets, and their text. Reads only the product
 * (its rows are loaded with the list, O5) and the cached post and terms,
 * so a product list costs no query per badge.
 */
final class BadgeMatcher {
	public function __construct( private readonly BadgeRepository $badges ) {}

	/**
	 * The badges of `$product` shown in `$place`, in list order, each with
	 * its label after `{percent}` is filled in.
	 *
	 * @param string $now UTC `Y-m-d H:i:s`
	 * @return list<array{badge:Badge,label:string}>
	 */
	public function for_product( Product $product, string $place, string $now ): array {
		$out = [];
		foreach ( $this->badges->all() as $badge ) {
			if ( ! $badge->is_live( $now ) || ! $badge->shows_in( $place ) || ! $this->targets( $badge, $product ) || ! $this->holds( $badge, $product, $now ) ) {
				continue;
			}
			$label = $badge->label;
			if ( str_contains( $label, Badge::PERCENT_TOKEN ) ) {
				$percent = self::sale_percent( $product, $now );
				if ( $percent <= 0 ) {
					continue;
				}
				$label = str_replace( Badge::PERCENT_TOKEN, (string) $percent, $label );
			}
			$out[] = [
				'badge' => $badge,
				'label' => $label,
			];
		}
		return $out;
	}

	private function targets( Badge $badge, Product $product ): bool {
		if ( in_array( $product->id, $badge->exclude_product_ids, true ) ) {
			return false;
		}
		return match ( $badge->applies_to ) {
			Badge::PRODUCTS   => in_array( $product->id, $badge->product_ids, true ),
			Badge::CATEGORIES => array_intersect( $badge->category_ids, self::categories( $product->id ) ) !== [],
			default           => true,
		};
	}

	private function holds( Badge $badge, Product $product, string $now ): bool {
		$rows = self::rows( $product );
		return match ( $badge->condition ) {
			Badge::ON_SALE      => self::sale_percent( $product, $now ) > 0,
			Badge::NEW          => self::is_new( $product->id, $badge->new_days, $now ),
			Badge::FEATURED     => $product->featured,
			Badge::LOW_STOCK    => array_filter( $rows, static fn ( Variant $row ): bool => self::low( $product->stock_owner( $row ) ) ) !== [],
			Badge::OUT_OF_STOCK => $rows !== [] && array_filter( $rows, static fn ( Variant $row ): bool => Availability::of( $product->stock_owner( $row ) )->is_in_stock() ) === [],
			default             => true,
		};
	}

	/** The largest sale discount, in whole percent, of the rows a customer can buy. */
	public static function sale_percent( Product $product, string $now ): int {
		$best = 0;
		foreach ( self::rows( $product ) as $row ) {
			if ( $row->is_on_sale( $now ) && (int) $row->regular_price > 0 ) {
				$best = max( $best, (int) floor( ( (int) $row->regular_price - (int) $row->sale_price ) * 100 / (int) $row->regular_price ) );
			}
		}
		return $best;
	}

	/** @return list<Variant> */
	private static function rows( Product $product ): array {
		return array_values( array_filter( $product->sellable_rows(), static fn ( Variant $row ): bool => $row->enabled ) );
	}

	private static function low( Variant $owner ): bool {
		if ( ! $owner->manage_stock || $owner->stock_quantity === null || $owner->stock_quantity <= 0 ) {
			return false;
		}
		return $owner->stock_quantity <= ( $owner->low_stock_threshold ?? Settings::int( 'low_stock_threshold' ) );
	}

	private static function is_new( int $product_id, int $days, string $now ): bool {
		$post = get_post( $product_id );
		if ( ! $post instanceof \WP_Post || $post->post_date_gmt === '0000-00-00 00:00:00' ) {
			return false;
		}
		return strtotime( $post->post_date_gmt . ' UTC' ) > strtotime( $now . ' UTC' ) - $days * DAY_IN_SECONDS;
	}

	/** @return list<int> the product's categories and their ancestors */
	private static function categories( int $product_id ): array {
		$terms = get_the_terms( $product_id, PostTypes::CATEGORY );
		$ids   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$ids[] = $term->term_id;
			foreach ( get_ancestors( $term->term_id, PostTypes::CATEGORY, 'taxonomy' ) as $parent ) {
				$ids[] = (int) $parent;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}
