<?php

declare(strict_types=1);

namespace Flexa\Commerce\Coupons;

use Flexa\Commerce\Cart\CartItem;
use Flexa\Commerce\Catalog\PostTypes;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\InvalidCheckout;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\ConditionFacts;
use Flexa\Commerce\Support\Conditions;
use Flexa\Commerce\Support\IntMath;
use Flexa\Commerce\Support\Settings;
use Flexa\Commerce\Support\Units;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon codes in the cart and at checkout (doc 02 §7.13). The cart keeps
 * the codes; every read checks them again and computes the discount, so a
 * coupon that expires or runs out while it sits in a cart stops applying
 * with a message. Coupons apply in the order they were added, each on what
 * the earlier ones left of a line, in prices as entered. A use is taken in
 * the checkout transaction and given back when the order is cancelled.
 */
final class CouponService {
	public const MAX_CODES = 10;

	public function __construct(
		private readonly CouponRepository $coupons,
		private readonly PricingService $pricing,
		private readonly Clock $clock,
	) {}

	public function repository(): CouponRepository {
		return $this->coupons;
	}

	/**
	 * The discounts of a resolved cart. Codes that do not apply are kept in
	 * `rejected` with the reason. Automatic coupons (ADR-026) come after the
	 * codes and drop out without a message when they do not fit; an
	 * individual-use code keeps them out, and an individual-use automatic
	 * coupon only applies alone. `next` is the automatic coupon closest to
	 * applying when only its minimum spend is missing, else the buy X get Y
	 * or bundle one that needs the fewest more units (ADR-027, ADR-045). A code whose conditions
	 * wait on a checkout choice is kept in `waiting`; an automatic coupon
	 * applies only once its conditions are met (ADR-041).
	 *
	 * @param list<string>   $codes normalized, in the order added
	 * @param list<CartItem> $items
	 */
	public function discounts( array $codes, array $items, Money $subtotal, int $user_id, ?ConditionFacts $facts = null ): Discounts {
		$found     = $this->coupons->by_codes( $codes );
		$accepted  = [];
		$rejected  = [];
		$waiting   = [];
		$exclusive = false;
		foreach ( $codes as $code ) {
			$coupon = $found[ $code ] ?? null;
			if ( $coupon?->automatic === true ) {
				// Turned automatic while in the cart: the pass below applies it.
				continue;
			}
			if ( $coupon === null ) {
				$rejected[ $code ] = self::unknown( $code );
				continue;
			}
			$error = $this->check( $coupon, $items, $subtotal, $user_id );
			if ( $error === null ) {
				$unmet = $this->unmet( $coupon, $facts, $user_id );
				if ( $unmet !== null && $unmet[1] ) {
					$waiting[ $code ] = $unmet[0];
					continue;
				}
				$error = $unmet[0] ?? null;
			}
			if ( $error === null && ( $exclusive || ( $coupon->individual_use && $accepted !== [] ) ) ) {
				/* translators: %s: coupon code. */
				$error = sprintf( __( 'Coupon "%s" cannot be used with other coupons.', 'flexacommerce' ), $coupon->code );
			}
			if ( $error !== null ) {
				$rejected[ $code ] = $error;
				continue;
			}
			$accepted[] = $coupon;
			$exclusive  = $coupon->individual_use;
		}
		$next  = null;
		$short = null;
		foreach ( $exclusive ? [] : $this->coupons->automatic() as $coupon ) {
			if ( ( $coupon->individual_use && $accepted !== [] ) || $this->unmet( $coupon, $facts, $user_id ) !== null ) {
				continue;
			}
			if ( $this->check( $coupon, $items, $subtotal, $user_id ) === null ) {
				$accepted[] = $coupon;
				if ( $coupon->individual_use ) {
					break;
				}
				continue;
			}
			$missing = $coupon->min_subtotal !== null ? $coupon->min_subtotal - $subtotal->amount : 0;
			if ( $missing > 0 && ( $next === null || $missing < $next['remaining'] )
				&& $this->check( $coupon, $items, new Money( $coupon->min_subtotal ?? 0, $subtotal->currency ), $user_id ) === null ) {
				$next = [
					'coupon'    => $coupon,
					'remaining' => $missing,
					'items'     => 0,
				];
				continue;
			}
			if ( $coupon->set_size() > 0 && $this->check( $coupon, $items, $subtotal, $user_id, false ) === null ) {
				$units = $this->short( $coupon, $items );
				if ( $units > 0 && ( $short === null || $units < $short['items'] ) ) {
					$short = [
						'coupon'    => $coupon,
						'remaining' => 0,
						'items'     => $units,
					];
				}
			}
		}
		return new Discounts( $subtotal->currency, $this->calculate( $accepted, $items ), $rejected, $next ?? $short, $waiting );
	}

