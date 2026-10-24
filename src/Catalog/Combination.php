<?php

declare(strict_types=1);

namespace Flexa\Commerce\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * The attribute values that identify a variant (doc 03 §3.2).
 *
 * Keys are `g:{attribute_term_id}` for global attributes and
 * `l:{sanitize_title(name)}` for local ones. Values are the value term id
 * (global), the sanitized value (local) or `*` for "any value". Pairs are
 * kept sorted by byte order, so equal combinations always produce the same
 * canonical string and hash.
 */
final class Combination {
	public const WILDCARD = '*';

	/** @param array<string,string> $pairs sorted by key */
	private function __construct( private readonly array $pairs ) {}

	public static function empty(): self {
		return new self( [] );
	}

	/**
	 * @param array<array-key,mixed> $pairs
	 * @throws \InvalidArgumentException on a malformed key or value.
	 */
	public static function from_array( array $pairs ): self {
		$clean = [];
		foreach ( $pairs as $key => $value ) {
			$key = (string) $key;
			if ( ! self::is_valid_key( $key ) ) {
				throw new \InvalidArgumentException( sprintf( 'Invalid attribute key "%s".', $key ) );
			}
			if ( is_int( $value ) ) {
				$value = (string) $value;
			}
			if ( ! is_string( $value ) || ! self::is_valid_value( $key, $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'Invalid value for attribute "%s".', $key ) );
			}
			$clean[ $key ] = $value;
		}
		ksort( $clean, SORT_STRING );
		return new self( $clean );
	}

	/** Decodes the `attributes` column. Anything unreadable becomes the empty combination. */
	public static function from_json( ?string $json ): self {
		if ( $json === null || $json === '' ) {
			return self::empty();
		}
		try {
			$data = json_decode( $json, true, 8, JSON_THROW_ON_ERROR );
			return is_array( $data ) ? self::from_array( $data ) : self::empty();
		} catch ( \JsonException | \InvalidArgumentException ) {
			return self::empty();
		}
	}

	public static function global_key( int $attribute_term_id ): string {
		return 'g:' . $attribute_term_id;
	}

	public static function local_key( string $name ): string {
		return 'l:' . sanitize_title( $name );
	}

	public static function local_value( string $value ): string {
		return sanitize_title( $value );
	}

	public static function is_valid_key( string $key ): bool {
		return strlen( $key ) <= 200 && preg_match( '/^(g:[1-9][0-9]*|l:[a-z0-9_%\-]+)$/', $key ) === 1;
	}

	public static function is_valid_value( string $key, string $value ): bool {
		if ( $value === self::WILDCARD ) {
			return true;
		}
		if ( str_starts_with( $key, 'g:' ) ) {
			return preg_match( '/^[1-9][0-9]*$/', $value ) === 1;
		}
		return strlen( $value ) <= 200 && preg_match( '/^[a-z0-9_%\-]+$/', $value ) === 1;
	}

	/** `g:12=31&l:fabric=linen`; the base row's combination is the empty string. */
	public function canonical(): string {
		$parts = [];
		foreach ( $this->pairs as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}
		return implode( '&', $parts );
	}

	public function hash(): string {
		return sha1( $this->canonical() );
	}

	/** @return array<string,string> */
	public function to_array(): array {
		return $this->pairs;
	}

	/** JSON for the `attributes` column: always an object, `{}` when empty. */
	public function to_json(): string {
		return $this->pairs === []
			? '{}'
			: json_encode( $this->pairs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- THROW_ON_ERROR, wp_json_encode() swallows failures.
	}

	public function is_empty(): bool {
		return $this->pairs === [];
	}

	/** @return list<string> */
	public function keys(): array {
		return array_keys( $this->pairs );
	}

	public function get( string $key ): ?string {
		return $this->pairs[ $key ] ?? null;
	}

	public function with( string $key, string $value ): self {
		$pairs         = $this->pairs;
		$pairs[ $key ] = $value;
		return self::from_array( $pairs );
	}

	public function wildcard_count(): int {
		return count( array_keys( $this->pairs, self::WILDCARD, true ) );
	}

	public function equals( self $other ): bool {
		return $this->pairs === $other->pairs;
	}

	/**
	 * Whether a concrete selection satisfies every pair. A key the
	 * combination does not mention is not constrained.
	 *
	 * @param array<string,string> $selection
	 */
	public function matches( array $selection ): bool {
		foreach ( $this->pairs as $key => $value ) {
			if ( ! isset( $selection[ $key ] ) ) {
				return false;
			}
			if ( $value !== self::WILDCARD && $value !== $selection[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	/** True when at least one concrete selection matches both combinations. */
	public function overlaps( self $other ): bool {
		foreach ( $this->pairs as $key => $value ) {
			$theirs = $other->pairs[ $key ] ?? self::WILDCARD;
			if ( $value !== self::WILDCARD && $theirs !== self::WILDCARD && $value !== $theirs ) {
				return false;
			}
		}
		return true;
	}
}
