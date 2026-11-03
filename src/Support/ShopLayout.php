<?php

declare(strict_types=1);

namespace Flexa\Commerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The shop's filter sidebar (ADR-084), read by the storefront templates
 * and by the Settings card, WP-CLI and the demo store.
 */
final class ShopLayout {
	public const SIDES = [ 'off', 'left', 'right' ];

	/** The first archive-product.php with the sidebar; an older theme copy cannot show it. */
	public const CLASSIC_SINCE = '1.1.0';

	/** Where the filters go: off, left or right. */
	public static function side(): string {
		$side = Settings::string( 'shop_filters' );
		return in_array( $side, self::SIDES, true ) ? $side : 'off';
	}

	/** The `@version` tag in a template's first 8 KB, as WooCommerce reads it. */
	public static function version( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local template file.
		$head = is_readable( $file ) ? (string) file_get_contents( $file, false, null, 0, 8192 ) : '';
		return preg_match( '/^[ \t\/*#]*@version[ \t:]+([0-9][0-9A-Za-z.\-]*)/m', $head, $match ) === 1 ? $match[1] : '';
	}
}
