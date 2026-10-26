<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Targets;

use Flexa\Commerce\Contracts\Exceptions\InvalidCoupon;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponEditor;
use Flexa\Commerce\Coupons\CouponRepository;
use Flexa\Commerce\Migration\Canonical\Dates;
use Flexa\Commerce\Migration\Canonical\Decimal;
use Flexa\Commerce\Migration\Canonical\Problem;
use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;
use Flexa\Commerce\Migration\Canonical\Rejected;
use Flexa\Commerce\Migration\Mapping;
use Flexa\Commerce\Orders\OrderItem;
use Flexa\Commerce\Orders\OrderStatus;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Coupons into FlexaCommerce (doc 05 §5.8), saved through the coupon editor
 * so they pass the same checks as one made on the admin screen. A rule
 * FlexaCommerce cannot apply the same way imports the coupon disabled, so
 * no shopper gets a discount the old store would not have given.
 */
final class FxcCouponWriter {
	/** @var list<Problem> */
	private array $problems = [];

	public function __construct(
		private readonly CouponRepository $coupons,
		private readonly CouponEditor $editor,
	) {}

	public function coupon( Record $record, Mapping $map ): WriteResult {
		$this->problems = [];
		$data           = $record->data;
		$code           = trim( (string) ( $data['code'] ?? '' ) );
		$type           = (string) ( $data['discount_type'] ?? '' );
		if ( ! in_array( $type, Coupon::TYPES, true ) ) {
			throw new Rejected( 'unsupported_coupon', sprintf( 'The coupon "%1$s" has the discount type "%2$s", which FlexaCommerce does not support.', $code, $type ), [ 'type' => $type ] );
		}

		$currency = Settings::string( 'currency' );
		$exponent = Currency::exponent( $currency );
		$source   = strtoupper( (string) ( $data['currency'] ?? '' ) );
		if ( $source !== '' && $source !== $currency && $type !== Coupon::PERCENT ) {
			$this->problems[] = Problem::warning( 'coupon_currency', sprintf( 'The coupon "%1$s" is in %2$s and the store uses %3$s. The amounts are copied as they are.', $code, $source, $currency ) );
		}
		$amount        = $this->amount( (string) ( $data['amount'] ?? '0' ), $type, $currency, $exponent, $code );
		$free_shipping = ! empty( $data['free_shipping'] );
		if ( $amount <= 0 && ! $free_shipping ) {
			throw new Rejected( 'unsupported_coupon', sprintf( 'The coupon "%s" was not imported. The coupon has no amount.', $code ) );
		}

		$status = ( $data['status'] ?? '' ) === Coupon::ACTIVE ? Coupon::ACTIVE : Coupon::DISABLED;
		if ( (int) ( $data['item_limit'] ?? 0 ) > 0 ) {
			$status           = Coupon::DISABLED;
			$this->problems[] = Problem::warning( 'coupon_item_limit', sprintf( 'The coupon "%s" applies to a limited number of items, which FlexaCommerce does not support. It was imported disabled.', $code ) );
		}

		$products = $this->products( (array) ( $data['products'] ?? [] ), $map, $code );
		$excluded = $this->products( (array) ( $data['exclude_products'] ?? [] ), $map, $code );
		$cats     = $this->terms( (array) ( $data['categories'] ?? [] ), $map, $code );
		if ( ( $products === [ [], [] ] && (array) ( $data['products'] ?? [] ) !== [] ) || ( $cats === [] && (array) ( $data['categories'] ?? [] ) !== [] ) ) {
			$status           = Coupon::DISABLED;
			$this->problems[] = Problem::warning( 'coupon_rules_lost', sprintf( 'None of the products or categories the coupon "%s" is limited to were imported. It was imported disabled so it does not apply to every product.', $code ) );
		}

		$target = $map->target_id( Record::COUPON, $record->source_id() );
		if ( $target > 0 && $this->coupons->find( $target ) === null ) {
			$target = 0;
		}
		if ( $this->coupons->code_taken( $code, $target ) ) {
			throw new Rejected( 'duplicate_code', sprintf( 'FlexaCommerce already has a coupon with the code "%s".', $code ), [ 'code' => $code ] );
		}

		$money = function ( mixed $value ) use ( $currency, $exponent ): ?int {
			return is_string( $value ) && Decimal::is_decimal( $value ) ? Decimal::to_minor( $value, $currency, $exponent )[0] : null;
		};
		$input = [
			'code'                 => $code,
			'type'                 => $type,
			'amount'               => max( 0, $amount ),
			'status'               => $status,
			'description'          => (string) ( $data['description'] ?? '' ),
			'starts_at'            => Dates::to_db( is_string( $data['starts_at'] ?? null ) ? $data['starts_at'] : null ),
			'expires_at'           => Dates::to_db( is_string( $data['expires_at'] ?? null ) ? $data['expires_at'] : null ),
			'min_subtotal'         => $money( $data['min_subtotal'] ?? null ),
			'max_subtotal'         => $money( $data['max_subtotal'] ?? null ),
			'usage_limit'          => max( 0, (int) ( $data['usage_limit'] ?? 0 ) ),
			'per_customer_limit'   => max( 0, (int) ( $data['per_customer_limit'] ?? 0 ) ),
			'individual_use'       => ! empty( $data['individual_use'] ),
			'exclude_sale_items'   => ! empty( $data['exclude_sale_items'] ),
			'free_shipping'        => $free_shipping,
			'product_ids'          => $products[0],
			'exclude_product_ids'  => $excluded[0],
			'variant_ids'          => $products[1],
			'exclude_variant_ids'  => $excluded[1],
			'category_ids'         => $cats,
			'exclude_category_ids' => $this->terms( (array) ( $data['exclude_categories'] ?? [] ), $map, $code ),
			'emails'               => array_values( array_map( 'strval', (array) ( $data['emails'] ?? [] ) ) ),
		];
		try {
			$coupon = $this->editor->save( $input, $target );
		} catch ( InvalidCoupon $e ) {
			throw new Rejected( 'invalid_coupon', sprintf( 'The coupon "%1$s" was not imported: %2$s', $code, $e->getMessage() ), [ 'field' => $e->field ] );
		}
		$this->coupons->set_usage_count( $coupon->id, (int) ( $data['usage_count'] ?? 0 ) );

		$map->remember( Record::COUPON, $record->source_id(), $coupon->id, $record->checksum );
		return new WriteResult( $target > 0 ? WriteResult::UPDATED : WriteResult::CREATED, $coupon->id, $this->problems );
	}

