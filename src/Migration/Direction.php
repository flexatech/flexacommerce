<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * The two job directions. The id map always stores the WooCommerce id as
 * `remote_id` and the FlexaCommerce id as `local_id` (doc 05 §4.2).
 */
final class Direction {
	public const WC_TO_FXC = 'wc_to_fxc';
	public const FXC_TO_WC = 'fxc_to_wc';

	public const ALL = [ self::WC_TO_FXC, self::FXC_TO_WC ];

	public static function source( string $direction ): string {
		return $direction === self::FXC_TO_WC ? 'fxc' : 'wc';
	}

	public static function target( string $direction ): string {
		return $direction === self::FXC_TO_WC ? 'wc' : 'fxc';
	}

	public static function reverse( string $direction ): string {
		return $direction === self::FXC_TO_WC ? self::WC_TO_FXC : self::FXC_TO_WC;
	}
}
