<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping\Methods;

defined( 'ABSPATH' ) || exit;

/** The merchant's name for a method instance, else the method title. */
final class Label {
	/** @param array<string,mixed> $settings */
	public static function of( array $settings, string $fallback ): string {
		$title = is_string( $settings['title'] ?? null ) ? trim( $settings['title'] ) : '';
		return $title !== '' ? $title : $fallback;
	}
}