	/**
	 * The code list after adding `$code` to a cart with `$current`. An
	 * individual-use coupon replaces the other codes. A code whose
	 * conditions wait on a checkout choice is taken.
	 *
	 * @param list<string>   $current
	 * @param list<CartItem> $items
	 * @return list<string>
	 * @throws InvalidCartRequest the code does not apply; the message says why
	 */
	public function accept( array $current, string $code, array $items, Money $subtotal, int $user_id, ?ConditionFacts $facts = null ): array {
		$code = Coupon::normalize( $code );
		if ( $code === '' ) {
			throw new InvalidCartRequest( __( 'Enter a coupon code.', 'flexacommerce' ), 'code', 'coupon_empty' );
		}
		if ( in_array( $code, $current, true ) ) {
			/* translators: %s: coupon code. */
			throw new InvalidCartRequest( sprintf( __( 'Coupon "%s" is already applied.', 'flexacommerce' ), $code ), 'code', 'coupon_applied' );
		}
		$found  = $this->coupons->by_codes( [ ...$current, $code ] );
		$coupon = $found[ $code ] ?? null;
		if ( $coupon?->automatic === true ) {
			// Its name is not a code a shopper can type.
			$coupon = null;
		}
		$error = $coupon !== null ? $this->check( $coupon, $items, $subtotal, $user_id ) : self::unknown( $code );
		if ( $coupon !== null && $error === null ) {
			$unmet = $this->unmet( $coupon, $facts, $user_id );
			$error = $unmet !== null && ! $unmet[1] ? $unmet[0] : null;
		}
		if ( $coupon === null || $error !== null ) {
			throw new InvalidCartRequest( $error ?? self::unknown( $code ), 'code', 'coupon_invalid' );
		}
		if ( $coupon->individual_use ) {
			return [ $code ];
		}
		foreach ( $current as $other ) {
			if ( ( $found[ $other ] ?? null )?->individual_use === true ) {
				throw new InvalidCartRequest(
					/* translators: %s: coupon code already in the cart. */
					sprintf( __( 'Coupon "%s" cannot be used with other coupons. Remove it first.', 'flexacommerce' ), $found[ $other ]->code ),
					'code',
					'coupon_invalid'
				);
			}
		}
		if ( count( $current ) >= self::MAX_CODES ) {
			throw new InvalidCartRequest( __( 'No more coupons can be added to this cart.', 'flexacommerce' ), 'code', 'coupon_limit' );
		}
		return [ ...$current, $code ];
	}

	/**
	 * Checks that need the billing email, run when the order is placed.
	 *
	 * @return array<string,string> field => message, empty when all coupons may be used
	 */
	public function check_customer( Discounts $discounts, int $user_id, string $billing_email, string $account_email = '' ): array {
		foreach ( $discounts->rejected as $message ) {
			return [ 'coupon' => $message ];
		}
		foreach ( $discounts->applied as $applied ) {
			$coupon = $applied->coupon;
			if ( ! $coupon->allows_email( $billing_email ) && ( $account_email === '' || ! $coupon->allows_email( $account_email ) ) ) {
				/* translators: %s: coupon code. */
				return [ 'coupon' => sprintf( __( 'Coupon "%s" is not valid for your email address.', 'flexacommerce' ), $coupon->code ) ];
			}
			if ( $coupon->per_customer_limit > 0 && $this->coupons->uses_by( $coupon->id, $user_id, $billing_email ) >= $coupon->per_customer_limit ) {
				return [ 'coupon' => self::used_up_by_customer( $coupon ) ];
			}
		}
		return [];
	}

	/**
	 * Takes one use of each coupon for a new order. Runs inside the checkout
	 * transaction: the usage UPDATE locks the coupon row, so the counts read
	 * after it are exact and a refusal rolls the order back.
	 *
	 * @throws InvalidCheckout a coupon ran out between the quote and the order
	 */
	public function redeem( Discounts $discounts, int $order_id, int $user_id, string $email ): void {
		$now = $this->clock->db_now();
		foreach ( $discounts->applied as $applied ) {
			$coupon = $applied->coupon;
			if ( ! $this->coupons->take_use( $coupon->id ) ) {
				throw new InvalidCheckout( [ 'coupon' => self::used_up( $coupon ) ] );
			}
			if ( $coupon->per_customer_limit > 0 && $this->coupons->uses_by( $coupon->id, $user_id, $email ) >= $coupon->per_customer_limit ) {
				throw new InvalidCheckout( [ 'coupon' => self::used_up_by_customer( $coupon ) ] );
			}
			$this->coupons->record_use( $coupon->id, $order_id, $user_id, $email, $now );
		}
	}

