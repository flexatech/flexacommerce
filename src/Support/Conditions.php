<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Conditions a pricing rule or a coupon may add (ADR-041), stored as
 * `{match: all|any, items: [{type, op, value}]}`. Numbers are whole: a
 * count, grams for the weight, minor units of the store currency for the
 * spend and the subtotal. Lists hold country codes, gateway ids, shipping methods (a type
 * such as `flat_rate` or one method such as `flat_rate:3`), coupon codes
 * or product ids. No items means no conditions.
 *
 * A condition on a fact not known yet (no address, no payment or shipping
 * method chosen) is neither met nor failed: the outcome is null, so a
 * coupon can wait for checkout while a rule simply does not apply.
 *
 * @phpstan-type Item array{type:string,op:string,value:int|list<string>|list<int>}
 * @phpstan-type Block array{match:string,items:list<Item>}
 */
final class Conditions {
	public const CART_QUANTITY   = 'cart_quantity';
	public const CART_WEIGHT     = 'cart_weight';
	public const COUNTRY         = 'country';
	public const PAYMENT_METHOD  = 'payment_method';
	public const SHIPPING_METHOD = 'shipping_method';
	public const COUPON          = 'coupon';
	public const LIFETIME_SPEND  = 'lifetime_spend';
	public const ORDER_COUNT     = 'order_count';
	public const PURCHASED       = 'purchased';
	/** Only for fees (ADR-043): a price rule or a coupon changes the subtotal it would read. */
	public const CART_SUBTOTAL = 'cart_subtotal';

	public const NUMBERS = [ self::CART_QUANTITY, self::CART_WEIGHT, self::LIFETIME_SPEND, self::ORDER_COUNT, self::CART_SUBTOTAL ];
	public const LISTS   = [ self::COUNTRY, self::PAYMENT_METHOD, self::SHIPPING_METHOD, self::COUPON, self::PURCHASED ];
	public const TYPES   = [ ...self::NUMBERS, ...self::LISTS ];

	/** What the customer's past orders decide. */
	public const HISTORY = [ self::LIFETIME_SPEND, self::ORDER_COUNT, self::PURCHASED ];

	public const AT_LEAST = 'gte';
	public const AT_MOST  = 'lte';
	public const IN       = 'in';
	public const NOT_IN   = 'not_in';

	public const ALL = 'all';
	public const ANY = 'any';

	public const MAX_ITEMS  = 10;
	public const MAX_VALUES = 500;

	/** @return Block */
	public static function none(): array {
		return [
			'match' => self::ALL,
			'items' => [],
		];
	}

	/** @param array<mixed> $conditions a stored block */
	public static function is_empty( array $conditions ): bool {
		return ( $conditions['items'] ?? [] ) === [];
	}

