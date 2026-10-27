<?php

declare(strict_types=1);

namespace Flexa\Commerce\Coupons;

use Flexa\Commerce\Contracts\Exceptions\InvalidCoupon;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Conditions;

defined( 'ABSPATH' ) || exit;

/**
 * Saves coupons from the admin screen. An update merges the given fields
 * over the stored coupon, so a partial body changes only what it names.
 * Dates are UTC `Y-m-d H:i:s` (ISO 8601 with `T` and `Z` is read too); amounts are minor units, or hundredths of
 * a percent for a percent coupon.
 */
final class CouponEditor {
	private const MAX_LIST = 1000;

	private const ID_LISTS = [ 'product_ids', 'exclude_product_ids', 'variant_ids', 'exclude_variant_ids', 'category_ids', 'exclude_category_ids' ];

	private const GET_LISTS = [ 'get_product_ids', 'get_variant_ids', 'get_category_ids' ];

	/** Units a buy X get Y set may ask for on either side. */
	private const MAX_SET_QTY = 999;

	public function __construct(
		private readonly CouponRepository $coupons,
		private readonly Clock $clock,
	) {}

	/**
	 * @param array<string,mixed> $input
	 * @throws InvalidCoupon
	 */
	public function save( array $input, int $id = 0 ): Coupon {
		$current = null;
		if ( $id > 0 ) {
			$current = $this->coupons->find( $id );
			if ( $current === null ) {
				throw new InvalidCoupon( __( 'Coupon not found.', 'flexacommerce' ), 'id' );
			}
		}
		$fields = $this->fields( $input + ( $current?->to_array() ?? [] ), $id );
		$now    = $this->clock->db_now();
		if ( $current === null ) {
			$id = $this->coupons->insert( $fields, $now );
		} else {
			$this->coupons->update( $id, $fields, $now );
		}
		$coupon = $this->coupons->find( $id );
		if ( $coupon === null ) {
			throw new InvalidCoupon( __( 'Coupon not found.', 'flexacommerce' ), 'id' );
		}

		/**
		 * Fires after a coupon is created or changed on the admin screen.
		 *
		 * @param Coupon $coupon
		 * @param bool   $created
		 */
		do_action( 'flexacommerce.coupon.saved', $coupon, $current === null );
		return $coupon;
	}

	/** @throws InvalidCoupon */
	public function delete( int $id ): void {
		$coupon = $this->coupons->find( $id );
		if ( $coupon === null ) {
			throw new InvalidCoupon( __( 'Coupon not found.', 'flexacommerce' ), 'id' );
		}
		$this->coupons->delete( $id );

		/**
		 * Fires after a coupon is deleted. Orders keep their discount lines.
		 *
		 * @param Coupon $coupon
		 */
		do_action( 'flexacommerce.coupon.deleted', $coupon );
	}

