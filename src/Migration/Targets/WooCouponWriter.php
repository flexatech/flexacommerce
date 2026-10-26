<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Coupons into WooCommerce, the reverse of FxcCouponWriter. WooCommerce has
 * no start date, so a coupon that starts later is saved as a draft.
 */
final class WooCouponWriter {
	private const TYPES = [ 'percent', 'fixed_cart', 'fixed_product' ];

	/** @var list<Problem> */
	private array $problems = [];

	public function coupon( Record $record, Mapping $map ): WriteResult {
		$this->problems = [];
		$data           = $record->data;
		$code           = trim( (string) ( $data['code'] ?? '' ) );
		$type           = (string) ( $data['discount_type'] ?? '' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new Rejected( 'unsupported_coupon', sprintf( 'The coupon "%1$s" has the discount type "%2$s", which WooCommerce does not support.', $code, $type ), [ 'type' => $type ] );
		}
		$source = strtoupper( (string) ( $data['currency'] ?? '' ) );
		if ( $source !== '' && $source !== get_woocommerce_currency() && $type !== 'percent' ) {
			$this->problems[] = Problem::warning( 'coupon_currency', sprintf( 'The coupon "%1$s" is in %2$s and WooCommerce uses %3$s. The amounts are copied as they are.', $code, $source, get_woocommerce_currency() ) );
		}

		$target = $map->target_id( Record::COUPON, $record->source_id() );
		if ( $target > 0 && get_post_type( $target ) !== 'shop_coupon' ) {
			$target = 0;
		}
		$taken = (int) wc_get_coupon_id_by_code( $code, $target );
		if ( $taken > 0 ) {
			throw new Rejected( 'duplicate_code', sprintf( 'WooCommerce already has a coupon with the code "%s".', $code ), [ 'code' => $code ] );
		}

		$status = ( $data['status'] ?? '' ) === 'active' ? 'publish' : 'draft';
		$starts = Dates::to_timestamp( is_string( $data['starts_at'] ?? null ) ? $data['starts_at'] : null );
		if ( $starts !== null && $starts > time() && $status === 'publish' ) {
			$status           = 'draft';
			$this->problems[] = Problem::warning( 'coupon_starts_later', sprintf( 'The coupon "%s" starts later and WooCommerce has no start date. It was saved as a draft; publish it when it should start.', $code ) );
		}

		$products = $this->products( (array) ( $data['products'] ?? [] ), $map, $code );
		$cats     = $this->terms( (array) ( $data['categories'] ?? [] ), $map, $code );
		if ( ( $products === [] && (array) ( $data['products'] ?? [] ) !== [] ) || ( $cats === [] && (array) ( $data['categories'] ?? [] ) !== [] ) ) {
			$status           = 'draft';
			$this->problems[] = Problem::warning( 'coupon_rules_lost', sprintf( 'None of the products or categories the coupon "%s" is limited to were copied. It was saved as a draft so it does not apply to every product.', $code ) );
		}

		$coupon = new \WC_Coupon( $target );
		try {
			$coupon->set_code( $code );
			$coupon->set_description( (string) ( $data['description'] ?? '' ) );
			$coupon->set_discount_type( $type );
			$coupon->set_amount( (string) ( $data['amount'] ?? '0' ) );
			$coupon->set_status( $status );
			$coupon->set_date_expires( Dates::to_timestamp( is_string( $data['expires_at'] ?? null ) ? $data['expires_at'] : null ) );
			$coupon->set_minimum_amount( is_string( $data['min_subtotal'] ?? null ) ? $data['min_subtotal'] : '' );
			$coupon->set_maximum_amount( is_string( $data['max_subtotal'] ?? null ) ? $data['max_subtotal'] : '' );
			$coupon->set_usage_limit( max( 0, (int) ( $data['usage_limit'] ?? 0 ) ) );
			$coupon->set_usage_limit_per_user( max( 0, (int) ( $data['per_customer_limit'] ?? 0 ) ) );
			$coupon->set_limit_usage_to_x_items( (int) ( $data['item_limit'] ?? 0 ) > 0 ? (int) $data['item_limit'] : null );
			$coupon->set_individual_use( ! empty( $data['individual_use'] ) );
			$coupon->set_exclude_sale_items( ! empty( $data['exclude_sale_items'] ) );
			$coupon->set_free_shipping( ! empty( $data['free_shipping'] ) );
			$coupon->set_product_ids( $products );
			$coupon->set_excluded_product_ids( $this->products( (array) ( $data['exclude_products'] ?? [] ), $map, $code ) );
			$coupon->set_product_categories( $cats );
			$coupon->set_excluded_product_categories( $this->terms( (array) ( $data['exclude_categories'] ?? [] ), $map, $code ) );
			$coupon->set_email_restrictions( array_map( 'strval', (array) ( $data['emails'] ?? [] ) ) );
			$coupon->set_usage_count( max( 0, (int) ( $data['usage_count'] ?? 0 ) ) );
			$id = (int) $coupon->save();
		} catch ( \WC_Data_Exception $e ) {
			throw new Rejected( 'invalid_coupon', sprintf( 'The coupon "%1$s" was not imported: %2$s', $code, $e->getMessage() ) );
		}
		if ( $id <= 0 ) {
			throw new Rejected( 'coupon_failed', sprintf( 'WooCommerce did not save the coupon "%s".', $code ) );
		}

		// The data store only adds _used_by rows when an order uses the coupon, so they are written here.
		delete_post_meta( $id, '_used_by' );
		foreach ( (array) ( $data['used_by'] ?? [] ) as $who ) {
			if ( is_scalar( $who ) && (string) $who !== '' ) {
				add_post_meta( $id, '_used_by', (string) $who );
			}
		}
		clean_post_cache( $id );

		$map->remember( Record::COUPON, $record->source_id(), $id, $record->checksum );
		return new WriteResult( $target > 0 ? WriteResult::UPDATED : WriteResult::CREATED, $id, $this->problems );
	}

