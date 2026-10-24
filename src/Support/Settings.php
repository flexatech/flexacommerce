<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the flexacommerce_settings option: schema,
 * defaults, coercion, sanitizing. REST and PHP callers both go through here.
 * This is the only autoloaded option the plugin owns (doc 06 P10).
 */
final class Settings {
	public const OPTION = 'flexacommerce_settings';

	/**
	 * Flat key => [type, default]. Types: bool | string | currency |
	 * separator (up to 3 characters, spaces kept) | slug (sanitize_title, may
	 * be empty) | enum:a|b|c | int:min:max.
	 *
	 * @var array<string,array{0:string,1:mixed}>
	 */
	private const SCHEMA = [
		'currency'                 => [ 'currency', 'USD' ],
		'currency_position'        => [ 'enum:left|right|left_space|right_space', 'left' ],
		'thousand_separator'       => [ 'separator', ',' ],
		'decimal_separator'        => [ 'separator', '.' ],
		'order_number_prefix'      => [ 'string', '' ],
		'migration_number_prefix'  => [ 'string', 'WC-' ],
		'hold_minutes'             => [ 'int:1:10080', 60 ],
		'max_line_quantity'        => [ 'int:1:100000', 999 ],
		'store_customer_ip'        => [ 'bool', true ],
		'checkout_paused'          => [ 'bool', false ],
		'log_level'                => [ 'enum:debug|info|notice|warning|error|critical', 'info' ],
		'log_retention_days'       => [ 'int:1:365', 30 ],
		'remove_data_on_uninstall' => [ 'bool', false ],
		'weight_unit'              => [ 'enum:kg|g|lb|oz', 'kg' ],
		'dimension_unit'           => [ 'enum:cm|mm|m|in|yd', 'cm' ],
		'product_base'             => [ 'slug', '' ],
		'shop_base'                => [ 'slug', '' ],
		'category_base'            => [ 'slug', '' ],
		'tag_base'                 => [ 'slug', '' ],
	];

	/** @return array<string,mixed> */
	public static function defaults(): array {
		$defaults = [];
		foreach ( self::SCHEMA as $key => $def ) {
			$defaults[ $key ] = $def[1];
		}
		return $defaults;
	}

	/** @return array<string,mixed> */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$out = [];
		foreach ( self::SCHEMA as $key => $def ) {
			$out[ $key ] = array_key_exists( $key, $stored )
				? self::coerce( $def[0], $stored[ $key ], $def[1] )
				: $def[1];
		}
		return $out;
	}

	public static function get( string $key ): mixed {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function string( string $key ): string {
		$value = self::get( $key );
		return is_scalar( $value ) ? (string) $value : '';
	}

	public static function int( string $key ): int {
		$value = self::get( $key );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	public static function bool( string $key ): bool {
		return self::get( $key ) === true;
	}

	/**
	 * Sanitize a partial payload against the schema, dropping unknown keys.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$clean = [];
		foreach ( $input as $key => $value ) {
			if ( ! isset( self::SCHEMA[ $key ] ) ) {
				continue;
			}
			$clean[ $key ] = self::coerce( self::SCHEMA[ $key ][0], $value, self::SCHEMA[ $key ][1] );
		}
		return $clean;
	}

	/**
	 * Merge a sanitized partial over stored values and persist.
	 *
	 * @param array<string,mixed> $partial
	 * @return array<string,mixed> the new full settings
	 */
	public static function update( array $partial ): array {
		$old = self::all();
		$new = array_merge( $old, self::sanitize( $partial ) );
		update_option( self::OPTION, $new, true );
		do_action( 'flexacommerce.settings.updated', $new, $old );
		return $new;
	}

	/** Write defaults for a fresh site without touching existing values. */
	public static function seed(): void {
		add_option( self::OPTION, self::defaults(), '', true );
	}

	private static function coerce( string $type, mixed $value, mixed $default ): mixed {
		if ( $type === 'bool' ) {
			return is_bool( $value ) ? $value : in_array( $value, [ 1, '1', 'true', 'yes', 'on' ], true );
		}
		if ( $type === 'string' ) {
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $default;
		}
		if ( $type === 'slug' ) {
			return is_scalar( $value ) ? sanitize_title( (string) $value ) : $default;
		}
		if ( $type === 'separator' ) {
			if ( ! is_scalar( $value ) ) {
				return $default;
			}
			$clean = (string) preg_replace( '/[<>"\'\\\\\p{Cc}]/u', '', (string) $value );
			return mb_substr( $clean, 0, 3 );
		}
		if ( $type === 'currency' ) {
			$code = is_string( $value ) ? strtoupper( trim( $value ) ) : '';
			return Currency::is_valid( $code ) ? $code : $default;
		}
		if ( str_starts_with( $type, 'enum:' ) ) {
			$allowed = explode( '|', substr( $type, 5 ) );
			return in_array( $value, $allowed, true ) ? $value : $default;
		}
		if ( str_starts_with( $type, 'int:' ) ) {
			[ , $min, $max ] = explode( ':', $type );
			if ( ! is_numeric( $value ) ) {
				return $default;
			}
			return max( (int) $min, min( (int) $max, (int) $value ) );
		}
		return $default;
	}
}
