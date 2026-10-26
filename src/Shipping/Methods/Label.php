<?php

declare(strict_types=1);

namespace Flexa\Commerce\Shipping\Methods;

use Flexa\Commerce\Contracts\SettingField;

defined( 'ABSPATH' ) || exit;

/** Settings shared by the core methods: the name shown at checkout and the tax status. */
final class Label {
	/** @param array<string,mixed> $settings */
	public static function of( array $settings, string $fallback ): string {
		$title = is_string( $settings['title'] ?? null ) ? trim( $settings['title'] ) : '';
		return $title !== '' ? $title : $fallback;
	}

	/** Whether the rate is taxed; the same choice WooCommerce offers per method. */
	public static function tax_status(): SettingField {
		return new SettingField(
			'tax_status',
			'select',
			__( 'Tax status', 'flexacommerce' ),
			'taxable',
			'',
			[
				'taxable' => __( 'Taxable', 'flexacommerce' ),
				'none'    => __( 'None', 'flexacommerce' ),
			]
		);
	}

	/** @param array<string,mixed> $settings */
	public static function taxable( array $settings ): bool {
		return ( $settings['tax_status'] ?? 'taxable' ) !== 'none';
	}
}
