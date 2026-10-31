<?php

declare(strict_types=1);

namespace Flexa\Commerce\Recovery;

defined( 'ABSPATH' ) || exit;

/**
 * Abandoned cart settings (ADR-063), one option that is not autoloaded:
 * the checkout page and the five-minute cron read it. Reminder texts left
 * empty use the defaults below, so a translation still reaches them.
 *
 * @phpstan-type Reminder array{enabled:bool,delay_hours:int,subject:string,heading:string,message:string,coupon_type:string,coupon_amount:int,coupon_days:int}
 * @phpstan-type Values array{enabled:bool,abandon_minutes:int,capture_guests:bool,reminders:list<Reminder>}
 */
final class RecoverySettings {
	public const OPTION = 'flexacommerce_recovery';

	public const REMINDERS = 3;

	/** '' gives no coupon; a percent is whole percent, a fixed amount in the minor unit. */
	public const COUPON_TYPES = [ '', 'percent', 'fixed' ];

	public const MIN_ABANDON = 15;

	public const MAX_ABANDON = 1440;

	private const DELAYS = [ 1, 24, 72 ];

	/** @return Values */
	public static function get(): array {
		$stored = get_option( self::OPTION, [] );
		return self::clean( is_array( $stored ) ? $stored : [] );
	}

	public static function enabled(): bool {
		return self::get()['enabled'];
	}

	/** Reminders are on and guests' addresses are kept from the checkout. */
	public static function captures_guests(): bool {
		$values = self::get();
		return $values['enabled'] && $values['capture_guests'];
	}

	/**
	 * Merges a partial update over the stored values. A partial `reminders`
	 * list replaces the reminders it names by position.
	 *
	 * @param array<string,mixed> $partial
	 * @return Values
	 */
	public static function update( array $partial ): array {
		$current = self::get();
		$merged  = array_merge( $current, array_intersect_key( $partial, $current ) );
		if ( is_array( $partial['reminders'] ?? null ) ) {
			$reminders = $current['reminders'];
			foreach ( $partial['reminders'] as $index => $reminder ) {
				if ( isset( $reminders[ $index ] ) && is_array( $reminder ) ) {
					$reminders[ $index ] = array_merge( $reminders[ $index ], $reminder );
				}
			}
			$merged['reminders'] = $reminders;
		}
		$values = self::clean( $merged );
		update_option( self::OPTION, $values, false );
		return $values;
	}

	/**
	 * The texts a reminder sends when its own are empty.
	 *
	 * @return array{subject:string,heading:string,message:string}
	 */
	public static function default_texts( int $index ): array {
		return match ( $index ) {
			0       => [
				'subject' => __( 'You left something in your cart', 'flexacommerce' ),
				'heading' => __( 'Your cart is waiting', 'flexacommerce' ),
				'message' => __( 'You added these items to your cart at {site_title} but did not finish your order. Your cart is saved, so you can pick up where you left off.', 'flexacommerce' ),
			],
			1       => [
				'subject' => __( 'Still thinking it over?', 'flexacommerce' ),
				'heading' => __( 'Your cart is still here', 'flexacommerce' ),
				'message' => __( 'The items in your cart are still saved. If something stopped you from ordering, reply to this email and we will help.', 'flexacommerce' ),
			],
			default => [
				'subject' => __( 'A last reminder about your cart', 'flexacommerce' ),
				'heading' => __( 'Last call for your cart', 'flexacommerce' ),
				'message' => __( 'This is the last reminder we will send about your cart. Items are not held for you, so they may sell out.', 'flexacommerce' ),
			],
		};
	}

	/**
	 * @param array<mixed> $values
	 * @return Values
	 */
	public static function clean( array $values ): array {
		$reminders = [];
		$given     = is_array( $values['reminders'] ?? null ) ? array_values( $values['reminders'] ) : [];
		for ( $i = 0; $i < self::REMINDERS; $i++ ) {
			$reminders[] = self::reminder( is_array( $given[ $i ] ?? null ) ? $given[ $i ] : [], $i );
		}
		return [
			'enabled'         => (bool) ( $values['enabled'] ?? false ),
			'abandon_minutes' => max( self::MIN_ABANDON, min( self::MAX_ABANDON, (int) ( $values['abandon_minutes'] ?? 60 ) ) ),
			'capture_guests'  => (bool) ( $values['capture_guests'] ?? true ),
			'reminders'       => $reminders,
		];
	}

	/**
	 * @param array<mixed> $in
	 * @return Reminder
	 */
	private static function reminder( array $in, int $index ): array {
		$string = static fn ( string $key, int $max ): string => is_string( $in[ $key ] ?? null ) ? mb_substr( sanitize_text_field( $in[ $key ] ), 0, $max ) : '';
		$type   = is_string( $in['coupon_type'] ?? null ) && in_array( $in['coupon_type'], self::COUPON_TYPES, true ) ? $in['coupon_type'] : '';
		$amount = max( 0, (int) ( $in['coupon_amount'] ?? 0 ) );
		if ( $type === 'percent' ) {
			$amount = min( 100, $amount );
		}
		if ( $amount === 0 ) {
			$type = '';
		}
		return [
			'enabled'       => (bool) ( $in['enabled'] ?? $index === 0 ),
			'delay_hours'   => max( 1, min( 720, (int) ( $in['delay_hours'] ?? self::DELAYS[ $index ] ) ) ),
			'subject'       => $string( 'subject', 200 ),
			'heading'       => $string( 'heading', 200 ),
			'message'       => is_string( $in['message'] ?? null ) ? mb_substr( sanitize_textarea_field( $in['message'] ), 0, 2000 ) : '',
			'coupon_type'   => $type,
			'coupon_amount' => $type === '' ? 0 : $amount,
			'coupon_days'   => max( 1, min( 90, (int) ( $in['coupon_days'] ?? 7 ) ) ),
		];
	}
}
