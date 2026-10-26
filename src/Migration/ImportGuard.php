<?php

declare(strict_types=1);

namespace Flexa\Commerce\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Side-effect suppression while a batch runs (doc 05 §8.3): no mail leaves
 * the site, WooCommerce does not touch stock, FlexaCommerce emails stay
 * quiet. Nested enable() calls are counted; the filters go away with the
 * last disable().
 */
final class ImportGuard {
	private static int $depth = 0;

	/** @var list<array{0:string,1:callable,2:int}> */
	private static array $filters = [];

	public static function active(): bool {
		return self::$depth > 0;
	}

	public static function enable(): void {
		if ( self::$depth++ > 0 ) {
			return;
		}
		$false = static fn (): bool => false;
		$true  = static fn (): bool => true;
		// pre_wp_mail short-circuits when the value is not null; false means "not sent".
		self::add( 'pre_wp_mail', $false, PHP_INT_MAX );
		self::add( 'flexacommerce.emails.suspended', $true, PHP_INT_MAX );
		self::add( 'woocommerce_payment_complete_reduce_order_stock', $false, PHP_INT_MAX );
		self::add( 'woocommerce_can_reduce_order_stock', $false, PHP_INT_MAX );
		self::add( 'woocommerce_can_restore_order_stock', $false, PHP_INT_MAX );
		foreach ( self::woocommerce_email_ids() as $id ) {
			self::add( 'woocommerce_email_enabled_' . $id, $false, PHP_INT_MAX );
		}
	}

	public static function disable(): void {
		if ( self::$depth === 0 || --self::$depth > 0 ) {
			return;
		}
		foreach ( self::$filters as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		self::$filters = [];
	}

	/**
	 * @template T
	 * @param callable():T $work
	 * @return T
	 */
	public static function run( callable $work ): mixed {
		self::enable();
		try {
			return $work();
		} finally {
			self::disable();
		}
	}

	private static function add( string $hook, callable $callback, int $priority ): void {
		add_filter( $hook, $callback, $priority );
		self::$filters[] = [ $hook, $callback, $priority ];
	}

	/** @return list<string> */
	private static function woocommerce_email_ids(): array {
		if ( ! function_exists( 'WC' ) ) {
			return [];
		}
		$ids = [];
		foreach ( WC()->mailer()->get_emails() as $email ) {
			$ids[] = (string) $email->id;
		}
		return $ids;
	}
}
