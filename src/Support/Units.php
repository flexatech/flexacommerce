<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Weight is stored in grams and dimensions in millimetres (doc 03 §3.6);
 * the store's units are only for input and display.
 */
final class Units {
	public const GRAMS = [
		'kg' => 1000.0,
		'g'  => 1.0,
		'lb' => 453.59237,
		'oz' => 28.349523125,
	];

	public const MILLIMETRES = [
		'mm' => 1.0,
		'cm' => 10.0,
		'm'  => 1000.0,
		'in' => 25.4,
		'yd' => 914.4,
	];

	/** Grams in the store's weight unit. */
	public static function weight( float $grams ): float {
		return $grams / ( self::GRAMS[ Settings::string( 'weight_unit' ) ] ?? 1000.0 );
	}

	/** Millimetres in the store's dimension unit. */
	public static function dimension( float $millimetres ): float {
		return $millimetres / ( self::MILLIMETRES[ Settings::string( 'dimension_unit' ) ] ?? 10.0 );
	}
}
