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
	 * be empty) | enum:a|b|c | int:min:max | country (ISO alpha-2) |
	 * countries (list of ISO alpha-2, empty means all) | state (up to 20
	 * upper-case characters) | tax_class (a class slug or `inherit`) |
	 * tax_classes | tax_markets | tax_rates (internal, see INTERNAL).
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
		'low_stock_threshold'      => [ 'int:0:100000', 2 ],
		'notify_low_stock'         => [ 'bool', true ],
		'guest_session_hours'      => [ 'int:1:720', 48 ],
		'user_session_days'        => [ 'int:1:365', 14 ],
		'store_customer_ip'        => [ 'bool', true ],
		'defer_emails'             => [ 'bool', false ],
		'checkout_paused'          => [ 'bool', false ],
		'cart_page_id'             => [ 'int:0:2147483647', 0 ],
		'checkout_page_id'         => [ 'int:0:2147483647', 0 ],
		'terms_page_id'            => [ 'int:0:2147483647', 0 ],
		'account_page_id'          => [ 'int:0:2147483647', 0 ],
		'store_country'            => [ 'country', 'US' ],
		'store_state'              => [ 'state', '' ],
		'store_postcode'           => [ 'string', '' ],
		'store_city'               => [ 'string', '' ],
		'sell_to_countries'        => [ 'countries', [] ],
		'checkout_company'         => [ 'enum:hidden|optional|required', 'hidden' ],
		'checkout_phone'           => [ 'enum:hidden|optional|required', 'optional' ],
		'checkout_address_2'       => [ 'enum:hidden|optional', 'optional' ],
		'log_level'                => [ 'enum:debug|info|notice|warning|error|critical', 'info' ],
		'log_retention_days'       => [ 'int:1:365', 30 ],
		'remove_data_on_uninstall' => [ 'bool', false ],
		'weight_unit'              => [ 'enum:kg|g|lb|oz', 'kg' ],
		'dimension_unit'           => [ 'enum:cm|mm|m|in|yd', 'cm' ],
		'product_base'             => [ 'slug', '' ],
		'shop_base'                => [ 'slug', '' ],
		'category_base'            => [ 'slug', '' ],
		'tag_base'                 => [ 'slug', '' ],
		'products_per_page'        => [ 'int:1:100', 16 ],
		'tax_enabled'              => [ 'bool', false ],
		'prices_include_tax'       => [ 'bool', false ],
		'tax_based_on'             => [ 'enum:shipping|billing|base', 'shipping' ],
		'tax_default_location'     => [ 'enum:base|none', 'base' ],
		'shipping_tax_class'       => [ 'tax_class', 'inherit' ],
		'tax_round_at_subtotal'    => [ 'bool', false ],
		'tax_classes'              => [
			'tax_classes',
			[
				[
					'slug' => 'reduced-rate',
					'name' => 'Reduced rate',
				],
				[
					'slug' => 'zero-rate',
					'name' => 'Zero rate',
				],
			],
		],
		'tax_display_shop'         => [ 'enum:excl|incl', 'excl' ],
		'tax_display_cart'         => [ 'enum:excl|incl', 'excl' ],
		'price_display_suffix'     => [ 'string', '' ],
		'tax_total_display'        => [ 'enum:itemized|single', 'itemized' ],
		'tax_markets'              => [ 'tax_markets', [] ],
		'tax_base_rates'           => [ 'tax_rates', [] ],
	];

	/** Written by the plugin, never by the settings screen: sanitize() drops them. */
	public const INTERNAL = [ 'tax_base_rates' ];

	/** @var array<string,mixed>|null coerced copy of the last stored value seen */
	private static ?array $memo = null;

	private static mixed $memo_raw = null;

	/** @var array<string,mixed> keys coerced one by one by get(), for $keys_raw */
	private static array $keys = [];

	private static mixed $keys_raw = null;

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
		if ( self::$memo !== null && self::$memo_raw === $stored ) {
			return self::$memo;
		}
		$out = [];
		foreach ( self::SCHEMA as $key => $def ) {
			$out[ $key ] = array_key_exists( $key, $stored )
				? self::coerce( $def[0], $stored[ $key ], $def[1] )
				: $def[1];
		}
		self::$memo_raw = $stored;
		self::$memo     = $out;
		return $out;
	}

	/** Coerces only the key asked for, so a page that reads one setting does not pay for the whole schema. */
	public static function get( string $key ): mixed {
		if ( ! isset( self::SCHEMA[ $key ] ) ) {
			return null;
		}
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		if ( self::$memo !== null && self::$memo_raw === $stored ) {
			return self::$memo[ $key ];
		}
		if ( self::$keys_raw !== $stored ) {
			self::$keys     = [];
			self::$keys_raw = $stored;
		}
		if ( ! array_key_exists( $key, self::$keys ) ) {
			$def                = self::SCHEMA[ $key ];
			self::$keys[ $key ] = array_key_exists( $key, $stored ) ? self::coerce( $def[0], $stored[ $key ], $def[1] ) : $def[1];
		}
		return self::$keys[ $key ];
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
			if ( ! isset( self::SCHEMA[ $key ] ) || in_array( $key, self::INTERNAL, true ) ) {
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
	 * @param bool                $internal also accept the INTERNAL keys (plugin code only)
	 * @return array<string,mixed> the new full settings
	 */
	public static function update( array $partial, bool $internal = false ): array {
		$old   = self::all();
		$clean = self::sanitize( $partial );
		if ( $internal ) {
			foreach ( self::INTERNAL as $key ) {
				if ( array_key_exists( $key, $partial ) ) {
					$clean[ $key ] = self::coerce( self::SCHEMA[ $key ][0], $partial[ $key ], self::SCHEMA[ $key ][1] );
				}
			}
		}
		$new = array_merge( $old, $clean );
		update_option( self::OPTION, $new, true );
		do_action( 'flexacommerce.settings.updated', $new, $old );
		return $new;
	}

	/** Write defaults for a fresh site without touching existing values. */
	public static function seed(): void {
		add_option( self::OPTION, self::defaults(), '', true );
	}

	/** @internal TaxSettings uses it for market overrides. */
	public static function coerce( string $type, mixed $value, mixed $default ): mixed {
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
		if ( $type === 'country' ) {
			$code = is_string( $value ) ? strtoupper( trim( $value ) ) : '';
			return preg_match( '/^[A-Z]{2}$/', $code ) === 1 ? $code : $default;
		}
		if ( $type === 'countries' ) {
			$list = is_string( $value ) ? explode( ',', $value ) : ( is_array( $value ) ? $value : [] );
			$out  = [];
			foreach ( $list as $code ) {
				$code = is_string( $code ) ? strtoupper( trim( $code ) ) : '';
				if ( preg_match( '/^[A-Z]{2}$/', $code ) === 1 ) {
					$out[ $code ] = $code;
				}
			}
			return array_values( $out );
		}
		if ( $type === 'state' ) {
			$code = is_scalar( $value ) ? strtoupper( trim( sanitize_text_field( (string) $value ) ) ) : '';
			return substr( $code, 0, 20 );
		}
		if ( $type === 'states' ) {
			$out = [];
			foreach ( is_array( $value ) ? $value : explode( ',', is_string( $value ) ? $value : '' ) as $code ) {
				$code = is_scalar( $code ) ? (string) self::coerce( 'state', $code, '' ) : '';
				if ( $code !== '' ) {
					$out[ $code ] = $code;
				}
			}
			return array_values( $out );
		}
		if ( $type === 'tax_class' ) {
			$slug = is_scalar( $value ) ? sanitize_title( (string) $value ) : '';
			return $slug === '' && $value !== '' ? $default : $slug;
		}
		if ( $type === 'tax_classes' ) {
			return TaxSettings::classes( $value, $default );
		}
		if ( $type === 'tax_markets' ) {
			return TaxSettings::markets( $value );
		}
		if ( $type === 'tax_rates' ) {
			return TaxSettings::rates( $value );
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
