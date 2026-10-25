<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping;

use Flexa\Commerce\Contracts\Shipping\Destination;

defined( 'ABSPATH' ) || exit;

/**
 * A shipping zone: where it applies and the methods offered there. Zone 0 is
 * "everywhere else" and has no regions.
 *
 * Regions are `country:US`, `state:US:CA` or a postcode pattern in
 * `postcodes`: `90210`, `902*` or a range `90000...90299`. A zone matches
 * when the country or state matches and, if it lists postcodes, one of them
 * matches too (doc 01, shipping zones).
 */
final class Zone {
	public const REST_OF_WORLD = 0;

	/**
	 * @param list<string>         $regions
	 * @param list<string>         $postcodes
	 * @param list<MethodInstance> $methods
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly array $regions = [],
		public readonly array $postcodes = [],
		public readonly array $methods = [],
	) {}

	/** @param array<string,mixed> $row */
	public static function from_array( array $row ): self {
		$methods = [];
		foreach ( is_array( $row['methods'] ?? null ) ? $row['methods'] : [] as $method ) {
			if ( is_array( $method ) ) {
				$methods[] = MethodInstance::from_array( $method );
			}
		}
		return new self(
			max( 0, (int) ( $row['id'] ?? 0 ) ),
			sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
			self::strings( $row['regions'] ?? [], true ),
			self::strings( $row['postcodes'] ?? [], false ),
			$methods,
		);
	}

	public function matches( Destination $to ): bool {
		if ( $this->id === self::REST_OF_WORLD ) {
			return true;
		}
		$country = strtoupper( $to->country );
		$state   = strtoupper( $to->state );
		$hit     = false;
		foreach ( $this->regions as $region ) {
			$parts = explode( ':', $region );
			if ( ( $parts[0] === 'country' && ( $parts[1] ?? '' ) === $country )
				|| ( $parts[0] === 'state' && ( $parts[1] ?? '' ) === $country && ( $parts[2] ?? '' ) === $state && $state !== '' ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return false;
		}
		if ( $this->postcodes === [] ) {
			return true;
		}
		$code = self::normalize_postcode( $to->postcode );
		foreach ( $this->postcodes as $pattern ) {
			if ( self::postcode_matches( $pattern, $code ) ) {
				return true;
			}
		}
		return false;
	}

	public function method( int $instance_id ): ?MethodInstance {
		foreach ( $this->methods as $method ) {
			if ( $method->instance_id === $instance_id ) {
				return $method;
			}
		}
		return null;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'id'        => $this->id,
			'name'      => $this->name,
			'regions'   => $this->regions,
			'postcodes' => $this->postcodes,
			'methods'   => array_map( static fn ( MethodInstance $m ): array => $m->to_array(), $this->methods ),
		];
	}

	public static function normalize_postcode( string $code ): string {
		return strtoupper( (string) preg_replace( '/[\s\-]+/', '', $code ) );
	}

	private static function postcode_matches( string $pattern, string $code ): bool {
		if ( $code === '' ) {
			return false;
		}
		if ( str_contains( $pattern, '...' ) ) {
			[ $low, $high ] = array_map( [ self::class, 'normalize_postcode' ], explode( '...', $pattern, 2 ) );
			if ( ctype_digit( $low ) && ctype_digit( $high ) && ctype_digit( $code ) ) {
				return (int) $code >= (int) $low && (int) $code <= (int) $high;
			}
			return strcmp( $code, $low ) >= 0 && strcmp( $code, $high ) <= 0;
		}
		$pattern = self::normalize_postcode( $pattern );
		if ( str_ends_with( $pattern, '*' ) ) {
			return str_starts_with( $code, rtrim( $pattern, '*' ) );
		}
		return $pattern === $code;
	}

	/** @return list<string> */
	private static function strings( mixed $values, bool $upper ): array {
		$out = [];
		foreach ( is_array( $values ) ? $values : [] as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$text = trim( sanitize_text_field( (string) $value ) );
			if ( $text === '' ) {
				continue;
			}
			if ( $upper ) {
				$parts = explode( ':', $text );
				$text  = strtolower( $parts[0] ) . ( count( $parts ) > 1 ? ':' . strtoupper( implode( ':', array_slice( $parts, 1 ) ) ) : '' );
			}
			$out[] = $text;
		}
		return array_values( array_unique( $out ) );
	}
}