	/**
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 * @throws InvalidCoupon
	 */
	private function fields( array $in, int $id ): array {
		$automatic = ! empty( $in['automatic'] );
		$code      = trim( self::text( $in['code'] ?? '' ) );
		if ( $code === '' ) {
			throw new InvalidCoupon( $automatic ? __( 'Enter a name.', 'flexacommerce' ) : __( 'Enter a code.', 'flexacommerce' ), 'code' );
		}
		if ( mb_strlen( $code ) > Coupon::MAX_CODE_LENGTH ) {
			/* translators: %d: maximum number of characters */
			throw new InvalidCoupon( sprintf( __( 'A code can have at most %d characters.', 'flexacommerce' ), Coupon::MAX_CODE_LENGTH ), 'code' );
		}
		if ( $this->coupons->code_taken( $code, $id ) ) {
			throw new InvalidCoupon( $automatic ? __( 'Another coupon already uses this name.', 'flexacommerce' ) : __( 'Another coupon already uses this code.', 'flexacommerce' ), 'code' );
		}

		$type = self::text( $in['type'] ?? '' );
		if ( ! in_array( $type, Coupon::TYPES, true ) ) {
			throw new InvalidCoupon( __( 'Choose a discount type.', 'flexacommerce' ), 'type' );
		}
		$bundle = $type === Coupon::BUNDLE;
		$mode   = $bundle ? self::text( $in['bundle_mode'] ?? '' ) : '';
		$mode   = $bundle && $mode === '' ? Coupon::BUNDLE_PERCENT : $mode;
		if ( $bundle && ! in_array( $mode, Coupon::BUNDLE_MODES, true ) ) {
			throw new InvalidCoupon( __( 'Choose how the bundle is priced.', 'flexacommerce' ), 'bundle_mode' );
		}
		// A free-shipping coupon may give no discount besides the shipping; a bundle may cost nothing.
		$free_shipping = ! empty( $in['free_shipping'] );
		$may_be_zero   = $free_shipping || $mode === Coupon::BUNDLE_PRICE;
		$amount        = self::integer( $in['amount'] ?? null, 'amount' ) ?? ( $may_be_zero ? 0 : null );
		if ( $amount === null || $amount < 0 || ( $amount === 0 && ! $may_be_zero ) ) {
			throw new InvalidCoupon( __( 'The amount must be more than zero.', 'flexacommerce' ), 'amount' );
		}
		$bogo = $type === Coupon::BUY_X_GET_Y;
		if ( Coupon::percent_type( $type, $mode ) && $amount > Coupon::FULL_PERCENT ) {
			throw new InvalidCoupon( __( 'A percentage cannot be more than 100.', 'flexacommerce' ), 'amount' );
		}
		if ( $bogo && $amount === 0 ) {
			throw new InvalidCoupon( __( 'The amount must be more than zero.', 'flexacommerce' ), 'amount' );
		}

		$status = self::text( $in['status'] ?? Coupon::ACTIVE );
		if ( ! in_array( $status, [ Coupon::ACTIVE, Coupon::DISABLED ], true ) ) {
			throw new InvalidCoupon( __( 'Choose a status.', 'flexacommerce' ), 'status' );
		}

		$starts  = self::date( $in['starts_at'] ?? null, 'starts_at' );
		$expires = self::date( $in['expires_at'] ?? null, 'expires_at' );
		if ( $starts !== null && $expires !== null && $expires <= $starts ) {
			throw new InvalidCoupon( __( 'The end date must be after the start date.', 'flexacommerce' ), 'expires_at' );
		}

		$min = self::integer( $in['min_subtotal'] ?? null, 'min_subtotal' );
		$max = self::integer( $in['max_subtotal'] ?? null, 'max_subtotal' );
		foreach ( [
			'min_subtotal' => $min,
			'max_subtotal' => $max,
		] as $field => $value ) {
			if ( $value !== null && $value < 0 ) {
				throw new InvalidCoupon( __( 'Spend limits cannot be negative.', 'flexacommerce' ), $field );
			}
		}
		if ( $min !== null && $max !== null && $max < $min ) {
			throw new InvalidCoupon( __( 'The maximum spend must be at least the minimum spend.', 'flexacommerce' ), 'max_subtotal' );
		}

		$limits = [];
		foreach ( [ 'usage_limit', 'per_customer_limit', 'item_limit' ] as $field ) {
			$value = self::integer( $in[ $field ] ?? 0, $field ) ?? 0;
			if ( $value < 0 || $value > 4294967295 ) {
				throw new InvalidCoupon( __( 'Usage limits must be zero (no limit) or more.', 'flexacommerce' ), $field );
			}
			$limits[ $field ] = $value;
		}

		$rules = [];
		foreach ( self::ID_LISTS as $field ) {
			$rules[ $field ] = self::ids( $in[ $field ] ?? [], $field );
		}
		if ( $bundle ) {
			$size = count( $rules['product_ids'] ) + count( $rules['variant_ids'] );
			if ( $size < 2 || $size > Coupon::MAX_BUNDLE ) {
				/* translators: %d: the most products a bundle can hold */
				throw new InvalidCoupon( sprintf( __( 'A bundle holds from 2 to %d products.', 'flexacommerce' ), Coupon::MAX_BUNDLE ), 'product_ids' );
			}
			// The set names its products; a category rule would only hide some of them.
			$rules['category_ids'] = [];
		}
		// An automatic coupon applies before the shopper gives an email, so it has no email rules.
		$rules['emails']      = $automatic ? [] : self::emails( $in['emails'] ?? [] );
		$rules               += $bogo ? self::set( $in ) : [
			'buy_qty'          => 0,
			'get_qty'          => 0,
			'repeat_limit'     => $bundle ? self::repeat( $in ) : 0,
			'get_product_ids'  => [],
			'get_variant_ids'  => [],
			'get_category_ids' => [],
		];
		$rules['bundle_mode'] = $mode;
		try {
			$rules['conditions'] = Conditions::clean( $in['conditions'] ?? null );
		} catch ( \InvalidArgumentException $e ) {
			throw new InvalidCoupon( $e->getMessage(), 'conditions' );
		}

		return [
			'code'               => $code,
			'type'               => $type,
			'amount'             => $amount,
			'status'             => $status,
			'description'        => sanitize_textarea_field( self::text( $in['description'] ?? '' ) ),
			'starts_at'          => $starts,
			'expires_at'         => $expires,
			'min_subtotal'       => $min,
			'max_subtotal'       => $max,
			'usage_limit'        => $limits['usage_limit'],
			'per_customer_limit' => $automatic ? 0 : $limits['per_customer_limit'],
			// A cart discount is split over the lines; an item limit has nothing to count there.
			'item_limit'         => $type === Coupon::FIXED_CART || $bogo || $bundle ? 0 : $limits['item_limit'],
			'individual_use'     => ! empty( $in['individual_use'] ),
			'exclude_sale_items' => ! empty( $in['exclude_sale_items'] ),
			'free_shipping'      => $free_shipping,
			'automatic'          => $automatic,
			'rules'              => $rules,
		];
	}

