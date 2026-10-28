<?php

declare(strict_types=1);

namespace Flexa\Commerce\Storefront\Render;

use Flexa\Commerce\Cart\CartPresenter;
use Flexa\Commerce\Cart\CartService;
use Flexa\Commerce\Catalog\Product;
use Flexa\Commerce\Catalog\Variant;
use Flexa\Commerce\Pricing\PricingService;
use Flexa\Commerce\Storefront\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * The pricing blocks and shortcodes (ADR-050): the quantity prices, a
 * discount badge, an offer countdown and the progress to the next
 * automatic discount, for a merchant to place anywhere. They reuse what
 * the product page and the cart show, so the numbers always agree.
 */
final class PricingBlocks {
	/** How far ahead the countdown of a product looks when the merchant placed it. */
	private const PRODUCT_DAYS = 365;

	public function __construct(
		private readonly PricingService $pricing,
		private readonly CartService $cart,
		private readonly CartPresenter $presenter,
	) {}

	/** The tier table, shown whatever the "tier table" setting says. */
	public function quantity_prices( Product $product, int $user_id ): string {
		$table = QuantityTiers::table( $this->pricing, $product, $user_id );
		if ( $table === '' ) {
			return '';
		}
		// The interactive root lets the countdown under the table tick.
		Assets::use_store();
		return sprintf( '<div class="fxc-quantity-prices" data-product="%1$d" data-wp-interactive="flexacommerce/cart">%2$s</div>', $product->id, $table );
	}

	/**
	 * The most a shopper saves on one unit, from sales and pricing rules:
	 * "Save 20%", or "Up to 20% off" when the variations save different
	 * amounts. Empty when nothing is off.
	 */
	public function discount_badge( Product $product, int $user_id ): string {
		$percents = [];
		foreach ( array_filter( $product->sellable_rows(), static fn ( Variant $row ): bool => $row->enabled ) as $row ) {
			$unit = $this->pricing->unit_price( $product, $row, 1, $user_id );
			if ( $unit !== null && $unit->regular->amount > 0 ) {
				$percents[] = max( 0, (int) floor( ( $unit->regular->amount - $unit->active->amount ) * 100 / $unit->regular->amount ) );
			}
		}
		$best = $percents === [] ? 0 : max( $percents );
		if ( $best === 0 ) {
			return '';
		}
		$text = min( $percents ) === $best
			/* translators: %d: percent off the regular price */
			? sprintf( __( 'Save %d%%', 'flexacommerce' ), $best )
			/* translators: %d: the largest percent off the regular price */
			: sprintf( __( 'Up to %d%% off', 'flexacommerce' ), $best );
		return '<p class="fxc-badge fxc-discount-badge">' . esc_html( $text ) . '</p>';
	}

	/**
	 * The time left on one pricing rule, or, with no rule, before the
	 * price of the product goes up. Empty when nothing ends.
	 */
	public function countdown( int $rule_id, ?Product $product, int $user_id ): string {
		$ends = match ( true ) {
			$rule_id > 0       => $this->pricing->rule_ends( $rule_id, $user_id ),
			$product !== null  => $this->pricing->price_ends( $product, $user_id, self::PRODUCT_DAYS ),
			default            => null,
		};
		if ( $ends === null ) {
			return '';
		}
		Assets::use_store();
		return '<div class="fxc-offer-countdown" data-wp-interactive="flexacommerce/cart">' . Countdown::html( $ends ) . '</div>';
	}

	/**
	 * How far the cart is from the next automatic discount. Guests get the
	 * hidden bar, so a page cache can keep the page for everyone; cart.js
	 * reads their cart when the count cookie says it has items. A
	 * logged-in customer's page is not cached and starts from the cart.
	 */
	public function nudge( int $user_id ): string {
		$next = $user_id > 0 ? $this->presenter->next_discount( $this->cart->state( $user_id ) ) : null;
		Assets::use_store();
		wp_interactivity_state(
			'flexacommerce/cart',
			[
				'nudge'      => $next,
				'noNudge'    => static fn (): bool => $next === null,
				'nudgeLabel' => static fn (): string => (string) ( $next['message'] ?? '' ),
				'nudgeWidth' => static fn (): string => (int) ( $next['percent'] ?? 0 ) . '%',
			]
		);
		return sprintf(
			'<div class="fxc-discount-nudge fxc-cart__next" data-wp-interactive="flexacommerce/cart" data-wp-init="callbacks.nudge" data-wp-bind--hidden="state.noNudge"%1$s><p aria-live="polite" data-wp-text="state.nudgeLabel">%2$s</p><div class="fxc-cart__progress" aria-hidden="true"><span data-wp-style--width="state.nudgeWidth" style="width:%3$d%%"></span></div></div>',
			$next === null ? ' hidden' : '',
			esc_html( (string) ( $next['message'] ?? '' ) ),
			(int) ( $next['percent'] ?? 0 )
		);
	}
}