	/**
	 * Gives back the uses of a cancelled order.
	 *
	 * @return list<int> the coupon ids released
	 */
	public function release( int $order_id ): array {
		return $this->coupons->release_order( $order_id );
	}

	/**
	 * Sequential discounts: percent of what is left of each eligible line,
	 * a fixed amount per unit capped at what is left, or a fixed amount for
	 * the cart split over the eligible lines by what is left of them. An
	 * item limit counts units from the dearest line down, as WooCommerce
	 * does; a line only partly counted is discounted on its share of what
	 * is left. Buy X get Y takes its percentage off the units sets() picks,
	 * on their share of what is left. A bundle prices each set bundles()
	 * forms on what is left of its units, and splits the discount over the
	 * lines by their share of the sets.
	 *
	 * @param list<Coupon>   $coupons
	 * @param list<CartItem> $items
	 * @return list<AppliedCoupon>
	 */
	private function calculate( array $coupons, array $items ): array {
		$left              = [];
		$subtotal_currency = '';
		foreach ( $items as $item ) {
			if ( self::priced( $item ) && $item->total !== null ) {
				$left[ $item->line->key ] = $item->total->amount;
				$subtotal_currency        = $item->total->currency;
			}
		}
		$applied = [];
		foreach ( $coupons as $coupon ) {
			$eligible = $this->eligible( $coupon, $items );
			$lines    = [];
			if ( $coupon->type === Coupon::BUY_X_GET_Y ) {
				foreach ( $this->sets( $coupon, $items ) as $key => $units ) {
					$quantity      = $this->quantity( $items, $key );
					$base          = $units === $quantity ? $left[ $key ] : IntMath::mul_div_half_up( $left[ $key ], $units, $quantity );
					$lines[ $key ] = IntMath::mul_div_half_up( $base, min( $coupon->amount, Coupon::FULL_PERCENT ), Coupon::FULL_PERCENT );
				}
			} elseif ( $coupon->type === Coupon::BUNDLE ) {
				$lines = $this->bundle_discount( $coupon, $items, $left, $subtotal_currency );
			} elseif ( $coupon->type === Coupon::FIXED_CART ) {
				$base = 0;
				foreach ( $eligible as $item ) {
					$base += $left[ $item->line->key ];
				}
				$amount = min( $coupon->amount, $base );
				if ( $amount > 0 ) {
					$ratios = array_map( static fn ( CartItem $item ): int => $left[ $item->line->key ], $eligible );
					$parts  = ( new Money( $amount, $subtotal_currency ) )->allocate( $ratios );
					foreach ( $eligible as $index => $item ) {
						$lines[ $item->line->key ] = $parts[ $index ]->amount;
					}
				}
			} else {
				$units = $coupon->item_limit > 0 ? $coupon->item_limit : PHP_INT_MAX;
				foreach ( $coupon->item_limit > 0 ? self::by_unit_price( $eligible ) : $eligible as $item ) {
					$key      = $item->line->key;
					$quantity = $item->line->quantity;
					$apply    = min( $quantity, $units );
					if ( $apply <= 0 ) {
						break;
					}
					$units        -= $apply;
					$base          = $apply === $quantity ? $left[ $key ] : IntMath::mul_div_half_up( $left[ $key ], $apply, $quantity );
					$lines[ $key ] = $coupon->type === Coupon::PERCENT
						? IntMath::mul_div_half_up( $base, min( $coupon->amount, Coupon::FULL_PERCENT ), Coupon::FULL_PERCENT )
						: min( $base, $coupon->amount * $apply );
				}
			}
			foreach ( $lines as $key => $discount ) {
				$left[ $key ] -= $discount;
			}
			$applied[] = new AppliedCoupon( $coupon, array_filter( $lines, static fn ( int $d ): bool => $d > 0 ) );
		}
		return $applied;
	}

