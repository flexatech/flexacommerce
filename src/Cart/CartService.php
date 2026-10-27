<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

use Flexa\Commerce\Catalog\Combination;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\ProductRepository;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Catalog\VariantMatcher;
use Flexa\Commerce\Contracts\Exceptions\InsufficientStock;
use Flexa\Commerce\Contracts\Exceptions\InvalidCartRequest;
use Flexa\Commerce\Contracts\Exceptions\NotPurchasable;
use Flexa\Commerce\Contracts\Exceptions\RateLimited;
use Flexa\Commerce\Contracts\Money;
use Flexa\Commerce\Contracts\Pricing\PriceResolution;
use Flexa\Commerce\Contracts\Shipping\Destination;
use Flexa\Commerce\Contracts\Shipping\PackageLine;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Contracts\ValidationResult;
use Flexa\Commerce\Coupons\Coupon;
use Flexa\Commerce\Coupons\CouponRepository;
use Flexa\Commerce\Coupons\CouponService;
use Flexa\Commerce\Inventory\StockService;
use Flexa\Commerce\Options\ProductOptions;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Support\Clock;
use Flexa\Commerce\Support\Currency;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cart reads and writes (doc 02 §7.3). Reads write nothing, except the one
 * write that merges a guest cart into the user's cart on the first touch
 * after login. Writes are versioned: on a conflict the change is applied
 * once more to the fresh row, then the request fails.
 */
final class CartService {
	public const WRITES_PER_MINUTE = 60;

	/**
	 * @param ?\Closure():CouponService  $coupons built only when a cart holds a code
	 * @param ?\Closure():ProductOptions $options built only when the store has option sets
	 */
	public function __construct(
		private readonly SessionStore $sessions,
		private readonly ProductRepository $products,
		private readonly VariantMatcher $matcher,
		private readonly PricingService $pricing,
		private readonly StockService $stock,
		private readonly Clock $clock,
		private readonly ?\Closure $coupons = null,
		private readonly ?\Closure $options = null,
	) {}

	/** The cart without a session lookup when no cookie is present (P11). */
	public function state( int $user_id ): CartState {
		if ( $user_id <= 0 && ! $this->sessions->has_token() ) {
			return $this->resolve( new Cart(), $user_id, false );
		}
		$loaded = $this->sessions->load( $user_id );
		if ( $loaded['guest'] !== null ) {
			return $this->mutate( $user_id, static fn ( Cart $cart ): Cart => $cart, false );
		}
		$cart = $loaded['session'] !== null ? Cart::from_data( $loaded['session']->data ) : new Cart();
		// A logged-in shopper on a new browser has no count cookie yet.
		$this->sessions->publish_count( $cart->item_count(), $user_id );
		return $this->resolve( $cart, $user_id, $loaded['session'] !== null );
	}

	/**
	 * @param array<string,string>   $attributes selection, attribute key => value key
	 * @param array<array-key,mixed> $options    product option values as posted, key => value (ADR-025)
	 * @throws InvalidCartRequest|NotPurchasable|InsufficientStock|RateLimited
	 */
	public function add( int $user_id, int $product_id, int $variant_id, int $quantity, array $attributes = [], array $options = [] ): CartState {
		[ $product, $variant, $line, $max ] = $this->prepare( $product_id, $variant_id, $quantity, $attributes, $options );

		return $this->mutate(
			$user_id,
			function ( Cart $cart ) use ( $product, $variant, $line, $max, $user_id ): Cart {
				$existing = $cart->line( $line->key );
				if ( $existing === null && count( $cart->lines ) >= Cart::MAX_LINES ) {
					throw new InvalidCartRequest( __( 'Your cart is full.', 'flexacommerce' ), 'product_id' );
				}
				if ( $existing !== null && $existing->quantity + $line->quantity > $max ) {
					throw new InvalidCartRequest(
						/* translators: %d: maximum quantity. */
						sprintf( __( 'You can add at most %d of this item.', 'flexacommerce' ), $max ),
						'quantity'
					);
				}
				$this->assert_stock( $cart, $product, $variant, $line->quantity );
				$this->assert_option_stock( $cart, $line->options, ( $existing->quantity ?? 0 ) + $line->quantity, $line->quantity, $line->key, $variant->id );
				$this->validate_add( $cart, $product, $variant, $line->quantity, $user_id );
				return $cart->with_added( $line );
			}
		);
	}

