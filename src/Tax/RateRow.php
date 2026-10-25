<?php

declare(strict_types=1);

namespace Flexa\Commerce\Tax;

use Flexa\Commerce\Contracts\Tax\TaxLocation;
use Flexa\Commerce\Contracts\Tax\TaxRate;

defined( 'ABSPATH' ) || exit;

/**
 * A row of fxc_tax_rates with its locations, as the Taxes screen, CSV and
 * presets read and write it (doc 03 §4.13). Postcodes are normalized codes,
 * prefixes ending in `*`, or numeric ranges `from...to`.
 */
final class RateRow {
	public const SOURCES = [ 'manual', 'import', 'migration' ];

	/**
	 * @param list<string> $postcodes
	 * @param list<string> $cities
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $tax_class,
		public readonly string $country,
		public readonly string $state,
		public readonly int $rate,
		public readonly string $name,
		public readonly int $priority = 1,
		public readonly bool $compound = false,
		public readonly bool $shipping = true,
		public readonly int $rate_order = 0,
		public readonly array $postcodes = [],
		public readonly array $cities = [],
		public readonly string $source = 'manual',
	) {}

	/**
	 * Validated input from the admin screen, CSV or a preset.
	 *
	 * @param array<string,mixed> $data
	 * @throws \InvalidArgumentException with a message for the admin
	 */
	public static function from_input( array $data, int $id = 0 ): self {
		$country = strtoupper( trim( (string) ( $data['country'] ?? '' ) ) );
		if ( $country === '*' ) {
			$country = '';
		}
		if ( $country !== '' && preg_match( '/^[A-Z]{2}$/', $country ) !== 1 ) {
			throw new \InvalidArgumentException( sprintf( /* translators: %s: country code. */ __( 'Unknown country code "%s".', 'flexacommerce' ), $country ) );
		}
		$state = strtoupper( trim( (string) ( $data['state'] ?? '' ) ) );
		if ( $state === '*' ) {
			$state = '';
		}
		if ( strlen( $state ) > 20 ) {
			throw new \InvalidArgumentException( __( 'The state code is too long.', 'flexacommerce' ) );
		}
		$rate = $data['rate'] ?? null;
		if ( is_int( $rate ) ) {
			$millionths = $rate >= 0 && $rate <= TaxRate::SCALE ? $rate : null;
		} else {
			$millionths = is_scalar( $rate ) ? TaxRate::from_percent( (string) $rate ) : null;
		}
		if ( $millionths === null ) {
			throw new \InvalidArgumentException( __( 'The rate must be a percent from 0 to 100 with at most four decimals.', 'flexacommerce' ) );
		}
		$priority = (int) ( $data['priority'] ?? 1 );
		if ( $priority < 1 || $priority > 65535 ) {
			throw new \InvalidArgumentException( __( 'The priority must be a whole number from 1.', 'flexacommerce' ) );
		}

		return new self(
			id: $id,
			tax_class: sanitize_title( (string) ( $data['tax_class'] ?? '' ) ),
			country: $country,
			state: $state,
			rate: $millionths,
			name: mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 200 ),
			priority: $priority,
			compound: self::flag( $data['compound'] ?? false ),
			shipping: self::flag( $data['shipping'] ?? true ),
			rate_order: max( 0, (int) ( $data['rate_order'] ?? 0 ) ),
			postcodes: self::postcodes( $data['postcodes'] ?? [], $country ),
			cities: self::cities( $data['cities'] ?? [] ),
			source: self::source( (string) ( $data['source'] ?? 'manual' ) ),
		);
	}

	/**
	 * @param array<string,mixed> $row       fxc_tax_rates row
	 * @param list<array<string,mixed>> $locations its fxc_tax_rate_locations rows
	 */
	public static function from_row( array $row, array $locations = [] ): self {
		$postcodes = [];
		$cities    = [];
		foreach ( $locations as $location ) {
			if ( $location['type'] === 'city' ) {
				$cities[] = (string) $location['code'];
			} elseif ( $location['range_from'] !== null && $location['range_to'] !== null ) {
				$postcodes[] = self::range_text( (int) $location['range_from'], (int) $location['range_to'] );
			} else {
				$postcodes[] = (string) $location['code'];
			}
		}
		return new self(
			id: (int) $row['id'],
			tax_class: (string) $row['tax_class'],
			country: (string) $row['country'],
			state: (string) $row['state'],
			rate: (int) $row['rate'],
			name: (string) $row['name'],
			priority: (int) $row['priority'],
			compound: (bool) (int) $row['compound'],
			shipping: (bool) (int) $row['shipping'],
			rate_order: (int) $row['rate_order'],
			postcodes: $postcodes,
			cities: $cities,
			source: (string) $row['source'],
		);
	}

	public function to_rate(): TaxRate {
		return new TaxRate(
			$this->id,
			TaxRate::code_for( $this->country, $this->state, $this->name, $this->priority ),
			$this->name !== '' ? $this->name : __( 'Tax', 'flexacommerce' ),
			$this->rate,
			$this->priority,
			$this->compound,
			$this->shipping,
		);
	}

	/**
	 * Location rows to store: postcode codes and prefixes, numeric ranges, cities.
	 *
	 * @return list<array{type:string,code:string,range_from:?int,range_to:?int}>
	 */
	public function location_rows(): array {
		$rows = [];
		foreach ( $this->postcodes as $postcode ) {
			if ( str_contains( $postcode, '...' ) ) {
				[ $from, $to ] = explode( '...', $postcode, 2 );
				$rows[]        = [
					'type'       => 'postcode',
					'code'       => '',
					'range_from' => (int) $from,
					'range_to'   => (int) $to,
				];
			} else {
				$rows[] = [
					'type'       => 'postcode',
					'code'       => $postcode,
					'range_from' => null,
					'range_to'   => null,
				];
			}
		}
		foreach ( $this->cities as $city ) {
			$rows[] = [
				'type'       => 'city',
				'code'       => $city,
				'range_from' => null,
				'range_to'   => null,
			];
		}
		return $rows;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'         => $this->id,
			'tax_class'  => $this->tax_class,
			'country'    => $this->country,
			'state'      => $this->state,
			'rate'       => TaxRate::to_percent( $this->rate ),
			'name'       => $this->name,
			'priority'   => $this->priority,
			'compound'   => $this->compound,
			'shipping'   => $this->shipping,
			'rate_order' => $this->rate_order,
			'postcodes'  => $this->postcodes,
			'cities'     => $this->cities,
			'source'     => $this->source,
		];
	}

	/** Whether this row matches a location, the same rules as the SQL match (tests and previews). */
	public function matches( TaxLocation $location ): bool {
		if ( ( $this->country !== '' && $this->country !== $location->country ) || ( $this->state !== '' && $this->state !== $location->state ) ) {
			return false;
		}
		if ( $this->postcodes !== [] ) {
			$hit = false;
			foreach ( $this->postcodes as $code ) {
				if ( str_contains( $code, '...' ) ) {
					[ $from, $to ] = explode( '...', $code, 2 );
					$hit           = ctype_digit( $location->postcode ) && (int) $location->postcode >= (int) $from && (int) $location->postcode <= (int) $to;
				} elseif ( str_ends_with( $code, '*' ) ) {
					$hit = str_starts_with( $location->postcode, substr( $code, 0, -1 ) );
				} else {
					$hit = $code === $location->postcode;
				}
				if ( $hit ) {
					break;
				}
			}
			if ( ! $hit ) {
				return false;
			}
		}
		return $this->cities === [] || in_array( $location->city, $this->cities, true );
	}

	private static function flag( mixed $value ): bool {
		return is_bool( $value ) ? $value : in_array( $value, [ 1, '1', 'true', 'yes', 'on' ], true );
	}

	private static function source( string $source ): string {
		return in_array( $source, self::SOURCES, true ) || preg_match( '/^preset:[a-z0-9_-]{1,24}$/', $source ) === 1 ? $source : 'manual';
	}

	/**
	 * @return list<string>
	 * @throws \InvalidArgumentException
	 */
	private static function postcodes( mixed $value, string $country ): array {
		$list = is_array( $value ) ? $value : explode( ';', (string) ( is_scalar( $value ) ? $value : '' ) );
		$out  = [];
		foreach ( $list as $item ) {
			$item = trim( (string) ( is_scalar( $item ) ? $item : '' ) );
			if ( $item === '' ) {
				continue;
			}
			if ( str_contains( $item, '...' ) ) {
				[ $from, $to ] = array_map( 'trim', explode( '...', $item, 2 ) );
				if ( ! ctype_digit( $from ) || ! ctype_digit( $to ) || (int) $from > (int) $to ) {
					throw new \InvalidArgumentException( sprintf( /* translators: %s: postcode range. */ __( 'The postcode range "%s" must be two numbers, low to high.', 'flexacommerce' ), $item ) );
				}
				$code = $from . '...' . $to;
			} else {
				$code = TaxLocation::postcode( $country, $item );
				if ( $code === '' || strlen( $code ) > 100 || preg_match( '/^[A-Z0-9]*\*?$/', $code ) !== 1 ) {
					throw new \InvalidArgumentException( sprintf( /* translators: %s: postcode. */ __( 'The postcode "%s" is not valid. Use letters and digits, optionally ending in *.', 'flexacommerce' ), $item ) );
				}
			}
			$out[ $code ] = $code;
		}
		return array_values( $out );
	}

	/** @return list<string> */
	private static function cities( mixed $value ): array {
		$list = is_array( $value ) ? $value : explode( ';', (string) ( is_scalar( $value ) ? $value : '' ) );
		$out  = [];
		foreach ( $list as $item ) {
			$city = mb_strtoupper( trim( sanitize_text_field( (string) ( is_scalar( $item ) ? $item : '' ) ) ) );
			if ( $city !== '' ) {
				$out[ $city ] = mb_substr( $city, 0, 100 );
			}
		}
		return array_values( $out );
	}

	private static function range_text( int $from, int $to ): string {
		return $from . '...' . $to;
	}
}