	/**
	 * Why each automatic coupon did not apply to the cart, for the rule
	 * simulator (ADR-046), with the tests discounts() runs: coupon id =>
	 * the message, in the order the coupons are tried. Applied ones are not
	 * listed; their amounts are in `$discounts`.
	 *
	 * @param list<CartItem> $items
	 * @return array<int,string>
	 */
	public function skipped( Discounts $discounts, array $items, Money $subtotal, int $user_id, ?ConditionFacts $facts = null ): array {
		$exclusive = false;
		foreach ( $discounts->applied as $applied ) {
			$exclusive = $exclusive || ( ! $applied->coupon->automatic && $applied->coupon->individual_use );
		}
		$out = [];
		foreach ( $this->coupons->automatic() as $coupon ) {
			if ( $discounts->find( $coupon->code ) !== null ) {
				continue;
			}
			$unmet              = $this->unmet( $coupon, $facts, $user_id );
			$out[ $coupon->id ] = match ( true ) {
				$exclusive                                            => __( 'A coupon in the cart cannot be used with other coupons.', 'flexacommerce' ),
				$coupon->individual_use && $discounts->applied !== [] => sprintf(
					/* translators: %s: coupon code. */
					__( 'Coupon "%s" cannot be used with other coupons.', 'flexacommerce' ),
					$coupon->code
				),
				$unmet !== null                                       => $unmet[0],
				default                                               => $this->check( $coupon, $items, $subtotal, $user_id ) ?? __( 'An automatic discount before it cannot be used with other coupons.', 'flexacommerce' ),
			};
		}
		return $out;
	}

	/**
	 * Why a coupon does not apply to the cart, or null when it does. The
	 * email rules wait for the billing email at checkout. Without $sets a
	 * buy X get Y coupon passes when the cart has none of its sets yet.
	 *
	 * @param list<CartItem> $items
	 */
	private function check( Coupon $coupon, array $items, Money $subtotal, int $user_id, bool $sets = true ): ?string {
		$now = $this->clock->db_now();
		if ( $coupon->status !== Coupon::ACTIVE ) {
			return self::unknown( $coupon->code );
		}
		if ( $coupon->starts_at !== null && $coupon->starts_at > $now ) {
			/* translators: %s: coupon code. */
			return sprintf( __( 'Coupon "%s" cannot be used yet.', 'flexacommerce' ), $coupon->code );
		}
		if ( $coupon->expires_at !== null && $coupon->expires_at <= $now ) {
			/* translators: %s: coupon code. */
			return sprintf( __( 'Coupon "%s" has expired.', 'flexacommerce' ), $coupon->code );
		}
		if ( $coupon->min_subtotal !== null && $subtotal->amount < $coupon->min_subtotal ) {
			return sprintf(
				/* translators: 1: coupon code, 2: amount. */
				__( 'The minimum spend for coupon "%1$s" is %2$s.', 'flexacommerce' ),
				$coupon->code,
				$this->pricing->format( new Money( $coupon->min_subtotal, $subtotal->currency ) )
			);
		}
		if ( $coupon->max_subtotal !== null && $subtotal->amount > $coupon->max_subtotal ) {
			return sprintf(
				/* translators: 1: coupon code, 2: amount. */
				__( 'The maximum spend for coupon "%1$s" is %2$s.', 'flexacommerce' ),
				$coupon->code,
				$this->pricing->format( new Money( $coupon->max_subtotal, $subtotal->currency ) )
			);
		}
		if ( $coupon->usage_limit > 0 && $coupon->usage_count >= $coupon->usage_limit ) {
			return self::used_up( $coupon );
		}
		if ( $coupon->per_customer_limit > 0 && $user_id > 0 && $this->coupons->uses_by( $coupon->id, $user_id, '' ) >= $coupon->per_customer_limit ) {
			return self::used_up_by_customer( $coupon );
		}
		if ( $this->eligible( $coupon, $items ) === [] ) {
			/* translators: %s: coupon code. */
			return sprintf( __( 'Coupon "%s" does not apply to the items in your cart.', 'flexacommerce' ), $coupon->code );
		}
		if ( $sets && $coupon->type === Coupon::BUY_X_GET_Y && $this->sets( $coupon, $items ) === [] ) {
			return sprintf(
				/* translators: 1: coupon code, 2: units to buy, 3: units discounted. */
				__( 'Coupon "%1$s" is a buy %2$d, get %3$d offer. Add the items it covers to your cart to use it.', 'flexacommerce' ),
				$coupon->code,
				$coupon->buy_qty,
				$coupon->get_qty
			);
		}
		if ( $sets && $coupon->type === Coupon::BUNDLE && $this->bundles( $coupon, $items ) === [] ) {
			/* translators: %s: coupon code. */
			return sprintf( __( 'Coupon "%s" is a bundle offer. Add every product in the bundle to your cart to use it.', 'flexacommerce' ), $coupon->code );
		}
		return null;
	}