	/**
	 * Changes the variation, options and quantity of the line `$key` (ADR-037).
	 * The line keeps its place; when the new choices match another line, the
	 * two are merged.
	 *
	 * @param array<string,string>   $attributes
	 * @param array<array-key,mixed> $options
	 * @throws InvalidCartRequest|NotPurchasable|InsufficientStock|RateLimited
	 */
	public function replace( int $user_id, string $key, int $product_id, int $variant_id, int $quantity, array $attributes = [], array $options = [] ): CartState {
		[ $product, $variant, $line, $max ] = $this->prepare( $product_id, $variant_id, $quantity, $attributes, $options );

		return $this->mutate(
			$user_id,
			function ( Cart $cart ) use ( $key, $product, $variant, $line, $max, $user_id ): Cart {
				$old = $cart->line( $key );
				if ( $old === null || $old->product_id !== $product->id ) {
					throw new InvalidCartRequest( __( 'This item is no longer in your cart.', 'flexacommerce' ), 'key', 'unknown_line' );
				}
				$rest  = $cart->without( $key );
				$other = $line->key !== $key ? $rest->line( $line->key ) : null;
				if ( $other !== null && $other->quantity + $line->quantity > $max ) {
					throw new InvalidCartRequest(
						/* translators: %d: maximum quantity. */
						sprintf( __( 'You can add at most %d of this item.', 'flexacommerce' ), $max ),
						'quantity'
					);
				}
				$this->assert_stock( $rest, $product, $variant, $line->quantity );
				$this->assert_option_stock( $rest, $line->options, ( $other->quantity ?? 0 ) + $line->quantity, $line->quantity, $line->key, $variant->id );
				$this->validate_add( $rest, $product, $variant, $line->quantity, $user_id );
				// The line was added then; changing it does not make it new.
				$kept = new CartLine( $line->key, $line->product_id, $line->variant_id, $line->quantity, $line->attributes, $line->labels, $old->added_at, $line->options );
				return $cart->with_replaced( $key, $kept );
			}
		);
	}

	/**
	 * Checks a product choice and builds its line.
	 *
	 * @param array<string,string>   $attributes
	 * @param array<array-key,mixed> $options
	 * @return array{0:Product,1:Variant,2:CartLine,3:int}
	 * @throws InvalidCartRequest|NotPurchasable
	 */
	private function prepare( int $product_id, int $variant_id, int $quantity, array $attributes, array $options ): array {
		$product = $this->products->find( $product_id );
		if ( $product === null ) {
			throw new NotPurchasable( $variant_id, __( 'This product does not exist.', 'flexacommerce' ) );
		}
		[ $variant, $selection ] = $this->select( $product, $variant_id, $attributes );
		$this->assert_purchasable( $product, $variant );
		$max = $this->line_max( $product, $variant );
		if ( $quantity < 1 || $quantity > $max ) {
			throw new InvalidCartRequest(
				/* translators: %d: maximum quantity. */
				sprintf( __( 'Choose a quantity between 1 and %d.', 'flexacommerce' ), $max ),
				'quantity'
			);
		}
		$chosen = [
			'values' => [],
			'labels' => [],
		];
		if ( $this->options !== null && ProductOptions::in_use() ) {
			$chosen = ( $this->options )()->accept( $product, $options );
		}
		$line = new CartLine(
			key: CartLine::key_for( $variant->id, $selection, $chosen['values'] ),
			product_id: $product->id,
			variant_id: $variant->id,
			quantity: $quantity,
			attributes: $selection,
			labels: [ ...$this->labels( $product, $selection ), ...$chosen['labels'] ],
			added_at: $this->clock->db_now(),
			options: $chosen['values'],
		);
		return [ $product, $variant, $line, $max ];
	}