	/**
	 * A block from an admin form or a stored row, checked. `$fee` allows
	 * the cart subtotal, which only a fee can read (ADR-043).
	 *
	 * @return Block
	 * @throws \InvalidArgumentException the message says what is wrong
	 */
	public static function clean( mixed $value, bool $fee = false ): array {
		if ( $value === null || $value === '' || $value === [] ) {
			return self::none();
		}
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( __( 'The conditions are not valid.', 'flexacommerce' ) );
		}
		$match = $value['match'] ?? self::ALL;
		if ( ! in_array( $match, [ self::ALL, self::ANY ], true ) ) {
			throw new \InvalidArgumentException( __( 'Choose whether all or any of the conditions must be met.', 'flexacommerce' ) );
		}
		$items = $value['items'] ?? [];
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			throw new \InvalidArgumentException( __( 'The conditions are not valid.', 'flexacommerce' ) );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			/* translators: %d: the largest number of conditions */
			throw new \InvalidArgumentException( sprintf( __( 'Add at most %d conditions.', 'flexacommerce' ), self::MAX_ITEMS ) );
		}
		$out = [];
		foreach ( $items as $item ) {
			$out[] = self::item( $item, $fee );
		}
		return [
			'match' => (string) $match,
			'items' => $out,
		];
	}

	/**
	 * Whether the facts meet the conditions: true, false, or null when the
	 * answer waits on a fact not known yet.
	 *
	 * @param array<mixed> $conditions a block from clean()
	 */
	public static function passes( array $conditions, ConditionFacts $facts ): ?bool {
		return self::outcome( $conditions, $facts )['pass'];
	}

	/**
	 * The answer and the condition behind it: with all, the first one not
	 * met (or the first unknown); with any, the first unknown one, or the
	 * first one when none is met.
	 *
	 * @param array<mixed> $conditions a block from clean()
	 * @return array{pass:?bool,item:?array<string,mixed>}
	 */
	public static function outcome( array $conditions, ConditionFacts $facts ): array {
		$items = is_array( $conditions['items'] ?? null ) ? $conditions['items'] : [];
		$any   = ( $conditions['match'] ?? self::ALL ) === self::ANY;
		if ( $items === [] ) {
			return [
				'pass' => true,
				'item' => null,
			];
		}
		$unknown = null;
		$failed  = null;
		foreach ( $items as $item ) {
			$pass = is_array( $item ) ? self::test( $item, $facts ) : false;
			if ( $pass === null ) {
				$unknown ??= $item;
			} elseif ( $pass === false ) {
				$failed ??= $item;
				if ( ! $any ) {
					break;
				}
			} elseif ( $any ) {
				return [
					'pass' => true,
					'item' => null,
				];
			}
		}
		if ( ! $any && $failed !== null ) {
			return [
				'pass' => false,
				'item' => $failed,
			];
		}
		if ( $unknown !== null ) {
			return [
				'pass' => null,
				'item' => $unknown,
			];
		}
		return [
			'pass' => ! $any,
			'item' => $failed,
		];
	}

	/** @param array<mixed> $item */
	private static function test( array $item, ConditionFacts $facts ): ?bool {
		$type  = (string) ( $item['type'] ?? '' );
		$value = $item['value'] ?? null;
		if ( in_array( $type, self::NUMBERS, true ) ) {
			$have = match ( $type ) {
				self::CART_QUANTITY  => $facts->quantity,
				self::CART_WEIGHT    => $facts->weight_g,
				self::CART_SUBTOTAL  => $facts->subtotal,
				self::LIFETIME_SPEND => $facts->spent(),
				default              => $facts->order_count(),
			};
			if ( $have === null ) {
				return $facts->final ? false : null;
			}
			$want = is_int( $value ) ? $value : 0;
			return ( $item['op'] ?? '' ) === self::AT_MOST ? $have <= $want : $have >= $want;
		}
		$list = is_array( $value ) ? $value : [];
		$in   = match ( $type ) {
			self::PURCHASED       => $facts->bought( array_values( array_map( 'intval', $list ) ) ),
			self::COUPON          => array_intersect( array_map( 'strval', $list ), $facts->coupons ) !== [],
			self::COUNTRY         => $facts->country !== null ? in_array( $facts->country, $list, true ) : null,
			self::PAYMENT_METHOD  => $facts->payment_method !== null ? in_array( $facts->payment_method, $list, true ) : null,
			self::SHIPPING_METHOD => $facts->shipping_rate !== null ? self::ships( $facts->shipping_rate, $list ) : null,
			default               => false,
		};
		if ( $in === null ) {
			return $facts->final ? false : null;
		}
		return ( $item['op'] ?? '' ) === self::NOT_IN ? ! $in : $in;
	}

	/**
	 * A rate id is `type:instance[:suffix]`: a type matches all its
	 * methods, `type:instance` one of them.
	 *
	 * @param array<mixed> $methods
	 */
	private static function ships( string $rate, array $methods ): bool {
		foreach ( $methods as $method ) {
			$method = (string) $method;
			if ( $method !== '' && ( $rate === $method || str_starts_with( $rate, $method . ':' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return Item
	 * @throws \InvalidArgumentException
	 */
	private static function item( mixed $item, bool $fee ): array {
		$type = is_array( $item ) ? ( $item['type'] ?? '' ) : '';
		if ( ! is_array( $item ) || ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( __( 'Choose what each condition checks.', 'flexacommerce' ) );
		}
		if ( $type === self::CART_SUBTOTAL && ! $fee ) {
			throw new \InvalidArgumentException( __( 'Only a fee can depend on the cart subtotal.', 'flexacommerce' ) );
		}
		$op = $item['op'] ?? '';
		if ( in_array( $type, self::NUMBERS, true ) ) {
			if ( ! in_array( $op, [ self::AT_LEAST, self::AT_MOST ], true ) ) {
				throw new \InvalidArgumentException( __( 'Choose "at least" or "at most" for each number condition.', 'flexacommerce' ) );
			}
			$value = $item['value'] ?? null;
			if ( is_string( $value ) && preg_match( '/^\d{1,15}$/', trim( $value ) ) === 1 ) {
				$value = (int) trim( $value );
			}
			if ( ! is_int( $value ) || $value < 0 || $value > 999999999999999 ) {
				throw new \InvalidArgumentException( __( 'Enter a whole number of 0 or more for each number condition.', 'flexacommerce' ) );
			}
			return [
				'type'  => (string) $type,
				'op'    => (string) $op,
				'value' => $value,
			];
		}
		if ( ! in_array( $op, [ self::IN, self::NOT_IN ], true ) ) {
			throw new \InvalidArgumentException( __( 'Choose "is" or "is not" for each list condition.', 'flexacommerce' ) );
		}
		$raw = $item['value'] ?? [];
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $raw ) ) {
			throw new \InvalidArgumentException( __( 'The conditions are not valid.', 'flexacommerce' ) );
		}
		$values = [];
		foreach ( $raw as $entry ) {
			$entry = is_scalar( $entry ) ? trim( (string) $entry ) : '';
			$clean = match ( $type ) {
				self::PURCHASED => ( preg_match( '/^[1-9]\d{0,18}$/', $entry ) === 1 ? (int) $entry : null ),
				self::COUNTRY   => ( Countries::exists( strtoupper( $entry ) ) ? strtoupper( $entry ) : null ),
				self::COUPON    => ( $entry !== '' && mb_strlen( $entry ) <= 100 ? mb_strtolower( $entry ) : null ),
				default         => ( preg_match( '/^[a-z0-9_-]{1,64}(:[a-z0-9_-]{1,64})?$/', $entry ) === 1 ? $entry : null ),
			};
			if ( $clean === null ) {
				/* translators: %s: a value the admin entered */
				throw new \InvalidArgumentException( sprintf( __( '"%s" is not a valid choice for this condition.', 'flexacommerce' ), $entry ) );
			}
			$values[ (string) $clean ] = $clean;
		}
		if ( $values === [] ) {
			throw new \InvalidArgumentException( __( 'Choose at least one value for each list condition.', 'flexacommerce' ) );
		}
		if ( count( $values ) > self::MAX_VALUES ) {
			throw new \InvalidArgumentException( __( 'The list is too long.', 'flexacommerce' ) );
		}
		return [
			'type'  => (string) $type,
			'op'    => (string) $op,
			'value' => array_values( $values ),
		];
	}
}