	/**
	 * Why the coupon's conditions (ADR-041) are not met, or null when they
	 * are. The flag is set when they wait on a fact not known yet.
	 *
	 * @return array{0:string,1:bool}|null
	 */
	private function unmet( Coupon $coupon, ?ConditionFacts $facts, int $user_id ): ?array {
		if ( Conditions::is_empty( $coupon->conditions ) ) {
			return null;
		}
		$facts ??= new ConditionFacts( user_id: $user_id );
		$outcome = Conditions::outcome( $coupon->conditions, $facts );
		if ( $outcome['pass'] === true ) {
			return null;
		}
		$item = $outcome['item'] ?? [];
		$type = (string) ( $item['type'] ?? '' );
		if ( $outcome['pass'] === null ) {
			$message = match ( $type ) {
				/* translators: %s: coupon code. */
				Conditions::COUNTRY        => __( 'Coupon "%s" applies once you enter your address.', 'flexacommerce' ),
				/* translators: %s: coupon code. */
				Conditions::PAYMENT_METHOD => __( 'Coupon "%s" applies once you choose a payment method.', 'flexacommerce' ),
				/* translators: %s: coupon code. */
				default                    => __( 'Coupon "%s" applies once you choose a shipping method.', 'flexacommerce' ),
			};
			return [ sprintf( $message, $coupon->code ), true ];
		}
		return [ $this->condition_message( $coupon, $item, $facts, ( $coupon->conditions['match'] ?? '' ) === Conditions::ANY ), false ];
	}

	/** @param array<string,mixed> $item the condition not met */
	private function condition_message( Coupon $coupon, array $item, ConditionFacts $facts, bool $any ): string {
		$code   = $coupon->code;
		$type   = (string) ( $item['type'] ?? '' );
		$most   = ( $item['op'] ?? '' ) === Conditions::AT_MOST;
		$not    = ( $item['op'] ?? '' ) === Conditions::NOT_IN;
		$number = is_int( $item['value'] ?? null ) ? $item['value'] : 0;
		$list   = is_array( $item['value'] ?? null ) ? array_map( 'strval', $item['value'] ) : [];
		if ( $any ) {
			/* translators: %s: coupon code. */
			return sprintf( __( 'Your order does not meet the conditions of coupon "%s".', 'flexacommerce' ), $code );
		}
		if ( $facts->user_id <= 0 && in_array( $type, Conditions::HISTORY, true ) && ( $type === Conditions::PURCHASED ? ! $not : ! $most && $number > 0 ) ) {
			/* translators: %s: coupon code. */
			return sprintf( __( 'Log in to use coupon "%s".', 'flexacommerce' ), $code );
		}
		switch ( $type ) {
			case Conditions::CART_QUANTITY:
				return $most
					/* translators: 1: coupon code, 2: number of items. */
					? sprintf( _n( 'Coupon "%1$s" allows at most %2$d item in the cart.', 'Coupon "%1$s" allows at most %2$d items in the cart.', $number, 'flexacommerce' ), $code, $number )
					/* translators: 1: coupon code, 2: number of items. */
					: sprintf( _n( 'Coupon "%1$s" needs at least %2$d item in the cart.', 'Coupon "%1$s" needs at least %2$d items in the cart.', $number, 'flexacommerce' ), $code, $number );
			case Conditions::CART_WEIGHT:
				$weight = round( Units::weight( $number ), 3 ) . ' ' . Settings::string( 'weight_unit' );
				return $most
					/* translators: 1: coupon code, 2: a weight with its unit. */
					? sprintf( __( 'Coupon "%1$s" allows a cart weight of at most %2$s.', 'flexacommerce' ), $code, $weight )
					/* translators: 1: coupon code, 2: a weight with its unit. */
					: sprintf( __( 'Coupon "%1$s" needs a cart weight of at least %2$s.', 'flexacommerce' ), $code, $weight );
			case Conditions::COUNTRY:
				/* translators: %s: coupon code. */
				return sprintf( __( 'Coupon "%s" is not available in your country.', 'flexacommerce' ), $code );
			case Conditions::PAYMENT_METHOD:
				/* translators: %s: coupon code. */
				return sprintf( __( 'Coupon "%s" is not available with this payment method.', 'flexacommerce' ), $code );
			case Conditions::SHIPPING_METHOD:
				/* translators: %s: coupon code. */
				return sprintf( __( 'Coupon "%s" is not available with this shipping method.', 'flexacommerce' ), $code );
			case Conditions::COUPON:
				return $not
					/* translators: 1: coupon code, 2: other coupon codes. */
					? sprintf( __( 'Coupon "%1$s" cannot be used with %2$s.', 'flexacommerce' ), $code, implode( ', ', array_intersect( $list, $facts->coupons ) ) )
					/* translators: 1: coupon code, 2: other coupon codes. */
					: sprintf( __( 'Coupon "%1$s" works only together with %2$s.', 'flexacommerce' ), $code, implode( ', ', $list ) );
			case Conditions::LIFETIME_SPEND:
				$spend = $this->pricing->format( $this->pricing->money( $number ) );
				return $most
					/* translators: 1: coupon code, 2: an amount of money. */
					? sprintf( __( 'Coupon "%1$s" is for customers who have spent at most %2$s.', 'flexacommerce' ), $code, $spend )
					/* translators: 1: coupon code, 2: an amount of money. */
					: sprintf( __( 'Coupon "%1$s" is for customers who have spent at least %2$s.', 'flexacommerce' ), $code, $spend );
			case Conditions::ORDER_COUNT:
				if ( $most && $number === 0 ) {
					/* translators: %s: coupon code. */
					return sprintf( __( 'Coupon "%s" is for a first order only.', 'flexacommerce' ), $code );
				}
				return $most
					/* translators: 1: coupon code, 2: number of orders. */
					? sprintf( _n( 'Coupon "%1$s" is for customers with at most %2$d order.', 'Coupon "%1$s" is for customers with at most %2$d orders.', $number, 'flexacommerce' ), $code, $number )
					/* translators: 1: coupon code, 2: number of orders. */
					: sprintf( _n( 'Coupon "%1$s" is for customers with at least %2$d order.', 'Coupon "%1$s" is for customers with at least %2$d orders.', $number, 'flexacommerce' ), $code, $number );
			default:
				return $not
					/* translators: %s: coupon code. */
					? sprintf( __( 'Coupon "%s" is not for customers who bought certain products before.', 'flexacommerce' ), $code )
					/* translators: %s: coupon code. */
					: sprintf( __( 'Coupon "%s" is for customers who bought certain products before.', 'flexacommerce' ), $code );
		}
	}