	/**
	 * The buy X get Y set: how many units are bought and discounted, how
	 * often, and which units can be the discounted ones.
	 *
	 * @param array<string,mixed> $in
	 * @return array<string,int|list<int>>
	 * @throws InvalidCoupon
	 */
	private static function set( array $in ): array {
		$out = [];
		foreach ( [ 'buy_qty', 'get_qty' ] as $field ) {
			$value = self::integer( $in[ $field ] ?? null, $field );
			if ( $value === null || $value < 1 || $value > self::MAX_SET_QTY ) {
				/* translators: %d: the largest quantity allowed */
				throw new InvalidCoupon( sprintf( __( 'Enter a quantity from 1 to %d.', 'flexacommerce' ), self::MAX_SET_QTY ), $field );
			}
			$out[ $field ] = $value;
		}
		$out['repeat_limit'] = self::repeat( $in );
		foreach ( self::GET_LISTS as $field ) {
			$out[ $field ] = self::ids( $in[ $field ] ?? [], $field );
		}
		return $out;
	}

	/**
	 * Sets one order gets at most; 0 is no limit.
	 *
	 * @param array<string,mixed> $in
	 * @throws InvalidCoupon
	 */
	private static function repeat( array $in ): int {
		$repeat = self::integer( $in['repeat_limit'] ?? 0, 'repeat_limit' ) ?? 0;
		if ( $repeat < 0 || $repeat > 4294967295 ) {
			throw new InvalidCoupon( __( 'Usage limits must be zero (no limit) or more.', 'flexacommerce' ), 'repeat_limit' );
		}
		return $repeat;
	}

	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** @throws InvalidCoupon */
	private static function integer( mixed $value, string $field ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/^-?\d{1,18}$/', trim( $value ) ) === 1 ) {
			return (int) trim( $value );
		}
		throw new InvalidCoupon( __( 'Enter a whole number.', 'flexacommerce' ), $field );
	}

	/** @throws InvalidCoupon */
	private static function date( mixed $value, string $field ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$text = rtrim( str_replace( 'T', ' ', self::text( $value ) ), 'Z' );
		foreach ( [ 'Y-m-d H:i:s', 'Y-m-d H:i' ] as $format ) {
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, $text, new \DateTimeZone( 'UTC' ) );
			if ( $date !== false && $date->format( $format ) === $text ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}
		throw new InvalidCoupon( __( 'Enter a valid date and time.', 'flexacommerce' ), $field );
	}

	/**
	 * @return list<int>
	 * @throws InvalidCoupon
	 */
	private static function ids( mixed $value, string $field ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidCoupon( __( 'Expected a list of IDs.', 'flexacommerce' ), $field );
		}
		$ids = [];
		foreach ( $value as $id ) {
			$id = self::integer( $id, $field );
			if ( $id === null || $id <= 0 ) {
				throw new InvalidCoupon( __( 'Expected a list of IDs.', 'flexacommerce' ), $field );
			}
			$ids[ $id ] = $id;
		}
		if ( count( $ids ) > self::MAX_LIST ) {
			throw new InvalidCoupon( __( 'The list is too long.', 'flexacommerce' ), $field );
		}
		return array_values( $ids );
	}

	/**
	 * @return list<string>
	 * @throws InvalidCoupon
	 */
	private static function emails( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $value ) ) {
			throw new InvalidCoupon( __( 'Expected a list of email addresses.', 'flexacommerce' ), 'emails' );
		}
		$emails = [];
		foreach ( $value as $email ) {
			$email = strtolower( trim( self::text( $email ) ) );
			if ( $email === '' ) {
				continue;
			}
			// A pattern such as *@example.com is checked with the wildcard taken out.
			if ( strlen( $email ) > 190 || ! is_email( str_replace( '*', 'x', $email ) ) ) {
				/* translators: %s: the email address entered */
				throw new InvalidCoupon( sprintf( __( '"%s" is not a valid email address.', 'flexacommerce' ), $email ), 'emails' );
			}
			$emails[ $email ] = $email;
		}
		if ( count( $emails ) > self::MAX_LIST ) {
			throw new InvalidCoupon( __( 'The list is too long.', 'flexacommerce' ), 'emails' );
		}
		return array_values( $emails );
	}
}
