<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

use Flexa\Commerce\Contracts\Orders\CustomerHistory;

defined( 'ABSPATH' ) || exit;

/**
 * What the conditions of a rule or a coupon are checked against
 * (ADR-041). A null country, payment method or shipping rate is not known
 * yet: the cart has no address, or checkout has not chosen it. On a
 * product page there is no cart, so the quantity and weight are 0.
 */
final class ConditionFacts {
	/** @var array<string,int|bool> */
	private array $memo = [];

	private ?CustomerHistory $orders = null;

	/**
	 * @param list<string>                   $coupons normalized codes in the cart
	 * @param ?\Closure():CustomerHistory    $history resolved only when a condition reads past orders
	 * @param bool                           $final   checkout is placing the order: what is still unknown counts as not met
	 */
	public function __construct(
		public readonly int $quantity = 0,
		public readonly int $weight_g = 0,
		public readonly ?string $country = null,
		public readonly ?string $payment_method = null,
		public readonly ?string $shipping_rate = null,
		public readonly array $coupons = [],
		public readonly int $user_id = 0,
		private readonly ?\Closure $history = null,
		public readonly bool $final = false,
	) {}

	/**
	 * A shopper on a product page, with no cart.
	 *
	 * @param ?\Closure():CustomerHistory $history
	 */
	public static function shopper( int $user_id, ?\Closure $history ): self {
		return new self( user_id: $user_id, history: $history );
	}

	public function order_count(): int {
		return (int) ( $this->memo['orders'] ??= ( $this->user_id > 0 ? (int) $this->history()?->order_count( $this->user_id ) : 0 ) );
	}

	public function spent(): int {
		return (int) ( $this->memo['spent'] ??= ( $this->user_id > 0 ? (int) $this->history()?->spent( $this->user_id ) : 0 ) );
	}

	/** @param list<int> $product_ids */
	public function bought( array $product_ids ): bool {
		$key = 'bought:' . implode( ',', $product_ids );
		return (bool) ( $this->memo[ $key ] ??= $this->user_id > 0 && $product_ids !== [] && $this->history()?->bought( $this->user_id, $product_ids ) === true );
	}

	private function history(): ?CustomerHistory {
		if ( $this->orders === null && $this->history !== null ) {
			$this->orders = ( $this->history )();
		}
		return $this->orders;
	}

	/** A key for memoising prices: the facts that change what a rule gives. */
	public function key(): string {
		return implode( '|', [ $this->quantity, $this->weight_g, (string) $this->country, (string) $this->payment_method, (string) $this->shipping_rate, implode( ',', $this->coupons ), $this->final ? 1 : 0 ] );
	}
}
