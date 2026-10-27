<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * The cart as stored in the session `data` column:
 * `{v:1, lines:[...], notices:[...], rate:{window,count}, shipping_rate,
 * destination:{country,state,postcode,city}, checkout_token, coupons?}` (doc
 * 03 §4.9). `coupons` holds normalized codes in the order added and is
 * written only when there is one.
 * Immutable; every change returns a new instance.
 */
final class Cart {
	public const VERSION = 1;

	public const MAX_LINES = 100;

	/**
	 * @param list<CartLine>                       $lines
	 * @param list<array{code:string,message:string}> $notices shown once, then cleared by the next write
	 * @param array{window:int,count:int}          $rate
	 * @param array{country:string,state:string,postcode:string,city:string} $destination last address used for shipping quotes
	 * @param list<string>                         $coupons normalized codes
	 */
	public function __construct(
		public readonly array $lines = [],
		public readonly array $notices = [],
		public readonly array $rate = [
			'window' => 0,
			'count'  => 0,
		],
		public readonly string $shipping_rate = '',
		public readonly array $destination = [
			'country'  => '',
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		],
		public readonly string $checkout_token = '',
		public readonly array $coupons = [],
	) {}

	/** @param array<string,mixed> $data */
	public static function from_data( array $data ): self {
		$lines = [];
		foreach ( is_array( $data['lines'] ?? null ) ? $data['lines'] : [] as $entry ) {
			$line = is_array( $entry ) ? CartLine::from_array( $entry ) : null;
			if ( $line !== null && ! isset( $lines[ $line->key ] ) ) {
				$lines[ $line->key ] = $line;
			}
		}
		$notices = [];
		foreach ( is_array( $data['notices'] ?? null ) ? $data['notices'] : [] as $notice ) {
			if ( is_array( $notice ) && isset( $notice['code'], $notice['message'] ) ) {
				$notices[] = [
					'code'    => (string) $notice['code'],
					'message' => (string) $notice['message'],
				];
			}
		}
		$coupons = [];
		foreach ( is_array( $data['coupons'] ?? null ) ? $data['coupons'] : [] as $code ) {
			if ( is_string( $code ) && $code !== '' && ! in_array( $code, $coupons, true ) ) {
				$coupons[] = $code;
			}
		}
		$rate        = is_array( $data['rate'] ?? null ) ? $data['rate'] : [];
		$destination = is_array( $data['destination'] ?? null ) ? $data['destination'] : [];
		return new self(
			array_values( $lines ),
			$notices,
			[
				'window' => (int) ( $rate['window'] ?? 0 ),
				'count'  => (int) ( $rate['count'] ?? 0 ),
			],
			is_string( $data['shipping_rate'] ?? null ) ? $data['shipping_rate'] : '',
			[
				'country'  => is_string( $destination['country'] ?? null ) ? $destination['country'] : '',
				'state'    => is_string( $destination['state'] ?? null ) ? $destination['state'] : '',
				'postcode' => is_string( $destination['postcode'] ?? null ) ? $destination['postcode'] : '',
				'city'     => is_string( $destination['city'] ?? null ) ? $destination['city'] : '',
			],
			is_string( $data['checkout_token'] ?? null ) ? $data['checkout_token'] : '',
			$coupons
		);
	}

	/** @return array<string,mixed> */
	public function to_data(): array {
		$data = [
			'v'              => self::VERSION,
			'lines'          => array_map( static fn ( CartLine $l ): array => $l->to_array(), $this->lines ),
			'notices'        => $this->notices,
			'rate'           => $this->rate,
			'shipping_rate'  => $this->shipping_rate,
			'destination'    => $this->destination,
			'checkout_token' => $this->checkout_token,
		];
		if ( $this->coupons !== [] ) {
			$data['coupons'] = $this->coupons;
		}
		return $data;
	}

