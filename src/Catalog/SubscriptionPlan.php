<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

use Flexa\Commerce\Contracts\Exceptions\InvalidProduct;

defined( 'ABSPATH' ) || exit;

/**
 * How a product is billed when it is sold as a subscription (ADR-073):
 * every `interval` days, weeks, months or years, for `length` billing
 * periods (0 until cancelled), after `trial_days` free days, with a
 * `signup_fee` per unit on the first payment. The plan lives in the
 * variant row's meta; a variation without its own plan uses the
 * product's.
 */
final class SubscriptionPlan {
	public const DAY   = 'day';
	public const WEEK  = 'week';
	public const MONTH = 'month';
	public const YEAR  = 'year';

	public const PERIODS = [ self::DAY, self::WEEK, self::MONTH, self::YEAR ];

	/** The key in Variant::$meta. */
	public const META = 'subscription';

	public const MAX_INTERVAL = 365;
	public const MAX_LENGTH   = 999;
	public const MAX_TRIAL    = 730;

	public function __construct(
		public readonly string $period,
		public readonly int $interval = 1,
		public readonly int $length = 0,
		public readonly int $trial_days = 0,
		public readonly int $signup_fee = 0,
	) {}

	/** A stored plan; anything that does not read as one is no plan. */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! in_array( $data['period'] ?? null, self::PERIODS, true ) ) {
			return null;
		}
		return new self(
			(string) $data['period'],
			max( 1, min( self::MAX_INTERVAL, (int) ( $data['interval'] ?? 1 ) ) ),
			max( 0, min( self::MAX_LENGTH, (int) ( $data['length'] ?? 0 ) ) ),
			max( 0, min( self::MAX_TRIAL, (int) ( $data['trial_days'] ?? 0 ) ) ),
			max( 0, (int) ( $data['signup_fee'] ?? 0 ) )
		);
	}

	/**
	 * What the merchant entered. Null or false removes the plan.
	 *
	 * @throws InvalidProduct
	 */
	public static function parse( mixed $value, string $path ): ?self {
		if ( $value === null || $value === false || $value === '' ) {
			return null;
		}
		if ( ! is_array( $value ) ) {
			throw new InvalidProduct( __( 'An object is required.', 'flexacommerce' ), $path );
		}
		$period = $value['period'] ?? null;
		if ( ! is_string( $period ) || ! in_array( $period, self::PERIODS, true ) ) {
			throw new InvalidProduct( __( 'Choose how often the subscription is billed.', 'flexacommerce' ), $path . '.period' );
		}
		$int      = static function ( string $key, int $min, int $max, string $message ) use ( $value, $path ): int {
			$raw = $value[ $key ] ?? '';
			if ( $raw === '' ) {
				$raw = $min;
			}
			if ( ! is_numeric( $raw ) || (int) $raw != $raw || (int) $raw < $min || (int) $raw > $max ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- "3" and 3 are the same whole number, 3.5 is not.
				throw new InvalidProduct( $message, $path . '.' . $key );
			}
			return (int) $raw;
		};
		$interval = $int( 'interval', 1, self::MAX_INTERVAL, __( 'Bill every 1 to 365 periods.', 'flexacommerce' ) );
		$length   = $int( 'length', 0, self::MAX_LENGTH, __( 'The number of payments is 0 (until cancelled) to 999.', 'flexacommerce' ) );
		$trial    = $int( 'trial_days', 0, self::MAX_TRIAL, __( 'A free trial lasts 0 to 730 days.', 'flexacommerce' ) );
		$fee      = $int( 'signup_fee', 0, PHP_INT_MAX, __( 'The sign-up fee cannot be negative.', 'flexacommerce' ) );
		return new self( $period, $interval, $length, $trial, $fee );
	}

	/** @return array{period:string,interval:int,length:int,trial_days:int,signup_fee:int} */
	public function to_array(): array {
		return [
			'period'     => $this->period,
			'interval'   => $this->interval,
			'length'     => $this->length,
			'trial_days' => $this->trial_days,
			'signup_fee' => $this->signup_fee,
		];
	}

	/**
	 * Lines with the same key share one subscription: they renew on the
	 * same days. The sign-up fee is paid once, so it does not count.
	 */
	public function key(): string {
		return $this->period . ':' . $this->interval . ':' . $this->length . ':' . $this->trial_days;
	}

	/**
	 * `$utc` moved on by `$periods` billing periods. Months and years keep
	 * the day of `$anchor_day` (1 to 31) where the month has it, else take
	 * the month's last day, so a plan that started on the 31st bills on the
	 * 30th in April and on the 31st again in May.
	 */
	public function after( string $utc, int $periods, int $anchor_day = 0 ): string {
		$date = new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
		$step = $this->interval * $periods;
		if ( $this->period === self::DAY || $this->period === self::WEEK ) {
			$days = $this->period === self::WEEK ? $step * 7 : $step;
			return $date->modify( '+' . $days . ' days' )->format( 'Y-m-d H:i:s' );
		}
		$months = $this->period === self::YEAR ? $step * 12 : $step;
		$day    = $anchor_day > 0 ? min( 31, $anchor_day ) : (int) $date->format( 'j' );
		$first  = $date->setDate( (int) $date->format( 'Y' ), (int) $date->format( 'n' ), 1 )->modify( '+' . $months . ' months' );
		$last   = (int) $first->format( 't' );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), min( $day, $last ) )->format( 'Y-m-d H:i:s' );
	}

	/** "month", "3 months" and the like: one billing period in words. */
	public function every(): string {
		$n = $this->interval;
		return match ( $this->period ) {
			/* translators: %d: number of days. */
			self::DAY => $n === 1 ? __( 'day', 'flexacommerce' ) : sprintf( _n( '%d day', '%d days', $n, 'flexacommerce' ), $n ),
			/* translators: %d: number of weeks. */
			self::WEEK => $n === 1 ? __( 'week', 'flexacommerce' ) : sprintf( _n( '%d week', '%d weeks', $n, 'flexacommerce' ), $n ),
			/* translators: %d: number of months. */
			self::MONTH => $n === 1 ? __( 'month', 'flexacommerce' ) : sprintf( _n( '%d month', '%d months', $n, 'flexacommerce' ), $n ),
			/* translators: %d: number of years. */
			default => $n === 1 ? __( 'year', 'flexacommerce' ) : sprintf( _n( '%d year', '%d years', $n, 'flexacommerce' ), $n ),
		};
	}

	/** What follows a price: "/ month", "every 3 months". */
	public function period_label(): string {
		return $this->interval === 1
			/* translators: %s: billing period such as "month". */
			? sprintf( __( '/ %s', 'flexacommerce' ), $this->every() )
			/* translators: %s: billing period such as "3 months". */
			: sprintf( __( 'every %s', 'flexacommerce' ), $this->every() );
	}

	/**
	 * The terms besides the price, for the product page: "12 payments,
	 * 14-day free trial, $5.00 sign-up fee". Empty when there are none.
	 */
	public function terms( string $fee ): string {
		$parts = [];
		if ( $this->length > 0 ) {
			/* translators: %d: number of payments. */
			$parts[] = sprintf( _n( '%d payment', '%d payments', $this->length, 'flexacommerce' ), $this->length );
		}
		if ( $this->trial_days > 0 ) {
			/* translators: %d: number of free days. */
			$parts[] = sprintf( _n( '%d-day free trial', '%d-day free trial', $this->trial_days, 'flexacommerce' ), $this->trial_days );
		}
		if ( $this->signup_fee > 0 ) {
			/* translators: %s: the sign-up fee. */
			$parts[] = sprintf( __( '%s sign-up fee', 'flexacommerce' ), $fee );
		}
		return implode( ', ', $parts );
	}

	/** "$10.00 / month", "$10.00 every 3 months": a price per billing period. */
	public function per( string $price ): string {
		return $this->interval === 1
			/* translators: 1: price, 2: billing period such as "month". */
			? sprintf( __( '%1$s / %2$s', 'flexacommerce' ), $price, $this->every() )
			/* translators: 1: price, 2: billing period such as "3 months". */
			: sprintf( __( '%1$s every %2$s', 'flexacommerce' ), $price, $this->every() );
	}

	/**
	 * The whole plan in one line: "$10.00 / month for 12 months with a
	 * 14-day free trial and a $5.00 sign-up fee". `$fee` is the formatted
	 * sign-up fee, empty when there is none.
	 */
	public function describe( string $price, string $fee = '' ): string {
		$text = $this->per( $price );
		if ( $this->length > 0 ) {
			$total = $this->length * $this->interval;
			$span  = match ( $this->period ) {
				/* translators: %d: number of days. */
				self::DAY => sprintf( _n( '%d day', '%d days', $total, 'flexacommerce' ), $total ),
				/* translators: %d: number of weeks. */
				self::WEEK => sprintf( _n( '%d week', '%d weeks', $total, 'flexacommerce' ), $total ),
				/* translators: %d: number of months. */
				self::MONTH => sprintf( _n( '%d month', '%d months', $total, 'flexacommerce' ), $total ),
				/* translators: %d: number of years. */
				default => sprintf( _n( '%d year', '%d years', $total, 'flexacommerce' ), $total ),
			};
			/* translators: 1: price per period, 2: how long it is billed, such as "12 months". */
			$text = sprintf( __( '%1$s for %2$s', 'flexacommerce' ), $text, $span );
		}
		if ( $this->trial_days > 0 ) {
			/* translators: 1: price per period, 2: number of free days. */
			$text = sprintf( _n( '%1$s with a %2$d-day free trial', '%1$s with a %2$d-day free trial', $this->trial_days, 'flexacommerce' ), $text, $this->trial_days );
		}
		if ( $fee !== '' && $this->signup_fee > 0 ) {
			/* translators: 1: the plan so far, 2: the sign-up fee. */
			$text = sprintf( __( '%1$s and a %2$s sign-up fee', 'flexacommerce' ), $text, $fee );
		}
		return $text;
	}
}