	/** @throws InvalidCartRequest|InsufficientStock|RateLimited */
	public function set_quantity( int $user_id, string $key, int $quantity ): CartState {
		return $this->mutate(
			$user_id,
			function ( Cart $cart ) use ( $key, $quantity ): Cart {
				$line = $cart->line( $key );
				if ( $line === null ) {
					throw new InvalidCartRequest( __( 'This item is no longer in your cart.', 'flexacommerce' ), 'key', 'unknown_line' );
				}
				if ( $quantity <= 0 ) {
					return $cart->without( $key );
				}
				$product = $this->products->find( $line->product_id );
				$variant = $product?->sellable( $line->variant_id );
				if ( $product === null || $variant === null ) {
					throw new NotPurchasable( $line->variant_id );
				}
				$max = $this->line_max( $product, $variant );
				if ( $quantity > $max ) {
					throw new InvalidCartRequest(
						/* translators: %d: maximum quantity. */
						sprintf( __( 'Choose a quantity between 1 and %d.', 'flexacommerce' ), $max ),
						'quantity'
					);
				}
				if ( $quantity > $line->quantity ) {
					$this->assert_stock( $cart->without( $key ), $product, $variant, $quantity );
					$this->assert_option_stock( $cart, $line->options, $quantity, $quantity, $key, $variant->id );
				}
				return $cart->with_quantity( $key, $quantity );
			}
		);
	}

	/** @throws InvalidCartRequest|RateLimited */
	public function remove( int $user_id, string $key ): CartState {
		return $this->mutate(
			$user_id,
			static function ( Cart $cart ) use ( $key ): Cart {
				if ( $cart->line( $key ) === null ) {
					throw new InvalidCartRequest( __( 'This item is no longer in your cart.', 'flexacommerce' ), 'key', 'unknown_line' );
				}
				return $cart->without( $key );
			}
		);
	}

	public function clear( int $user_id ): CartState {
		if ( $user_id <= 0 && ! $this->sessions->has_token() ) {
			return $this->resolve( new Cart(), $user_id, false );
		}
		return $this->mutate( $user_id, static fn ( Cart $cart ): Cart => $cart->emptied( $cart->checkout_token ) );
	}

	/**
	 * Adds a coupon code. The coupon is checked against the cart as it is;
	 * an individual-use coupon replaces the codes already there.
	 *
	 * @throws InvalidCartRequest|RateLimited
	 */
	public function apply_coupon( int $user_id, string $code ): CartState {
		$service = $this->coupons();
		return $this->mutate(
			$user_id,
			function ( Cart $cart ) use ( $service, $code, $user_id ): Cart {
				if ( $cart->is_empty() ) {
					throw new InvalidCartRequest( __( 'Add a product to your cart before you apply a coupon.', 'flexacommerce' ), 'code', 'coupon_invalid' );
				}
				$state = $this->resolve( $cart->with_coupons( [] ), $user_id, true );
				return $cart->with_coupons( $service->accept( $cart->coupons, $code, $state->items, $state->subtotal, $user_id ) );
			}
		);
	}

	/** @throws InvalidCartRequest|RateLimited */
	public function remove_coupon( int $user_id, string $code ): CartState {
		$code = Coupon::normalize( $code );
		return $this->mutate(
			$user_id,
			static function ( Cart $cart ) use ( $code ): Cart {
				if ( ! in_array( $code, $cart->coupons, true ) ) {
					throw new InvalidCartRequest( __( 'This coupon is not in your cart.', 'flexacommerce' ), 'code', 'unknown_coupon' );
				}
				return $cart->with_coupons( array_values( array_diff( $cart->coupons, [ $code ] ) ) );
			}
		);
	}

	/**
	 * Remember the address used for quotes and the chosen rate. The rate is
	 * not checked here: checkout checks it against fresh rates.
	 *
	 * @param array{country:string,state:string,postcode:string,city:string} $destination
	 * @throws RateLimited
	 */
	public function set_shipping( int $user_id, array $destination, string $rate_id ): CartState {
		return $this->mutate( $user_id, static fn ( Cart $cart ): Cart => $cart->with_shipping( $destination, $rate_id ) );
	}

	/**
	 * The token the checkout form posts back (doc 02 §7.5). Issued on first
	 * use and stored in the session, so it only exists for a cart that has a
	 * session. Returns '' without one.
	 */
	public function checkout_token( int $user_id, CartState $state ): string {
		if ( ! $state->has_session ) {
			return '';
		}
		if ( $state->cart->checkout_token !== '' ) {
			return $state->cart->checkout_token;
		}
		$token = self::new_token();
		$next  = $this->mutate(
			$user_id,
			static fn ( Cart $cart ): Cart => $cart->checkout_token !== '' ? $cart : $cart->with_checkout_token( $token ),
			false
		);
		return $next->cart->checkout_token;
	}

