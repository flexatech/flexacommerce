<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Product Add-ons as canonical option set records (doc 05
 * §5.9, ADR-029). A global add-on group is one record; the add-ons saved
 * on products are one record per distinct list, read from its first
 * product and naming every product that has the same list. The add-ons
 * stay as the extension stores them: the writer maps them, so it can say
 * what it changed. The meta is read even when the extension is inactive.
 */
final class WooOptionReader {
	public function __construct( private readonly WooEnumerationRepository $enumeration ) {}

	public function option_set( int $id ): ?Record {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( $post->post_type === 'global_product_addon' ) {
			return $this->global( $post );
		}
		if ( $post->post_type !== 'product' ) {
			return null;
		}
		$addons = self::addons( $post->ID );
		if ( $addons === [] ) {
			return null;
		}
		$depends  = [];
		$products = [];
		foreach ( $this->enumeration->addon_products( $post->ID ) as $product_id ) {
			$products[] = Ref::make( 'wc', Record::PRODUCT, $product_id );
		}
		$products = $products === [] ? [ Ref::make( 'wc', Record::PRODUCT, $post->ID ) ] : $products;
		array_push( $depends, ...$products );
		return new Record(
			Record::OPTION_SET,
			Ref::make( 'wc', Record::OPTION_SET, $post->ID ),
			[
				'scope'       => 'product',
				'name'        => $post->post_title,
				'enabled'     => true,
				// Woo shows a product's own add-ons at priority 10, before global groups of the same priority.
				'priority'    => 10,
				'products'    => $products,
				'addons'      => $addons,
				'modified_at' => Dates::from_db( $post->post_modified_gmt ),
			],
			$depends
		);
	}

	private function global( \WP_Post $post ): Record {
		$terms = wp_get_object_terms( $post->ID, 'product_cat', [ 'fields' => 'ids' ] );
		$cats  = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term_id ) {
			$cats[] = Ref::make( 'wc', Record::TERM, (int) $term_id, 'product_cat' );
		}
		$all      = get_post_meta( $post->ID, '_all_products', true );
		$excluded = [];
		foreach ( $this->enumeration->addon_excluded_products() as $product_id ) {
			$excluded[] = Ref::make( 'wc', Record::PRODUCT, $product_id );
		}
		$priority = get_post_meta( $post->ID, '_priority', true );
		return new Record(
			Record::OPTION_SET,
			Ref::make( 'wc', Record::OPTION_SET, $post->ID ),
			[
				'scope'            => 'global',
				'name'             => $post->post_title,
				'enabled'          => $post->post_status === 'publish',
				'priority'         => is_numeric( $priority ) ? (int) $priority : 10,
				// Before the "All products" box, a group without categories was for every product.
				'all_products'     => $all === '1' || ( $all === '' && $cats === [] ),
				'categories'       => $cats,
				'exclude_products' => $excluded,
				'addons'           => self::addons( $post->ID ),
				'modified_at'      => Dates::from_db( $post->post_modified_gmt ),
			],
			array_merge( $cats, $excluded )
		);
	}

	/** @return list<array<string,mixed>> */
	private static function addons( int $post_id ): array {
		$addons = get_post_meta( $post_id, '_product_addons', true );
		$out    = [];
		foreach ( is_array( $addons ) ? $addons : [] as $addon ) {
			if ( is_array( $addon ) ) {
				$out[] = self::plain( $addon );
			}
		}
		return $out;
	}

	/**
	 * Strings, numbers and arrays only, so the record survives the file
	 * transport.
	 *
	 * @param array<array-key,mixed> $value
	 * @return array<array-key,mixed>
	 */
	private static function plain( array $value ): array {
		$out = [];
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$out[ $key ] = self::plain( $item );
			} elseif ( is_scalar( $item ) || $item === null ) {
				$out[ $key ] = $item;
			}
		}
		return $out;
	}
}
