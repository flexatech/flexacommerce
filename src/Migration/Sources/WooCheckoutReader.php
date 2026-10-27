<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration\Sources;

use Flexa\Commerce\Migration\Canonical\Record;
use Flexa\Commerce\Migration\Canonical\Ref;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout fields of the Checkout Field Editor for WooCommerce
 * (ThemeHigh) as one record (doc 05 §5.10, ADR-036). Each section is the
 * extension's option as it stores it, in the order the checkout shows the
 * fields, or null when the merchant never saved that section. The writer
 * maps them, so it can say what it changed. The options are read even when
 * the extension is inactive.
 */
final class WooCheckoutReader {
	/** Section => option of the extension. */
	public const OPTIONS = [
		'billing'    => 'wc_fields_billing',
		'shipping'   => 'wc_fields_shipping',
		'additional' => 'wc_fields_additional',
	];

	public function checkout_fields(): ?Record {
		$sections = [];
		foreach ( self::OPTIONS as $section => $option ) {
			$sections[ $section ] = self::section( get_option( $option, null ) );
		}
		if ( array_filter( $sections, static fn ( ?array $fields ): bool => $fields !== null ) === [] ) {
			return null;
		}
		return new Record(
			Record::CHECKOUT_FIELDS,
			Ref::make( 'wc', Record::CHECKOUT_FIELDS, 1 ),
			[ 'sections' => $sections ]
		);
	}

	/** True when the merchant saved at least one section in the extension. */
	public static function exists(): bool {
		foreach ( self::OPTIONS as $option ) {
			if ( self::section( get_option( $option, null ) ) !== null ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{sections:int,custom:int} saved sections and the merchant's own fields in them */
	public static function summary(): array {
		$out = [
			'sections' => 0,
			'custom'   => 0,
		];
		foreach ( self::OPTIONS as $option ) {
			$fields = self::section( get_option( $option, null ) );
			if ( $fields === null ) {
				continue;
			}
			++$out['sections'];
			$out['custom'] += count( array_filter( $fields, static fn ( array $f ): bool => ! empty( $f['custom'] ) ) );
		}
		return $out;
	}

	/**
	 * The fields with their names, sorted by priority as the extension does;
	 * fields without one keep their place after the others.
	 *
	 * @return list<array<array-key,mixed>>|null
	 */
	private static function section( mixed $option ): ?array {
		if ( ! is_array( $option ) || $option === [] ) {
			return null;
		}
		$fields = [];
		$i      = 0;
		foreach ( $option as $name => $field ) {
			if ( ! is_array( $field ) || ! is_string( $name ) || $name === '' ) {
				continue;
			}
			$fields[] = [
				'field' => [ 'name' => $name ] + self::plain( $field ),
				'order' => is_numeric( $field['priority'] ?? null ) ? (float) $field['priority'] : PHP_INT_MAX,
				'index' => $i++,
			];
		}
		usort( $fields, static fn ( array $a, array $b ): int => [ $a['order'], $a['index'] ] <=> [ $b['order'], $b['index'] ] );
		return $fields === [] ? null : array_column( $fields, 'field' );
	}

	/**
	 * Strings, numbers and arrays only, so the record survives the file
	 * transport.
	 *
	 * @param array<array-key,mixed> $value
	 * @return array<array-key,mixed>
	 */
	private static function plain( array $value ): array {
		$out = [];
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$out[ $key ] = self::plain( $item );
			} elseif ( is_scalar( $item ) || $item === null ) {
				$out[ $key ] = $item;
			}
		}
		return $out;
	}
}