	/**
	 * After an order: empty the cart and rotate the token. When the cart
	 * already holds another token (a second tab finished first), it is left
	 * alone.
	 */
	public function finish_checkout( int $user_id, string $used_token ): void {
		$this->mutate(
			$user_id,
			static fn ( Cart $cart ): Cart => $cart->checkout_token === $used_token ? $cart->emptied( self::new_token() ) : $cart,
			false
		);
	}

	/** What shipping methods price: the sellable lines of a resolved cart. */
	public function package( CartState $state, Destination $destination ): ShippingPackage {
		$lines = [];
		foreach ( $state->items as $item ) {
			if ( $item->total === null ) {
				continue;
			}
			$lines[] = new PackageLine( $item->line->variant_id, $item->line->quantity, $item->weight_g(), $item->dimensions_mm(), $item->total );
		}
		return new ShippingPackage(
			$destination,
			$lines,
			$state->discounts !== null ? $state->discounted_subtotal() : $state->subtotal,
			$state->discounts?->free_shipping() ?? false,
			$state->discounts !== null ? $state->subtotal : null
		);
	}

	/** Lines of a resolved cart a renderer can show, with display prices. */
	public function format( Money $money ): string {
		return $this->pricing->format( $money );
	}

	private function coupons(): CouponService {
		if ( $this->coupons === null ) {
			throw new \LogicException( 'Coupons are not available.' );
		}
		return ( $this->coupons )();
	}

	private static function new_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	public function line_max_quantity( Product $product, Variant $variant ): int {
		return $this->line_max( $product, $variant );
	}

