<?php

declare(strict_types=1);

namespace Flexa\Commerce\Invoices;

use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Invoice settings (ADR-060), one option that is not autoloaded: it is read
 * when a PDF is made, an email may carry one, or an order page offers the
 * link. The store settings have no room left (64 keys at most).
 *
 * @phpstan-type Values array{prefix:string,suffix:string,padding:int,reset:string,attach_to:list<string>,customer_access:string,shop_name:string,shop_address:string,shop_details:string,logo_id:int,footer:string,paper:string,show_sku:bool,credit_prefix:string,credit_suffix:string,credit_attach:bool}
 */
final class InvoiceSettings {
	public const OPTION = 'flexacommerce_invoices';

	public const RESETS = [ 'never', 'yearly' ];

	/** When a customer sees the invoice link on their order. */
	public const ACCESS = [ 'paid', 'invoiced', 'never' ];

	public const PAPERS = [ '', 'a4', 'letter' ];

	private const DEFAULTS = [
		'prefix'          => 'INV-',
		'suffix'          => '',
		'padding'         => 4,
		'reset'           => 'never',
		'attach_to'       => [],
		'customer_access' => 'paid',
		'shop_name'       => '',
		'shop_address'    => '',
		'shop_details'    => '',
		'logo_id'         => 0,
		'footer'          => '',
		'paper'           => '',
		'show_sku'        => true,
		'credit_prefix'   => 'CN-',
		'credit_suffix'   => '',
		'credit_attach'   => false,
	];

	/** @return Values */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		return self::clean( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Merges a partial update over the stored values.
	 *
	 * @param array<string,mixed> $partial
	 * @return Values
	 */
	public static function update( array $partial ): array {
		$values = self::clean( array_merge( self::get(), array_intersect_key( $partial, self::DEFAULTS ) ) );
		update_option( self::OPTION, $values, false );
		return $values;
	}

	/** A4, or Letter for a store in the United States or Canada, unless chosen. */
	public static function paper( string $paper ): string {
		if ( $paper !== '' ) {
			return $paper;
		}
		return in_array( Settings::string( 'store_country' ), [ 'US', 'CA' ], true ) ? 'letter' : 'a4';
	}

	/**
	 * @param array<mixed> $values
	 * @return Values
	 */
	public static function clean( array $values ): array {
		$string = static fn ( string $key ): string => is_string( $values[ $key ] ?? null ) ? sanitize_text_field( $values[ $key ] ) : self::DEFAULTS[ $key ];
		$text   = static fn ( string $key ): string => is_string( $values[ $key ] ?? null ) ? sanitize_textarea_field( $values[ $key ] ) : '';
		$choice = static fn ( string $key, array $allowed ): string => in_array( $values[ $key ] ?? null, $allowed, true ) ? (string) $values[ $key ] : (string) self::DEFAULTS[ $key ];

		$attach = [];
		foreach ( is_array( $values['attach_to'] ?? null ) ? $values['attach_to'] : [] as $id ) {
			if ( is_string( $id ) && preg_match( '/^[a-z0-9_]{1,64}$/', $id ) === 1 ) {
				$attach[] = $id;
			}
		}
		return [
			'prefix'          => mb_substr( $string( 'prefix' ), 0, 40 ),
			'suffix'          => mb_substr( $string( 'suffix' ), 0, 40 ),
			'padding'         => max( 0, min( 12, (int) ( $values['padding'] ?? self::DEFAULTS['padding'] ) ) ),
			'reset'           => $choice( 'reset', self::RESETS ),
			'attach_to'       => array_values( array_unique( $attach ) ),
			'customer_access' => $choice( 'customer_access', self::ACCESS ),
			'shop_name'       => mb_substr( $string( 'shop_name' ), 0, 200 ),
			'shop_address'    => mb_substr( $text( 'shop_address' ), 0, 1000 ),
			'shop_details'    => mb_substr( $text( 'shop_details' ), 0, 1000 ),
			'logo_id'         => max( 0, (int) ( $values['logo_id'] ?? 0 ) ),
			'footer'          => mb_substr( $text( 'footer' ), 0, 2000 ),
			'paper'           => $choice( 'paper', self::PAPERS ),
			'show_sku'        => (bool) ( $values['show_sku'] ?? self::DEFAULTS['show_sku'] ),
			'credit_prefix'   => mb_substr( $string( 'credit_prefix' ), 0, 40 ),
			'credit_suffix'   => mb_substr( $string( 'credit_suffix' ), 0, 40 ),
			'credit_attach'   => (bool) ( $values['credit_attach'] ?? false ),
		];
	}
}
