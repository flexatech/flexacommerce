<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping;

use Flexa\Commerce\Contracts\Shipping\PackageLine;
use Flexa\Commerce\Contracts\Shipping\ShippingPackage;
use Flexa\Commerce\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The store's shipping classes (ADR-068) live in the `shipping_classes`
 * setting; products store the slug. '' is "no shipping class".
 */
final class ShippingClasses {
	/** @return list<array{slug:string,name:string}> */
	public static function all(): array {
		$out = [];
		foreach ( (array) Settings::get( 'shipping_classes' ) as $class ) {
			if ( is_array( $class ) && is_string( $class['slug'] ?? null ) ) {
				$out[] = [
					'slug' => $class['slug'],
					'name' => (string) ( $class['name'] ?? $class['slug'] ),
				];
			}
		}
		return $out;
	}

	public static function any(): bool {
		return self::all() !== [];
	}

	/**
	 * The lines of a package by class, in the order they appear.
	 *
	 * @return array<string,list<PackageLine>>
	 */
	public static function groups( ShippingPackage $package ): array {
		$out = [];
		foreach ( $package->lines as $line ) {
			$out[ $line->shipping_class ][] = $line;
		}
		return $out;
	}

	/** @return array<string,int> item count per class */
	public static function quantities( ShippingPackage $package ): array {
		return array_map( static fn ( array $lines ): int => array_sum( array_map( static fn ( PackageLine $l ): int => $l->qty, $lines ) ), self::groups( $package ) );
	}
}
