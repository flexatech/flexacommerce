<?php

declare(strict_types=1);

namespace Flexa\Commerce\Contracts\Tax;

defined( 'ABSPATH' ) || exit;

/**
 * One rate that applies to a location. `rate` is in millionths:
 * 7.25 % = 72500, 100 % = 1000000. Rates from a provider that are not table
 * rows have an id of 0 or below and a stable `code`.
 *
 * @api
 */
final class TaxRate {
	public const SCALE = 1000000;

	public function __construct(
		public readonly int $id,
		public readonly string $code,
		public readonly string $label,
		public readonly int $rate,
		public readonly int $priority = 1,
		public readonly bool $compound = false,
		public readonly bool $shipping = true,
	) {
		if ( $rate < 0 || $rate > self::SCALE ) {
			throw new \InvalidArgumentException( sprintf( 'Tax rate %d is out of range.', $rate ) );
		}
	}

	/** Key used in order item meta and results: the row id, or the code for provider rates. */
	public function key(): string {
		return $this->id > 0 ? (string) $this->id : $this->code;
	}

	/**
	 * WooCommerce's rate code: `{COUNTRY}-{STATE}-{NAME}-{priority}`, parts
	 * left out when empty, `TAX` when there is no name.
	 */
	public static function code_for( string $country, string $state, string $name, int $priority ): string {
		$parts = [];
		if ( $country !== '' ) {
			$parts[] = strtoupper( $country );
		}
		if ( $state !== '' ) {
			$parts[] = strtoupper( $state );
		}
		$parts[] = $name !== '' ? mb_strtoupper( $name ) : 'TAX';
		$parts[] = (string) $priority;
		return implode( '-', $parts );
	}

	/** "7.25" from 72500, up to four decimals, no float. */
	public function percent(): string {
		return self::to_percent( $this->rate );
	}

	public static function to_percent( int $rate ): string {
		$whole = intdiv( $rate, 10000 );
		$frac  = rtrim( str_pad( (string) ( $rate % 10000 ), 4, '0', STR_PAD_LEFT ), '0' );
		return $frac === '' ? (string) $whole : $whole . '.' . $frac;
	}

	/** 72500 from "7.25" or "7.2500"; null when the text is not a percent with at most four decimals. */
	public static function from_percent( string $percent ): ?int {
		$percent = trim( $percent );
		if ( str_contains( $percent, '.' ) ) {
			$percent = rtrim( rtrim( $percent, '0' ), '.' );
		}
		if ( preg_match( '/^(\d{1,3})(?:\.(\d{1,4}))?$/', $percent, $m ) !== 1 ) {
			return null;
		}
		$rate = (int) $m[1] * 10000 + (int) str_pad( $m[2] ?? '', 4, '0' );
		return $rate <= self::SCALE ? $rate : null;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'       => $this->id,
			'code'     => $this->code,
			'label'    => $this->label,
			'rate'     => $this->rate,
			'priority' => $this->priority,
			'compound' => $this->compound,
			'shipping' => $this->shipping,
		];
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): self {
		return new self(
			(int) ( $data['id'] ?? 0 ),
			(string) ( $data['code'] ?? '' ),
			(string) ( $data['label'] ?? '' ),
			(int) ( $data['rate'] ?? 0 ),
			(int) ( $data['priority'] ?? 1 ),
			! empty( $data['compound'] ),
			! empty( $data['shipping'] ),
		);
	}
}