	/**
	 * Lines the coupon may discount. Product rules match the product or the
	 * variation; category rules match the product's categories and their
	 * parents. Category terms are read only when a rule needs them.
	 *
	 * Lines the coupon applies to. With $get, the lines a buy X get Y coupon
	 * may discount: its get rules in place of the product and category
	 * rules (the same lines when it has none), with the same exclusions.
	 *
	 * @param list<CartItem> $items
	 * @return list<CartItem>
	 */
	private function eligible( Coupon $coupon, array $items, bool $get = false ): array {
		$get           = $get && $coupon->has_get_rules();
		$product_ids   = $get ? $coupon->get_product_ids : $coupon->product_ids;
		$variant_ids   = $get ? $coupon->get_variant_ids : $coupon->variant_ids;
		$category_ids  = $get ? $coupon->get_category_ids : $coupon->category_ids;
		$product_rules = $product_ids !== [] || $variant_ids !== [];
		$priced        = array_values( array_filter( $items, [ self::class, 'priced' ] ) );
		if ( $coupon->has_category_rules() && $priced !== [] ) {
			update_object_term_cache( array_values( array_unique( array_map( static fn ( CartItem $i ): int => $i->line->product_id, $priced ) ) ), PostTypes::PRODUCT );
		}
		$out = [];
		foreach ( $priced as $item ) {
			$product = $item->line->product_id;
			$variant = $item->line->variant_id;
			if ( $product_rules && ! in_array( $product, $product_ids, true ) && ! in_array( $variant, $variant_ids, true ) ) {
				continue;
			}
			if ( in_array( $product, $coupon->exclude_product_ids, true ) || in_array( $variant, $coupon->exclude_variant_ids, true ) ) {
				continue;
			}
			if ( $coupon->exclude_sale_items && $item->unit?->is_reduced() === true ) {
				continue;
			}
			if ( $coupon->has_category_rules() ) {
				$terms = self::categories( $item->line->product_id );
				if ( $category_ids !== [] && array_intersect( $terms, $category_ids ) === [] ) {
					continue;
				}
				if ( array_intersect( $terms, $coupon->exclude_category_ids ) !== [] ) {
					continue;
				}
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * The units a buy X get Y coupon discounts, by line key. Each set takes
	 * the cheapest units it may discount, then the dearest units left that
	 * count as bought; sets are formed until one cannot be, or the repeat
	 * limit is reached. With the same lines on both sides this discounts
	 * the cheapest Y of every X + Y units.
	 *
	 * @param list<CartItem> $items
	 * @return array<string,int>
	 */
	private function sets( Coupon $coupon, array $items ): array {
		if ( $coupon->buy_qty < 1 || $coupon->get_qty < 1 ) {
			return [];
		}
		$buy  = self::by_unit_price( $this->eligible( $coupon, $items ) );
		$get  = self::by_unit_price( $this->eligible( $coupon, $items, true ), true );
		$free = array_fill_keys( array_map( static fn ( CartItem $i ): string => $i->line->key, [ ...$buy, ...$get ] ), 0 );
		$open = [];
		foreach ( [ ...$buy, ...$get ] as $item ) {
			$open[ $item->line->key ] = $item->line->quantity;
		}
		$sets = 0;
		while ( $coupon->repeat_limit === 0 || $sets < $coupon->repeat_limit ) {
			$taken = $open;
			$given = self::take( $get, $taken, $coupon->get_qty );
			if ( $given === null || self::take( $buy, $taken, $coupon->buy_qty ) === null ) {
				break;
			}
			foreach ( $given as $key => $units ) {
				$free[ $key ] += $units;
			}
			$open = $taken;
			++$sets;
		}
		return array_filter( $free );
	}

	/**
	 * The discount of a bundle coupon by line key. Each set is priced on
	 * what is left of its units: a percentage of it, an amount capped at
	 * it, or what it costs above the set price. The total is split over
	 * the lines by what is left of the units the sets took.
	 *
	 * @param list<CartItem>    $items
	 * @param array<string,int> $left
	 * @return array<string,int>
	 */
	private function bundle_discount( Coupon $coupon, array $items, array $left, string $currency ): array {
		$taken    = [];
		$discount = 0;
		foreach ( $this->bundles( $coupon, $items ) as $set ) {
			$value = 0;
			foreach ( $set as $key => $units ) {
				$value        += IntMath::mul_div_half_up( $left[ $key ], $units, $this->quantity( $items, $key ) );
				$taken[ $key ] = ( $taken[ $key ] ?? 0 ) + $units;
			}
			$discount += match ( $coupon->bundle_mode ) {
				Coupon::BUNDLE_AMOUNT => min( $coupon->amount, $value ),
				Coupon::BUNDLE_PRICE  => max( 0, $value - $coupon->amount ),
				default               => IntMath::mul_div_half_up( $value, min( $coupon->amount, Coupon::FULL_PERCENT ), Coupon::FULL_PERCENT ),
			};
		}
		$bases = [];
		foreach ( $taken as $key => $units ) {
			$quantity      = $this->quantity( $items, $key );
			$bases[ $key ] = $units === $quantity ? $left[ $key ] : IntMath::mul_div_half_up( $left[ $key ], $units, $quantity );
		}
		$discount = min( $discount, array_sum( $bases ) );
		if ( $discount <= 0 ) {
			return [];
		}
		$parts = ( new Money( $discount, $currency ) )->allocate( array_values( $bases ) );
		$lines = [];
		foreach ( array_keys( $bases ) as $index => $key ) {
			$lines[ $key ] = $parts[ $index ]->amount;
		}
		return $lines;
	}

	/**
	 * The complete sets of a bundle coupon, each the units it takes by line
	 * key: one unit for every product or variation of the bundle, from the
	 * dearest line that has it, variations before whole products so a
	 * named variation is not used up by a product that covers it. Sets are
	 * formed until one cannot be, or the repeat limit is reached.
	 * `$missing` gets the products the first set that could not be formed
	 * lacked.
	 *
	 * @param list<CartItem> $items
	 * @return list<array<string,int>>
	 */
	private function bundles( Coupon $coupon, array $items, int &$missing = 0 ): array {
		$members = [
			...array_map( static fn ( int $id ): array => [ false, $id ], $coupon->variant_ids ),
			...array_map( static fn ( int $id ): array => [ true, $id ], $coupon->product_ids ),
		];
		$lines   = self::by_unit_price( $this->eligible( $coupon, $items ) );
		$open    = [];
		foreach ( $lines as $item ) {
			$open[ $item->line->key ] = $item->line->quantity;
		}
		$sets    = [];
		$missing = 0;
		$limit   = $coupon->repeat_limit > 0 ? $coupon->repeat_limit : PHP_INT_MAX;
		while ( $members !== [] && $limit > 0 ) {
			$taken = $open;
			$set   = [];
			foreach ( $members as [ $product, $id ] ) {
				foreach ( $lines as $item ) {
					$key = $item->line->key;
					if ( $taken[ $key ] > 0 && ( $product ? $item->line->product_id : $item->line->variant_id ) === $id ) {
						--$taken[ $key ];
						$set[ $key ] = ( $set[ $key ] ?? 0 ) + 1;
						continue 2;
					}
				}
				++$missing;
			}
			if ( $missing > 0 ) {
				break;
			}
			$sets[] = $set;
			$open   = $taken;
			--$limit;
		}
		return $sets;
	}

	/**
	 * How many more units the cart needs for the first set of a buy X get Y
	 * or bundle coupon. A bundle lacks the products of the set not in the
	 * cart. For buy X get Y, units on lines that only count as bought or
	 * only may be discounted fill their side; units on lines that count for
	 * both fill the discounted side first, then the bought one.
	 *
	 * @param list<CartItem> $items
	 */
	private function short( Coupon $coupon, array $items ): int {
		if ( $coupon->type === Coupon::BUNDLE ) {
			$missing = 0;
			$this->bundles( $coupon, $items, $missing );
			return $missing;
		}
		$get  = [];
		$both = 0;
		$only = 0;
		foreach ( $this->eligible( $coupon, $items, true ) as $item ) {
			$get[ $item->line->key ] = $item->line->quantity;
		}
		foreach ( $this->eligible( $coupon, $items ) as $item ) {
			if ( isset( $get[ $item->line->key ] ) ) {
				$both += $item->line->quantity;
				unset( $get[ $item->line->key ] );
			} else {
				$only += $item->line->quantity;
			}
		}
		$get_left = max( 0, $coupon->get_qty - array_sum( $get ) );
		$shared   = min( $both, $get_left );
		return $get_left - $shared + max( 0, $coupon->buy_qty - $only - ( $both - $shared ) );
	}

	/**
	 * Takes $need units from the lines in order, out of what is open on
	 * each; null when there are not enough.
	 *
	 * @param list<CartItem>    $lines
	 * @param array<string,int> $open
	 * @return array<string,int>|null units taken by line key
	 */
	private static function take( array $lines, array &$open, int $need ): ?array {
		$taken = [];
		foreach ( $lines as $item ) {
			$key   = $item->line->key;
			$units = min( $open[ $key ], $need );
			if ( $units > 0 ) {
				$open[ $key ] -= $units;
				$taken[ $key ] = $units;
				$need         -= $units;
			}
			if ( $need === 0 ) {
				return $taken;
			}
		}
		return null;
	}

	/** @param list<CartItem> $items */
	private function quantity( array $items, string $key ): int {
		foreach ( $items as $item ) {
			if ( $item->line->key === $key ) {
				return $item->line->quantity;
			}
		}
		return 1;
	}

	/**
	 * Lines by unit price before discounts, dearest first (or cheapest
	 * first); equal prices keep the cart order.
	 *
	 * @param list<CartItem> $items
	 * @return list<CartItem>
	 */
	private static function by_unit_price( array $items, bool $cheapest_first = false ): array {
		$sign = $cheapest_first ? -1 : 1;
		usort(
			$items,
			// Cross-multiplied so the unit prices are compared without rounding.
			static fn ( CartItem $a, CartItem $b ): int => $sign * ( ( (int) $b->total?->amount * $a->line->quantity ) <=> ( (int) $a->total?->amount * $b->line->quantity ) )
		);
		return $items;
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

	private static function priced( CartItem $item ): bool {
		return $item->unit !== null && $item->total !== null && $item->product !== null && $item->variant !== null;
	}

	private static function unknown( string $code ): string {
		/* translators: %s: coupon code. */
		return sprintf( __( 'Coupon "%s" does not exist.', 'flexacommerce' ), $code );
	}

	private static function used_up( Coupon $coupon ): string {
		/* translators: %s: coupon code. */
		return sprintf( __( 'Coupon "%s" has reached its usage limit.', 'flexacommerce' ), $coupon->code );
	}

	private static function used_up_by_customer( Coupon $coupon ): string {
		/* translators: %s: coupon code. */
		return sprintf( __( 'You have already used coupon "%s" as often as it allows.', 'flexacommerce' ), $coupon->code );
	}
}