	public function line( string $key ): ?CartLine {
		foreach ( $this->lines as $line ) {
			if ( $line->key === $key ) {
				return $line;
			}
		}
		return null;
	}

	/** Units of one variant across lines (wildcard variations can have several). */
	public function quantity_of( int $variant_id ): int {
		$total = 0;
		foreach ( $this->lines as $line ) {
			if ( $line->variant_id === $variant_id ) {
				$total += $line->quantity;
			}
		}
		return $total;
	}

	public function item_count(): int {
		return array_sum( array_map( static fn ( CartLine $l ): int => $l->quantity, $this->lines ) );
	}

	public function is_empty(): bool {
		return $this->lines === [];
	}

	/** Adds to an existing line with the same key, else appends. */
	public function with_added( CartLine $line ): self {
		$lines = [];
		$found = false;
		foreach ( $this->lines as $existing ) {
			if ( $existing->key === $line->key ) {
				$existing = $existing->with_quantity( $existing->quantity + $line->quantity );
				$found    = true;
			}
			$lines[] = $existing;
		}
		if ( ! $found ) {
			$lines[] = $line;
		}
		return $this->copy( lines: $lines );
	}

	/**
	 * The line `$key` becomes `$line`, in its place. When another line
	 * already has the new key, the two are merged there.
	 */
	public function with_replaced( string $key, CartLine $line ): self {
		$other = $line->key !== $key ? $this->line( $line->key ) : null;
		if ( $other !== null ) {
			return $this->without( $key )->with_added( $line );
		}
		return $this->copy( lines: array_map( static fn ( CartLine $l ): CartLine => $l->key === $key ? $line : $l, $this->lines ) );
	}

	public function with_quantity( string $key, int $quantity ): self {
		if ( $quantity <= 0 ) {
			return $this->without( $key );
		}
		return $this->copy( lines: array_map( static fn ( CartLine $l ): CartLine => $l->key === $key ? $l->with_quantity( $quantity ) : $l, $this->lines ) );
	}

	public function without( string $key ): self {
		return $this->copy( lines: array_values( array_filter( $this->lines, static fn ( CartLine $l ): bool => $l->key !== $key ) ) );
	}

	/** After an order: no lines, no coupons, a new checkout token. The destination is kept for the next quote. */
	public function emptied( string $checkout_token = '' ): self {
		return new self( [], [], $this->rate, '', $this->destination, $checkout_token );
	}

	/** @param list<array{code:string,message:string}> $notices */
	public function with_notices( array $notices ): self {
		return $this->copy( notices: $notices );
	}

	/** @param array{window:int,count:int} $rate */
	public function with_rate( array $rate ): self {
		return $this->copy( rate: $rate );
	}

	/** @param array{country:string,state:string,postcode:string,city:string} $destination */
	public function with_shipping( array $destination, string $rate_id ): self {
		return $this->copy( shipping_rate: $rate_id, destination: $destination );
	}

	public function with_checkout_token( string $token ): self {
		return $this->copy( checkout_token: $token );
	}

	/** @param list<string> $codes */
	public function with_coupons( array $codes ): self {
		return $this->copy( coupons: $codes );
	}

	/**
	 * @param list<CartLine>|null                                     $lines
	 * @param list<array{code:string,message:string}>|null            $notices
	 * @param array{window:int,count:int}|null                        $rate
	 * @param array{country:string,state:string,postcode:string,city:string}|null $destination
	 * @param list<string>|null                                       $coupons
	 */
	private function copy( ?array $lines = null, ?array $notices = null, ?array $rate = null, ?string $shipping_rate = null, ?array $destination = null, ?string $checkout_token = null, ?array $coupons = null ): self {
		return new self(
			$lines ?? $this->lines,
			$notices ?? $this->notices,
			$rate ?? $this->rate,
			$shipping_rate ?? $this->shipping_rate,
			$destination ?? $this->destination,
			$checkout_token ?? $this->checkout_token,
			$coupons ?? $this->coupons,
		);
	}
}
