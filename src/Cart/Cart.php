<?php

declare(strict_types=1);

namespace Flexa\Commerce\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * The cart as stored in the session `data` column:
 * `{v:1, lines:[...], notices:[...], rate:{window,count}}`. Immutable; every
 * change returns a new instance.
 */
final class Cart {
	public const VERSION = 1;

	public const MAX_LINES = 100;

	/**
	 * @param list<CartLine>                       $lines
	 * @param list<array{code:string,message:string}> $notices shown once, then cleared by the next write
	 * @param array{window:int,count:int}          $rate
	 */
	public function __construct(
		public readonly array $lines = [],
		public readonly array $notices = [],
		public readonly array $rate = [
			'window' => 0,
			'count'  => 0,
		],
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
		$rate = is_array( $data['rate'] ?? null ) ? $data['rate'] : [];
		return new self(
			array_values( $lines ),
			$notices,
			[
				'window' => (int) ( $rate['window'] ?? 0 ),
				'count'  => (int) ( $rate['count'] ?? 0 ),
			]
		);
	}

	/** @return array<string,mixed> */
	public function to_data(): array {
		return [
			'v'       => self::VERSION,
			'lines'   => array_map( static fn ( CartLine $l ): array => $l->to_array(), $this->lines ),
			'notices' => $this->notices,
			'rate'    => $this->rate,
		];
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
		return new self( $lines, $this->notices, $this->rate );
	}

	public function with_quantity( string $key, int $quantity ): self {
		if ( $quantity <= 0 ) {
			return $this->without( $key );
		}
		$lines = array_map( static fn ( CartLine $l ): CartLine => $l->key === $key ? $l->with_quantity( $quantity ) : $l, $this->lines );
		return new self( $lines, $this->notices, $this->rate );
	}

	public function without( string $key ): self {
		return new self( array_values( array_filter( $this->lines, static fn ( CartLine $l ): bool => $l->key !== $key ) ), $this->notices, $this->rate );
	}

	public function emptied(): self {
		return new self( [], [], $this->rate );
	}

	/** @param list<array{code:string,message:string}> $notices */
	public function with_notices( array $notices ): self {
		return new self( $this->lines, $notices, $this->rate );
	}

	/** @param array{window:int,count:int} $rate */
	public function with_rate( array $rate ): self {
		return new self( $this->lines, $this->notices, $rate );
	}
}
