<?php

declare(strict_types=1);

namespace Flexa\Commerce\StockAlerts;

defined( 'ABSPATH' ) || exit;

/**
 * Back-in-stock settings (ADR-065), one option that is not autoloaded:
 * only product pages with nothing in stock and the cron read it. Texts
 * left empty use the defaults below, so a translation still reaches them.
 *
 * @phpstan-type Values array{enabled:bool,guests:bool,subject:string,heading:string,message:string}
 */
final class StockAlertSettings {
	public const OPTION = 'flexacommerce_stock_alerts';

	/** @return Values */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		return self::clean( is_array( $stored ) ? $stored : [] );
	}

	public static function enabled(): bool {
		return self::get()['enabled'];
	}

	/**
	 * @param array<string,mixed> $partial
	 * @return Values
	 */
	public static function update( array $partial ): array {
		$current = self::get();
		$values  = self::clean( array_merge( $current, array_intersect_key( $partial, $current ) ) );
		update_option( self::OPTION, $values, false );
		return $values;
	}

	/** @return array{subject:string,heading:string,message:string} */
	public static function default_texts(): array {
		return [
			'subject' => __( '{product} is back in stock', 'flexacommerce' ),
			'heading' => __( 'Back in stock', 'flexacommerce' ),
			'message' => __( 'Good news: {product} is back in stock at {site_title}. You asked us to tell you, so here is the link. Stock is not held for you, so it may sell out again.', 'flexacommerce' ),
		];
	}

	/**
	 * @param array<mixed> $values
	 * @return Values
	 */
	public static function clean( array $values ): array {
		$string = static fn ( string $key ): string => is_string( $values[ $key ] ?? null ) ? mb_substr( sanitize_text_field( $values[ $key ] ), 0, 200 ) : '';
		return [
			'enabled' => (bool) ( $values['enabled'] ?? true ),
			'guests'  => (bool) ( $values['guests'] ?? true ),
			'subject' => $string( 'subject' ),
			'heading' => $string( 'heading' ),
			'message' => is_string( $values['message'] ?? null ) ? mb_substr( sanitize_textarea_field( $values['message'] ), 0, 2000 ) : '',
		];
	}
}