	/**
	 * @param callable(Cart):Cart $change
	 */
	private function mutate( int $user_id, callable $change, bool $count = true ): CartState {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$loaded = $this->sessions->load( $user_id );
			$cart   = $loaded['session'] !== null ? Cart::from_data( $loaded['session']->data ) : new Cart();
			if ( $loaded['guest'] !== null ) {
				$cart = $this->merge( $cart, Cart::from_data( $loaded['guest']->data ) );
			} else {
				$cart = $cart->with_notices( [] );
			}
			if ( $count ) {
				$cart = $this->count_write( $cart );
			}
			$next   = $change( $cart );
			$stored = $this->sessions->save( $loaded['session'], $next->to_data(), $user_id, $loaded['guest'] );
			if ( $stored !== null ) {
				$this->sessions->publish_count( $next->item_count(), $user_id );
				return $this->resolve( $next, $user_id, true );
			}
			$this->sessions->forget();
		}
		throw new InvalidCartRequest( __( 'Your cart was changed in another window. Please try again.', 'flexacommerce' ), '', 'conflict' );
	}

	/** Guest lines into the user cart: quantities summed, capped by stock and the line maximum. */
	private function merge( Cart $user_cart, Cart $guest ): Cart {
		$ids = array_values( array_unique( array_map( static fn ( CartLine $l ): int => $l->product_id, [ ...$user_cart->lines, ...$guest->lines ] ) ) );
		$this->load_products( $ids );

		$cart    = $user_cart;
		$notices = [];
		foreach ( $guest->lines as $line ) {
			$cart = $cart->with_added( $line );
		}
		if ( $guest->coupons !== [] ) {
			$cart = $cart->with_coupons( array_slice( array_values( array_unique( [ ...$user_cart->coupons, ...$guest->coupons ] ) ), 0, CouponService::MAX_CODES ) );
		}
		foreach ( $cart->lines as $line ) {
			$product = $this->products->find( $line->product_id );
			$variant = $product?->sellable( $line->variant_id );
			if ( $product === null || $variant === null ) {
				continue;
			}
			$cap = $this->line_max( $product, $variant );
			$max = $this->stock->availability( $product, $variant )->max_quantity();
			if ( $max !== null ) {
				$cap = min( $cap, max( 1, $max ) );
			}
			if ( $line->quantity > $cap ) {
				$cart      = $cart->with_quantity( $line->key, $cap );
				$notices[] = [
					'code'    => 'quantity_reduced',
					'message' => sprintf(
						/* translators: 1: product name, 2: quantity. */
						__( 'The quantity of %1$s was reduced to %2$d.', 'flexacommerce' ),
						$product->title,
						$cap
					),
				];
			}
		}
		return $cart->with_notices( $notices );
	}

	private function count_write( Cart $cart ): Cart {
		$window = intdiv( $this->clock->now()->getTimestamp(), 60 );
		$count  = $cart->rate['window'] === $window ? $cart->rate['count'] : 0;
		$limit  = (int) apply_filters( 'flexacommerce.cart.writes_per_minute', self::WRITES_PER_MINUTE );
		if ( $limit > 0 && $count >= $limit ) {
			throw new RateLimited( 60 - $this->clock->now()->getTimestamp() % 60 );
		}
		return $cart->with_rate(
			[
				'window' => $window,
				'count'  => $count + 1,
			]
		);
	}

	private function resolve( Cart $cart, int $user_id, bool $has_session ): CartState {
		$this->load_products( array_map( static fn ( CartLine $l ): int => $l->product_id, $cart->lines ) );

		$items   = [];
		$totals  = [];
		$notices = $cart->notices;
		$units   = [];
		foreach ( $cart->lines as $line ) {
			$units[ $line->product_id ] = ( $units[ $line->product_id ] ?? 0 ) + $line->quantity;
		}
		foreach ( $cart->lines as $line ) {
			$product = $this->products->find( $line->product_id );
			$variant = $product?->sellable( $line->variant_id );
			if ( $product === null || $variant === null || ! $this->is_purchasable( $product, $variant ) ) {
				$items[]   = new CartItem( $line, $product, $variant, null, null, null, CartItem::UNAVAILABLE );
				$notices[] = [
					'code'    => CartItem::UNAVAILABLE,
					'message' => sprintf(
						/* translators: %s: product name. */
						__( '%s is no longer available. Remove it to continue.', 'flexacommerce' ),
						$product !== null ? $product->title : __( 'An item', 'flexacommerce' )
					),
				];
				continue;
			}
			$availability = $this->stock->availability( $product, $variant );
			$owner_total  = $this->owner_quantity( $cart, $product, $variant );
			$status       = $availability->allows( $owner_total ) ? CartItem::OK : CartItem::INSUFFICIENT_STOCK;
			$unit         = $this->pricing->unit_price( $product, $variant, $line->quantity, $user_id, product_quantity: $units[ $line->product_id ] );
			$fee          = 0;
			$tracked      = [];
			if ( $unit !== null && $line->options !== [] && $this->options !== null ) {
				$options = ( $this->options )();
				$fee     = $options->fee( $line->options );
				$tracked = $options->tracked( $line->options );
				$unit    = self::with_extra( $unit, $options->extra( $line->options, $unit->active->amount, $line->quantity, Currency::exponent( $unit->active->currency ) ) );
			}
			$total = $unit !== null ? $this->pricing->line_total( $unit, $line->quantity ) : null;
			if ( $total !== null && $fee > 0 ) {
				// An option charged once per line, whatever the quantity (ADR-029).
				$total = $total->add( new Money( $fee, $total->currency ) );
			}
			if ( $total !== null ) {
				$totals[] = $total;
			}
			if ( $status === CartItem::INSUFFICIENT_STOCK ) {
				$left      = (int) $availability->max_quantity();
				$notices[] = [
					'code'    => CartItem::INSUFFICIENT_STOCK,
					'message' => $left > 0
						? sprintf(
							/* translators: 1: product name, 2: quantity in stock. */
							_n( 'Only %2$d of %1$s is in stock. Lower the quantity to continue.', 'Only %2$d of %1$s are in stock. Lower the quantity to continue.', $left, 'flexacommerce' ),
							$product->title,
							$left
						)
						: sprintf(
							/* translators: %s: product name. */
							__( '%s is out of stock. Remove it to continue.', 'flexacommerce' ),
							$product->title
						),
				];
			}
			$items[] = new CartItem( $line, $product, $variant, $unit, $total, $availability, $status, $fee, $tracked );
		}

		$subtotal  = $this->pricing->sum( $totals );
		$discounts = null;
		// With no codes the coupons are still read when an automatic one may fit.
		if ( $items !== [] && $this->coupons !== null && ( $cart->coupons !== [] || CouponRepository::has_automatic() ) ) {
			$discounts = $this->coupons()->discounts( $cart->coupons, $items, $subtotal, $user_id );
			if ( $cart->coupons === [] && $discounts->applied === [] && $discounts->next === null ) {
				$discounts = null;
			}
			foreach ( $discounts->rejected ?? [] as $message ) {
				$notices[] = [
					'code'    => 'coupon_invalid',
					'message' => $message,
				];
			}
		}

		return new CartState( $cart, $items, $subtotal, $notices, $has_session, $discounts );
	}

	/** The unit price with what the product options add to it. */
	private static function with_extra( PriceResolution $unit, int $extra ): PriceResolution {
		if ( $extra <= 0 ) {
			return $unit;
		}
		$add = new Money( $extra, $unit->active->currency );
		return new PriceResolution( $unit->regular->add( $add ), $unit->active->add( $add ), $unit->on_sale );
	}

	/**
	 * Posts and meta in one query each, then all variant rows in one query.
	 * Term caches are not primed: labels are stored on the line.
	 *
	 * @param list<int> $product_ids
	 */
	private function load_products( array $product_ids ): void {
		$product_ids = array_values( array_unique( array_filter( $product_ids ) ) );
		if ( $product_ids === [] ) {
			return;
		}
		_prime_post_caches( $product_ids, false, true );
		$this->products->prime( $product_ids );
	}

	/**
	 * @param array<string,string> $attributes
	 * @return array{0:Variant,1:array<string,string>} the row and the stored selection
	 */
	private function select( Product $product, int $variant_id, array $attributes ): array {
		if ( ! $product->is_variable() ) {
			return [ $product->base, [] ];
		}
		$selection = [];
		foreach ( $product->variation_attributes() as $definition ) {
			$value = $attributes[ $definition->key ] ?? '';
			if ( $value !== '' && ! $definition->allows( $value ) ) {
				throw new InvalidCartRequest( __( 'Choose a valid option.', 'flexacommerce' ), 'attributes', 'invalid_attribute' );
			}
			if ( $value !== '' ) {
				$selection[ $definition->key ] = $value;
			}
		}

		$variant = $variant_id > 0 ? $product->sellable( $variant_id ) : null;
		if ( $variant_id > 0 && $variant === null ) {
			throw new NotPurchasable( $variant_id );
		}
		if ( $variant === null ) {
			$variant = $this->matcher->match( $product->variations, $selection );
			if ( $variant === null ) {
				throw new InvalidCartRequest( __( 'Choose product options before adding to the cart.', 'flexacommerce' ), 'attributes', 'incomplete_selection' );
			}
		}

		// Fixed values come from the row; wildcard values must be chosen.
		$stored = [];
		foreach ( $variant->combination->to_array() as $key => $value ) {
			if ( $value === Combination::WILDCARD ) {
				if ( ! isset( $selection[ $key ] ) ) {
					throw new InvalidCartRequest( __( 'Choose product options before adding to the cart.', 'flexacommerce' ), 'attributes', 'incomplete_selection' );
				}
				$stored[ $key ] = $selection[ $key ];
			} else {
				if ( isset( $selection[ $key ] ) && $selection[ $key ] !== $value ) {
					throw new InvalidCartRequest( __( 'The selected options do not match this variation.', 'flexacommerce' ), 'attributes', 'invalid_attribute' );
				}
				$stored[ $key ] = $value;
			}
		}
		ksort( $stored, SORT_STRING );
		return [ $variant, $stored ];
	}

	/** Published, enabled, priced, the right kind of row; `flexacommerce.product.is_purchasable` may only forbid. */
	public function is_purchasable( Product $product, Variant $variant ): bool {
		$ok = $product->status === 'publish'
			&& $variant->id > 0
			&& $variant->enabled
			&& $variant->regular_price !== null
			&& $variant->is_base === ! $product->is_variable();
		if ( ! $ok || ! has_filter( 'flexacommerce.product.is_purchasable' ) ) {
			return $ok;
		}
		// The filter can only forbid.
		return apply_filters( 'flexacommerce.product.is_purchasable', true, $product->view_of( $variant ) ) === true;
	}

	private function assert_purchasable( Product $product, Variant $variant ): void {
		if ( ! $this->is_purchasable( $product, $variant ) ) {
			throw new NotPurchasable( $variant->id );
		}
	}

	private function assert_stock( Cart $cart, Product $product, Variant $variant, int $adding ): void {
		$wanted = $this->owner_quantity( $cart, $product, $variant ) + $adding;
		$fresh  = $this->stock->available( $product, $variant );
		if ( ! $fresh->allows( $wanted ) ) {
			$left = $fresh->max_quantity();
			throw new InsufficientStock( $variant->id, $left === null ? null : max( 0, $left - ( $wanted - $adding ) ) );
		}
	}

	/**
	 * Checks the option choices of the line `$key` for `$quantity`, `$adding`
	 * of them new, with the other lines of `$cart` that use the same choices
	 * (ADR-040).
	 *
	 * @param array<string,string|list<string>> $values
	 * @throws InsufficientStock
	 */
	private function assert_option_stock( Cart $cart, array $values, int $quantity, int $adding, string $key, int $variant_id ): void {
		if ( $values === [] || $this->options === null ) {
			return;
		}
		$others = [];
		foreach ( $cart->lines as $line ) {
			if ( $line->key !== $key && $line->options !== [] ) {
				$others[] = [ $line->options, $line->quantity ];
			}
		}
		( $this->options )()->assert_stock( $values, $quantity, $adding, $others, $variant_id );
	}

	/** Units in the cart that draw on the same stock row as `$variant`. */
	private function owner_quantity( Cart $cart, Product $product, Variant $variant ): int {
		$owner = $product->stock_owner( $variant )->id;
		$total = 0;
		foreach ( $cart->lines as $line ) {
			if ( $line->product_id !== $product->id ) {
				continue;
			}
			$row = $product->sellable( $line->variant_id );
			if ( $row !== null && $product->stock_owner( $row )->id === $owner ) {
				$total += $line->quantity;
			}
		}
		return $total;
	}

	private function line_max( Product $product, Variant $variant ): int {
		$max = max( 1, Settings::int( 'max_line_quantity' ) );
		if ( has_filter( 'flexacommerce.cart.line_max_quantity' ) ) {
			$filtered = apply_filters( 'flexacommerce.cart.line_max_quantity', $max, $product->view_of( $variant ) );
			$max      = is_int( $filtered ) && $filtered > 0 ? $filtered : $max;
		}
		return $max;
	}

	private function validate_add( Cart $cart, Product $product, Variant $variant, int $quantity, int $user_id ): void {
		if ( ! has_filter( 'flexacommerce.cart.validate_add' ) ) {
			return;
		}
		$state  = $this->resolve( $cart, $user_id, true );
		$result = apply_filters( 'flexacommerce.cart.validate_add', new ValidationResult(), $product->view_of( $variant ), $quantity, $state->view() );
		if ( $result instanceof ValidationResult && ! $result->is_valid() ) {
			throw new InvalidCartRequest( $result->first_message(), 'product_id', 'rejected' );
		}
	}

	/**
	 * Display names of the chosen options, stored with the line.
	 *
	 * @param array<string,string> $selection
	 * @return list<array{name:string,value:string}>
	 */
	private function labels( Product $product, array $selection ): array {
		if ( $selection === [] ) {
			return [];
		}
		$term_ids = [];
		foreach ( $product->variation_attributes() as $definition ) {
			if ( $definition->is_global() && isset( $selection[ $definition->key ] ) ) {
				$term_ids[] = $definition->attribute_term_id;
				$term_ids[] = (int) $selection[ $definition->key ];
			}
		}
		if ( $term_ids !== [] ) {
			_prime_term_caches( array_values( array_unique( $term_ids ) ), false );
		}
		$labels = [];
		foreach ( $product->variation_attributes() as $definition ) {
			$value = $selection[ $definition->key ] ?? null;
			if ( $value === null ) {
				continue;
			}
			if ( $definition->is_global() ) {
				$name_term  = get_term( $definition->attribute_term_id );
				$value_term = get_term( (int) $value );
				$labels[]   = [
					'name'  => $name_term instanceof \WP_Term ? $name_term->name : '',
					'value' => $value_term instanceof \WP_Term ? $value_term->name : $value,
				];
			} else {
				$labels[] = [
					'name'  => $definition->name,
					'value' => $definition->local_label( $value ) ?? $value,
				];
			}
		}
		return $labels;
	}
}