	public function modified_at( int $coupon_id ): ?string {
		$post = get_post( $coupon_id );
		return $post instanceof \WP_Post && $post->post_type === 'shop_coupon' ? $post->post_modified_gmt : null;
	}

	public function delete( int $coupon_id ): bool {
		if ( get_post_type( $coupon_id ) !== 'shop_coupon' ) {
			return false;
		}
		return wp_delete_post( $coupon_id, true ) instanceof \WP_Post;
	}

	/**
	 * @param array<mixed> $refs
	 * @return list<int>
	 */
	private function products( array $refs, Mapping $map, string $code ): array {
		$out     = [];
		$missing = 0;
		foreach ( $refs as $ref ) {
			$parsed = Ref::parse( is_string( $ref ) ? $ref : null );
			$id     = $parsed === null ? 0 : $map->target_id( $parsed->entity === 'variant' ? 'variant' : Record::PRODUCT, $parsed->id );
			if ( $id > 0 ) {
				$out[] = $id;
			} else {
				++$missing;
			}
		}
		if ( $missing > 0 ) {
			$this->problems[] = Problem::warning( 'coupon_product_missing', sprintf( '%1$d products of the coupon "%2$s" were not copied and were left out of its rules.', $missing, $code ) );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<mixed> $refs
	 * @return list<int>
	 */
	private function terms( array $refs, Mapping $map, string $code ): array {
		$out     = [];
		$missing = 0;
		foreach ( $refs as $ref ) {
			$id = $map->target_id( Record::TERM, Ref::parse( is_string( $ref ) ? $ref : null )->id ?? '' );
			if ( $id > 0 ) {
				$out[] = $id;
			} else {
				++$missing;
			}
		}
		if ( $missing > 0 ) {
			$this->problems[] = Problem::warning( 'coupon_category_missing', sprintf( '%1$d categories of the coupon "%2$s" were not copied and were left out of its rules.', $missing, $code ) );
		}
		return array_values( array_unique( $out ) );
	}
}