	public function modified_at( int $coupon_id ): ?string {
		$coupon = $this->coupons->find( $coupon_id );
		return $coupon === null || $coupon->updated_at === '' ? null : $coupon->updated_at;
	}

	public function delete( int $coupon_id ): bool {
		if ( $this->coupons->find( $coupon_id ) === null ) {
			return false;
		}
		$this->coupons->delete( $coupon_id );
		return true;
	}

	/** Use rows for the imported orders, so per-customer limits count them. */
	public function backfill_uses(): void {
		$this->coupons->backfill_uses( OrderItem::DISCOUNT, OrderStatus::CANCELLED );
	}

	private function amount( string $value, string $type, string $currency, int $exponent, string $code ): int {
		if ( ! Decimal::is_decimal( $value ) ) {
			return 0;
		}
		if ( $type !== Coupon::PERCENT ) {
			[ $minor, $exact ] = Decimal::to_minor( $value, $currency, $exponent );
			if ( ! $exact ) {
				$this->problems[] = Problem::warning( 'coupon_rounded', sprintf( 'The amount of the coupon "%1$s" was rounded to %2$s.', $code, Decimal::from_minor( $minor, $currency, $exponent ) ) );
			}
			return $minor;
		}
		$hundredths = Decimal::multiply( $value, '100' );
		$rounded    = Decimal::round( $hundredths, 0 );
		if ( Decimal::trim( $rounded ) !== Decimal::trim( $hundredths ) ) {
			$this->problems[] = Problem::warning( 'coupon_rounded', sprintf( 'The percentage of the coupon "%s" was rounded to two decimals.', $code ) );
		}
		return min( (int) $rounded, Coupon::FULL_PERCENT );
	}

	/**
	 * Product and variant references to FlexaCommerce product ids and variant
	 * ids; ones that were not imported are dropped.
	 *
	 * @param array<mixed> $refs
	 * @return array{0:list<int>,1:list<int>}
	 */
	private function products( array $refs, Mapping $map, string $code ): array {
		$out     = [ [], [] ];
		$missing = 0;
		foreach ( $refs as $ref ) {
			$parsed  = Ref::parse( is_string( $ref ) ? $ref : null );
			$variant = $parsed?->entity === 'variant';
			$id      = $parsed === null ? 0 : $map->target_id( $variant ? 'variant' : Record::PRODUCT, $parsed->id );
			if ( $id > 0 ) {
				$out[ $variant ? 1 : 0 ][ $id ] = $id;
			} else {
				++$missing;
			}
		}
		if ( $missing > 0 ) {
			$this->problems[] = Problem::warning( 'coupon_product_missing', sprintf( '%1$d products of the coupon "%2$s" were not imported and were left out of its rules.', $missing, $code ) );
		}
		return [ array_values( $out[0] ), array_values( $out[1] ) ];
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
			$this->problems[] = Problem::warning( 'coupon_category_missing', sprintf( '%1$d categories of the coupon "%2$s" were not imported and were left out of its rules.', $missing, $code ) );
		}
		return array_values( array_unique( $out ) );
	}
}
